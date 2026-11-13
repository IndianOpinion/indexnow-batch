<?php

/**
 * Public helper functions (PRD section 5.1).
 */

declare(strict_types=1);

use IndianOpinion\IndexNowBatch\Plugin;

if (!function_exists('indexnow_batch_queue_post')) {
    /**
     * Queues a post through the core rules and the site filter.
     *
     * @return bool Whether a row was added or refreshed.
     */
    function indexnow_batch_queue_post(int $post_id): bool
    {
        return Plugin::instance()->enqueuer()->queuePost($post_id)->accepted;
    }

    /**
     * Queues a raw URL through the URL rules and the site filter.
     */
    function indexnow_batch_queue_url(string $url): bool
    {
        return Plugin::instance()->enqueuer()->queueUrl($url)->accepted;
    }

    /**
     * Runs a flush now and returns the run record.
     *
     * @param array{dry_run?: bool, force?: bool, limit?: int} $args
     * @return array<string, mixed>
     */
    function indexnow_batch_flush(array $args = []): array
    {
        return Plugin::instance()->flush($args)->record;
    }

    /**
     * Suppresses post enqueue for a bulk import.
     */
    function indexnow_batch_pause(int $seconds = 3600): void
    {
        $plugin = Plugin::instance();
        $plugin->pause()->pause($seconds, $plugin->clock()->now());
    }

    /**
     * Ends a pause. Does not back-fill posts published while paused.
     */
    function indexnow_batch_resume(): void
    {
        Plugin::instance()->pause()->resume();
    }

    /**
     * The current key.
     */
    function indexnow_batch_key(): string
    {
        return Plugin::instance()->keys()->key();
    }
}
