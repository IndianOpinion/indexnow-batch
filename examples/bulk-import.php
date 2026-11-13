<?php

/**
 * Example: pause queueing around a bulk import, from a WP-CLI eval-file.
 *
 *     wp eval-file examples/bulk-import.php
 *
 * Posts published while paused are not queued, and resuming does not
 * back-fill them: old content reaches search engines through the sitemap.
 */

declare(strict_types=1);

if (!function_exists('indexnow_batch_pause')) {
    fwrite(STDERR, "indexnow-batch is not active.\n");
    exit(1);
}

indexnow_batch_pause(2 * HOUR_IN_SECONDS);

try {
    for ($i = 1; $i <= 3; $i++) {
        wp_insert_post([
            'post_title' => 'Imported story ' . $i,
            'post_status' => 'publish',
            'post_content' => 'Archive copy.',
        ]);
    }
} finally {
    indexnow_batch_resume();
}

// Announce one page by hand, then show what the next run would send.
indexnow_batch_queue_url(home_url('/archive/'));
$record = indexnow_batch_flush(['dry_run' => true]);
printf("Dry run: %s, %d URL(s) would be sent.\n", $record['outcome'], $record['n_urls']);
