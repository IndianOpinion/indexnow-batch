<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

use IndianOpinion\IndexNowBatch\Clock;
use IndianOpinion\IndexNowBatch\Logger;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Pause;
use IndianOpinion\IndexNowBatch\Settings;
use IndianOpinion\IndexNowBatch\SiteFilter;
use IndianOpinion\IndexNowBatch\Url\UrlRules;

/**
 * Puts URLs in the queue (rules 10 to 21). Never makes an HTTP request and
 * never throws into the save path.
 */
final class Enqueuer
{
    /** @var array<string, true> Hashes queued during this request. */
    private array $seen = [];

    /**
     * @param \Closure(): bool $schemaReady
     */
    public function __construct(
        private Settings $settings,
        private QueueRepository $repo,
        private UrlRules $rules,
        private Eligibility $eligibility,
        private Pause $pause,
        private Clock $clock,
        private Logger $logger,
        private \Closure $schemaReady,
    ) {
    }

    /**
     * transition_post_status handler. Queues on any move into `publish`,
     * including publish to publish (an update).
     */
    public function onTransition(string $new, string $old, \WP_Post $post): void
    {
        if ($new !== 'publish') {
            return;
        }
        $this->guarded(function () use ($post): void {
            $this->queuePostObject($post);
        });
    }

    /**
     * add_attachment and edit_attachment handler, when attachments are on.
     */
    public function onAttachment(int $postId): void
    {
        if (!$this->settings->includeAttachments()) {
            return;
        }
        $this->guarded(function () use ($postId): void {
            $this->queuePost($postId);
        });
    }

    private function guarded(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            // Publishing matters more than pinging: never break the save.
            $this->logger->error('enqueue failed: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function queuePost(int $postId): EnqueueOutcome
    {
        $post = $postId > 0 ? get_post($postId) : null;
        if (!$post instanceof \WP_Post) {
            return EnqueueOutcome::refused('gone');
        }

        return $this->queuePostObject($post);
    }

    private function queuePostObject(\WP_Post $post): EnqueueOutcome
    {
        if ($this->pause->activeUntil($this->clock->now()) !== null) {
            return EnqueueOutcome::refused('paused');
        }
        $reason = $this->eligibility->check($post);
        if ($reason !== null) {
            return EnqueueOutcome::refused($reason);
        }
        // get_permalink() can return false through filters.
        $url = (string) get_permalink($post);
        if ($url === '') {
            return EnqueueOutcome::refused('gone');
        }
        $outcome = $this->queueChecked($url, $post);
        if ($outcome->accepted) {
            $this->queueRelated($post);
        }

        return $outcome;
    }

    public function queueUrl(string $url): EnqueueOutcome
    {
        return $this->queueChecked(trim($url), null);
    }

    private function queueChecked(string $url, ?\WP_Post $post): EnqueueOutcome
    {
        $reason = $this->rules->check($url);
        if ($reason !== null) {
            return EnqueueOutcome::refused($reason, $url);
        }
        [$allowed, $error] = SiteFilter::allows($url, $post, SiteFilter::ENQUEUE);
        if ($error !== '') {
            $this->logger->errorOnce('filter_enqueue', $error);
        }
        if (!$allowed) {
            return EnqueueOutcome::refused('filter', $url);
        }
        if (!($this->schemaReady)()) {
            $this->logger->errorOnce('schema', 'queue table is not ready; run `wp indexnow status` for details');

            return EnqueueOutcome::refused('schema', $url);
        }

        $hash = UrlRules::hash($url);
        $postId = $post instanceof \WP_Post ? (int) $post->ID : null;
        if (isset($this->seen[$hash . ':' . (string) $postId])) {
            return new EnqueueOutcome(true, '', $url);
        }

        $result = $this->repo->enqueue(
            $url,
            $hash,
            $postId,
            $this->clock->now(),
            $this->settings->resubmitInterval() * 60,
            $this->settings->queueCap()
        );
        if (!$result->ok()) {
            $this->logger->error('enqueue failed for ' . $url . ': ' . $result->error);

            return EnqueueOutcome::refused('db_error', $url);
        }
        $this->seen[$hash . ':' . (string) $postId] = true;
        if ($result->evicted > 0) {
            update_option(Options::EVICTED, (int) get_option(Options::EVICTED, 0) + $result->evicted, false);
        }
        do_action('indexnow_batch_queued', $url, $postId);

        return new EnqueueOutcome(true, '', $url, $result->notBefore);
    }

    /**
     * Category and author archives, off by default (open question 4).
     */
    private function queueRelated(\WP_Post $post): void
    {
        $urls = [];
        if (apply_filters('indexnow_batch_include_archives', false, $post) === true) {
            if (is_object_in_taxonomy($post->post_type, 'category')) {
                foreach (get_the_category($post->ID) as $term) {
                    $link = get_category_link($term);
                    if ($link !== '') {
                        $urls[] = $link;
                    }
                }
            }
            $author = get_author_posts_url((int) $post->post_author);
            if ($author !== '') {
                $urls[] = $author;
            }
        }
        $urls = apply_filters('indexnow_batch_related_urls', $urls, $post);
        if (!is_array($urls)) {
            return;
        }
        foreach (array_unique(array_filter($urls, 'is_string')) as $url) {
            $this->queueChecked($url, null);
        }
    }
}
