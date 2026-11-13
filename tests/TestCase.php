<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests;

use IndianOpinion\IndexNowBatch\Logger;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Plugin;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Queue\Schema;
use IndianOpinion\IndexNowBatch\Tests\Support\FixedClock;
use IndianOpinion\IndexNowBatch\Tests\Support\MemoryLockStore;
use IndianOpinion\IndexNowBatch\Tests\Support\MemoryQueueRepository;
use WpStubs;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Plugin $plugin;
    protected MemoryQueueRepository $repo;
    protected FixedClock $clock;
    protected MemoryLockStore $locks;
    /** @var list<string> */
    protected array $logLines = [];

    protected function setUp(): void
    {
        WpStubs::reset();
        \WP_CLI::reset();
        $this->logLines = [];
        update_option('blog_public', '1');
        update_option(Options::SCHEMA, Schema::VERSION, true);

        $this->plugin = new Plugin('/plugins/indexnow-batch/indexnow-batch.php');
        $this->repo = new MemoryQueueRepository();
        $this->clock = new FixedClock();
        $this->locks = new MemoryLockStore();
        $this->plugin->useRepository($this->repo);
        $this->plugin->useClock($this->clock);
        $this->plugin->useLockStore($this->locks);
        $this->plugin->useLogger(new Logger(false, function (string $line): void {
            $this->logLines[] = $line;
        }));
        $this->plugin->boot();
        Plugin::setInstance($this->plugin);
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function post(int $id, array $fields = []): \WP_Post
    {
        $post = new \WP_Post($fields + ['ID' => $id, 'post_name' => 'post-' . $id]);
        WpStubs::$posts[$id] = $post;

        return $post;
    }

    protected function url(int $id): string
    {
        return 'https://example.com/post-' . $id . '/';
    }

    /** Fires the core transition the way wp_insert_post does. */
    protected function transition(\WP_Post $post, string $old, string $new = 'publish'): void
    {
        $post->post_status = $new;
        do_action('transition_post_status', $new, $old, $post);
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function settings(array $settings): void
    {
        add_filter('indexnow_batch_settings', static fn (array $s): array => array_merge($s, $settings));
        $this->plugin->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function flush(array $args = []): array
    {
        $this->plugin->refresh();

        return $this->plugin->flush($args)->record;
    }

    /**
     * @return list<string>
     */
    protected function pendingUrls(): array
    {
        return array_map(static fn (QueueRow $r): string => $r->url, $this->repo->inState(QueueRow::PENDING));
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastBody(): array
    {
        $last = end(WpStubs::$posts_sent);
        $this->assertNotFalse($last, 'no request was sent');

        return json_decode((string) $last['args']['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    protected function respond(int $code, string $body = '', array $headers = []): void
    {
        WpStubs::$postResponses[] = WpStubs::response($code, $body, $headers);
    }
}
