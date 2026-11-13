<?php

/**
 * Example must-use plugin: ship run records to your own metrics.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('indexnow_batch_flushed', static function (array $record): void {
    error_log(sprintf(
        'indexnow outcome=%s sent=%d queue=%d dropped=%s',
        $record['outcome'],
        $record['sent'],
        $record['queue'],
        (string) wp_json_encode($record['dropped'])
    ));
});

add_action('indexnow_batch_dropped', static function (string $url, string $reason): void {
    if ($reason === 'filter') {
        error_log('indexnow kept back ' . $url);
    }
}, 10, 2);
