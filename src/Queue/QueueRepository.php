<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

/**
 * Storage for queue rows of the current site.
 *
 * Times are UTC Unix timestamps. Every method scopes itself to the current
 * site of a network.
 */
interface QueueRepository
{
    /**
     * Inserts a pending row, or refreshes the post ID of an existing pending
     * row with the same hash. When the hash was sent less than
     * $resubmitSeconds ago, the new row gets a not-before time. When the
     * pending count is at $cap, the oldest pending rows are evicted.
     */
    public function enqueue(string $url, string $hash, ?int $postId, int $now, int $resubmitSeconds, int $cap): EnqueueResult;

    public function countPending(): int;

    /** Pending rows with no not-before or a not-before at or before $dueBy. */
    public function countDue(int $dueBy): int;

    /**
     * @return array<string, int> Counts keyed by state.
     */
    public function countByState(): array;

    /**
     * Due pending rows outside any retry group, oldest first.
     *
     * @param list<int> $excludeIds
     * @return list<QueueRow>
     */
    public function fetchDue(int $dueBy, int $limit, bool $ignoreNotBefore, array $excludeIds): array;

    /**
     * Every pending row of the oldest retry group.
     *
     * @param list<int> $excludeIds
     * @return list<QueueRow>
     */
    public function fetchNextGroup(array $excludeIds): array;

    /**
     * @param list<string> $hashes
     * @return array<string, int> Last successful send time keyed by hash.
     */
    public function lastSent(array $hashes): array;

    /**
     * @param list<int> $ids
     */
    public function delete(array $ids): int;

    /**
     * Replaces pending rows with rows in $state ('sent' or 'failed'), keeping
     * the URL actually sent. An older row in that state with the same hash
     * is replaced.
     *
     * @param list<QueueRow> $rows Rows carrying the URL and hash as sent.
     */
    public function moveTo(string $state, array $rows, int $http, int $now): void;

    /**
     * @param list<int> $ids
     */
    public function recordAttempt(array $ids, int $http): void;

    /**
     * Deletes the given rows whose attempts reached $max.
     *
     * @param list<int> $ids
     */
    public function deleteAtAttempts(array $ids, int $max): int;

    /**
     * @param list<int> $ids
     */
    public function assignGroup(array $ids, string $group): void;

    public function setNotBefore(int $id, int $notBefore): void;

    /**
     * Removes pending rows queued before $pendingBefore, sent rows sent before
     * $sentBefore and failed rows failed before $failedBefore.
     *
     * @return array{pending: int, sent: int, failed: int}
     */
    public function purge(int $pendingBefore, int $sentBefore, int $failedBefore): array;

    /**
     * @return list<QueueRow> Newest first.
     */
    public function list(string $state, int $limit): array;

    /**
     * @param list<string> $hashes
     */
    public function removePending(array $hashes): int;

    public function clearPending(): int;

    /** Removes every row of a site, used when a site of a network is deleted. */
    public function deleteSite(int $siteId): int;
}
