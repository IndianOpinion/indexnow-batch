<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * The recurring WP-Cron event that runs the flush (rule 22).
 */
final class Scheduler
{
    /**
     * cron_schedules filter callback body.
     *
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public static function addSchedule(array $schedules, int $minutes): array
    {
        $schedules[Options::CRON_SCHEDULE] = [
            'interval' => $minutes * 60,
            'display' => sprintf('IndexNow Batch, every %d minutes', $minutes),
        ];

        return $schedules;
    }

    /**
     * Schedules the event, or reschedules it when the interval changed.
     */
    public function ensure(int $minutes, int $now): void
    {
        $event = wp_get_scheduled_event(Options::CRON_HOOK);
        if (
            $event !== false
            && $event->schedule === Options::CRON_SCHEDULE
            && (int) ($event->interval ?? 0) === $minutes * 60
        ) {
            return;
        }
        if ($event !== false) {
            wp_clear_scheduled_hook(Options::CRON_HOOK);
        }
        wp_schedule_event($now + 60, Options::CRON_SCHEDULE, Options::CRON_HOOK);
    }

    public function clear(): void
    {
        wp_clear_scheduled_hook(Options::CRON_HOOK);
    }

    public function next(): ?int
    {
        $next = wp_next_scheduled(Options::CRON_HOOK);

        return $next === false ? null : (int) $next;
    }
}
