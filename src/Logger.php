<?php
declare(strict_types=1);

namespace Constituency;

final class Logger
{
    public static function log(string $event, array $context = []): void
    {
        error_log(json_encode(
            ['time' => gmdate('c'), 'event' => $event] + $context,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }
}
