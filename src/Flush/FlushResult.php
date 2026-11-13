<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

/**
 * The run record plus, for callers that want it, every batch in full.
 */
final class FlushResult
{
    /**
     * @param array<string, mixed> $record
     * @param list<list<string>> $batches URLs per request (sent, or would be sent on a dry run).
     */
    public function __construct(public array $record, public array $batches)
    {
    }

    public function outcome(): string
    {
        return (string) $this->record['outcome'];
    }

    /** Whether a system cron wrapper should treat this run as a failure. */
    public function isFailure(): bool
    {
        return in_array($this->outcome(), ['transport_error', 'refused', 'key_unverified', 'schema_error'], true);
    }
}
