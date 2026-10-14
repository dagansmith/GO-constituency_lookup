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
        // Action webhooks can embed a person without identifiers but include this link.
        $link = $payload['_links']['osdi:person']['href'] ?? null;
        if (is_string($link)) {
            return $an->idFromUrl($link);
        }
        if (isset($payload['person']) && is_array($payload['person'])) {
            return $an->personId($payload['person']);
        }
        return $an->personId($payload);
    }

    public static function personIds(array $payload, ActionNetworkClient $an): array
    {
        $events = array_is_list($payload) ? $payload : [$payload];
        $ids = [];
        foreach ($events as $event) {
            if (!is_array($event) || array_is_list($event)) {
                throw new \InvalidArgumentException('Expected webhook event objects.');
            }
            $wrapped = false;
            foreach ($event as $key => $resource) {
                if (!str_starts_with((string) $key, 'osdi:')) {
                    continue;
                }
                $wrapped = true;
                if (!is_array($resource) || ($key !== 'osdi:person'
                    && !isset($resource['person']) && !isset($resource['_links']['osdi:person']))) {
                    throw new \InvalidArgumentException('Webhook action must identify its person, not just the action.');
                }
                $ids[] = self::personId($resource, $an);
            }
            if (!$wrapped) {
                $ids[] = self::personId($event, $an);
            }
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('Webhook contains no people.');
        }
        return array_values(array_unique($ids));
    }
}
