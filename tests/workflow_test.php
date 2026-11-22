<?php
declare(strict_types=1);

// php tests/workflow_test.php  (throwaway database and audio folder; Kokoro, ffmpeg and ffprobe are faked)
$tmp = sys_get_temp_dir() . '/audiobook-flow-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv('AI_AUDIOBOOK_DB_PATH=' . $tmp . '/app.sqlite');
putenv('AI_AUDIOBOOK_PROJECTS_DIR=' . $tmp . '/projects');
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\AudioEncoder;
use App\Services\ExportService;
use App\Services\GenerationService;
use App\Services\NarrationService;
use App\Services\ProjectWorkflow;
use App\Services\PronunciationService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id,tts_speed) VALUES('p','p','My Book','am_michael',1.0)");
$project = fn (): array => $pdo->query("SELECT * FROM projects WHERE id='p'")->fetch();
$flow = fn (bool $imported, bool $analyzed): array => ProjectWorkflow::build($project(), $imported, $analyzed, 'book.epub');
$states = fn (array $f): string => implode(',', array_column($f['steps'], 'state'));

$f = $flow(false, false);
check('a new project starts at the source step', [$f['next']['label'], $f['next']['href'] !== '' , substr($states($f), 0, 7)], ['Import the EPUB', true, 'current']);
check('later steps wait, the voice step is already fine', $states($f), 'current,todo,done,todo,todo,todo');

$f = $flow(true, false);
check('an imported EPUB asks to be analyzed', [$f['next']['type'], $f['next']['label']], ['post', 'Analyze the EPUB']);

$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','book.epub','x','h',1)");
foreach ([1, 2] as $n) {
    $pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s$n','p','a',$n,'c$n','c$n.xhtml','h')");
    $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(["b$n", 'p', "s$n", 0, 'p', "Paragraph of chapter $n. It has some words in it.", 'h', '/']);
}
$f = $flow(true, true);
check('an analyzed book asks for the structure', [$f['next']['label'], $f['steps'][0]['state'], $f['steps'][1]['state']], ['Build the audiobook structure', 'done', 'current']);

foreach ([1, 2] as $n) {
    $pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L$n','p',$n,'Chapter $n',1)");
    $pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L$n','s$n',0)");
}
$f = $flow(true, true);
check('with a structure the next step is the review', [$f['next']['label'], $f['counts']['reviewable'], $f['counts']['approved']], ['Review and approve chapters', 2, 0]);
check('and the summary counts the chapters', $f['steps'][3]['summary'], '0 of 2 chapters approved.');

$narr = new NarrationService();
$narr->approve('p', 'L1');
$f = $flow(true, true);
check('a partly approved book keeps asking for review', [$f['next']['label'], $f['counts']['approved']], ['Review and approve chapters', 1]);
$narr->approve('p', 'L2');
$f = $flow(true, true);
check('all approved: the next step is generating audio', [$f['next']['label'], $f['counts']['todo'], $f['steps'][3]['state'], $f['steps'][4]['state']], ['Generate audio', 2, 'done', 'current']);

$synth = function (string $text): string { $d = str_repeat(pack('v', 400), strlen($text) * 240); return 'RIFF' . pack('V', 36 + strlen($d)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($d)) . $d; };
$encode = function (string $wav, string $m4a): void { file_put_contents($m4a, 'AAC' . str_repeat('x', 300)); };
$gen = new GenerationService($synth, $encode);
foreach (['L1', 'L2'] as $id) { $gen->prepare('p', $id); while ($gen->generateNext('p', $id)['segment'] !== null) {} if ($id === 'L1') $gen->assemble('p', $id); }
$f = $flow(true, true);
check('a chapter whose segments are done but not joined is still to do', [$f['counts']['ready'], $f['counts']['todo']], [1, 1]);
$gen->assemble('p', 'L2');
$f = $flow(true, true);
check('all chapters ready: the next step is the audiobook file', [$f['next']['label'], $f['counts']['ready'], $f['steps'][4]['state'], $f['steps'][5]['state'], str_contains($f['next']['href'], '#export')], ['Build the audiobook file', 2, 'done', 'current', true]);

$pdo->exec("INSERT INTO jobs(id,project_id,job_type,status) VALUES('j','p','x','QUEUED')");
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status) VALUES('g1','p','L2','RUNNING')");
$f = $flow(true, true);
check('a running job shows as generating', [$f['counts']['working'], $f['next']['label']], [1, 'Audio is being generated']);
$pdo->exec("UPDATE generation_jobs SET status='FAILED', message='x'");
$f = $flow(true, true);
check('an old failed job does not hide audio that is fine', [$f['counts']['failed'], $f['counts']['ready']], [0, 2]);
$gen->resetAll('p', 'L2');
$f = $flow(true, true);
check('a failed job on a chapter that lost its audio asks for attention', [$f['counts']['failed'], $f['next']['label']], [1, 'Fix the failed chapters']);
$pdo->exec('DELETE FROM generation_jobs');
while ($gen->generateNext('p', 'L2')['segment'] !== null) {}
$gen->assemble('p', 'L2');

$exports = new ExportService(new AudioEncoder(function (array $argv): string {
    if (str_ends_with($argv[0], 'ffprobe')) return in_array('-show_chapters', $argv, true) ? json_encode(['chapters' => [[], []]]) : '30.0';
    file_put_contents(end($argv), 'M4B' . str_repeat('y', 100));
    return '';
}, '/fake/ffmpeg', '/fake/ffprobe'));
$exports->buildM4b('p');
$f = $flow(true, true);
check('with a current audiobook file everything is done and the next step is the download', [$f['next']['label'], $f['export_current'], $states($f)], ['Download the audiobook', true, 'done,done,done,done,done,done']);

$narr->setOverride('p', 'L2', 'b2', 'A new paragraph.');
$f = $flow(true, true);
check('changing a chapter sends the project back to review', [$f['next']['label'], $f['counts']['approved'], $f['steps'][3]['state']], ['Review and approve chapters', 1, 'current']);

(new PronunciationService())->save('project', 'p', 'Bajor', 'Bay-jor');
$set = (new PronunciationService())->createSet('DS9');
(new PronunciationService())->save('set', $set, 'Sisko', 'Siss-ko');
(new PronunciationService())->attachSet('p', $set);
$f = $flow(true, true);
check('the voice step counts linked rulesets and project rules', [$f['counts']['linked'], $f['counts']['project_rules'], $f['linked'][0]['name'], $f['linked'][0]['rules'], $f['steps'][2]['summary']], [1, 1, 'DS9', 1, 'Voice am_michael at 1.00x. 1 linked ruleset, 1 project rule.']);

$pdo->exec("UPDATE projects SET status='ARCHIVED'");
$f = $flow(true, true);
check('an archived project has no next step', $f['next']['type'], 'none');

function rrmdir(string $d): void { foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $x) { if (in_array(basename($x), ['.', '..'], true)) continue; is_dir($x) ? rrmdir($x) : @unlink($x); } @rmdir($d); }
rrmdir($tmp);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
