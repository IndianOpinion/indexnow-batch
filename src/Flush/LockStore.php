<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

/**
 * Atomic primitives for the flush lock.
 */
interface LockStore
{
    /**
     * Adds the row only if absent. True when this call created it.
     *
     * @phpstan-impure
     */
    public function add(string $name, string $value): bool;

    /** @phpstan-impure */
    public function get(string $name): ?string;

    /** Replaces the value only if it still equals $expected. */
    public function replace(string $name, string $expected, string $value): bool;

    /** Deletes the row only if it still holds $value. */
    public function delete(string $name, string $value): bool;
}
