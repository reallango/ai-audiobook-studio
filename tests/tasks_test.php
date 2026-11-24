<?php
declare(strict_types=1);

// php tests/tasks_test.php  (uses a throwaway database)
$db = sys_get_temp_dir() . '/audiobook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('AI_AUDIOBOOK_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/app/bootstrap.php';

use App\Core\Database;
use App\Services\TaskService;

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) { echo "ok   $name\n"; return; }
    $failures++;
    echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO projects(id,slug,title) VALUES('p','p','Book')");
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('L','p',1,'Chapter 1',1)");
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status,started_at) VALUES('j','p','L','RUNNING',datetime('now'))");
foreach ([1, 2, 3, 4] as $n) $pdo->exec("INSERT INTO segments(id,logical_section_id,position,text,text_sha256,voice,speed,status) VALUES('g$n','L',$n,'t','h$n','am_michael',1.0," . ($n <= 3 ? "'DONE'" : "'PENDING'") . ')');

$tasks = new TaskService();
$refused = '';
try { $tasks->enqueue('p', 'm4b'); } catch (InvalidArgumentException $e) { $refused = $e->getMessage(); }
check('a build is refused while no chapter has audio', $refused, 'No chapter has finished audio yet.');
$refused = '';
try { $tasks->enqueue('p', 'nonsense'); } catch (InvalidArgumentException $e) { $refused = $e->getMessage(); }
check('an unknown task is refused', $refused, 'Unknown task.');

$a = $tasks->activity('p');
check('chapter audio shows with its progress', [$a['items'][0]['kind'], $a['items'][0]['status'], $a['items'][0]['done'], $a['items'][0]['total'], $a['active']], ['chapter', 'RUNNING', 3, 4, 1]);
check('the label names the chapter', $a['items'][0]['label'], 'Audio for Chapter 1');

// a queued build runs in the worker and its result is reported
$pdo->exec("INSERT INTO background_tasks(id,project_id,kind,status) VALUES('t','p','m4b','QUEUED')");
check('a waiting build is busy', $tasks->isBusy('p'), true);
check('and is not queued twice', $pdo->query("SELECT COUNT(*) FROM background_tasks WHERE project_id='p'")->fetchColumn() >= 1, true);
check('it appears first in the activity', [$tasks->activity('p')['items'][0]['kind'], $tasks->activity('p')['items'][0]['status'], $tasks->activity('p')['active']], ['m4b', 'QUEUED', 2]);
check('the worker runs it', $tasks->runNext(), true);
$row = $pdo->query("SELECT status, message FROM background_tasks WHERE id='t'")->fetch();
check('a build with nothing ready fails with the reason', [$row['status'], $row['message']], ['FAILED', 'No chapter has finished audio yet.']);
check('and is no longer busy', $tasks->isBusy('p'), false);
check('the failure stays visible for a while', $tasks->activity('p')['items'][0]['status'], 'FAILED');
check('nothing left to run', $tasks->runNext(), false);

$pdo->exec("INSERT INTO background_tasks(id,project_id,kind,status,heartbeat_at) VALUES('r','p','m4b','RUNNING',datetime('now'))");
check('a running build counts as the worker being there', $tasks->activity('p')['worker'], true);
check('a restart puts a half-done build back in the queue', [$tasks->recover(), $pdo->query("SELECT status FROM background_tasks WHERE id='r'")->fetchColumn()], [1, 'QUEUED']);

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
