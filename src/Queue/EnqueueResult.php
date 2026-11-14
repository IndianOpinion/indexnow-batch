<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

/**
 * What the repository did with one enqueue.
 */
final class EnqueueResult
{
    public const ADDED = 'added';
    public const REFRESHED = 'refreshed';
    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public int $evicted = 0,
        public ?int $notBefore = null,
        public string $error = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status !== self::FAILED;
    }
}
