<?php
declare(strict_types=1);

// php tests/jobs_test.php  (throwaway database and audio folder, Kokoro replaced by a fake)
$tmp = sys_get_temp_dir() . '/audiobook-job-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv('AI_AUDIOBOOK_DB_PATH=' . $tmp . '/app.sqlite');
putenv('AI_AUDIOBOOK_PROJECTS_DIR=' . $tmp . '/projects');
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\GenerationService;
use App\Services\JobService;
use App\Services\NarrationService;

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

$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title,active_voice_profile_id,tts_speed) VALUES('p','p','Book','am_michael',1.0)");
$pdo->exec("INSERT INTO source_assets(id,project_id,original_name,stored_relpath,sha256,byte_size) VALUES('a','p','b.epub','x','h',1)");
$pdo->exec("INSERT INTO source_sections(id,project_id,source_asset_id,spine_index,manifest_id,href,raw_sha256) VALUES('s1','p','a',1,'c1','c1.xhtml','h')");
for ($i = 0; $i < 5; $i++) {
    $pdo->prepare('INSERT INTO source_blocks(id,project_id,source_section_id,block_index,block_type,source_text,source_sha256,dom_path) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(["b$i", 'p', 's1', $i, 'p', "Paragraph number $i. " . str_repeat('It has some words in it. ', 8), 'h', '/']);
}
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L','p',1,'Chapter 1',1)");
$pdo->exec("INSERT INTO logical_section_sources(logical_section_id,source_section_id,source_order) VALUES('L','s1',0)");

$calls = 0;
$failOn = null;
$fake = function (string $text) use (&$calls, &$failOn): string {
    $calls++;
    if ($failOn !== null && str_contains($text, $failOn)) throw new RuntimeException('Kokoro is down');
    $data = str_repeat(pack('v', 500), strlen($text) * 240);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
};
$fakeEncode = function (string $wav, string $m4a, int $kbps, array $meta): void { file_put_contents($m4a, 'FAKE-AAC' . str_repeat('x', 100)); };
$jobs = new JobService(new GenerationService($fake, $fakeEncode));
$narr = new NarrationService();

check('cannot queue an unapproved chapter', expectError(fn () => $jobs->enqueue('p', 'L')), 'rejected');
check('nothing to do when the queue is empty', $jobs->runNext(), false);
$narr->approve('p', 'L');

$job = $jobs->enqueue('p', 'L');
check('queued', $job['status'], 'QUEUED');
check('queuing again returns the same job', $jobs->enqueue('p', 'L')['id'], $job['id']);
check('worker runs it to the end', $jobs->runNext(), true);
$job = $jobs->forChapter('L');
$gen = new GenerationService($fake, $fakeEncode);
check('job done, chapter audio built', [$job['status'], $gen->status('p', 'L')['chapter_audio'] !== null, $calls], ['DONE', true, 6]);
check('queue is empty again', $jobs->runNext(), false);

// a restart in the middle: stop after two segments, then resume
$narr->setOverride('p', 'L', 'b1', 'Changed text for the second paragraph.');
$narr->setOverride('p', 'L', 'b2', 'Changed text for the third paragraph.');
$narr->approve('p', 'L');
$jobs->enqueue('p', 'L');
$calls = 0;
$seen = 0;
$jobs->runNext(function () use (&$seen): bool { return ++$seen > 1; });   // asked to stop before the second segment
check('stopped job goes back to the queue', [$jobs->forChapter('L')['status'], $calls], ['QUEUED', 1]);
// simulate a hard kill: a segment left GENERATING and a job left RUNNING
$pdo->exec("UPDATE generation_jobs SET status='RUNNING', heartbeat_at=datetime('now')");
$pdo->exec("UPDATE segments SET status='GENERATING', claimed_at=datetime('now') WHERE status='PENDING' AND position=(SELECT MIN(position) FROM segments WHERE status='PENDING')");
check('a fresh RUNNING job is left alone', $jobs->runNext(), false);
check('recovery after a restart', $jobs->recover() >= 1, true);
check('the job is picked up again', $jobs->runNext(), true);
check('only the missing segment was generated after the restart', [$jobs->forChapter('L')['status'], $calls], ['DONE', 2]);
check('chapter audio is current', $gen->status('p', 'L')['audio_stale'], false);

// a stale RUNNING job (worker died without a restart) is taken over
$narr->setOverride('p', 'L', 'b3', 'Another change entirely.');
$narr->approve('p', 'L');
$jobs->enqueue('p', 'L');
$pdo->exec("UPDATE generation_jobs SET status='RUNNING', heartbeat_at=datetime('now','-5 minutes')");
check('stale job is taken over', $jobs->runNext(), true);
check('and finished', $jobs->forChapter('L')['status'], 'DONE');

// failures
$narr->setOverride('p', 'L', 'b4', 'This one will fail to generate.');
$narr->approve('p', 'L');
$jobs->enqueue('p', 'L');
$failOn = 'will fail';
$jobs->runNext();
$job = $jobs->forChapter('L');
check('a failing segment fails the job, with a reason', [$job['status'], str_contains($job['message'], '1 segment')], ['FAILED', true]);
$failOn = null;
check('a failed job can be queued again', $jobs->enqueue('p', 'L')['status'], 'QUEUED');
$gen->reset('p', 'L', null);
$jobs->runNext();
check('and succeeds after the retry', $jobs->forChapter('L')['status'], 'DONE');

// cancel
$narr->setOverride('p', 'L', 'b0', 'Cancelled before it could finish.');
$narr->approve('p', 'L');
$jobs->enqueue('p', 'L');
$jobs->cancel('p', 'L');
check('a cancelled job is not run', [$jobs->runNext(), $jobs->forChapter('L')['status']], [false, 'CANCELLED']);

// worker heartbeat
$pdo->exec("DELETE FROM settings WHERE setting_key='worker_heartbeat'");
check('worker offline before the first heartbeat', JobService::workerOnline(), false);
$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('worker_heartbeat', datetime('now','-5 minutes'))");
check('worker offline when the heartbeat is old', JobService::workerOnline(), false);
JobService::beat();
check('worker online right after a heartbeat', JobService::workerOnline(), true);

function rrmdir(string $d): void { foreach (glob("$d/*") ?: [] as $f) is_dir($f) ? rrmdir($f) : @unlink($f); @rmdir($d); }
rrmdir($tmp);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
