<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';

use Constituency\ActionNetworkClient;
use Constituency\Config;
use Constituency\Enricher;
use Constituency\Env;
use Constituency\HttpClient;
use Constituency\Lock;
use Constituency\ParliamentLookup;
use Constituency\Postcode;
use Constituency\Queue;
use Constituency\QueueWorker;
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

function queueFiles(string $directory, string $state): array
{
    return glob("$directory/$state/*") ?: [];
}

/** Edits a stored job, e.g. to make a scheduled retry due now in tests. */
function editJob(string $directory, string $state, callable $edit): void
{
    [$path] = queueFiles($directory, $state);
    $job = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    file_put_contents($path, json_encode($edit($job), JSON_PRESERVE_ZERO_FRACTION));
}

function runCli(array $arguments, array $env): array
{
    $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/scripts/queue-worker.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        $env + ['PATH' => (string) getenv('PATH')]);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), json_decode(trim($stdout), true)];
}

$workRoot = sys_get_temp_dir() . '/constituency-work-' . bin2hex(random_bytes(8));
mkdir($workRoot, 0700);
$lockDirectory = sys_get_temp_dir() . '/constituency-tests-' . bin2hex(random_bytes(8));
$testEnv = [
    'AN_API_TOKEN' => 'test-placeholder',
    'AN_API_BASE_URL' => 'https://actionnetwork.org/api/v2',
    'PARLIAMENT_LOOKUP_URL' => 'https://members-api.parliament.uk/api/Location/Constituency/Search',
    'AN_CONSTITUENCY_FIELD' => 'parliamentary_constituency',
    'AN_POSTCODE_FIELD' => '',
    'HTTP_RETRIES' => '0',
    'HTTP_TIMEOUT_SECONDS' => '1',
    'LOCK_DIRECTORY' => $lockDirectory,
    'QUEUE_DIRECTORY' => $workRoot . '/default-queue',
];
foreach ($testEnv as $key => $value) {
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
$correct = $person;
$correct['custom_fields']['parliamentary_constituency'] = 'Cities of London and Westminster';
$newWorker = static function (Queue $queue, FakeHttpClient $http) use ($config): QueueWorker {
    return new QueueWorker($queue, new Enricher(new ActionNetworkClient($http, $config),
        new ParliamentLookup($http, $config->parliamentUrl), $config));
};
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
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
    'env file is parsed as data and the real environment wins' => static function () use ($workRoot): void {
        $path = $workRoot . '/test.env';
        $marker = $workRoot . '/executed';
        file_put_contents($path, implode("\n", [
            '# comment',
            'export ENV_TEST_A=plain # trailing comment',
            "ENV_TEST_B='single \$HOME `x`'",
            'ENV_TEST_C="double \"q\" \$literal"',
            "ENV_TEST_D=\$(touch $marker)",
            'ENV_TEST_E="has $HOME"',
            'ENV_TEST_F=two words',
            'ENV_TEST_PRESET=from-file',
            'ENV_TEST_EMPTY=',
            'not a variable line',
            "ENV_TEST_CRLF=value\r",
            'ENV_TEST_DUP=first',
            'ENV_TEST_DUP=last',
        ]));
        putenv('ENV_TEST_PRESET=from-environment');
        same([5, 6, 7, 10], Env::load($path));
        same('plain', getenv('ENV_TEST_A'));
        same('single $HOME `x`', getenv('ENV_TEST_B'));
        same('double "q" $literal', getenv('ENV_TEST_C'));
        same('', getenv('ENV_TEST_EMPTY'));
        same('value', getenv('ENV_TEST_CRLF'));
        same('last', getenv('ENV_TEST_DUP'));
        same('from-environment', getenv('ENV_TEST_PRESET'));
        foreach (['D', 'E', 'F'] as $suffix) {
            same(false, getenv("ENV_TEST_$suffix"));
        }
        same(false, file_exists($marker));
        same([], Env::load($workRoot . '/missing.env'));
        foreach (['A', 'B', 'C', 'EMPTY', 'CRLF', 'DUP', 'PRESET'] as $suffix) {
            putenv("ENV_TEST_$suffix");
        }
    },
    'queue persists only validated person IDs, atomically and privately' => static function () use ($workRoot): void {
        $directory = $workRoot . '/queue-persist';
        $queue = new Queue($directory);
        $id = $queue->enqueue(['person-1', 'person-2', 'person-1']);
        same(1, preg_match('/^[0-9]{20}-[0-9a-f]{16}$/D', $id));
        same(["$directory/pending/$id.json"], queueFiles($directory, 'pending'));
        same([], queueFiles($directory, 'tmp'));
        same(0600, fileperms("$directory/pending/$id.json") & 0777);
        same(0700, fileperms("$directory/pending") & 0777);
        $job = json_decode(file_get_contents("$directory/pending/$id.json"), true);
        same(['version', 'id', 'received_at', 'attempts', 'next_attempt_at', 'person_ids', 'last_error'], array_keys($job));
        same(['person-1', 'person-2'], $job['person_ids']);
        fails(static fn () => $queue->enqueue(['../etc/passwd']));
        fails(static fn () => $queue->enqueue([]));
        fails(static fn () => $queue->read('pending', '../' . $id));
        same(1, count(queueFiles($directory, 'pending')));
    },
    'unwritable queue fails closed without partial jobs' => static function () use ($workRoot, $isRoot): void {
        if ($isRoot) {
            echo "SKIP: permission checks do not apply to root\n";
            return;
        }
        $directory = $workRoot . '/queue-readonly';
        $queue = new Queue($directory);
        chmod("$directory/pending", 0500);
        fails(static fn () => $queue->enqueue(['person-1']));
        chmod("$directory/pending", 0700);
        same([], queueFiles($directory, 'pending'));
        same([], queueFiles($directory, 'tmp'));
        mkdir($workRoot . '/locked-parent', 0500);
        fails(static fn () => new Queue($workRoot . '/locked-parent/queue'));
    },
    'worker keeps failed people for backoff retries and removes jobs only after success' =>
        static function () use ($workRoot, $newWorker, $person, $correct, $match): void {
        $directory = $workRoot . '/queue-retry';
        $queue = new Queue($directory);
        $queue->enqueue(['person-1', 'person-2']);
        $http = new FakeHttpClient();
        $http->responses = [$person, $match, [], new RuntimeException('API request failed (HTTP 503).')];
        $before = time();
        $summary = $newWorker($queue, $http)->run(10, 30);
        same([1, 0, 1, 1, ['updated' => 1]], [$summary['claimed'], $summary['completed'],
            $summary['retry_scheduled'], $summary['errors'], $summary['outcomes']]);
        same([], $http->responses);
        [$path] = queueFiles($directory, 'pending');
        $job = json_decode(file_get_contents($path), true);
        same(['person-2'], $job['person_ids']);
        same(1, $job['attempts']);
        same('API request failed (HTTP 503).', $job['last_error']);
        same(true, $job['next_attempt_at'] >= $before + Queue::backoff(1));
        same([], queueFiles($directory, 'processing'));
        // Not yet due: no API calls.
        same(0, $newWorker($queue, $http)->run(10, 30)['claimed']);
        editJob($directory, 'pending', static fn (array $job): array => ['next_attempt_at' => 0] + $job);
        $http->responses = [$correct, $match];
        $summary = $newWorker($queue, $http)->run(10, 30);
        same([1, ['unchanged' => 1]], [$summary['completed'], $summary['outcomes']]);
        same([], queueFiles($directory, 'pending'));
        same(60, Queue::backoff(1));
        same(3600, Queue::backoff(Queue::MAX_ATTEMPTS));
    },
    'terminal failures are retained for investigation and can be requeued' =>
        static function () use ($workRoot, $newWorker): void {
        $directory = $workRoot . '/queue-failed';
        $queue = new Queue($directory);
        $queue->enqueue(['person-1']);
        editJob($directory, 'pending', static fn (array $job): array => ['attempts' => Queue::MAX_ATTEMPTS - 1] + $job);
        $http = new FakeHttpClient();
        $http->responses = [new RuntimeException('API request failed (HTTP 404).')];
        same(1, $newWorker($queue, $http)->run(10, 30)['failed']);
        same([], queueFiles($directory, 'pending'));
        [$path] = queueFiles($directory, 'failed');
        same('API request failed (HTTP 404).', json_decode(file_get_contents($path), true)['last_error']);
        same(['requeued' => 1, 'unreadable' => 0], $queue->requeueFailed(time()));
        same(0, json_decode(file_get_contents(queueFiles($directory, 'pending')[0]), true)['attempts']);
    },
    'duplicate deliveries share one fresh read; later events are still processed' =>
        static function () use ($workRoot, $newWorker, $correct, $match): void {
        $directory = $workRoot . '/queue-duplicates';
        $queue = new Queue($directory);
        $first = $queue->enqueue(['person-1']);
        $queue->enqueue(['person-1']);
        $late = $queue->enqueue(['person-1']);
        // An event received after this run's read began must not be skipped.
        $path = "$directory/pending/$late.json";
        $job = json_decode(file_get_contents($path), true);
        $job['received_at'] = microtime(true) + 3600;
        file_put_contents($path, json_encode($job, JSON_PRESERVE_ZERO_FRACTION));
        $http = new FakeHttpClient();
        $http->responses = [$correct, $match, $correct];
        $summary = $newWorker($queue, $http)->run(10, 30);
        same([3, 1, 2], [$summary['completed'], $summary['duplicates_skipped'], $summary['people_processed']]);
        same([], $http->responses);
        same([], queueFiles($directory, 'pending'));
        same(true, $first < $late);
    },
    'crashed claims and corrupt files are recovered, never silently dropped' =>
        static function () use ($workRoot, $newWorker, $correct, $match): void {
        $directory = $workRoot . '/queue-crash';
        $queue = new Queue($directory);
        $id = $queue->enqueue(['person-1']);
        same('person-1', $queue->claim($id)['person_ids'][0]);
        same(null, $queue->claim($id));
        file_put_contents("$directory/pending/00000000000000000000-0000000000000000.json", '{not json');
        touch("$directory/tmp/abandoned.tmp", time() - 7200);
        $http = new FakeHttpClient();
        $summary = $newWorker($queue, $http)->run(10, 30);
        same([1, 0], [$summary['recovered'], $summary['completed']]);
        same([], $http->calls);
        same(["$directory/failed/00000000000000000000-0000000000000000.json"], queueFiles($directory, 'failed'));
        same([], queueFiles($directory, 'tmp'));
        same([], queueFiles($directory, 'processing'));
        [$path] = queueFiles($directory, 'pending');
        same('Worker stopped before finishing the job.', json_decode(file_get_contents($path), true)['last_error']);
        editJob($directory, 'pending', static fn (array $job): array => ['next_attempt_at' => 0] + $job);
        $http->responses = [$correct, $match];
        same(1, $newWorker($queue, $http)->run(10, 30)['completed']);
    },
    'worker CLI: lock prevents concurrent runs, status, arguments' =>
        static function () use ($workRoot, $lockDirectory, $testEnv): void {
        $env = ['QUEUE_DIRECTORY' => $workRoot . '/queue-cli'] + $testEnv;
        (new Queue($env['QUEUE_DIRECTORY']))->enqueue(['person-1']);
        $lock = new Lock($lockDirectory, 'queue-worker');
        same([0, ['skipped' => 'worker_already_running']], runCli([], $env));
        unset($lock);
        [$code, $status] = runCli(['--status'], $env);
        same([0, 1, 1, 0, 0], [$code, $status['pending'], $status['due'], $status['processing'], $status['failed']]);
        same(2, runCli(['--max-jobs=0'], $env)[0]);
        same(2, runCli(['--unknown'], $env)[0]);
        [$code, $summary] = runCli(['--max-jobs=1'], ['QUEUE_DIRECTORY' => $workRoot . '/queue-cli-empty'] + $env);
        same([0, 0, 0], [$code, $summary['claimed'], $summary['errors']]);
    },
    'webhook acknowledges only after persisting; worker then enriches' =>
        static function () use ($workRoot, $testEnv, $newWorker, $person, $match, $isRoot): void {
        $directory = $workRoot . '/queue-http';
        $secret = 'test-only-' . bin2hex(random_bytes(16));
        $log = $workRoot . '/server.log';
        // An unreachable AN base proves acceptance makes no remote API calls.
        $base = 'https://127.0.0.1:9/api/v2';
        $server = startWebhookServer(['QUEUE_DIRECTORY' => $directory, 'WEBHOOK_SECRET' => $secret,
            'AN_API_BASE_URL' => $base] + $testEnv, $log);
        try {
            $json = ['Content-Type: application/json'];
            $auth = array_merge($json, ["X-Webhook-Token: $secret"]);
            $native = json_encode([
                ['osdi:submission' => ['identifiers' => ['action_network:action-1'],
                    '_links' => ['osdi:person' => ['href' => "$base/people/person-2"]]], 'idempotency_key' => 'x'],
                ['osdi:person' => ['identifiers' => ['action_network:person-1']]],
            ]);
            same(405, postWebhook($server['url'], '', [], 'GET')[0]);
            same(401, postWebhook($server['url'], $native, array_merge($json, ['X-Webhook-Token: wrong']))[0]);
            same(401, postWebhook($server['url'], $native, $json)[0]);
            same(415, postWebhook($server['url'], $native, ["X-Webhook-Token: $secret", 'Content-Type: text/plain'])[0]);
            same(413, postWebhook($server['url'], str_repeat(' ', 1048577), $auth)[0]);
            same(400, postWebhook($server['url'], '{not json', $auth)[0]);
            same(400, postWebhook($server['url'], '{"email":"someone@example.com"}', $auth)[0]);
            same(400, postWebhook($server['url'], '{"osdi:submission":{"identifiers":["action_network:a"]}}', $auth)[0]);
            same(400, postWebhook($server['url'], '{"_links":{"osdi:person":{"href":"https://evil.example/people/x"}}}', $auth)[0]);
            same([], queueFiles($directory, 'pending'));
            [$status, $body] = postWebhook($server['url'], $native, $auth);
            same([200, true, 2], [$status, $body['accepted'], $body['people']]);
            $stored = file_get_contents("$directory/pending/{$body['job_id']}.json");
            same(['person-2', 'person-1'], json_decode($stored, true)['person_ids']);
            same(false, str_contains($stored, $secret));
            same(200, postWebhook($server['url'] . '?token=' . $secret,
                '{"person":{"identifiers":["action_network:person-1"]}}', $json)[0]);
            same(2, count(queueFiles($directory, 'pending')));
            if (!$isRoot) {
                chmod("$directory/pending", 0500);
                same(503, postWebhook($server['url'], $native, $auth)[0]);
                chmod("$directory/pending", 0700);
                same(2, count(queueFiles($directory, 'pending')));
                same([], queueFiles($directory, 'tmp'));
            }
        } finally {
            stopWebhookServer($server);
        }
        foreach (file($log) ?: [] as $line) {
            if (str_contains($line, '"event"')) {
                same(false, str_contains($line, $secret));
            }
        }
        $short = startWebhookServer(['QUEUE_DIRECTORY' => $directory, 'WEBHOOK_SECRET' => 'too-short'] + $testEnv, $log);
        try {
            same(503, postWebhook($short['url'], '{}', ["X-Webhook-Token: too-short", 'Content-Type: application/json'])[0]);
        } finally {
            stopWebhookServer($short);
        }
        $http = new FakeHttpClient();
        $person2 = ['identifiers' => ['action_network:person-2']] + $person;
        // Job 1: person-2 then person-1 (postcode cached); job 2: person-1 is a covered duplicate.
        $http->responses = [$person2, $match, [], $person, []];
        $summary = $newWorker(new Queue($directory), $http)->run(10, 30);
        same([2, 2, 1, ['updated' => 2]], [$summary['completed'], $summary['people_processed'],
            $summary['duplicates_skipped'], $summary['outcomes']]);
        same(['PUT', 'PUT'], array_values(array_map(static fn ($call) => $call['method'],
            array_filter($http->calls, static fn ($call) => $call['method'] === 'PUT'))));
        same([], queueFiles($directory, 'pending'));
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
        foreach (['relative/queue', '/tmp/../queue', dirname(__DIR__) . '/public/queue', '/'] as $queueDirectory) {
            putenv("QUEUE_DIRECTORY=$queueDirectory");
            fails(static fn () => new Config());
        }
        putenv('QUEUE_DIRECTORY=' . sys_get_temp_dir() . '/queue');
        new Config();
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
    removeTree($workRoot);
}
echo count($tests) . " tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
