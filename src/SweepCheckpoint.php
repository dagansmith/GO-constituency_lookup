<?php
declare(strict_types=1);

namespace Constituency;

final class SweepCheckpoint
{
    public function __construct(private readonly string $path)
    {
    }

    public function read(): ?int
    {
        if (is_link($this->path)) {
            throw new \RuntimeException('Refusing a symlink sweep checkpoint.');
        }
        if (!file_exists($this->path)) {
            return null;
        }
        $contents = @file_get_contents($this->path);
        $data = $contents === false ? null : json_decode($contents, true);
        $value = is_array($data) ? ($data['completed_through'] ?? null) : null;
        $date = is_string($value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC')) : false;
        if (($data['version'] ?? null) !== 1 || $date === false
            || $date->format('Y-m-d\TH:i:s\Z') !== $value || $date->getTimestamp() < 0) {
            throw new \RuntimeException('Cannot read sweep checkpoint; restore it or run --full to rebuild it.');
        }
        return $date->getTimestamp();
    }

    /** Call only under the sweeper lock, after all pages and people succeeded. */
    public function write(int $timestamp): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create checkpoint directory; check SWEEP_CHECKPOINT_PATH permissions.');
        }
        if (is_link($this->path)) {
            throw new \RuntimeException('Refusing a symlink sweep checkpoint.');
        }
        $json = json_encode(['version' => 1, 'completed_through' => gmdate('Y-m-d\TH:i:s\Z', $timestamp)],
            JSON_THROW_ON_ERROR);
        $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write sweep checkpoint; check SWEEP_CHECKPOINT_PATH permissions.');
        }
        try {
            $written = @chmod($temporary, 0600) && @fwrite($handle, $json) === strlen($json)
                && @fflush($handle) && @fsync($handle);
            $closed = @fclose($handle);
            $handle = null;
            if (!$written || !$closed || !@rename($temporary, $this->path)) {
                throw new \RuntimeException('Cannot persist sweep checkpoint; check permissions and free space.');
            }
        } finally {
            if ($handle !== null) {
                @fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
