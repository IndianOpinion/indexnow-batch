<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

/**
 * Result of one queue request, for callers and the CLI.
 */
final class EnqueueOutcome
{
    public function __construct(
        public bool $accepted,
        public string $reason = '',
        public string $url = '',
        public ?int $notBefore = null,
    ) {
    }

    public static function refused(string $reason, string $url = ''): self
    {
        return new self(false, $reason, $url);
    }
}
