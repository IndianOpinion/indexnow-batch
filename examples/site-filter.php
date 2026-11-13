<?php

/**
 * Example must-use plugin: the site's own index rules.
 *
 * Drop into wp-content/mu-plugins/. The plugin calls this filter when a URL
 * is queued and again just before it is sent, with post and meta caches
 * already primed for the batch, so reading post meta here is cheap.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_filter(
    'indexnow_batch_should_submit',
    static function (bool $submit, string $url, ?WP_Post $post, string $context): bool {
        if (!$submit || $post === null) {
            return $submit;
        }

        // Yoast SEO stores a per-post noindex flag as '1'.
        if (get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
            return false;
        }

        // Rank Math stores robots directives as an array.
        $robots = get_post_meta($post->ID, 'rank_math_robots', true);
        if (is_array($robots) && in_array('noindex', $robots, true)) {
            return false;
        }

        // A site-specific marker, for example a withdrawn story.
        if (get_post_meta($post->ID, 'withdrawn', true) !== '') {
            return false;
        }

        return true;
    },
    10,
    4
);

// Set-based rule: one query over the whole batch instead of one per URL.
add_filter(
    'indexnow_batch_urls',
    static function (array $urls): array {
        $blocked = (array) get_option('example_blocked_urls', []);

        return array_values(array_diff($urls, $blocked));
    }
);
