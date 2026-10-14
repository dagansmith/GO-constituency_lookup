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

function constituencyService(): array
{
    $config = new Constituency\Config();
    $http = new Constituency\HttpClient($config->retries, $config->timeout);
    $an = new Constituency\ActionNetworkClient($http, $config);
    $parliament = new Constituency\ParliamentLookup($http, $config->parliamentUrl);
    return [$config, $an, new Constituency\Enricher($an, $parliament, $config)];
}
