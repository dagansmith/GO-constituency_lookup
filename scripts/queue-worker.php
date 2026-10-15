<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Constituency\Lock;
use Constituency\Logger;
use Constituency\Queue;
use Constituency\QueueWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$usage = "Usage: php scripts/queue-worker.php [--max-jobs=N] [--max-seconds=N] | --status | --requeue-failed\n";
$options = ['max-jobs' => 50, 'max-seconds' => 45];
$limits = ['max-jobs' => [1, 1000], 'max-seconds' => [1, 3600]];
$mode = 'run';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo $usage;
        exit(0);
    }
    if (in_array($argument, ['--status', '--requeue-failed'], true) && $mode === 'run') {
        $mode = substr($argument, 2);
        continue;
    }
    if (preg_match('/^--(max-jobs|max-seconds)=([0-9]{1,4})$/D', $argument, $match)
        && (int) $match[2] >= $limits[$match[1]][0] && (int) $match[2] <= $limits[$match[1]][1]) {
        $options[$match[1]] = (int) $match[2];
        continue;
    }
    fwrite(STDERR, $usage);
    exit(2);
}

try {
    [$config, , $enricher] = constituencyService();
    $queue = new Queue($config->queueDirectory);
    if ($mode === 'status') {
        echo json_encode($queue->status(time()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    try {
        $workerLock = new Lock($config->lockDirectory, 'queue-worker');
    } catch (\RuntimeException $exception) {
        if ($exception->getCode() !== Lock::BUSY) {
            throw $exception;
        }
        // Overlapping cron runs are expected when a run is slow; not an error.
        echo json_encode(['skipped' => 'worker_already_running'], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    if ($mode === 'requeue-failed') {
        $result = $queue->requeueFailed(time());
        Logger::log('queue_failed_requeued', $result);
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $summary = (new QueueWorker($queue, $enricher))->run($options['max-jobs'], $options['max-seconds']);
} catch (\Throwable $exception) {
    // Claimed jobs stay in processing/ and are recovered by the next run.
    Logger::log('queue_worker_failed', ['error' => $exception->getMessage()]);
    echo json_encode(['error' => 'queue_worker_failed'], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
echo json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($summary['errors'] > 0 ? 1 : 0);
