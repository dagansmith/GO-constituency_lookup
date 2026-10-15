<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Constituency\Logger;
use Constituency\Queue;
use Constituency\Webhook;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'POST required.']);
}

$secret = getenv('WEBHOOK_SECRET');
if ($secret === false || strlen(trim($secret)) < 32) {
    Logger::log('webhook_configuration_error', ['error' => 'Set WEBHOOK_SECRET to at least 32 characters.']);
    respond(503, ['error' => 'Webhook is not configured.']);
}
$token = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? $_GET['token'] ?? null;
if (!Webhook::authenticate(trim($secret), $token)) {
    Logger::log('webhook_rejected', ['status' => 401, 'reason' => 'invalid_token']);
    respond(401, ['error' => 'Invalid webhook token.']);
}

if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    Logger::log('webhook_rejected', ['status' => 415, 'reason' => 'content_type']);
    respond(415, ['error' => 'Content-Type must be application/json.']);
}
$raw = file_get_contents('php://input', false, null, 0, 1048577);
if ($raw === false || strlen($raw) > 1048576) {
    Logger::log('webhook_rejected', ['status' => 413, 'reason' => 'payload_too_large']);
    respond(413, ['error' => 'Payload exceeds the 1 MiB limit.']);
}
try {
    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new \InvalidArgumentException('Expected a JSON object.');
    }
} catch (\JsonException | \InvalidArgumentException $exception) {
    Logger::log('webhook_rejected', ['status' => 400, 'reason' => 'invalid_json']);
    respond(400, ['error' => 'Invalid JSON payload.']);
}

// Acceptance makes no remote API calls: it validates, persists, and acknowledges.
// scripts/queue-worker.php performs enrichment; the twice-daily sweep reconciles.
try {
    [$config, $an] = constituencyService();
} catch (\Throwable $exception) {
    Logger::log('webhook_configuration_error', ['error' => $exception->getMessage()]);
    respond(503, ['error' => 'Service configuration error; see server logs.']);
}
try {
    $ids = Webhook::personIds($payload, $an);
} catch (\Throwable $exception) {
    Logger::log('webhook_rejected', ['status' => 400, 'reason' => 'no_person_identity']);
    respond(400, ['error' => 'Payload must identify an Action Network person.']);
}
try {
    // Only person IDs are stored: never the token, request body, or headers.
    $jobId = (new Queue($config->queueDirectory))->enqueue($ids);
} catch (\Throwable $exception) {
    Logger::log('webhook_queue_error', ['error' => $exception->getMessage()]);
    respond(503, ['error' => 'Webhook could not be queued; retry later.']);
}
Logger::log('webhook_queued', ['job_id' => $jobId, 'people' => count($ids)]);
respond(200, ['accepted' => true, 'job_id' => $jobId, 'people' => count($ids)]);
