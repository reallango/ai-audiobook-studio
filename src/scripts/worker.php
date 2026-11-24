#!/usr/bin/env php
<?php
declare(strict_types=1);

// Background generation worker. Run by supervisord in the container; safe to stop and start at any time.
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\JobService;
use App\Services\TaskService;

if (PHP_SAPI !== 'cli') exit(1);

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) pcntl_signal($signal, static function () use (&$stop): void { $stop = true; });
}

$jobs = new JobService();
$tasks = new TaskService();
$tasks->recover();
$recovered = $jobs->recover();
fwrite(STDOUT, '[worker] started' . ($recovered ? ", $recovered segment(s) made available again" : '') . "\n");

$idle = 0;
while (!$stop) {
    JobService::beat();
    try {
        if ($jobs->runNext(static function () use (&$stop): bool { return $stop; })) {
            $idle = 0;
            continue;
        }
    } catch (Throwable $error) {
        fwrite(STDERR, '[worker] ' . $error->getMessage() . "\n");
        sleep(5);
        continue;
    }
    try {
        if ($tasks->runNext()) { $idle = 0; continue; }      // after the chapter jobs: building the M4B
    } catch (Throwable $error) {
        fwrite(STDERR, '[worker] ' . $error->getMessage() . "\n");
        sleep(5);
        continue;
    }
    if (++$idle % 30 === 1) fwrite(STDOUT, "[worker] idle\n");
    sleep(2);
}
fwrite(STDOUT, "[worker] stopping\n");
