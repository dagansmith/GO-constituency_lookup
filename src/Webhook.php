<?php
declare(strict_types=1);

namespace Constituency;

final class Webhook
{
    public static function authenticate(string $secret, mixed $token): bool
    {
        return strlen($secret) >= 32 && is_string($token) && hash_equals($secret, $token);
    }

    public static function personId(array $payload, ActionNetworkClient $an): string
    {
        // Accept a person resource, a wrapped person, or an OSDI resource's person link.
        if (isset($payload['person']) && is_array($payload['person'])) {
            return $an->personId($payload['person']);
        }
        $link = $payload['_links']['osdi:person']['href'] ?? null;
        if (is_string($link)) {
            return $an->idFromUrl($link);
        }
        return $an->personId($payload);
    }
}
