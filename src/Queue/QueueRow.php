<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

/**
 * One row of the queue table.
 */
final class QueueRow
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $url,
        public string $hash,
        public ?int $postId,
        public string $state,
        public int $queuedAt,
        public ?int $notBefore = null,
        public int $attempts = 0,
        public ?int $lastHttp = null,
        public ?int $sentAt = null,
        public ?string $retryGroup = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row A database row.
     */
    public static function fromDb(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['url'],
            (string) $row['url_hash'],
            self::nullableInt($row['post_id'] ?? null),
            (string) $row['state'],
            (int) self::toTime($row['queued_at'] ?? null),
            self::toTime($row['not_before'] ?? null),
            (int) ($row['attempts'] ?? 0),
            self::nullableInt($row['last_http'] ?? null),
            self::toTime($row['sent_at'] ?? null),
            isset($row['retry_group']) && $row['retry_group'] !== '' ? (string) $row['retry_group'] : null,
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    public static function toTime(mixed $value): ?int
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $time = strtotime($value . ' UTC');

        return $time === false ? null : $time;
    }

    public static function toDb(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'post_id' => $this->postId,
            'state' => $this->state,
            'queued_at' => self::toDb($this->queuedAt),
            'not_before' => $this->notBefore === null ? null : self::toDb($this->notBefore),
            'attempts' => $this->attempts,
            'last_http' => $this->lastHttp,
            'sent_at' => $this->sentAt === null ? null : self::toDb($this->sentAt),
            'retry_group' => $this->retryGroup,
        ];
    }
}
