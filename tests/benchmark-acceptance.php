<?php
declare(strict_types=1);

// Local acceptance benchmark: real webhook script, real filesystem queue, no mocks
// and no remote API calls. It measures localhost HTTP only. It does NOT measure the
// production host, TLS, CDN/proxy, or the internet path Action Network uses.
//
// Usage: php tests/benchmark-acceptance.php [REQUESTS]

require __DIR__ . '/support.php';

$requests = (int) ($argv[1] ?? 50);
if ($requests < 1 || $requests > 10000) {
    fwrite(STDERR, "Usage: php tests/benchmark-acceptance.php [REQUESTS 1-10000]\n");
    exit(2);
}
$root = sys_get_temp_dir() . '/constituency-benchmark-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$secret = 'benchmark-only-' . bin2hex(random_bytes(16));
$server = startWebhookServer([
    'AN_API_TOKEN' => 'benchmark-placeholder',
    'WEBHOOK_SECRET' => $secret,
    'QUEUE_DIRECTORY' => "$root/queue",
    'LOCK_DIRECTORY' => "$root/locks",
], "$root/server.log");
$timings = [];
try {
    for ($i = 0; $i < $requests; $i++) {
        [$status, , $elapsed] = postWebhook($server['url'],
            json_encode(['person' => ['identifiers' => ['action_network:benchmark-' . $i]]]),
            ['Content-Type: application/json', "X-Webhook-Token: $secret"]);
        if ($status !== 200) {
            throw new RuntimeException("Unexpected HTTP $status.");
        }
        $timings[] = $elapsed * 1000;
    }
    $persisted = count(glob("$root/queue/pending/*.json") ?: []);
} finally {
    stopWebhookServer($server);
    removeTree($root);
}
sort($timings);
$percentile = static fn (float $p): float => round($timings[(int) ceil($p * count($timings)) - 1], 2);
echo json_encode([
    'scope' => 'local acceptance only (localhost, PHP built-in server); excludes host, TLS, CDN and network latency',
    'php' => PHP_VERSION,
    'requests' => $requests,
    'jobs_persisted' => $persisted,
    'ms_min' => round($timings[0], 2),
    'ms_p50' => $percentile(0.5),
    'ms_p95' => $percentile(0.95),
    'ms_max' => round(end($timings), 2),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
