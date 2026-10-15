<?php
declare(strict_types=1);

namespace Constituency;

/**
 * Durable filesystem job queue. A job moves between sibling directories by
 * atomic rename: pending -> processing -> (deleted | pending | failed).
 * File names are generated here and validated; input never selects a path.
 */
final class Queue
{
    public const MAX_ATTEMPTS = 10;
    private const STATES = ['tmp', 'pending', 'processing', 'failed'];
    private const ID_PATTERN = '/^[0-9]{20}-[0-9a-f]{16}$/D';
    private const STALE_TMP_SECONDS = 3600;

    public function __construct(private readonly string $directory)
    {
        foreach (self::STATES as $state) {
            $path = $this->directory . '/' . $state;
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException('Cannot create queue directory; check QUEUE_DIRECTORY permissions.');
            }
        }
    }

    /** Delay before retry N (1-based): 1, 2, 4 ... minutes, capped at one hour. */
    public static function backoff(int $attempts): int
    {
        return min(3600, 60 * 2 ** max(0, min(6, $attempts - 1)));
    }

    /** Persists a job durably and returns its ID; throws if it was not stored. */
    public function enqueue(array $personIds, ?float $now = null): string
    {
        $ids = [];
        foreach ($personIds as $personId) {
            if (!is_string($personId)) {
                throw new \InvalidArgumentException('Invalid Action Network person ID.');
            }
            $ids[] = ActionNetworkClient::validateId($personId);
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('A queue job needs at least one person.');
        }
        $now ??= microtime(true);
        $id = sprintf('%s%06d-%s', gmdate('YmdHis', (int) $now), (int) (fmod($now, 1) * 1000000),
            bin2hex(random_bytes(8)));
        $this->write('pending', [
            'version' => 1,
            'id' => $id,
            'received_at' => $now,
            'attempts' => 0,
            'next_attempt_at' => (int) floor($now),
            'person_ids' => array_values(array_unique($ids)),
            'last_error' => null,
        ]);
        return $id;
    }

    /** Pending job IDs, oldest first, that are due (or unreadable, so claim() quarantines them). */
    public function due(int $now): array
    {
        $due = [];
        foreach ($this->ids('pending') as $id) {
            try {
                if ($this->read('pending', $id)['next_attempt_at'] <= $now) {
                    $due[] = $id;
                }
            } catch (\RuntimeException) {
                $due[] = $id;
            }
        }
        return $due;
    }

    /** Atomically takes a pending job; returns null if another process took it or it is corrupt. */
    public function claim(string $id): ?array
    {
        if (!@rename($this->path('pending', $id), $this->path('processing', $id))) {
            return null;
        }
        try {
            return $this->read('processing', $id);
        } catch (\RuntimeException $exception) {
            $this->quarantine('processing', $id, $exception);
            return null;
        }
    }

    public function save(array $job): void
    {
        $this->write('processing', $job);
    }

    /** Removes a claimed job; only call after every person succeeded. */
    public function complete(string $id): void
    {
        if (!@unlink($this->path('processing', $id))) {
            throw new \RuntimeException('Cannot remove completed queue job; check QUEUE_DIRECTORY permissions.');
        }
    }

    public function release(array $job): void
    {
        $this->move($job, 'pending');
    }

    /** Terminal failure: retained in failed/ for investigation and manual requeue. */
    public function fail(array $job): void
    {
        $this->move($job, 'failed');
    }

    /**
     * Returns jobs left in processing/ by a crashed worker to pending/ (with backoff),
     * or failed/ once attempts are exhausted. Call only while holding the worker lock.
     */
    public function recover(int $now): int
    {
        $recovered = 0;
        foreach ($this->ids('processing') as $id) {
            try {
                $job = $this->read('processing', $id);
            } catch (\RuntimeException $exception) {
                $this->quarantine('processing', $id, $exception);
                continue;
            }
            $job['last_error'] = 'Worker stopped before finishing the job.';
            $job['next_attempt_at'] = $now + self::backoff($job['attempts']);
            if ($job['attempts'] >= self::MAX_ATTEMPTS) {
                $this->fail($job);
                Logger::log('queue_job_failed', ['job_id' => $id, 'attempts' => $job['attempts'],
                    'remaining_people' => count($job['person_ids']), 'error' => $job['last_error']]);
            } else {
                $this->release($job);
                Logger::log('queue_job_recovered', ['job_id' => $id, 'attempts' => $job['attempts']]);
            }
            $recovered++;
        }
        // Temporary files are never acknowledged jobs; remove abandoned ones.
        foreach (glob($this->directory . '/tmp/*.tmp') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $now - self::STALE_TMP_SECONDS) {
                @unlink($path);
            }
        }
        return $recovered;
    }

    /** Moves failed jobs back to pending with a fresh attempt budget. */
    public function requeueFailed(int $now): array
    {
        $result = ['requeued' => 0, 'unreadable' => 0];
        foreach ($this->ids('failed') as $id) {
            try {
                $job = $this->read('failed', $id);
            } catch (\RuntimeException) {
                $result['unreadable']++;
                continue;
            }
            $job['attempts'] = 0;
            $job['next_attempt_at'] = $now;
            $this->write('failed', $job);
            if (!@rename($this->path('failed', $id), $this->path('pending', $id))) {
                throw new \RuntimeException('Cannot requeue failed job; check QUEUE_DIRECTORY permissions.');
            }
            $result['requeued']++;
        }
        return $result;
    }

    public function status(int $now): array
    {
        $pending = $this->ids('pending');
        $oldest = null;
        if ($pending !== []) {
            try {
                $oldest = max(0, $now - (int) $this->read('pending', $pending[0])['received_at']);
            } catch (\RuntimeException) {
            }
        }
        return [
            'queue_directory' => $this->directory,
            'pending' => count($pending),
            'due' => count($this->due($now)),
            'processing' => count($this->ids('processing')),
            'failed' => count($this->ids('failed')),
            'oldest_pending_age_seconds' => $oldest,
        ];
    }

    public function ids(string $state): array
    {
        $ids = [];
        foreach (@scandir($this->stateDirectory($state)) ?: [] as $name) {
            if (str_ends_with($name, '.json') && preg_match(self::ID_PATTERN, substr($name, 0, -5))) {
                $ids[] = substr($name, 0, -5);
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function read(string $state, string $id): array
    {
        $raw = @file_get_contents($this->path($state, $id));
        if ($raw === false) {
            throw new \RuntimeException('Cannot read queue job.');
        }
        try {
            $job = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Queue job is not valid JSON.');
        }
        if (!is_array($job) || ($job['version'] ?? null) !== 1 || ($job['id'] ?? null) !== $id
            || !is_int($job['attempts'] ?? null) || $job['attempts'] < 0
            || !is_int($job['next_attempt_at'] ?? null)
            || !(is_int($job['received_at'] ?? null) || is_float($job['received_at'] ?? null))
            || !is_array($job['person_ids'] ?? null) || $job['person_ids'] === [] || !array_is_list($job['person_ids'])
            || !(($job['last_error'] ?? null) === null || is_string($job['last_error']))) {
            throw new \RuntimeException('Queue job has an invalid structure.');
        }
        foreach ($job['person_ids'] as $personId) {
            if (!is_string($personId) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $personId)) {
                throw new \RuntimeException('Queue job has an invalid person ID.');
            }
        }
        return $job;
    }

    private function move(array $job, string $state): void
    {
        $this->write('processing', $job);
        if (!@rename($this->path('processing', $job['id']), $this->path($state, $job['id']))) {
            throw new \RuntimeException('Cannot move queue job; check QUEUE_DIRECTORY permissions.');
        }
    }

    private function quarantine(string $state, string $id, \Throwable $exception): void
    {
        Logger::log('queue_job_corrupt', ['job_id' => $id, 'error' => $exception->getMessage()]);
        if (!@rename($this->path($state, $id), $this->path('failed', $id))) {
            throw new \RuntimeException('Cannot quarantine corrupt queue job; check QUEUE_DIRECTORY permissions.');
        }
    }

    /** Write to a private temporary file, flush to disk, then atomically rename into place. */
    private function write(string $state, array $job): void
    {
        $target = $this->path($state, $job['id']);
        $temporary = $this->directory . '/tmp/' . $job['id'] . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write queue job; check QUEUE_DIRECTORY permissions.');
        }
        try {
            $written = @chmod($temporary, 0600) && @fwrite($handle, $json) === strlen($json)
                && @fflush($handle) && @fsync($handle);
            $closed = @fclose($handle);
            $handle = null;
            if (!$written || !$closed || !@rename($temporary, $target)) {
                throw new \RuntimeException('Cannot persist queue job; check QUEUE_DIRECTORY permissions and free space.');
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

    private function path(string $state, string $id): string
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Invalid queue job ID.');
        }
        return $this->stateDirectory($state) . '/' . $id . '.json';
    }

    private function stateDirectory(string $state): string
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Invalid queue state.');
        }
        return $this->directory . '/' . $state;
    }
}
