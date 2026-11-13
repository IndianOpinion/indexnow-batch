<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * Bulk import pause (rule 31): suppresses post enqueue until resumed or expired.
 */
final class Pause
{
    public function pause(int $seconds, int $now): int
    {
        $until = $now + max(1, $seconds);
        update_option(Options::PAUSE, $until, false);

        return $until;
    }

    public function resume(): void
    {
        delete_option(Options::PAUSE);
    }

    public function activeUntil(int $now): ?int
    {
        $until = (int) get_option(Options::PAUSE, 0);

        return $until > $now ? $until : null;
    }
}
