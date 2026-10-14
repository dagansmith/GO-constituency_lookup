<?php
declare(strict_types=1);

namespace Constituency;

final class Config
{
    public readonly string $anToken;
    public readonly string $anBaseUrl;
    public readonly string $parliamentUrl;
    public readonly string $parliamentMembersUrl;
    public readonly string $constituencyField;
    public readonly string $postcodeField;
    public readonly string $webhookSecret;
    public readonly string $lockDirectory;
    public readonly int $retries;
    public readonly int $timeout;

    public function __construct()
    {
        $this->anToken = self::env('AN_API_TOKEN');
        $this->anBaseUrl = rtrim(self::env('AN_API_BASE_URL', 'https://actionnetwork.org/api/v2'), '/');
        $this->parliamentUrl = self::env(
            'PARLIAMENT_LOOKUP_URL',
            'https://members-api.parliament.uk/api/Location/Constituency/Search'
        );
        $this->parliamentMembersUrl = self::env(
            'PARLIAMENT_MEMBERS_LOOKUP_URL',
            'https://members-api.parliament.uk/api/Members/Search'
        );
        $this->constituencyField = self::env('AN_CONSTITUENCY_FIELD', 'parliamentary_constituency');
        $this->postcodeField = self::env('AN_POSTCODE_FIELD');
        $this->webhookSecret = self::env('WEBHOOK_SECRET');
        $this->lockDirectory = self::env('LOCK_DIRECTORY', dirname(__DIR__) . '/var/locks');
        $this->retries = self::integer('HTTP_RETRIES', 2, 0, 5);
        $this->timeout = self::integer('HTTP_TIMEOUT_SECONDS', 15, 1, 60);

        if ($this->anToken === '' || preg_match('/[\r\n]/', $this->anToken)) {
            throw new \RuntimeException('AN_API_TOKEN must be set and contain no newlines.');
        }
        if ($this->constituencyField === '') {
            throw new \RuntimeException('AN_CONSTITUENCY_FIELD must not be empty.');
        }
        if ($this->postcodeField !== '' && $this->postcodeField === $this->constituencyField) {
            throw new \RuntimeException('Postcode input and constituency output fields must be different.');
        }
        foreach ([$this->anBaseUrl, $this->parliamentUrl, $this->parliamentMembersUrl] as $url) {
            $parts = parse_url($url);
            if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \RuntimeException('API endpoints must be HTTPS URLs without credentials, query, or fragment.');
            }
        }
        if (!str_starts_with($this->lockDirectory, '/')) {
            throw new \RuntimeException('LOCK_DIRECTORY must be an absolute path outside the public web root.');
        }
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim($value);
    }

    private static function integer(string $name, int $default, int $min, int $max): int
    {
        $value = filter_var(self::env($name, (string) $default), FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new \RuntimeException("$name must be between $min and $max.");
        }
        return $value;
    }
}
