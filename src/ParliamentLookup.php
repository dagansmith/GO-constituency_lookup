<?php
declare(strict_types=1);

namespace Constituency;

final class ParliamentLookup
{
    private array $cache = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $endpoint,
        private readonly ?string $membersEndpoint = null
    ) {
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
            if (!is_array($value) || !array_key_exists('endDate', $value)) {
                throw new \RuntimeException('Parliament constituency is missing its lifecycle fields.');
            }
            // Constituencies use a nullable endDate, not an isCurrent flag.
            if ($value['endDate'] === null && is_string($value['name'] ?? null)
                && trim($value['name']) !== '') {
                $names[] = trim($value['name']);
            }
        }
        if ($names === [] && $this->membersEndpoint !== null) {
            $names = $this->fromCurrentMember($postcode);
        }
        $names = array_values(array_unique($names));
        if (count($names) > 1) {
            throw new \RuntimeException('Parliament returned multiple current constituencies for one postcode.');
        }
        // Cache successful results and no-matches, but never transient failures.
        return $this->cache[$postcode] = $names[0] ?? null;
    }

    private function fromCurrentMember(string $postcode): array
    {
        // The documented postcode parameter is Location on Members/Search.
        $response = $this->http->request('GET', $this->membersEndpoint . '?' . http_build_query([
            'Location' => $postcode,
            'House' => 1,
            'IsCurrentMember' => 'true',
            'skip' => 0,
            'take' => 20,
        ]));
        if (!isset($response['items']) || !is_array($response['items'])
            || ($response['totalResults'] ?? count($response['items'])) > count($response['items'])) {
            throw new \RuntimeException('Parliament returned an invalid or incomplete member result set.');
        }
        $names = [];
        foreach ($response['items'] as $item) {
            $membership = $item['value']['latestHouseMembership'] ?? [];
            if (!is_array($membership) || ($membership['house'] ?? null) !== 1
                || !array_key_exists('membershipEndDate', $membership)
                || $membership['membershipEndDate'] !== null
                || !is_string($membership['membershipFrom'] ?? null)
                || trim($membership['membershipFrom']) === '') {
                throw new \RuntimeException('Parliament member lacks a current Commons constituency.');
            }
            $names[] = trim($membership['membershipFrom']);
        }
        return $names;
    }
}
