<?php
declare(strict_types=1);

namespace Constituency;

final class ActionNetworkClient
{
    public function __construct(private readonly HttpClient $http, private readonly Config $config)
    {
    }

    public function getPerson(string $id): array
    {
        return $this->request('GET', $this->personUrl($id));
    }

    public function updateConstituency(string $id, string $name): void
    {
        $this->request('PUT', $this->personUrl($id), [
            'custom_fields' => [$this->config->constituencyField => $name],
        ]);
    }

    public function people(): \Generator
    {
        return $this->peoplePages($this->config->anBaseUrl . '/people');
    }

    public function peopleModifiedSince(string $since): \Generator
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $since, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $since) {
            throw new \InvalidArgumentException('Modified-date filter requires a UTC timestamp (YYYY-MM-DDTHH:MM:SSZ).');
        }
        // AN v2 documents $filter=modified_date gt <ISO8601>, without quotes.
        // Results are not ordered by modified_date: consume every next-page link.
        return $this->peoplePages($this->config->anBaseUrl . '/people?' . http_build_query(
            ['$filter' => 'modified_date gt ' . $since], '', '&', PHP_QUERY_RFC3986
        ));
    }

    private function peoplePages(string $url): \Generator
    {
        $seen = [];
        while ($url !== null) {
            $this->validateApiUrl($url);
            if (isset($seen[$url])) {
                throw new \RuntimeException('Action Network returned a pagination loop.');
            }
            $seen[$url] = true;
            $page = $this->request('GET', $url);
            $people = $page['_embedded']['osdi:people'] ?? null;
            if (!is_array($people)) {
                throw new \RuntimeException('Action Network people page is missing _embedded.osdi:people.');
            }
            foreach ($people as $person) {
                if (!is_array($person)) {
                    throw new \RuntimeException('Action Network returned an invalid person.');
                }
                yield $person;
            }
            $url = $page['_links']['next']['href'] ?? null;
            if ($url !== null && !is_string($url)) {
                throw new \RuntimeException('Action Network returned an invalid next-page link.');
            }
        }
    }

    public function personId(array $person): string
    {
        foreach (($person['identifiers'] ?? []) as $identifier) {
            if (is_string($identifier) && str_starts_with($identifier, 'action_network:')) {
                return self::validateId(substr($identifier, 15));
            }
        }
        $href = $person['_links']['self']['href'] ?? null;
        if (is_string($href)) {
            return $this->idFromUrl($href);
        }
        throw new \InvalidArgumentException('Person has no Action Network identifier or self link.');
    }

    public function idFromUrl(string $url): string
    {
        $prefix = $this->config->anBaseUrl . '/people/';
        if (!str_starts_with($url, $prefix)) {
            throw new \InvalidArgumentException('Person link is outside the configured Action Network people endpoint.');
        }
        return self::validateId(substr($url, strlen($prefix)));
    }

    public static function validateId(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Action Network person ID.');
        }
        return $id;
    }

    private function personUrl(string $id): string
    {
        return $this->config->anBaseUrl . '/people/' . self::validateId($id);
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $this->validateApiUrl($url);
        return $this->http->request($method, $url, ['OSDI-API-Token: ' . $this->config->anToken], $body);
    }

    private function validateApiUrl(string $url): void
    {
        $base = parse_url($this->config->anBaseUrl);
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== $base['scheme']
            || ($parts['host'] ?? '') !== $base['host']
            || ($parts['port'] ?? 443) !== ($base['port'] ?? 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !str_starts_with($parts['path'] ?? '', ($base['path'] ?? '') . '/')
            || preg_match('~(?:%|\\\\|(?:^|/)\.\.?(?:/|$))~', $parts['path'] ?? '')) {
            throw new \RuntimeException('Refusing an unsafe Action Network API link.');
        }
    }
}
