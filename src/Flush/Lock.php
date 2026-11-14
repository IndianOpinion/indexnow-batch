<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

use IndianOpinion\IndexNowBatch\Options;

/**
 * One flush at a time (rule 23). The lock expires after 10 minutes so a
 * crashed run cannot hold it forever.
 */
final class Lock
{
    public const TTL = 600;

    private ?string $token = null;

    public function __construct(private LockStore $store)
    {
    }

    public function acquire(int $now): bool
    {
        $token = $now . ':' . bin2hex(random_bytes(8));
        if ($this->store->add(Options::LOCK, $token)) {
            $this->token = $token;

            return true;
        }
        $current = $this->store->get(Options::LOCK);
        if ($current === null) {
            // Released between our insert and our read: try once more.
            if ($this->store->add(Options::LOCK, $token)) {
                $this->token = $token;

                return true;
            }

            return false;
        }
        $taken = (int) strtok($current, ':');
        if ($taken + self::TTL <= $now && $this->store->replace(Options::LOCK, $current, $token)) {
            $this->token = $token;

            return true;
        }

        return false;
    }

    public function release(): void
    {
        if ($this->token !== null) {
            $this->store->delete(Options::LOCK, $this->token);
            $this->token = null;
        }
    }
}
