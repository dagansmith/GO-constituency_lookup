<?php
declare(strict_types=1);

namespace Constituency;

final class Sweeper
{
    public function __construct(
        private readonly ActionNetworkClient $an,
        private readonly Enricher $enricher,
        private readonly SweepCheckpoint $checkpoint,
        private readonly int $overlapSeconds,
        private readonly int $initialLookbackSeconds
    ) {
    }

    public static function summary(bool $full, bool $dryRun): array
    {
        return ['mode' => $full ? 'full' : 'incremental', 'dry_run' => $dryRun,
            'window_start' => null, 'window_end' => null, 'checkpoint_advanced' => false,
            'processed' => 0, 'updated' => 0, 'would_update' => 0, 'unchanged' => 0,
            'invalid_postcode' => 0, 'no_match' => 0, 'errors' => 0];
    }

    /** Call only while holding the sweeper lock. */
    public function run(bool $full = false, bool $dryRun = false, ?int $now = null): array
    {
        $counts = self::summary($full, $dryRun);
        // Save the start, not the finish: changes during this run belong to the next run.
        $now ??= time();
        $counts['window_end'] = gmdate('Y-m-d\TH:i:s\Z', $now);
        try {
            if ($full) {
                $people = $this->an->people();
            } else {
                $last = $this->checkpoint->read();
                if ($last !== null && $last > $now) {
                    throw new \RuntimeException('Sweep checkpoint is in the future; check the system clock.');
                }
                $start = $last ?? ($this->initialLookbackSeconds > 0 ? $now - $this->initialLookbackSeconds : 0);
                $counts['window_start'] = gmdate('Y-m-d\TH:i:s\Z', max(0, $start - $this->overlapSeconds));
                $people = $this->an->peopleModifiedSince($counts['window_start']);
            }
            foreach ($people as $person) {
                $counts['processed']++;
                try {
                    $id = $this->an->personId($person);
                    $counts[$this->enricher->process($id, 'cron', $dryRun)]++;
                } catch (\Throwable $exception) {
                    $counts['errors']++;
                    Logger::log('sweep_person_failed', ['error' => $exception->getMessage()]);
                }
            }
            if (!$dryRun && $counts['errors'] === 0) {
                $this->checkpoint->write($now);
                $counts['checkpoint_advanced'] = true;
            }
        } catch (\Throwable $exception) {
            $counts['errors']++;
            Logger::log('sweep_failed', ['error' => $exception->getMessage()]);
        }
        return $counts;
    }
}
