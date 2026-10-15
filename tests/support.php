<?php
declare(strict_types=1);

// Helpers that run public/ under PHP's built-in server on localhost. No mocks:
// requests go through the real webhook script, filesystem queue, and HTTP stack.

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                removeTree($path . '/' . $name);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function startWebhookServer(array $env, string $logPath): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($probe === false) {
        throw new RuntimeException("Cannot reserve a local port: $error");
    }
    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        null,
        $env + ['PATH' => (string) getenv('PATH')]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the PHP built-in server.');
    }
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($socket !== false) {
            fclose($socket);
            return ['process' => $process, 'pipes' => $pipes, 'url' => "http://127.0.0.1:$port/webhook.php"];
        }
        usleep(50000);
    }
    stopWebhookServer(['process' => $process, 'pipes' => $pipes]);
    throw new RuntimeException('PHP built-in server did not start.');
}

function stopWebhookServer(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        fclose($pipe);
    }
    proc_terminate($server['process']);
    proc_close($server['process']);
}

/** Returns [status, decoded JSON body, seconds elapsed]. */
function postWebhook(string $url, string $body, array $headers = [], string $method = 'POST'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $started = hrtime(true);
    $response = file_get_contents($url, false, $context);
    $elapsed = (hrtime(true) - $started) / 1e9;
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if ($response === false || !preg_match('~^HTTP/\S+ (\d{3})~', $responseHeaders[0] ?? '', $match)) {
        throw new RuntimeException('Local webhook request failed.');
    }
    return [(int) $match[1], json_decode($response, true), $elapsed];
}
