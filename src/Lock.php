<?php
declare(strict_types=1);

namespace Constituency;

final class Lock
{
    public const BUSY = 1;

    private $handle;

    public function __construct(string $directory, string $key)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create lock directory; check LOCK_DIRECTORY permissions.');
        }
        $path = $directory . '/' . hash('sha256', $key) . '.lock';
        $this->handle = @fopen($path, 'c');
        if ($this->handle === false) {
            throw new \RuntimeException('Cannot open lock file; check LOCK_DIRECTORY permissions.');
        }
        chmod($path, 0600);
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            throw new \RuntimeException('Processing is already in progress; retry later.', self::BUSY);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
