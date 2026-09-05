#!/usr/bin/env php
<?php
/**
 * Mailpro Enterprise - Asynchronous Multi-Process Queue Worker
 * 
 * Usage:
 *   php worker.php                                       # Run 1 worker on all queues
 *   php worker.php --concurrency=4                       # Run 4 parallel workers (Supervisor mode)
 *   php worker.php --queue=urgent,autoreply --sleep=1    # Run high-priority worker
 *   php worker.php --once                                # Process 1 job and exit (test/cron mode)
 */

if (php_sapi_name() !== 'cli') {
    die("Error: worker.php must be run from the command line (CLI).\n");
}

// Pre-flight check: ensure pdo_mysql is loaded
if (!extension_loaded('pdo_mysql')) {
    echo "\n========================================================================\n";
    echo "  MAILPRO ENTERPRISE SETUP ERROR: 'pdo_mysql' DRIVER MISSING            \n";
    echo "========================================================================\n";
    echo "Current PHP Binary : " . PHP_BINARY . " (v" . PHP_VERSION . ")\n\n";
    echo "The PHP CLI binary you just ran does not have the 'pdo_mysql' extension.\n";
    echo "This typically happens in aaPanel, cPanel, or VPS when the default system\n";
    echo "'php' command points to a minimal OS PHP instead of the web server's PHP.\n\n";
    
    // Auto-detect aaPanel PHP paths
    $foundAaPhp = [];
    foreach (['83', '82', '81', '80', '74'] as $ver) {
        $bin = "/www/server/php/{$ver}/bin/php";
        if (@file_exists($bin)) {
            $foundAaPhp[] = $bin;
        }
    }

    if (!empty($foundAaPhp)) {
        echo "Found installed aaPanel PHP binary on this server! Please run:\n";
        foreach ($foundAaPhp as $p) {
            echo "  👉 \033[32m{$p} " . basename(__FILE__) . " " . implode(' ', array_slice($argv, 1)) . "\033[0m\n";
        }
    } else {
        echo "Recommended Fix:\n";
        echo "1. If using aaPanel:\n";
        echo "   👉 /www/server/php/82/bin/php " . basename(__FILE__) . " " . implode(' ', array_slice($argv, 1)) . "\n";
        echo "   👉 /www/server/php/81/bin/php " . basename(__FILE__) . " " . implode(' ', array_slice($argv, 1)) . "\n";
        echo "2. If using Ubuntu/Debian:\n";
        echo "   sudo apt-get install php-mysql -y\n";
        echo "3. If using CentOS / AlmaLinux / Rocky Linux:\n";
        echo "   sudo dnf install php-mysqlnd -y\n";
    }
    echo "========================================================================\n\n";
    exit(1);
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/queue.php';
require_once __DIR__ . '/includes/dispatcher.php';

// Parse command line arguments
$options = getopt('', [
    'queue::',
    'concurrency::',
    'sleep::',
    'max-jobs::',
    'memory::',
    'once',
    'help'
]);

if (isset($options['help'])) {
    echo "Mailpro Enterprise Queue Worker\n";
    echo "Options:\n";
    echo "  --queue=NAME1,NAME2   Comma-separated list of queues to listen on (default: all)\n";
    echo "  --concurrency=N       Spawn and supervise N concurrent child workers (default: 1)\n";
    echo "  --sleep=SECONDS       Seconds to sleep when queues are empty (default: 2)\n";
    echo "  --max-jobs=COUNT      Maximum jobs to process before worker recycles (default: 500)\n";
    echo "  --memory=MB           Max memory limit in MB before worker recycles (default: 128)\n";
    echo "  --once                Process only one job and exit immediately\n";
    echo "  --help                Display this help screen\n\n";
    exit(0);
}

$queuesArg   = $options['queue'] ?? '';
$queues      = $queuesArg ? array_map('trim', explode(',', $queuesArg)) : [];
$concurrency = max(1, (int)($options['concurrency'] ?? 1));
$sleepTime   = max(1, (int)($options['sleep'] ?? 1));
$maxJobs     = max(1, (int)($options['max-jobs'] ?? 500));
$memoryLimit = max(32, (int)($options['memory'] ?? 128));
$runOnce     = isset($options['once']);

$workerId = gethostname() . ':' . getmypid();

// ─────────────────────────────────────────────────────────────
// SUPERVISOR / MULTI-PROCESS MASTER MODE
// ─────────────────────────────────────────────────────────────
if ($concurrency > 1 && !$runOnce) {
    echo "\n=========================================================\n";
    echo "  MAILPRO ENTERPRISE - QUEUE WORKER SUPERVISOR           \n";
    echo "  Concurrency: {$concurrency} workers | Queues: " . ($queuesArg ?: 'all') . "\n";
    echo "=========================================================\n\n";

    $processes = [];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => STDOUT,
        2 => STDERR
    ];

    $startWorker = function($idx) use (&$processes, $descriptors, $queuesArg, $sleepTime, $maxJobs, $memoryLimit) {
        $cmd = sprintf(
            '%s %s --concurrency=1 --sleep=%d --max-jobs=%d --memory=%d %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            $sleepTime,
            $maxJobs,
            $memoryLimit,
            $queuesArg ? '--queue=' . escapeshellarg($queuesArg) : ''
        );
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            $processes[$idx] = ['proc' => $proc, 'pipes' => $pipes, 'started_at' => time()];
            echo "[Supervisor] Spawned child worker #{$idx} (PID: " . proc_get_status($proc)['pid'] . ")\n";
        }
    };

    // Initial spawn
    for ($i = 1; $i <= $concurrency; $i++) {
        $startWorker($i);
    }

    // Supervisor monitoring loop
    $running = true;
    if (function_exists('pcntl_signal')) {
        declare(ticks = 1);
        $stopSupervisor = function() use (&$running, &$processes) {
            echo "\n[Supervisor] Caught termination signal. Stopping all workers...\n";
            $running = false;
            foreach ($processes as $idx => $p) {
                if (is_resource($p['proc'])) {
                    proc_terminate($p['proc']);
                }
            }
        };
        pcntl_signal(SIGTERM, $stopSupervisor);
        pcntl_signal(SIGINT, $stopSupervisor);
    }

    $consecutiveRapidFailures = 0;

    while ($running) {
        $anyCrashed = false;
        foreach ($processes as $idx => $p) {
            $status = proc_get_status($p['proc']);
            if (!$status['running']) {
                proc_close($p['proc']);
                $uptime = time() - ($p['started_at'] ?? time());
                echo "[Supervisor] Worker #{$idx} exited (exit code: {$status['exitcode']}, uptime: {$uptime}s).\n";

                if ($uptime < 3) {
                    $consecutiveRapidFailures++;
                } else {
                    $consecutiveRapidFailures = max(0, $consecutiveRapidFailures - 1);
                }

                if ($consecutiveRapidFailures > ($concurrency * 2)) {
                    echo "\n\033[31m[Supervisor Error] Child workers are failing immediately upon launch (exit code {$status['exitcode']}).\033[0m\n";
                    echo "Please check PHP binary dependencies (such as 'pdo_mysql' extension) or run a single worker directly to inspect output:\n";
                    echo "  👉 php worker.php --once\n\n";
                    $running = false;
                    break 2;
                }

                echo "[Supervisor] Respawning Worker #{$idx}...\n";
                $startWorker($idx);
                $anyCrashed = true;
            }
        }
        if ($anyCrashed && $consecutiveRapidFailures > 0) {
            sleep(min(5, $consecutiveRapidFailures));
        } else {
            sleep(2);
        }
    }

    // Wait for all to finish
    foreach ($processes as $p) {
        if (is_resource($p['proc'])) {
            proc_close($p['proc']);
        }
    }
    echo "[Supervisor] All workers cleanly terminated.\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────
// SINGLE WORKER EXECUTION LOOP
// ─────────────────────────────────────────────────────────────
echo sprintf("[%s] [Worker %s] Started listening on queues: [%s]\n",
    date('Y-m-d H:i:s'),
    $workerId,
    implode(', ', $queues ?: ['all'])
);

$jobsProcessed = 0;
$shouldQuit = false;

if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    $signalHandler = function() use (&$shouldQuit, $workerId) {
        echo sprintf("\n[%s] [Worker %s] Received stop signal. Completing active job and shutting down...\n", date('Y-m-d H:i:s'), $workerId);
        $shouldQuit = true;
    };
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
}

while (!$shouldQuit) {
    // Check memory ceiling
    $memUsageMb = memory_get_usage(true) / 1024 / 1024;
    if ($memUsageMb >= $memoryLimit) {
        echo sprintf("[%s] [Worker %s] Memory limit exceeded (%.2fMB >= %dMB). Recycling worker...\n", date('Y-m-d H:i:s'), $workerId, $memUsageMb, $memoryLimit);
        break;
    }

    // Check max jobs ceiling
    if ($jobsProcessed >= $maxJobs) {
        echo sprintf("[%s] [Worker %s] Reached maximum job limit (%d). Recycling worker...\n", date('Y-m-d H:i:s'), $workerId, $maxJobs);
        break;
    }

    // 1. Real-time dispatch for due Auto-Reply and Follow-Up sequences (< 1s latency)
    $arDispatched = processAutoReplyQueue(25);
    $fuDispatched = processFollowUpQueue(25);
    if ($arDispatched > 0 || $fuDispatched > 0) {
        $jobsProcessed += ($arDispatched + $fuDispatched);
        echo sprintf("[%s] [Worker %s] Real-time dispatch: %d auto-reply, %d follow-up sent.\n",
            date('Y-m-d H:i:s'), $workerId, $arDispatched, $fuDispatched);
    }

    // 2. Try to reserve the next prioritized job
    $job = QueueManager::reserveNextJob($queues, $workerId);

    if (!$job) {
        if ($runOnce) {
            echo sprintf("[%s] [Worker %s] Queue empty. Exiting (--once mode).\n", date('Y-m-d H:i:s'), $workerId);
            break;
        }
        if ($arDispatched === 0 && $fuDispatched === 0) {
            sleep($sleepTime);
        }
        continue;
    }

    $startTime = microtime(true);
    $jobId = (int)$job['id'];
    $queueName = $job['queue'];
    $handlerName = $job['payload_data']['handler'] ?? 'unknown';

    echo sprintf("[%s] [Worker %s] [%s] Reserved job #%d (handler: %s, attempt: %d)\n",
        date('Y-m-d H:i:s'),
        $workerId,
        strtoupper($queueName),
        $jobId,
        $handlerName,
        $job['attempts']
    );

    try {
        $result = QueueManager::executeJob($job);
        QueueManager::markCompleted($jobId);
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        echo sprintf("[%s] [Worker %s] [%s] COMPLETED job #%d in %sms\n",
            date('Y-m-d H:i:s'),
            $workerId,
            strtoupper($queueName),
            $jobId,
            $durationMs
        );
        $jobsProcessed++;

    } catch (\Throwable $e) {
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        QueueManager::markFailed($job, $e);

        echo sprintf("[%s] [Worker %s] [%s] FAILED job #%d (%sms): %s\n",
            date('Y-m-d H:i:s'),
            $workerId,
            strtoupper($queueName),
            $jobId,
            $durationMs,
            $e->getMessage()
        );
        $jobsProcessed++;
    }

    if ($runOnce) {
        break;
    }
}

echo sprintf("[%s] [Worker %s] Finished execution. Processed: %d jobs.\n", date('Y-m-d H:i:s'), $workerId, $jobsProcessed);
exit(0);
