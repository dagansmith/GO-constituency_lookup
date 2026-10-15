<?php
declare(strict_types=1);

namespace Constituency;

/**
 * Reads KEY=VALUE lines as data; never evaluates them. Variables already present
 * in the process environment take precedence over the file.
 */
final class Env
{
    /** Returns the line numbers of ignored malformed lines (never their content). */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Cannot read the .env file; check its permissions.');
        }
        $invalid = [];
        foreach ($lines as $index => $line) {
            $parsed = self::parseLine($line);
            if ($parsed === false) {
                $invalid[] = $index + 1;
            } elseif ($parsed !== null && getenv($parsed[0]) === false) {
                putenv($parsed[0] . '=' . $parsed[1]);
            }
        }
        return $invalid;
    }

    /**
     * Returns [name, value], null for blank/comment lines, or false for lines that
     * a POSIX shell would interpret differently (expansion, commands, spaces).
     */
    public static function parseLine(string $line): array|false|null
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }
        if (!preg_match('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/sD', $line, $match)) {
            return false;
        }
        [, $name, $raw] = $match;
        if (preg_match("/^'([^']*)'(?:[ \\t]+#.*)?$/sD", $raw, $value)) {
            return [$name, $value[1]];
        }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(?:[ \t]+#.*)?$/sD', $raw, $value)) {
            $decoded = self::doubleQuoted($value[1]);
            return $decoded === null ? false : [$name, $decoded];
        }
        if (preg_match('/^([^\s\'"\\\\$`;&|<>()]*)(?:[ \t]+#.*)?$/D', $raw, $value)) {
            return [$name, $value[1]];
        }
        return false;
    }

    private static function doubleQuoted(string $value): ?string
    {
        $decoded = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $i + 1 < $length && str_contains('\\"$`', $value[$i + 1])) {
                $decoded .= $value[++$i];
            } elseif ($char === '$' || $char === '`') {
                // A shell would expand these; reject rather than diverge from cron.
                return null;
            } else {
                $decoded .= $char;
            }
        }
        return $decoded;
    }
}
