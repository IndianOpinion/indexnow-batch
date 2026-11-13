<?php

/**
 * Removes the table, every indexnow_batch_ option, the schedule and the
 * physical key file the plugin wrote, on every site. Leaves any log file.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

global $wpdb;
(new \IndianOpinion\IndexNowBatch\Uninstaller($wpdb))->run();
