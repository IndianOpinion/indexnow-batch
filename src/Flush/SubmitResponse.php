<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

/**
 * The endpoint's answer to one batch. Code 0 means no answer.
 */
final class SubmitResponse
{
    public function __construct(
        public int $code,
        public string $error = '',
        public string $retryAfter = '',
    ) {
    }

    public function isTransportError(): bool
    {
        return $this->code === 0;
    }
}
