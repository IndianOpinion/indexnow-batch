<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * Calls `indexnow_batch_should_submit`. A throwing callback refuses the URL
 * (fail closed for that URL) and reports the message.
 */
final class SiteFilter
{
    public const ENQUEUE = 'enqueue';
    public const FLUSH = 'flush';

    /**
     * @return array{0: bool, 1: string} Whether the URL may go, and an error message if the filter threw.
     */
    public static function allows(string $url, ?\WP_Post $post, string $context): array
    {
        try {
            $result = apply_filters('indexnow_batch_should_submit', true, $url, $post, $context);
        } catch (\Throwable $e) {
            return [false, 'indexnow_batch_should_submit threw ' . get_class($e) . ': ' . $e->getMessage()];
        }

        return [$result === true || $result === 1 || $result === '1', ''];
    }
}
