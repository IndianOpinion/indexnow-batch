<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
