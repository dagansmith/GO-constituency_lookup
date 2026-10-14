<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Constituency\ActionNetworkClient;
use Constituency\Config;
use Constituency\Enricher;
use Constituency\HttpClient;
use Constituency\Lock;
use Constituency\ParliamentLookup;
use Constituency\Postcode;
use Constituency\Webhook;

final class FakeHttpClient extends HttpClient
{
    public array $calls = [];
    public array $responses = [];

    public function __construct()
    {
        parent::__construct(0, 1);
    }

    public function request(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected API call in test.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function fails(callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException | InvalidArgumentException $exception) {
        return;
    }
    throw new RuntimeException('Expected operation to fail.');
}

$lockDirectory = sys_get_temp_dir() . '/constituency-tests-' . bin2hex(random_bytes(8));
foreach ([
    'AN_API_TOKEN' => 'test-placeholder',
    'AN_API_BASE_URL' => 'https://actionnetwork.org/api/v2',
    'PARLIAMENT_LOOKUP_URL' => 'https://members-api.parliament.uk/api/Location/Constituency/Search',
    'AN_CONSTITUENCY_FIELD' => 'parliamentary_constituency',
    'AN_POSTCODE_FIELD' => '',
    'HTTP_RETRIES' => '0',
    'HTTP_TIMEOUT_SECONDS' => '1',
    'LOCK_DIRECTORY' => $lockDirectory,
] as $key => $value) {
    putenv("$key=$value");
}
$config = new Config();
$person = [
    'identifiers' => ['action_network:person-1'],
    'postal_addresses' => [['primary' => true, 'postal_code' => ' sw1a1aa ']],
    'custom_fields' => ['unrelated' => 'preserve'],
];
$match = ['items' => [
    ['value' => ['name' => 'Historical name', 'endDate' => '2024-05-30T00:00:00']],
    ['value' => ['name' => 'Cities of London and Westminster', 'endDate' => null]],
]];
$tests = [
    'postcode normalization and validation' => static function (): void {
        foreach (['sw1a1aa' => 'SW1A 1AA', ' M1  1AE ' => 'M1 1AE', 'GIR0AA' => 'GIR 0AA',
            'EC1A1BB' => 'EC1A 1BB', 'W1A0AX' => 'W1A 0AX', 'B338TH' => 'B33 8TH'] as $raw => $expected) {
            same($expected, Postcode::normalize($raw));
        }
        foreach (['', '90210', 'ZZ1 1ZZ', 'SW1A 1I!', ['SW1A 1AA'], null] as $raw) {
            same(null, Postcode::normalize($raw));
        }
    },
    'primary address and custom postcode field' => static function () use ($person): void {
        same('SW1A 1AA', Postcode::fromPerson($person));
        same('M1 1AE', Postcode::fromPerson(['custom_fields' => ['postcode' => 'm11ae']], 'postcode'));
        same(null, Postcode::fromPerson(['postal_addresses' => [
            ['postal_code' => 'M1 1AE'], ['primary' => true, 'postal_code' => 'invalid'],
        ]]));
    },
    'webhook authentication and payload identifiers' => static function () use ($config, $person): void {
        $an = new ActionNetworkClient(new FakeHttpClient(), $config);
        $secret = str_repeat('x', 32);
        same(true, Webhook::authenticate($secret, $secret));
        foreach (['wrong', null, [$secret]] as $token) {
            same(false, Webhook::authenticate($secret, $token));
        }
        same(false, Webhook::authenticate('', ''));
        same('person-1', Webhook::personId($person, $an));
        same('person-1', Webhook::personId(['person' => $person], $an));
        same('person-1', Webhook::personId(['_links' => [
            'osdi:person' => ['href' => $config->anBaseUrl . '/people/person-1'],
        ]], $an));
        fails(static fn () => Webhook::personId(['_links' => [
            'osdi:person' => ['href' => 'https://evil.example/people/person-1'],
        ]], $an));
        fails(static fn () => ActionNetworkClient::validateId('../people'));
        fails(static fn () => Webhook::personId([], $an));
    },
    'current lookup and normalized cache' => static function () use ($config, $match): void {
        $http = new FakeHttpClient();
        $http->responses = [$match];
        $lookup = new ParliamentLookup($http, $config->parliamentUrl);
        same('Cities of London and Westminster', $lookup->constituency('sw1a1aa'));
        same('Cities of London and Westminster', $lookup->constituency('SW1A 1AA'));
        same(1, count($http->calls));
        same([], $http->calls[0]['headers']);
    },
    'documented postcode member fallback and cache' => static function () use ($config): void {
        $http = new FakeHttpClient();
        $http->responses = [['items' => []], ['items' => [['value' => [
            'latestHouseMembership' => ['house' => 1, 'membershipEndDate' => null, 'membershipFrom' => 'Example seat'],
        ]]]]];
        $lookup = new ParliamentLookup($http, $config->parliamentUrl, $config->parliamentMembersUrl);
        same('Example seat', $lookup->constituency('SW1A 1AA'));
        same('Example seat', $lookup->constituency('sw1a1aa'));
        same(2, count($http->calls));
        parse_str(parse_url($http->calls[1]['url'], PHP_URL_QUERY), $query);
        same('SW1A 1AA', $query['Location']);
        same('1', $query['House']);
        same('true', $query['IsCurrentMember']);
        same([], $http->calls[1]['headers']);
        $http->responses = [['items' => []], ['items' => []]];
        same(null, $lookup->constituency('M1 1AE'));
        same(null, $lookup->constituency('M11AE'));
        same(4, count($http->calls));
        $http->responses = [['items' => []], ['items' => [['value' => [
            'latestHouseMembership' => ['house' => 1, 'membershipEndDate' => '2024-05-30', 'membershipFrom' => 'Old seat'],
        ]]]]];
        fails(static fn () => $lookup->constituency('B33 8TH'));
        $http->responses = [['items' => [['value' => ['name' => 'Missing lifecycle']]]]];
        fails(static fn () => $lookup->constituency('B33 8TH'));
    },
    'native webhook batches, wrappers and canonical link precedence' => static function () use ($config, $person): void {
        $an = new ActionNetworkClient(new FakeHttpClient(), $config);
        $action = ['identifiers' => ['action_network:action-not-person'],
            'person' => ['postal_addresses' => []],
            '_links' => ['osdi:person' => ['href' => $config->anBaseUrl . '/people/person-2']]];
        same('person-2', Webhook::personId($action, $an));
        same(['person-2', 'person-1'], Webhook::personIds([
            ['osdi:submission' => $action, 'idempotency_key' => 'synthetic', 'action_network:sponsor' => []],
            ['osdi:person' => $person],
            ['osdi:signature' => $action],
        ], $an));
        same(['person-2'], Webhook::personIds(['osdi:donation' => $action], $an));
        same(['person-1'], Webhook::personIds(['person' => $person], $an));
        fails(static fn () => Webhook::personIds([], $an));
        fails(static fn () => Webhook::personIds([null], $an));
        fails(static fn () => Webhook::personIds(['osdi:submission' => [
            'identifiers' => ['action_network:action-not-person'],
        ]], $an));
    },
    'no-match caching, ambiguity and transient recovery' => static function () use ($config, $match): void {
        $http = new FakeHttpClient();
        $http->responses = [['items' => []], new RuntimeException('API request failed (HTTP 503).'), $match];
        $lookup = new ParliamentLookup($http, $config->parliamentUrl);
        same(null, $lookup->constituency('M1 1AE'));
        same(null, $lookup->constituency('M11AE'));
        fails(static fn () => $lookup->constituency('SW1A 1AA'));
        same('Cities of London and Westminster', $lookup->constituency('SW1A 1AA'));
        $http->responses = [['items' => [
            ['value' => ['name' => 'One', 'endDate' => null]],
            ['value' => ['name' => 'Two', 'endDate' => null]],
        ]], ['unexpected' => 'response']];
        fails(static fn () => $lookup->constituency('B33 8TH'));
        fails(static fn () => $lookup->constituency('B33 8TH'));
        $http->responses = [['items' => $match['items'], 'totalResults' => 21]];
        fails(static fn () => $lookup->constituency('B33 8TH'));
    },
    'update contract, fresh reads and idempotence' => static function () use ($config, $person, $match): void {
        $http = new FakeHttpClient();
        $correct = $person;
        $correct['custom_fields']['parliamentary_constituency'] = 'Cities of London and Westminster';
        $http->responses = [$person, $match, [], $correct];
        $an = new ActionNetworkClient($http, $config);
        $service = new Enricher($an, new ParliamentLookup($http, $config->parliamentUrl), $config);
        same('updated', $service->process('person-1', 'test'));
        same('unchanged', $service->process('person-1', 'test'));
        same(4, count($http->calls));
        same('PUT', $http->calls[2]['method']);
        same(['custom_fields' => ['parliamentary_constituency' => 'Cities of London and Westminster']],
            $http->calls[2]['body']);
        same(['OSDI-API-Token: test-placeholder'], $http->calls[2]['headers']);
        same('GET', $http->calls[3]['method']);
    },
    'dry-run, invalid input and no-match never write' => static function () use ($config, $person, $match): void {
        foreach (['would_update', 'invalid_postcode', 'no_match'] as $outcome) {
            $http = new FakeHttpClient();
            $http->responses = match ($outcome) {
                'would_update' => [$person, $match],
                'invalid_postcode' => [['postal_addresses' => []]],
                'no_match' => [$person, ['items' => []]],
            };
            $service = new Enricher(new ActionNetworkClient($http, $config),
                new ParliamentLookup($http, $config->parliamentUrl), $config);
            same($outcome, $service->process('person-1', 'test', true));
            same([], array_filter($http->calls, static fn ($call) => $call['method'] !== 'GET'));
        }
    },
    'failed writes are retried on the next processing attempt' => static function () use ($config, $person, $match): void {
        $http = new FakeHttpClient();
        $http->responses = [$person, $match, new RuntimeException('API request failed (HTTP 429).'), $person, []];
        $service = new Enricher(new ActionNetworkClient($http, $config),
            new ParliamentLookup($http, $config->parliamentUrl), $config);
        fails(static fn () => $service->process('person-1', 'test'));
        same('updated', $service->process('person-1', 'test'));
    },
    'pagination, untrusted next links and loops' => static function () use ($config, $person): void {
        $http = new FakeHttpClient();
        $first = ['_embedded' => ['osdi:people' => [$person]],
            '_links' => ['next' => ['href' => $config->anBaseUrl . '/people?page=2']]];
        $http->responses = [$first, ['_embedded' => ['osdi:people' => [$person]]]];
        $an = new ActionNetworkClient($http, $config);
        same(2, count(iterator_to_array($an->people())));
        $first['_links']['next']['href'] = 'https://evil.example/api/v2/people';
        $http->responses = [$first];
        fails(static fn () => iterator_to_array($an->people()));
        same([], $http->responses);
        $first['_links']['next']['href'] = $config->anBaseUrl . '/people';
        $http->responses = [$first];
        fails(static fn () => iterator_to_array($an->people()));
        $first['_links']['next']['href'] = $config->anBaseUrl . '/../people';
        $http->responses = [$first];
        fails(static fn () => iterator_to_array($an->people()));
    },
    'locks serialize processing and release after failure' => static function () use ($lockDirectory): void {
        $lock = new Lock($lockDirectory, 'test');
        fails(static fn () => new Lock($lockDirectory, 'test'));
        unset($lock);
        $lock = new Lock($lockDirectory, 'test');
        unset($lock);
    },
    'configuration fails closed' => static function (): void {
        putenv('AN_API_BASE_URL=http://actionnetwork.org/api/v2');
        fails(static fn () => new Config());
        putenv('AN_API_BASE_URL=https://actionnetwork.org/api/v2');
        putenv('HTTP_RETRIES=99');
        fails(static fn () => new Config());
        putenv('HTTP_RETRIES=0');
        putenv('AN_POSTCODE_FIELD=parliamentary_constituency');
        fails(static fn () => new Config());
        putenv('AN_POSTCODE_FIELD=');
    },
];

$failures = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "PASS: $name\n";
        } catch (Throwable $exception) {
            $failures++;
            fwrite(STDERR, "FAIL: $name: {$exception->getMessage()}\n");
        }
    }
} finally {
    foreach (glob($lockDirectory . '/*.lock') ?: [] as $path) {
        unlink($path);
    }
    if (is_dir($lockDirectory)) {
        rmdir($lockDirectory);
    }
}
echo count($tests) . " tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
