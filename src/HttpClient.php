<?php
declare(strict_types=1);

namespace Constituency;

class HttpClient
{
    public function __construct(private readonly int $retries, private readonly int $timeout)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        for ($attempt = 0; ; $attempt++) {
            $retryAfter = null;
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                    if (stripos($line, 'Retry-After:') === 0) {
                        $value = trim(substr($line, 12));
                        $retryAfter = ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: time()) - time());
                    }
                    return strlen($line);
                },
            ]);
            if ($encoded !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
            }
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($handle);
            curl_close($handle);

            if (($response === false || $status === 429 || $status >= 500) && $attempt < $this->retries) {
                // Keep retries bounded so a webhook cannot sleep indefinitely.
                sleep(min(5, max(1, $retryAfter ?? (2 ** $attempt))));
                continue;
            }
            if ($response === false) {
                throw new \RuntimeException("API transport failure (cURL code $error).");
            }
            if ($status < 200 || $status >= 300) {
                // Response bodies and URLs may contain personal data or credentials.
                throw new \RuntimeException("API request failed (HTTP $status).");
            }
            if ($response === '' && $status === 204) {
                return [];
            }
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('API returned invalid JSON.', 0, $exception);
            }
            if (!is_array($decoded)) {
                throw new \RuntimeException('API returned an unexpected JSON structure.');
            }
            return $decoded;
        }
    }
}
