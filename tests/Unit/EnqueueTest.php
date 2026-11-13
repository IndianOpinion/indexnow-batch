<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use IndianOpinion\IndexNowBatch\Url\UrlRules;
use WpStubs;

final class EnqueueTest extends TestCase
{
    public function testT10DraftToPublishQueuesThePermalink(): void
    {
        $post = $this->post(10, ['post_status' => 'draft']);
        $this->transition($post, 'draft');
        $this->assertSame([$this->url(10)], $this->pendingUrls());
        $this->assertSame(10, $this->repo->inState(QueueRow::PENDING)[0]->postId);
        $this->assertSame(1, did_action('indexnow_batch_queued'));
    }

    public function testT10ScheduledPostGoingLiveQueues(): void
    {
        $post = $this->post(11, ['post_status' => 'future']);
        $this->transition($post, 'future');
        $this->assertSame([$this->url(11)], $this->pendingUrls());
    }

    public function testT11UpdateQueuesAndTwoSavesLeaveOneRow(): void
    {
        $post = $this->post(12);
        $this->transition($post, 'publish');
        $this->transition($post, 'publish');
        $this->assertSame([$this->url(12)], $this->pendingUrls());
        $this->assertSame(1, $this->repo->writes, 'the second save in the same request does not touch the database');

        // A save in a later request refreshes the same row.
        $this->plugin->refresh();
        $this->transition($post, 'publish');
        $this->assertCount(1, $this->pendingUrls());
    }

    public function testT12SlugChangeQueuesTheNewUrl(): void
    {
        $post = $this->post(13, ['post_name' => 'old-slug']);
        $post->post_name = 'new-slug';
        $this->transition($post, 'publish');
        $this->assertSame(['https://example.com/new-slug/'], $this->pendingUrls());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1?: array<string, mixed>}>
     */
    public static function excluded(): array
    {
        return [
            'draft' => [['post_status' => 'draft']],
            'pending' => [['post_status' => 'pending']],
            'private' => [['post_status' => 'private']],
            'future' => [['post_status' => 'future']],
            'trash' => [['post_status' => 'trash']],
            'auto-draft' => [['post_status' => 'auto-draft']],
            'inherit' => [['post_status' => 'inherit']],
            'password' => [['post_password' => 'secret']],
            'revision' => [['post_type' => 'revision', 'post_parent' => 1]],
            'autosave' => [['post_type' => 'revision', 'post_name' => '1-autosave-v1', 'post_parent' => 1]],
            'nav menu item' => [['post_type' => 'nav_menu_item']],
            'attachment (default off)' => [['post_type' => 'attachment', 'post_status' => 'inherit']],
            'non-viewable type' => [['post_type' => 'secret_thing']],
            'type not in the list' => [['post_type' => 'product']],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @dataProvider excluded
     */
    public function testT13ExclusionsLeaveTheQueueEmpty(array $fields): void
    {
        $post = $this->post(20, $fields);
        // queue_post goes through the same rules as the hook.
        $this->assertFalse(indexnow_batch_queue_post(20));
        do_action('transition_post_status', 'publish', 'draft', $post);
        $this->assertSame([], $this->pendingUrls());
        $this->assertSame([], $this->logLines, 'exclusions are silent');
    }

    public function testT13AttachmentsQueueWhenTheSettingIsOn(): void
    {
        $this->settings(['include_attachments' => true]);
        $this->post(21, ['post_type' => 'attachment', 'post_status' => 'inherit']);
        do_action('add_attachment', 21);
        $this->assertSame([$this->url(21)], $this->pendingUrls());
    }

    public function testT14PublishToDraftQueuesNothing(): void
    {
        $post = $this->post(30);
        $this->transition($post, 'publish', 'draft');
        $this->assertSame([], $this->pendingUrls());
    }

    public function testT15FilterFalseKeepsTheUrlOut(): void
    {
        add_filter('indexnow_batch_should_submit', static fn (bool $ok, string $url, $post, string $context) => $context !== 'enqueue', 10, 4);
        $this->transition($this->post(31), 'draft');
        $this->assertSame([], $this->pendingUrls());
    }

    public function testT15ThrowingFilterKeepsTheUrlOutAndLogsOnce(): void
    {
        add_filter('indexnow_batch_should_submit', static function (): bool {
            throw new \RuntimeException('rule exploded');
        });
        $this->transition($this->post(32), 'draft');
        $this->transition($this->post(33), 'draft');
        $this->assertSame([], $this->pendingUrls());
        $this->assertCount(1, $this->logLines);
        $this->assertStringContainsString('rule exploded', $this->logLines[0]);
    }

    public function testT16QueuePostAndQueueUrlFollowTheSameRules(): void
    {
        $seen = [];
        add_filter('indexnow_batch_should_submit', static function (bool $ok, string $url, $post) use (&$seen): bool {
            $seen[] = [$url, $post];

            return $ok;
        }, 10, 3);
        $this->post(40);
        $this->assertTrue(indexnow_batch_queue_post(40));
        $this->assertTrue(indexnow_batch_queue_url('https://example.com/raw/'));
        $this->assertFalse(indexnow_batch_queue_url('https://other.example/raw/'));
        $this->assertFalse(indexnow_batch_queue_post(999));
        $this->assertInstanceOf(\WP_Post::class, $seen[0][1]);
        $this->assertSame(['https://example.com/raw/', null], $seen[1]);
        $this->assertNull($this->repo->inState(QueueRow::PENDING)[1]->postId);
    }

    public function testT18EnqueueMakesNoHttpRequestAndOneWrite(): void
    {
        $this->transition($this->post(41), 'draft');
        $this->assertSame([], WpStubs::$posts_sent);
        $this->assertSame([], WpStubs::$gets);
        $this->assertSame(1, $this->repo->writes);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'foreign host' => ['https://other.example/a/'],
            'www mismatch' => ['https://www.example.com/a/'],
            'fragment' => ['https://example.com/a/#top'],
            'ftp scheme' => ['ftp://example.com/a/'],
            'relative' => ['/a/'],
            'over-long' => ['https://example.com/' . str_repeat('a', 2050)],
            'credentials' => ['https://user:pw@example.com/a/'],
            'other port' => ['https://example.com:8443/a/'],
        ];
    }

    /**
     * @dataProvider refusedUrls
     */
    public function testT19RefusedUrls(string $url): void
    {
        $outcome = $this->plugin->enqueuer()->queueUrl($url);
        $this->assertFalse($outcome->accepted);
        $this->assertSame('foreign', $outcome->reason);
    }

    public function testT19UrlOutsideTheHomePathIsRefused(): void
    {
        WpStubs::$home = 'https://example.com/blog/';
        $this->plugin->refresh();
        $this->assertFalse(indexnow_batch_queue_url('https://example.com/other/'));
        $this->assertFalse(indexnow_batch_queue_url('https://example.com/blogger/'));
        $this->assertTrue(indexnow_batch_queue_url('https://example.com/blog/post/'));
    }

    public function testT20VariantsMakeOneRowAndNonLatinSlugIsKept(): void
    {
        $slug = 'https://example.com/%e0%a4%ad%e0%a4%be%e0%a4%b0%e0%a4%a4/';
        $this->assertTrue(indexnow_batch_queue_url($slug));
        $this->plugin->refresh();
        $this->assertTrue(indexnow_batch_queue_url('HTTPS://EXAMPLE.COM/%E0%A4%AD%E0%A4%BE%E0%A4%B0%E0%A4%A4/'));
        $this->assertSame([$slug], $this->pendingUrls());
        $this->assertSame(
            'https://example.com/%E0%A4%AD%E0%A4%BE%E0%A4%B0%E0%A4%A4/',
            UrlRules::dedupeKey($slug)
        );
        $this->assertNotSame(UrlRules::hash('https://example.com/A/'), UrlRules::hash('https://example.com/a/'));
    }

    public function testT21RecentlySentUrlGetsANotBefore(): void
    {
        $now = $this->clock->now();
        $this->repo->add($this->url(50), 50, $now - 3600, QueueRow::SENT, $now - 600);
        $this->post(50);
        $outcome = $this->plugin->enqueuer()->queuePost(50);
        $this->assertSame($now - 600 + 3600, $outcome->notBefore);
        $this->assertSame(0, $this->repo->countDue($now + 60));
        $this->assertSame(1, $this->repo->countDue($now + 3000 + 60));

        $record = $this->flush();
        $this->assertSame('empty', $record['outcome']);
        $this->assertSame([], WpStubs::$posts_sent);
    }

    public function testT31PauseSuppressesEnqueueUntilExpiryAndResumeDoesNotBackfill(): void
    {
        indexnow_batch_pause(600);
        $this->transition($this->post(60), 'draft');
        $this->assertSame([], $this->pendingUrls());
        indexnow_batch_resume();
        $this->assertSame([], $this->pendingUrls(), 'resume does not back-fill');
        $this->plugin->refresh();
        $this->transition($this->post(61), 'draft');
        $this->assertSame([$this->url(61)], $this->pendingUrls());

        indexnow_batch_pause(600);
        $this->clock->advance(601);
        $this->plugin->refresh();
        $this->transition($this->post(62), 'draft');
        $this->assertCount(2, $this->pendingUrls());
    }

    public function testT39EnqueueAtTheCapEvictsTheOldestAndCountsIt(): void
    {
        $this->settings(['queue_cap' => 100]);
        for ($i = 1; $i <= 100; $i++) {
            $this->repo->add('https://example.com/old-' . $i . '/', null, $this->clock->now() - 1000 + $i);
        }
        $this->assertTrue(indexnow_batch_queue_url('https://example.com/newest/'));
        $urls = $this->pendingUrls();
        $this->assertCount(100, $urls);
        $this->assertNotContains('https://example.com/old-1/', $urls);
        $this->assertContains('https://example.com/newest/', $urls);
        $this->assertSame(1, get_option(Options::EVICTED));

        update_option('blog_public', '0');
        $record = $this->flush();
        $this->assertSame(['evicted' => 1], $record['dropped']);
        $this->assertFalse(get_option(Options::EVICTED));
    }

    public function testQueueStillAcceptsWhileTheSiteIsNotPublic(): void
    {
        update_option('blog_public', '0');
        $this->transition($this->post(63), 'draft');
        $this->assertSame([$this->url(63)], $this->pendingUrls());
    }

    public function testT53DatabaseFailureLogsAndDoesNotStopTheSave(): void
    {
        $this->repo->failNext = true;
        $this->transition($this->post(70), 'draft');
        $this->assertSame([], $this->pendingUrls());
        $this->assertStringContainsString('enqueue failed', $this->logLines[0]);

        // Even an exception from storage is contained.
        $this->plugin->useRepository(new class extends \IndianOpinion\IndexNowBatch\Tests\Support\MemoryQueueRepository {
            public function enqueue(string $url, string $hash, ?int $postId, int $now, int $resubmitSeconds, int $cap): \IndianOpinion\IndexNowBatch\Queue\EnqueueResult
            {
                throw new \RuntimeException('MySQL server has gone away');
            }
        });
        $this->transition($this->post(71), 'draft');
        $this->assertStringContainsString('gone away', end($this->logLines) ?: '');
    }

    public function testSchemaNotReadyRefusesToQueue(): void
    {
        delete_option(Options::SCHEMA);
        $this->transition($this->post(72), 'draft');
        $this->assertSame([], $this->pendingUrls());
        $this->assertStringContainsString('not ready', $this->logLines[0]);
    }

    public function testArchivesAreOffByDefaultAndOnBehindAFilter(): void
    {
        WpStubs::$categories[80] = [(object) ['slug' => 'news']];
        $this->transition($this->post(80), 'draft');
        $this->assertSame([$this->url(80)], $this->pendingUrls());

        add_filter('indexnow_batch_include_archives', '__return_true');
        $this->plugin->refresh();
        $this->transition($this->post(81), 'draft');
        WpStubs::$categories[81] = [(object) ['slug' => 'news']];
        $this->plugin->refresh();
        $this->transition($this->post(81), 'publish');
        $this->assertContains('https://example.com/category/news/', $this->pendingUrls());
        $this->assertContains('https://example.com/author/user1/', $this->pendingUrls());
    }
}
