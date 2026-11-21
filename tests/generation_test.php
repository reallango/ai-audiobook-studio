<?php
declare(strict_types=1);

// php tests/generation_test.php  (throwaway database and audio folder, Kokoro replaced by a fake)
$tmp = sys_get_temp_dir() . '/audiobook-gen-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv('AI_AUDIOBOOK_DB_PATH=' . $tmp . '/app.sqlite');
putenv('AI_AUDIOBOOK_PROJECTS_DIR=' . $tmp . '/projects');
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\GenerationService;
use App\Services\NarrationService;
use App\Services\WavFile;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}
function expectError(callable $fn): string
{
    try { $fn(); return 'no error'; } catch (InvalidArgumentException $e) { return 'rejected'; }
}

// ----- fixture: one project, one chapter of five paragraphs -----
$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id,tts_speed) VALUES('p','p','Book','am_michael',1.0)");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','b.epub','x','h',1)");
$pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s1','p','a',1,'c1','c1.xhtml','h')");
for ($i = 0; $i < 5; $i++) {
    $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(["b$i", 'p', 's1', $i, 'p', "Paragraph number $i. " . str_repeat("It has some words in it. ", 8), 'h', '/']);
}
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L','p',1,'Chapter 1',1)");
$pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L','s1',0)");

// fake Kokoro: 10 ms of audio per character, 24 kHz mono; one text can be made to fail
$calls = [];
$failOn = null;
$fake = function (string $text, string $voice, float $speed) use (&$calls, &$failOn): string {
    $calls[] = $text;
    if ($failOn !== null && str_contains($text, $failOn)) throw new RuntimeException('Kokoro is having a bad day');
    $n = (int) (strlen($text) * 240);
    $data = str_repeat(pack('v', 500), $n);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
};
$gen = new GenerationService($fake);
$narr = new NarrationService();

check('cannot prepare an unapproved chapter', expectError(fn () => $gen->prepare('p', 'L')), 'rejected');
$narr->approve('p', 'L');
$prep = $gen->prepare('p', 'L');
check('segments created (title + 5 paragraphs)', [$prep['total'], $prep['created'], $prep['reused']], [6, 6, 0]);
$st = $gen->status('p', 'L');
check('all pending, nothing to assemble yet', [$st['counts']['pending'], $st['ready_to_assemble'], $st['needs_prepare']], [6, false, false]);

// generate everything, one segment per call
$n = 0;
while (($r = $gen->generateNext('p', 'L'))['segment'] !== null && $n++ < 20) {
}
check('every segment generated, in order', [$gen->status('p', 'L')['counts']['done'], count($calls)], [6, 6]);
check('segments went to the fake in position order', $calls[0], 'Chapter 1.');
$st = $gen->status('p', 'L');
check('ready to assemble', $st['ready_to_assemble'], true);

$res = $gen->assemble('p', 'L');
$expectedMs = (int) round(array_sum(array_map(fn ($s) => strlen($s['text']) * 10, $st['segments'])) + 5 * 800);
check('chapter audio length = segments + 5 paragraph pauses', $res['duration_ms'], $expectedMs);
$file = $gen->audioFile('p', 'chapter', 'L');
check('chapter file is a valid WAV', $file !== null && WavFile::info($file)['duration_ms'] === $expectedMs, true);
check('segment files can be fetched too', $gen->audioFile('p', 'segment', (string) $st['segments'][0]['id']) !== null, true);
check('another project cannot fetch it', $gen->audioFile('other', 'chapter', 'L'), null);
check('audio is fresh after assembling', $gen->status('p', 'L')['audio_stale'], false);

// edit one paragraph: only that segment is regenerated
$narr->setOverride('p', 'L', 'b2', 'A completely different paragraph, spoken instead.');
check('editing after approval removes the approval, so no prepare', expectError(fn () => $gen->prepare('p', 'L')), 'rejected');
$narr->approve('p', 'L');
check('status says the segments are out of date', $gen->status('p', 'L')['needs_prepare'], true);
$calls = [];
$prep = $gen->prepare('p', 'L');
check('only the changed paragraph is new', [$prep['reused'], $prep['created'], $prep['removed']], [5, 1, 1]);
while ($gen->generateNext('p', 'L')['segment'] !== null) {
}
check('only one segment was sent to Kokoro', [count($calls), str_contains($calls[0], 'completely different')], [1, true]);
check('chapter audio is stale until reassembled', $gen->status('p', 'L')['audio_stale'], true);
$gen->assemble('p', 'L');
check('reassembled', $gen->status('p', 'L')['audio_stale'], false);

// failures: three attempts, then it stops asking until reset
$seg = $gen->status('p', 'L')['segments'][4];
$gen->reset('p', 'L', (string) $seg['id']);
$failOn = 'Paragraph number 3';
$attempts = 0;
while (($r = $gen->generateNext('p', 'L'))['segment'] !== null && $attempts < 10) $attempts++;
$st = $gen->status('p', 'L');
check('a failing segment is tried three times, then left failed', [$attempts, $st['counts']['failed'], $st['segments'][4]['attempts']], [3, 1, 3]);
check('the error is recorded', str_contains((string) $st['segments'][4]['error'], 'bad day'), true);
check('cannot assemble with a failed segment', expectError(fn () => $gen->assemble('p', 'L')), 'rejected');
$failOn = null;
check('reset retries all failed segments', $gen->reset('p', 'L', null), 1);
while ($gen->generateNext('p', 'L')['segment'] !== null) {
}
check('retry succeeds', $gen->status('p', 'L')['counts']['done'], 6);

// voice change: everything is out of date and regenerated
$pdo->exec("UPDATE projects SET active_voice_profile_id='bm_lewis' WHERE id='p'");
check('a voice change makes segments out of date', $gen->status('p', 'L')['needs_prepare'], true);
$prep = $gen->prepare('p', 'L');
check('nothing is reused across voices', [$prep['reused'], $prep['created']], [0, 6]);

// project-wide audio state
$sum = $gen->summaries('p', $narr->overview('p'));
check('state: audio exists but the segments were remade for another voice', $sum['L']['state'], 'outdated');
while ($gen->generateNext('p', 'L')['segment'] !== null) {
}
$gen->assemble('p', 'L');
$sum = $gen->summaries('p', $narr->overview('p'));
check('state: ready after generating and joining', [$sum['L']['state'], $sum['L']['done'], $sum['L']['total']], ['ready', 6, 6]);
check('regenerate-everything resets every segment', [$gen->resetAll('p', 'L'), $gen->status('p', 'L')['counts']['pending']], [6, 6]);
check('and the chapter shows as out of date', $gen->summaries('p', $narr->overview('p'))['L']['state'], 'outdated');
$narr->unapprove('p', 'L');
check('an unapproved chapter has no audio state', $gen->summaries('p', $narr->overview('p'))['L']['state'], 'n/a');
$narr->approve('p', 'L');

// per-chapter voice and speed
use App\Services\ChapterSettings;
use App\Services\PronunciationService;
(new PronunciationService())->save('voice', 'af_bella', 'Paragraph', 'Para-graf');
check('no override at first', ChapterSettings::get('L'), ['voice' => null, 'speed' => null]);
ChapterSettings::set('p', 'L', 'af_bella', 0.9);
$st = $gen->status('p', 'L');
check('the chapter override wins over the project', [$st['voice'], $st['speed']], ['af_bella', 0.9]);
check("and selects that voice's pronunciation rules", [str_contains($narr->chapter('p', 'L')['text'], 'Para-graf'), $narr->chapter('p', 'L')['voice']], [true, 'af_bella']);
$narr->approve('p', 'L');
$gen->prepare('p', 'L');
$st = $gen->status('p', 'L');
check('segments are made for the chapter voice and speed', [$st['segments'][0]['voice'], (float) $st['segments'][0]['speed'], $st['needs_prepare']], ['af_bella', 0.9, false]);
$sum = $gen->summaries('p', $narr->overview('p'));
check('the chapter list knows it is custom', [$sum['L']['custom'], $sum['L']['voice'], $sum['L']['speed']], [true, 'af_bella', 0.9]);
check('a bad voice is refused', expectError(fn () => ChapterSettings::set('p', 'L', 'Bad Voice!', null)), 'rejected');
check('a bad speed is refused', expectError(fn () => ChapterSettings::set('p', 'L', null, 9.0)), 'rejected');
check('another project cannot set it', expectError(fn () => ChapterSettings::set('other', 'L', 'af_bella', null)), 'rejected');
ChapterSettings::set('p', 'L', null, null);
check('clearing goes back to the project', [ChapterSettings::get('L'), $gen->status('p', 'L')['voice'], $gen->status('p', 'L')['speed']], [['voice' => null, 'speed' => null], 'bm_lewis', 1.0]);
check('the text changed with the voice, so it needs approving again', $gen->status('p', 'L')['narration_status'], 'changed');
$narr->approve('p', 'L');
check('and then the segments are out of date', $gen->status('p', 'L')['needs_prepare'], true);

// foreign chapter
check('chapter must belong to the project', expectError(fn () => $gen->generateNext('p', 'nope')), 'rejected');

function rrmdir(string $d): void { foreach (glob("$d/*") ?: [] as $f) is_dir($f) ? rrmdir($f) : @unlink($f); @rmdir($d); }
rrmdir($tmp);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
