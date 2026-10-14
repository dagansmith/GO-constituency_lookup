<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Constituency\Logger;
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
    respond(401, ['error' => 'Invalid webhook token.']);
}

if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    respond(415, ['error' => 'Content-Type must be application/json.']);
}
$raw = file_get_contents('php://input', false, null, 0, 1048577);
if ($raw === false || strlen($raw) > 1048576) {
    respond(413, ['error' => 'Payload exceeds the 1 MiB limit.']);
}
try {
    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new \InvalidArgumentException('Expected a JSON object.');
    }
} catch (\JsonException | \InvalidArgumentException $exception) {
    respond(400, ['error' => 'Invalid JSON payload.']);
}

try {
    [$config, $an, $enricher] = constituencyService();
} catch (\Throwable $exception) {
    Logger::log('webhook_configuration_error', ['error' => $exception->getMessage()]);
    respond(503, ['error' => 'Service configuration error; see server logs.']);
}
try {
    $id = Webhook::personId($payload, $an);
} catch (\Throwable $exception) {
    respond(400, ['error' => 'Payload must identify an Action Network person.']);
}
try {
    $outcome = $enricher->process($id, 'webhook');
    respond(200, ['outcome' => $outcome]);
} catch (\Throwable $exception) {
    respond(503, ['error' => 'Enrichment failed; retry later. The cron sweep will also retry.']);
}
