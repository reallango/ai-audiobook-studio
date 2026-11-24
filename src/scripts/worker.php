#!/usr/bin/env php
<?php
declare(strict_types=1);

// Background worker. Run by supervisord in the container; safe to stop and start at any time.
//   php worker.php            chapter audio: the only work that needs the voice server, one chapter at a time
//   php worker.php tasks      everything else (building the M4B): ffmpeg only, so it never waits for chapter audio
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\JobService;
use App\Services\TaskService;

if (PHP_SAPI !== 'cli') exit(1);
$mode = ($argv[1] ?? 'chapters') === 'tasks' ? 'tasks' : 'chapters';

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) pcntl_signal($signal, static function () use (&$stop): void { $stop = true; });
}

$jobs = new JobService();
$tasks = new TaskService();
if ($mode === 'tasks') {
    $recovered = $tasks->recover();
    fwrite(STDOUT, '[tasks] started' . ($recovered ? ", $recovered task(s) put back in the queue" : '') . "\n");
} else {
    $recovered = $jobs->recover();
    fwrite(STDOUT, '[worker] started' . ($recovered ? ", $recovered segment(s) made available again" : '') . "\n");
}

$idle = 0;
while (!$stop) {
    try {
        if ($mode === 'tasks') {
            TaskService::beat();
            $worked = $tasks->runNext();
        } else {
            JobService::beat();
            $worked = $jobs->runNext(static function () use (&$stop): bool { return $stop; });
        }
        if ($worked) {
            $idle = 0;
            continue;
        }
    } catch (Throwable $error) {
        fwrite(STDERR, "[$mode] " . $error->getMessage() . "\n");
        sleep(5);
        continue;
    }
    if (++$idle % 30 === 1) fwrite(STDOUT, "[$mode] idle\n");
    sleep(2);
}
fwrite(STDOUT, "[$mode] stopping\n");
