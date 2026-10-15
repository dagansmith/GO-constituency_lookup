<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Constituency\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/D', $name)) {
            require_once __DIR__ . '/' . $name . '.php';
        }
    }
});

// Web servers often do not pass the shell/cron environment to PHP, so read the
// private root .env as data. Variables already in the environment win.
try {
    $invalidEnvLines = Constituency\Env::load(dirname(__DIR__) . '/.env');
    if ($invalidEnvLines !== []) {
        Constituency\Logger::log('env_file_lines_ignored', ['lines' => $invalidEnvLines]);
    }
} catch (\Throwable $exception) {
    Constituency\Logger::log('env_file_error', ['error' => $exception->getMessage()]);
}
unset($invalidEnvLines);

function constituencyService(): array
{
    $config = new Constituency\Config();
    $http = new Constituency\HttpClient($config->retries, $config->timeout);
    $an = new Constituency\ActionNetworkClient($http, $config);
    $parliament = new Constituency\ParliamentLookup($http, $config->parliamentUrl, $config->parliamentMembersUrl);
    return [$config, $an, new Constituency\Enricher($an, $parliament, $config)];
}
