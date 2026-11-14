<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * Source of the current time as a UTC Unix timestamp.
 */
interface Clock
{
    public function now(): int;
}
