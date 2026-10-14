<?php
declare(strict_types=1);

namespace Constituency;

final class ParliamentLookup
{
    private array $cache = [];

    public function __construct(private readonly HttpClient $http, private readonly string $endpoint)
    {
    }

    public function constituency(string $postcode): ?string
    {
        $postcode = Postcode::normalize($postcode);
        if ($postcode === null) {
            throw new \InvalidArgumentException('Cannot look up an invalid UK postcode.');
        }
        if (array_key_exists($postcode, $this->cache)) {
            return $this->cache[$postcode];
        }
        $response = $this->http->request('GET', $this->endpoint . '?' . http_build_query([
            'searchText' => $postcode,
            'skip' => 0,
            'take' => 20,
        ]));
        if (!isset($response['items']) || !is_array($response['items'])) {
            throw new \RuntimeException('Parliament response is missing constituency items.');
        }
        if (($response['totalResults'] ?? count($response['items'])) > count($response['items'])) {
            throw new \RuntimeException('Parliament returned an incomplete constituency result set.');
        }
        $names = [];
        foreach ($response['items'] as $item) {
            $value = $item['value'] ?? [];
            // Constituencies use a nullable endDate, not an isCurrent flag.
            if (array_key_exists('endDate', $value) && $value['endDate'] === null
                && is_string($value['name'] ?? null)
                && trim($value['name']) !== '') {
                $names[] = trim($value['name']);
            }
        }
        $names = array_values(array_unique($names));
        if (count($names) > 1) {
            throw new \RuntimeException('Parliament returned multiple current constituencies for one postcode.');
        }
        // Cache successful results and no-matches, but never transient failures.
        return $this->cache[$postcode] = $names[0] ?? null;
    }
}
