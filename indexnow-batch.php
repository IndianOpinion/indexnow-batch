<?php

/**
 * Plugin Name:       IndexNow Batch
 * Plugin URI:        https://github.com/IndianOpinion/indexnow-batch
 * Description:       Queues URLs on publish and update, re-checks them at send time, and submits them to IndexNow in batches with retries and a log. Serves the key file itself.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       indexnow-batch
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Loaded twice (a must-use loader and a regular copy): the first one wins.
if (defined('INDEXNOW_BATCH_FILE')) {
    return;
}
define('INDEXNOW_BATCH_FILE', __FILE__);

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/src/functions.php';

\IndianOpinion\IndexNowBatch\Plugin::bootstrap(__FILE__);
