<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

use IndianOpinion\IndexNowBatch\Options;

/**
 * Site-wide back-off state (rules 32 and 33).
 */
final class Backoff
{
    public const BASE = 900;
    public const CAP = 21600;
    public const RETRY_AFTER_CAP = 86400;
    public const KEY_HOLD_DELAY = 3600;

    /**
     * @return array{failures: int, until: int, reason: string, key_hold: bool}
     */
    public function state(): array
    {
        $value = get_option(Options::BACKOFF, []);
        $value = is_array($value) ? $value : [];

        return [
            'failures' => (int) ($value['failures'] ?? 0),
            'until' => (int) ($value['until'] ?? 0),
            'reason' => (string) ($value['reason'] ?? ''),
            'key_hold' => (bool) ($value['key_hold'] ?? false),
        ];
    }

    /**
     * @param array{failures: int, until: int, reason: string, key_hold: bool} $state
     */
    private function save(array $state): void
    {
        update_option(Options::BACKOFF, $state, false);
    }

    /** The time sends resume, or null when not backing off. */
    public function activeUntil(int $now): ?int
    {
        $until = $this->state()['until'];

        return $until > $now ? $until : null;
    }

    public function keyHold(): bool
    {
        return $this->state()['key_hold'];
    }

    /**
     * Records a failure and returns when sends resume.
     *
     * @param int|null $retryAfter Seconds from a Retry-After header.
     */
    public function fail(int $now, string $reason, ?int $retryAfter = null): int
    {
        $state = $this->state();
        $state['failures']++;
        if ($retryAfter !== null) {
            $delay = max(0, min(self::RETRY_AFTER_CAP, $retryAfter));
        } else {
            $delay = self::delayFor($state['failures']);
        }
        $state['until'] = $now + $delay;
        $state['reason'] = $reason;
        $this->save($state);

        return $state['until'];
    }

    /** Exponential delay: 15 minutes doubling per consecutive failure, capped at 6 hours. */
    public static function delayFor(int $failures): int
    {
        $failures = max(1, $failures);
        if ($failures > 10) {
            return self::CAP;
        }

        return min(self::CAP, self::BASE * (2 ** ($failures - 1)));
    }

    public function holdForKey(string $reason): void
    {
        $state = $this->state();
        $state['key_hold'] = true;
        $state['reason'] = $reason;
        $this->save($state);
    }

    /** Ends a key hold with a one hour back-off. */
    public function releaseKeyHold(int $now): int
    {
        $state = $this->state();
        $state['key_hold'] = false;
        $state['until'] = $now + self::KEY_HOLD_DELAY;
        $state['reason'] = 'key re-verified after 403';
        $this->save($state);

        return $state['until'];
    }

    public function reset(): void
    {
        delete_option(Options::BACKOFF);
    }

    /**
     * Parses Retry-After: delta seconds or an HTTP date.
     */
    public static function parseRetryAfter(string $header, int $now): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return min(self::RETRY_AFTER_CAP, (int) $header);
        }
        $time = strtotime($header);
        if ($time === false) {
            return null;
        }

        return max(0, min(self::RETRY_AFTER_CAP, $time - $now));
    }
}
