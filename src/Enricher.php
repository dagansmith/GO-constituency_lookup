<?php
declare(strict_types=1);

namespace Constituency;

final class Enricher
{
    public function __construct(
        private readonly ActionNetworkClient $an,
        private readonly ParliamentLookup $parliament,
        private readonly Config $config
    ) {
    }

    public function process(string $id, string $source, bool $dryRun = false): string
    {
        try {
            $lock = new Lock($this->config->lockDirectory, 'person:' . ActionNetworkClient::validateId($id));
            // Re-read under the lock: webhook and paginated records can be stale.
            $person = $this->an->getPerson($id);
            $postcode = Postcode::fromPerson($person, $this->config->postcodeField);
            if ($postcode === null) {
                $outcome = 'invalid_postcode';
            } else {
                $name = $this->parliament->constituency($postcode);
                if ($name === null) {
                    $outcome = 'no_match';
                } elseif (($person['custom_fields'][$this->config->constituencyField] ?? null) === $name) {
                    $outcome = 'unchanged';
                } elseif ($dryRun) {
                    $outcome = 'would_update';
                } else {
                    $this->an->updateConstituency($id, $name);
                    $outcome = 'updated';
                }
            }
            Logger::log('enrichment', ['person_id' => $id, 'source' => $source, 'outcome' => $outcome]);
            return $outcome;
        } catch (\Throwable $exception) {
            Logger::log('enrichment_failed', [
                'person_id' => $id,
                'source' => $source,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
