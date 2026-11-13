<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Support;

use IndianOpinion\IndexNowBatch\Queue\EnqueueResult;
use IndianOpinion\IndexNowBatch\Queue\QueueRepository;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;

/**
 * In-memory repository with the same semantics as the SQL one, including
 * the unique (hash, state) key. Counts writes.
 */
class MemoryQueueRepository implements QueueRepository
{
    /** @var array<int, QueueRow> */
    public array $rows = [];
    public int $writes = 0;
    public bool $failNext = false;
    private int $nextId = 1;

    public function add(string $url, ?int $postId, int $queuedAt, string $state = QueueRow::PENDING, ?int $sentAt = null): QueueRow
    {
        $row = new QueueRow($this->nextId++, $url, \IndianOpinion\IndexNowBatch\Url\UrlRules::hash($url), $postId, $state, $queuedAt, null, 0, null, $sentAt);
        $this->rows[$row->id] = $row;

        return $row;
    }

    /**
     * @return list<QueueRow>
     */
    public function inState(string $state): array
    {
        return array_values(array_filter($this->rows, static fn (QueueRow $r): bool => $r->state === $state));
    }

    private function find(string $hash, string $state): ?QueueRow
    {
        foreach ($this->rows as $row) {
            if ($row->hash === $hash && $row->state === $state) {
                return $row;
            }
        }

        return null;
    }

    public function enqueue(string $url, string $hash, ?int $postId, int $now, int $resubmitSeconds, int $cap): EnqueueResult
    {
        if ($this->failNext) {
            $this->failNext = false;

            return new EnqueueResult(EnqueueResult::FAILED, 0, null, 'insert failed: disk full');
        }
        $this->writes++;
        $sent = $this->find($hash, QueueRow::SENT);
        $notBefore = null;
        if ($sent !== null && $sent->sentAt !== null && $resubmitSeconds > 0 && $sent->sentAt + $resubmitSeconds > $now) {
            $notBefore = $sent->sentAt + $resubmitSeconds;
        }
        $existing = $this->find($hash, QueueRow::PENDING);
        if ($existing !== null) {
            $existing->postId = $postId ?? $existing->postId;
            $existing->queuedAt = $now;

            return new EnqueueResult(EnqueueResult::REFRESHED, 0, $notBefore);
        }
        $pending = count($this->inState(QueueRow::PENDING));
        $row = new QueueRow($this->nextId++, $url, $hash, $postId, QueueRow::PENDING, $now, $notBefore);
        $this->rows[$row->id] = $row;
        $evicted = 0;
        if ($pending + 1 > $cap) {
            $list = $this->sorted($this->inState(QueueRow::PENDING));
            foreach (array_slice($list, 0, $pending + 1 - $cap) as $old) {
                unset($this->rows[$old->id]);
                $evicted++;
            }
        }

        return new EnqueueResult(EnqueueResult::ADDED, $evicted, $notBefore);
    }

    /**
     * @param list<QueueRow> $rows
     * @return list<QueueRow>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (QueueRow $a, QueueRow $b): int => [$a->queuedAt, $a->id] <=> [$b->queuedAt, $b->id]);

        return $rows;
    }

    public function countPending(): int
    {
        return count($this->inState(QueueRow::PENDING));
    }

    public function countDue(int $dueBy): int
    {
        return count(array_filter(
            $this->inState(QueueRow::PENDING),
            static fn (QueueRow $r): bool => $r->notBefore === null || $r->notBefore <= $dueBy
        ));
    }

    public function countByState(): array
    {
        $out = [QueueRow::PENDING => 0, QueueRow::SENT => 0, QueueRow::FAILED => 0];
        foreach ($this->rows as $row) {
            $out[$row->state]++;
        }

        return $out;
    }

    public function fetchDue(int $dueBy, int $limit, bool $ignoreNotBefore, array $excludeIds): array
    {
        $rows = array_filter(
            $this->inState(QueueRow::PENDING),
            static fn (QueueRow $r): bool => $r->retryGroup === null
                && ($ignoreNotBefore || $r->notBefore === null || $r->notBefore <= $dueBy)
                && !in_array($r->id, $excludeIds, true)
        );

        return array_map(static fn (QueueRow $r): QueueRow => clone $r, array_slice($this->sorted(array_values($rows)), 0, $limit));
    }

    public function fetchNextGroup(array $excludeIds): array
    {
        $rows = $this->sorted(array_values(array_filter(
            $this->inState(QueueRow::PENDING),
            static fn (QueueRow $r): bool => $r->retryGroup !== null && !in_array($r->id, $excludeIds, true)
        )));
        if ($rows === []) {
            return [];
        }
        $group = $rows[0]->retryGroup;
        $out = array_values(array_filter($rows, static fn (QueueRow $r): bool => $r->retryGroup === $group));
        usort($out, static fn (QueueRow $a, QueueRow $b): int => $a->id <=> $b->id);

        return array_map(static fn (QueueRow $r): QueueRow => clone $r, $out);
    }

    public function lastSent(array $hashes): array
    {
        $out = [];
        foreach ($this->inState(QueueRow::SENT) as $row) {
            if (in_array($row->hash, $hashes, true) && $row->sentAt !== null) {
                $out[$row->hash] = max($out[$row->hash] ?? 0, $row->sentAt);
            }
        }

        return $out;
    }

    public function delete(array $ids): int
    {
        $this->writes++;
        $n = 0;
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                unset($this->rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function moveTo(string $state, array $rows, int $http, int $now): void
    {
        if ($state !== QueueRow::SENT && $state !== QueueRow::FAILED) {
            throw new \InvalidArgumentException('Unknown state ' . $state);
        }
        $this->writes++;
        foreach ($rows as $row) {
            // Like the SQL: only a pending row still as it was fetched.
            $stored = $this->rows[$row->id] ?? null;
            if ($stored !== null && $stored->state === QueueRow::PENDING && $stored->queuedAt === $row->queuedAt) {
                unset($this->rows[$row->id]);
            }
        }
        foreach ($rows as $row) {
            $existing = $this->find($row->hash, $state);
            if ($existing !== null) {
                $existing->url = $row->url;
                $existing->postId = $row->postId;
                $existing->attempts = $row->attempts;
                $existing->lastHttp = $http;
                $existing->sentAt = $now;
                continue;
            }
            $new = new QueueRow($this->nextId++, $row->url, $row->hash, $row->postId, $state, $row->queuedAt, null, $row->attempts, $http, $now);
            $this->rows[$new->id] = $new;
        }
    }

    public function recordAttempt(array $ids, int $http): void
    {
        $this->writes++;
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $this->rows[$id]->attempts++;
                $this->rows[$id]->lastHttp = $http;
            }
        }
    }

    public function deleteAtAttempts(array $ids, int $max): int
    {
        $this->writes++;
        $n = 0;
        foreach ($ids as $id) {
            if (isset($this->rows[$id]) && $this->rows[$id]->attempts >= $max) {
                unset($this->rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function assignGroup(array $ids, string $group): void
    {
        $this->writes++;
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $this->rows[$id]->retryGroup = $group;
            }
        }
    }

    public function setNotBefore(int $id, int $notBefore): void
    {
        $this->writes++;
        if (isset($this->rows[$id])) {
            $this->rows[$id]->notBefore = $notBefore;
        }
    }

    public function purge(int $pendingBefore, int $sentBefore, int $failedBefore): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->rows as $id => $row) {
            $old = match ($row->state) {
                QueueRow::PENDING => $row->queuedAt < $pendingBefore,
                QueueRow::SENT => $row->sentAt !== null && $row->sentAt < $sentBefore,
                default => $row->sentAt !== null && $row->sentAt < $failedBefore,
            };
            if ($old) {
                unset($this->rows[$id]);
                $out[$row->state]++;
            }
        }

        return $out;
    }

    public function list(string $state, int $limit): array
    {
        $rows = $state === 'all' ? array_values($this->rows) : $this->inState($state);
        usort($rows, static fn (QueueRow $a, QueueRow $b): int => $b->id <=> $a->id);

        return array_slice($rows, 0, $limit);
    }

    public function removePending(array $hashes): int
    {
        $n = 0;
        foreach ($this->inState(QueueRow::PENDING) as $row) {
            if (in_array($row->hash, $hashes, true)) {
                unset($this->rows[$row->id]);
                $n++;
            }
        }

        return $n;
    }

    public function clearPending(): int
    {
        $n = 0;
        foreach ($this->inState(QueueRow::PENDING) as $row) {
            unset($this->rows[$row->id]);
            $n++;
        }

        return $n;
    }

    public function deleteSite(int $siteId): int
    {
        $n = count($this->rows);
        $this->rows = [];

        return $n;
    }
}
