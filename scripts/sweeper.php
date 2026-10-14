<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Constituency\Lock;
use Constituency\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "Usage: php scripts/sweeper.php [--dry-run]\n";
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--dry-run']) {
    fwrite(STDERR, "Usage: php scripts/sweeper.php [--dry-run]\n");
    exit(2);
}
$dryRun = $arguments === ['--dry-run'];
$counts = ['processed' => 0, 'updated' => 0, 'would_update' => 0, 'unchanged' => 0,
    'invalid_postcode' => 0, 'no_match' => 0, 'errors' => 0];
try {
    [$config, $an, $enricher] = constituencyService();
    $sweepLock = new Lock($config->lockDirectory, 'sweeper');
    foreach ($an->people() as $person) {
        $counts['processed']++;
        try {
            $id = $an->personId($person);
            $counts[$enricher->process($id, 'cron', $dryRun)]++;
        } catch (\Throwable $exception) {
            $counts['errors']++;
            Logger::log('sweep_person_failed', ['error' => $exception->getMessage()]);
        }
    }
} catch (\Throwable $exception) {
    $counts['errors']++;
    Logger::log('sweep_failed', ['error' => $exception->getMessage()]);
}
echo json_encode(['dry_run' => $dryRun] + $counts, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($counts['errors'] > 0 ? 1 : 0);
