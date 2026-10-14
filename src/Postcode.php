<?php
declare(strict_types=1);

namespace Constituency;

final class Postcode
{
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $compact = preg_replace('/\s+/', '', strtoupper(trim($value)));
        $pattern = '/^(?:GIR0AA|(?:[A-PR-UWYZ][0-9][0-9A-HJKPSTUW]?|[A-PR-UWYZ][A-HK-Y][0-9][0-9ABEHMNPRVWXY]?)[0-9][ABD-HJLNP-UW-Z]{2})$/D';
        if (!preg_match($pattern, $compact)) {
            return null;
        }
        return substr($compact, 0, -3) . ' ' . substr($compact, -3);
    }

    public static function fromPerson(array $person, string $customField = ''): ?string
    {
        if ($customField !== '') {
            return self::normalize($person['custom_fields'][$customField] ?? null);
        }
        $addresses = $person['postal_addresses'] ?? [];
        foreach ($addresses as $address) {
            if (($address['primary'] ?? false) === true) {
                return self::normalize($address['postal_code'] ?? null);
            }
        }
        foreach ($addresses as $address) {
            if (!empty($address['postal_code'])) {
                return self::normalize($address['postal_code']);
            }
        }
        return null;
    }
}
