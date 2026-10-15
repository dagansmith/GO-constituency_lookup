<?php
declare(strict_types=1);

namespace Constituency;

final class QueueWorker
{
    public function __construct(private readonly Queue $queue, private readonly Enricher $enricher)
    {
    }

    /** Processes due jobs; call only while holding the queue-worker lock. */
    public function run(int $maxJobs, int $maxSeconds): array
    {
        $deadline = microtime(true) + $maxSeconds;
        $summary = ['recovered' => $this->queue->recover(time()), 'claimed' => 0, 'completed' => 0,
            'retry_scheduled' => 0, 'deferred' => 0, 'failed' => 0, 'people_processed' => 0,
            'duplicates_skipped' => 0, 'errors' => 0, 'outcomes' => []];
        // Person ID => time its fresh read started in this run. An event received
        // before then is already covered, because enrichment re-reads AN.
        $processedAt = [];
        foreach ($this->queue->due(time()) as $id) {
            if ($summary['claimed'] >= $maxJobs || microtime(true) >= $deadline) {
                break;
            }
            $job = $this->queue->claim($id);
            if ($job === null) {
                continue;
            }
            $summary['claimed']++;
            $previousAttempts = $job['attempts'];
            // Count the attempt before working so a job that crashes workers eventually fails.
            $job['attempts']++;
            $this->queue->save($job);
            $failed = false;
            $deferred = false;
            foreach ($job['person_ids'] as $personId) {
                if (microtime(true) >= $deadline) {
                    $deferred = true;
                    break;
                }
                if (isset($processedAt[$personId]) && $job['received_at'] < $processedAt[$personId]) {
                    $summary['duplicates_skipped']++;
                    $job['person_ids'] = array_values(array_diff($job['person_ids'], [$personId]));
                    continue;
                }
                $started = microtime(true);
                try {
                    $outcome = $this->enricher->process($personId, 'queue');
                } catch (\Throwable $exception) {
                    $failed = true;
                    $summary['errors']++;
                    $job['last_error'] = $exception->getMessage();
                    continue;
                }
                $processedAt[$personId] = $started;
                $summary['people_processed']++;
                $summary['outcomes'][$outcome] = ($summary['outcomes'][$outcome] ?? 0) + 1;
                $job['person_ids'] = array_values(array_diff($job['person_ids'], [$personId]));
                if ($job['person_ids'] !== []) {
                    // Record progress so a crash does not repeat finished people.
                    $this->queue->save($job);
                }
            }
            if ($job['person_ids'] === []) {
                $this->queue->complete($id);
                $summary['completed']++;
                Logger::log('queue_job_completed', ['job_id' => $id, 'attempts' => $job['attempts']]);
            } elseif (!$failed) {
                // Ran out of time before an error: not an attempt.
                $job['attempts'] = $previousAttempts;
                $this->queue->release($job);
                $summary['deferred']++;
            } elseif ($job['attempts'] >= Queue::MAX_ATTEMPTS) {
                $this->queue->fail($job);
                $summary['failed']++;
                Logger::log('queue_job_failed', ['job_id' => $id, 'attempts' => $job['attempts'],
                    'remaining_people' => count($job['person_ids']), 'error' => $job['last_error']]);
            } else {
                $job['next_attempt_at'] = time() + Queue::backoff($job['attempts']);
                $this->queue->release($job);
                $summary['retry_scheduled']++;
                Logger::log('queue_job_retry_scheduled', ['job_id' => $id, 'attempts' => $job['attempts'],
                    'next_attempt_at' => gmdate('c', $job['next_attempt_at']),
                    'remaining_people' => count($job['person_ids']), 'error' => $job['last_error']]);
            }
        }
        return $summary;
    }
}
