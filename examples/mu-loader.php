<?php

/**
 * Must-use loader for indexnow-batch.
 *
 * Copy this file to wp-content/mu-plugins/indexnow-batch-loader.php and put
 * the plugin directory at wp-content/mu-plugins/indexnow-batch/. Activation
 * hooks do not run for must-use plugins; the plugin creates its table, key
 * and schedule on the first `init` instead.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once WPMU_PLUGIN_DIR . '/indexnow-batch/indexnow-batch.php';
