<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Constituency\Lock;
use Constituency\Logger;
use Constituency\SweepCheckpoint;
use Constituency\Sweeper;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$arguments = array_slice($argv, 1);
$usage = "Usage: php scripts/sweeper.php [--full] [--dry-run]\n";
if ($arguments === ['--help']) {
    echo $usage;
    exit(0);
}
if (array_diff($arguments, ['--full', '--dry-run']) !== []
    || count(array_unique($arguments)) !== count($arguments)) {
    fwrite(STDERR, $usage);
    exit(2);
}
$dryRun = in_array('--dry-run', $arguments, true);
$full = in_array('--full', $arguments, true);
$counts = Sweeper::summary($full, $dryRun);
try {
    [$config, $an, $enricher] = constituencyService();
    $sweepLock = new Lock($config->lockDirectory, 'sweeper');
    $counts = (new Sweeper($an, $enricher, new SweepCheckpoint($config->sweepCheckpointPath),
        $config->sweepOverlapSeconds, $config->sweepInitialLookbackSeconds))->run($full, $dryRun);
} catch (\Throwable $exception) {
    $counts['errors']++;
    Logger::log('sweep_failed', ['error' => $exception->getMessage()]);
}
echo json_encode(['time' => gmdate('c'), 'event' => 'sweep_completed'] + $counts, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($counts['errors'] > 0 ? 1 : 0);
