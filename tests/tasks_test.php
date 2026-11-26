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
check('the label names the chapter and its book', [$a['items'][0]['label'], $a['items'][0]['book']], ['Chapter 1', 'Book']);

// a queued build runs in the worker and its result is reported
$pdo->exec("INSERT INTO background_tasks(id,project_id,kind,status) VALUES('t','p','m4b','QUEUED')");
check('a waiting build is busy', $tasks->isBusy('p'), true);
check('and is not queued twice', $pdo->query("SELECT COUNT(*) FROM background_tasks WHERE project_id='p'")->fetchColumn() >= 1, true);
check('it appears first in the activity', [$tasks->activity('p')['items'][0]['kind'], $tasks->activity('p')['items'][0]['status'], $tasks->activity('p')['active']], ['m4b', 'QUEUED', 2]);
check('a build waits while its own book still has a chapter being made', $tasks->runNext(), false);
$pdo->exec("UPDATE generation_jobs SET status='DONE', finished_at=datetime('now') WHERE id='j'");
check('the worker runs it', $tasks->runNext(), true);
$row = $pdo->query("SELECT status, message FROM background_tasks WHERE id='t'")->fetch();
check('a build with nothing ready fails with the reason', [$row['status'], $row['message']], ['FAILED', 'No chapter has finished audio yet.']);
check('and is no longer busy', $tasks->isBusy('p'), false);
check('the failure stays visible for a while', $tasks->activity('p')['items'][0]['status'], 'FAILED');
check('nothing left to run', $tasks->runNext(), false);
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) SELECT 'L2','p',9,'Chapter 9',1 WHERE NOT EXISTS (SELECT 1 FROM logical_sections WHERE id='L2')");
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status,requested_at) VALUES('jw','p','L2','QUEUED',datetime('now'))");
check('clearing hides the finished and failed work', $tasks->clearFinished('p'), 2);
check('and keeps what is still waiting', array_map(static fn (array $i): string => $i['status'], $tasks->activity('p')['items']), ['QUEUED']);
check('clearing again finds nothing', $tasks->clearFinished('p'), 0);
$pdo->exec("UPDATE generation_jobs SET status='DONE', finished_at=datetime('now'), cleared=0 WHERE id='j'");
$pdo->exec("DELETE FROM generation_jobs WHERE id='jw'");

$pdo->exec("INSERT INTO background_tasks(id,project_id,kind,status,heartbeat_at) VALUES('r','p','m4b','RUNNING',datetime('now'))");
check('a running build counts as the build worker being there', $tasks->activity('p')['task_worker'], true);
check('a restart puts a half-done build back in the queue', [$tasks->recover(), $pdo->query("SELECT status FROM background_tasks WHERE id='r'")->fetchColumn()], [1, 'QUEUED']);

// two books at once
$pdo->exec("INSERT INTO projects(id,slug,title) VALUES('z','z','Other Book')");
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('LZ','z',1,'Chapter 1',1)");
$pdo->exec("UPDATE generation_jobs SET status='RUNNING' WHERE id='j'");      // book p is busy making a chapter
check('a build for a book with no waiting chapters is not held up by another book', [$pdo->exec("INSERT INTO background_tasks(id,project_id,kind,status) VALUES('tz','z','m4b','QUEUED')"), $tasks->runNext()], [1, true]);
check('and it ran: its own task, not the other book\'s', [$pdo->query("SELECT status FROM background_tasks WHERE id='tz'")->fetchColumn(), $pdo->query("SELECT status FROM background_tasks WHERE id='r'")->fetchColumn()], ['FAILED', 'QUEUED']);
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status) VALUES('jz','z','LZ','QUEUED')");
$pdo->exec("INSERT INTO background_tasks(id,project_id,kind,status) VALUES('tz2','z','m4b','QUEUED')");
check('a book\'s build still waits for that book\'s own chapters', $tasks->runNext(), false);

$pdo->exec("DELETE FROM generation_jobs");
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status,started_at,requested_at) VALUES('jr','p','L','RUNNING',datetime('now'),datetime('now','-5 minutes'))");
$pdo->exec("INSERT INTO logical_sections(id,project_id,position,title,included) VALUES('LZ2','z',2,'Chapter 2',1)");
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status,requested_at) VALUES('jq','z','LZ','QUEUED',datetime('now','-1 minutes'))");
$wait = $tasks->activity('z');
check('a queued chapter says how many chapters from other books are ahead of it', [$wait['ahead'], $wait['ahead_books']], [1, ['Book']]);
check('a book that is running does not report waiting', $tasks->activity('p')['ahead'], 0);
$pdo->exec("INSERT INTO generation_jobs(id,project_id,logical_section_id,status,requested_at) VALUES('jq2','p','LZ2','QUEUED',datetime('now','-30 minutes'))");

@unlink($db);
echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
