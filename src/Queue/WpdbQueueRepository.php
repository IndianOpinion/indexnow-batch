<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

use IndianOpinion\IndexNowBatch\Options;

/**
 * Queue storage in the shared custom table, through $wpdb.
 *
 * Integer lists are cast and inlined; every string goes through prepare().
 */
final class WpdbQueueRepository implements QueueRepository
{
    /** Rows per multi-row INSERT, to stay well under max_allowed_packet. */
    private const INSERT_CHUNK = 200;

    private string $table;

    public function __construct(private \wpdb $db)
    {
        $this->table = $db->base_prefix . Options::TABLE;
    }

    private function site(): int
    {
        return get_current_blog_id();
    }

    public function enqueue(string $url, string $hash, ?int $postId, int $now, int $resubmitSeconds, int $cap): EnqueueResult
    {
        $site = $this->site();
        $probe = $this->db->get_row(
            $this->prepare(
                "SELECT (SELECT MAX(sent_at) FROM {$this->table} WHERE site_id = %d AND url_hash = %s AND state = 'sent') AS last_sent,"
                . " (SELECT COUNT(*) FROM {$this->table} WHERE site_id = %d AND state = 'pending') AS pending",
                $site,
                $hash,
                $site
            ),
            ARRAY_A
        );
        if (!is_array($probe)) {
            return new EnqueueResult(EnqueueResult::FAILED, 0, null, $this->error('read'));
        }

        $notBefore = null;
        $lastSent = QueueRow::toTime($probe['last_sent'] ?? null);
        if ($lastSent !== null && $resubmitSeconds > 0 && $lastSent + $resubmitSeconds > $now) {
            $notBefore = $lastSent + $resubmitSeconds;
        }

        $postSql = $postId === null ? 'NULL' : (string) max(0, $postId);
        $notBeforeSql = $notBefore === null ? 'NULL' : $this->prepare('%s', QueueRow::toDb($notBefore));
        $sql = $this->prepare(
            "INSERT INTO {$this->table} (site_id, url, url_hash, post_id, state, queued_at, not_before, attempts)"
            . " VALUES (%d, %s, %s, {$postSql}, 'pending', %s, {$notBeforeSql}, 0)"
            . ' ON DUPLICATE KEY UPDATE post_id = COALESCE(VALUES(post_id), post_id)',
            $site,
            $url,
            $hash,
            QueueRow::toDb($now)
        );
        $result = $this->db->query($sql);
        if ($result === false) {
            return new EnqueueResult(EnqueueResult::FAILED, 0, null, $this->error('insert'));
        }

        // MySQL reports 1 for an insert, 2 for an update, 0 for an unchanged row.
        if ((int) $result !== 1) {
            return new EnqueueResult(EnqueueResult::REFRESHED, 0, $notBefore);
        }

        $evicted = 0;
        $pending = (int) ($probe['pending'] ?? 0) + 1;
        if ($pending > $cap) {
            $evicted = (int) $this->db->query($this->prepare(
                "DELETE FROM {$this->table} WHERE site_id = %d AND state = 'pending' ORDER BY queued_at ASC, id ASC LIMIT %d",
                $site,
                $pending - $cap
            ));
        }

        return new EnqueueResult(EnqueueResult::ADDED, $evicted, $notBefore);
    }

    private function error(string $what): string
    {
        return $what . ' failed: ' . ($this->db->last_error !== '' ? $this->db->last_error : 'unknown database error');
    }

    public function countPending(): int
    {
        return (int) $this->db->get_var($this->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE site_id = %d AND state = 'pending'",
            $this->site()
        ));
    }

    public function countDue(int $dueBy): int
    {
        return (int) $this->db->get_var($this->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE site_id = %d AND state = 'pending'"
            . ' AND (not_before IS NULL OR not_before <= %s)',
            $this->site(),
            QueueRow::toDb($dueBy)
        ));
    }

    public function countByState(): array
    {
        $rows = $this->db->get_results($this->prepare(
            "SELECT state, COUNT(*) AS n FROM {$this->table} WHERE site_id = %d GROUP BY state",
            $this->site()
        ), ARRAY_A);
        $out = [QueueRow::PENDING => 0, QueueRow::SENT => 0, QueueRow::FAILED => 0];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['state']] = (int) $row['n'];
        }

        return $out;
    }

    public function fetchDue(int $dueBy, int $limit, bool $ignoreNotBefore, array $excludeIds): array
    {
        $where = "site_id = %d AND state = 'pending' AND retry_group IS NULL";
        $args = [$this->site()];
        if (!$ignoreNotBefore) {
            $where .= ' AND (not_before IS NULL OR not_before <= %s)';
            $args[] = QueueRow::toDb($dueBy);
        }
        $where .= $this->notIn($excludeIds);
        $args[] = max(1, $limit);

        return $this->rows($this->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY queued_at ASC, id ASC LIMIT %d",
            ...$args
        ));
    }

    public function fetchNextGroup(array $excludeIds): array
    {
        $site = $this->site();
        $group = $this->db->get_var($this->prepare(
            "SELECT retry_group FROM {$this->table} WHERE site_id = %d AND state = 'pending'"
            . ' AND retry_group IS NOT NULL' . $this->notIn($excludeIds)
            . ' ORDER BY queued_at ASC, id ASC LIMIT 1',
            $site
        ));
        if (!is_string($group) || $group === '') {
            return [];
        }

        return $this->rows($this->prepare(
            "SELECT * FROM {$this->table} WHERE site_id = %d AND state = 'pending' AND retry_group = %s"
            . $this->notIn($excludeIds) . ' ORDER BY id ASC',
            $site,
            $group
        ));
    }

    public function lastSent(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($hashes), '%s'));
        $rows = $this->db->get_results($this->prepare(
            "SELECT url_hash, MAX(sent_at) AS sent_at FROM {$this->table}"
            . " WHERE site_id = %d AND state = 'sent' AND url_hash IN ({$placeholders}) GROUP BY url_hash",
            $this->site(),
            ...$hashes
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $time = QueueRow::toTime($row['sent_at'] ?? null);
            if ($time !== null) {
                $out[(string) $row['url_hash']] = $time;
            }
        }

        return $out;
    }

    public function delete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->db->query($this->prepare(
            "DELETE FROM {$this->table} WHERE site_id = %d AND id IN (" . $this->intList($ids) . ')',
            $this->site()
        ));
    }

    public function moveTo(string $state, array $rows, int $http, int $now): void
    {
        if ($rows === []) {
            return;
        }
        if ($state !== QueueRow::SENT && $state !== QueueRow::FAILED) {
            throw new \InvalidArgumentException('Unknown state ' . $state);
        }
        $site = $this->site();
        $ids = array_map(static fn (QueueRow $row): int => $row->id, $rows);

        $this->db->query('START TRANSACTION');
        $this->delete($ids);
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $postSql = $row->postId === null ? 'NULL' : (string) max(0, $row->postId);
                $values[] = $this->prepare(
                    "(%d, %s, %s, {$postSql}, %s, %s, NULL, %d, %d, %s, NULL)",
                    $site,
                    $row->url,
                    $row->hash,
                    $state,
                    QueueRow::toDb($row->queuedAt),
                    $row->attempts,
                    $http,
                    QueueRow::toDb($now)
                );
            }
            $this->db->query(
                "INSERT INTO {$this->table}"
                . ' (site_id, url, url_hash, post_id, state, queued_at, not_before, attempts, last_http, sent_at, retry_group)'
                . ' VALUES ' . implode(', ', $values)
                . ' ON DUPLICATE KEY UPDATE url = VALUES(url), post_id = VALUES(post_id), attempts = VALUES(attempts),'
                . ' last_http = VALUES(last_http), sent_at = VALUES(sent_at)'
            );
        }
        $this->db->query('COMMIT');
    }

    public function recordAttempt(array $ids, int $http): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->query($this->prepare(
            "UPDATE {$this->table} SET attempts = attempts + 1, last_http = %d WHERE site_id = %d AND id IN ("
            . $this->intList($ids) . ')',
            $http,
            $this->site()
        ));
    }

    public function deleteAtAttempts(array $ids, int $max): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->db->query($this->prepare(
            "DELETE FROM {$this->table} WHERE site_id = %d AND attempts >= %d AND id IN (" . $this->intList($ids) . ')',
            $this->site(),
            $max
        ));
    }

    public function assignGroup(array $ids, string $group): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->query($this->prepare(
            "UPDATE {$this->table} SET retry_group = %s WHERE site_id = %d AND id IN (" . $this->intList($ids) . ')',
            $group,
            $this->site()
        ));
    }

    public function setNotBefore(int $id, int $notBefore): void
    {
        $this->db->query($this->prepare(
            "UPDATE {$this->table} SET not_before = %s WHERE site_id = %d AND id = %d",
            QueueRow::toDb($notBefore),
            $this->site(),
            $id
        ));
    }

    public function purge(int $pendingBefore, int $sentBefore, int $failedBefore): array
    {
        $site = $this->site();
        $out = [];
        foreach (
            [
                QueueRow::PENDING => ['queued_at', $pendingBefore],
                QueueRow::SENT => ['sent_at', $sentBefore],
                QueueRow::FAILED => ['sent_at', $failedBefore],
            ] as $state => [$column, $before]
        ) {
            $out[$state] = (int) $this->db->query($this->prepare(
                "DELETE FROM {$this->table} WHERE site_id = %d AND state = %s AND {$column} < %s",
                $site,
                $state,
                QueueRow::toDb($before)
            ));
        }

        return ['pending' => $out[QueueRow::PENDING] ?? 0, 'sent' => $out[QueueRow::SENT] ?? 0, 'failed' => $out[QueueRow::FAILED] ?? 0];
    }

    public function list(string $state, int $limit): array
    {
        $args = [$this->site()];
        $where = 'site_id = %d';
        if ($state !== 'all') {
            $where .= ' AND state = %s';
            $args[] = $state;
        }
        $args[] = max(1, $limit);

        return $this->rows($this->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY id DESC LIMIT %d",
            ...$args
        ));
    }

    public function removePending(array $hashes): int
    {
        if ($hashes === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($hashes), '%s'));

        return (int) $this->db->query($this->prepare(
            "DELETE FROM {$this->table} WHERE site_id = %d AND state = 'pending' AND url_hash IN ({$placeholders})",
            $this->site(),
            ...$hashes
        ));
    }

    public function clearPending(): int
    {
        return (int) $this->db->query($this->prepare(
            "DELETE FROM {$this->table} WHERE site_id = %d AND state = 'pending'",
            $this->site()
        ));
    }

    public function deleteSite(int $siteId): int
    {
        return (int) $this->db->query($this->prepare(
            "DELETE FROM {$this->table} WHERE site_id = %d",
            $siteId
        ));
    }

    /**
     * @param list<int> $ids
     */
    private function notIn(array $ids): string
    {
        return $ids === [] ? '' : ' AND id NOT IN (' . $this->intList($ids) . ')';
    }

    /**
     * @param list<int> $ids
     */
    private function intList(array $ids): string
    {
        return implode(', ', array_map(static fn ($id): string => (string) (int) $id, $ids));
    }

    /**
     * @return list<QueueRow>
     */
    private function rows(string $sql): array
    {
        $rows = $this->db->get_results($sql, ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = QueueRow::fromDb($row);
        }

        return $out;
    }

    /**
     * wpdb::prepare() for a query built from this class's own SQL and table
     * name. Every value goes through a placeholder.
     */
    private function prepare(string $query, mixed ...$args): string
    {
        /** @var literal-string $query */
        $sql = $this->db->prepare($query, ...$args);
        if (!is_string($sql)) {
            throw new \RuntimeException('wpdb::prepare() rejected a query: ' . $query);
        }

        return $sql;
    }
}
