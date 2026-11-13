<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Flush\Lock;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use WpStubs;

final class FlushTest extends TestCase
{
    public function testT14UnpublishedPostIsDroppedAtFlushWithStatus(): void
    {
        $post = $this->post(1);
        $this->transition($post, 'draft');
        $this->transition($post, 'publish', 'draft');
        $record = $this->flush();
        $this->assertSame('nothing_submittable', $record['outcome']);
        $this->assertSame(['status' => 1], $record['dropped']);
        $this->assertSame(1, did_action('indexnow_batch_dropped'));
    }

    public function testT17MarkerWrittenAfterPublishDropsAtFlush(): void
    {
        add_filter('indexnow_batch_should_submit', static function (bool $ok, string $url, $post): bool {
            return $ok && !($post instanceof \WP_Post && get_post_meta($post->ID, 'noindex', true) === '1');
        }, 10, 3);
        $this->transition($this->post(2), 'draft');
        WpStubs::$meta[2]['noindex'] = '1';
        $record = $this->flush();
        $this->assertSame(['filter' => 1], $record['dropped']);
        $this->assertSame([], WpStubs::$posts_sent);
    }

    public function testT22EventScheduledAtTheIntervalAndCronRunsTheSamePath(): void
    {
        $this->settings(['interval' => 20]);
        $this->plugin->installer()->installSite();
        $event = wp_get_scheduled_event(Options::CRON_HOOK);
        $this->assertNotFalse($event);
        $this->assertSame(1200, $event->interval);

        $this->settings(['interval' => 30]);
        $this->plugin->onSettingsChanged();
        $this->assertSame(1800, wp_get_scheduled_event(Options::CRON_HOOK)->interval);

        $this->repo->add($this->url(3), null, $this->clock->now());
        do_action(Options::CRON_HOOK);
        $this->assertSame('submitted', $this->plugin->runLog()->last()['outcome']);
    }

    public function testT22IntervalIsClampedToFiveMinutes(): void
    {
        $this->settings(['interval' => 1]);
        $this->assertSame(5, $this->plugin->settings()->interval());
    }

    public function testT23ConcurrentFlushLogsLockedAndExpiredLockIsTakenOver(): void
    {
        $other = new Lock($this->locks);
        $this->assertTrue($other->acquire($this->clock->now()));
        $this->repo->add($this->url(4), null, $this->clock->now());
        $this->assertSame('locked', $this->flush()['outcome']);
        $this->assertSame([], WpStubs::$posts_sent);

        $this->clock->advance(Lock::TTL);
        $this->assertSame('submitted', $this->flush()['outcome']);
        $this->assertSame([], $this->locks->rows, 'the lock is released after the run');
    }

    public function testT24NotPublicLogsTheCountAndKeepsTheQueue(): void
    {
        update_option('blog_public', '0');
        $this->repo->add($this->url(5), null, $this->clock->now());
        $this->repo->add($this->url(6), null, $this->clock->now());
        $record = $this->flush();
        $this->assertSame('skipped_not_public', $record['outcome']);
        $this->assertSame(2, $record['queue']);
        $this->assertSame(2, $this->repo->countPending());
        $this->assertSame([], WpStubs::$posts_sent);
    }

    public function testT24ForceNeverBypassesTheGates(): void
    {
        update_option('blog_public', '0');
        $this->repo->add($this->url(5), null, $this->clock->now());
        $this->assertSame('skipped_not_public', $this->flush(['force' => true])['outcome']);
    }

    public function testT25StagingSkipsUntilAllowed(): void
    {
        WpStubs::$environment = 'staging';
        $this->repo->add($this->url(7), null, $this->clock->now());
        $this->assertSame('skipped_environment', $this->flush()['outcome']);
        $this->settings(['environments' => ['production', 'staging']]);
        $this->assertSame('submitted', $this->flush()['outcome']);
    }

    public function testDisabledConstantHoldsSends(): void
    {
        $this->settings(['disabled' => true]);
        $this->repo->add($this->url(7), null, $this->clock->now());
        $this->assertSame('disabled', $this->flush()['outcome']);
        $this->assertSame(1, $this->repo->countPending());
    }

    public function testT26OnlyGoodUrlsGoAndEachReasonIsCounted(): void
    {
        $now = $this->clock->now();
        $this->post(10);
        $this->post(11, ['post_status' => 'private']);
        $this->post(12, ['post_password' => 'pw']);
        $this->post(13);
        $this->post(14);
        foreach ([10, 11, 12, 13, 14, 99] as $id) {
            $this->repo->add($this->url($id), $id, $now);
        }
        add_filter('indexnow_batch_should_submit', fn (bool $ok, string $url) => $url !== $this->url(13), 10, 2);
        $record = $this->flush();
        $this->assertSame('submitted', $record['outcome']);
        $this->assertSame([$this->url(10), $this->url(14)], $this->lastBody()['urlList']);
        $dropped = $record['dropped'];
        ksort($dropped);
        $this->assertSame(['filter' => 1, 'gone' => 1, 'password' => 1, 'status' => 1], $dropped);
        $this->assertSame(1, WpStubs::$primeCalls, 'post and meta caches primed once for the batch');
        $this->assertSame(0, $this->repo->countPending());
    }

    public function testT27RawUrlResolvingToAPostAppliesPostChecks(): void
    {
        $now = $this->clock->now();
        $this->post(20, ['post_status' => 'private']);
        WpStubs::$urlToPost[$this->url(20)] = 20;
        $this->repo->add($this->url(20), null, $now);
        $this->repo->add('https://example.com/category/news/', null, $now);
        $seen = [];
        add_filter('indexnow_batch_should_submit', static function (bool $ok, string $url, $post) use (&$seen): bool {
            $seen[$url] = $post;

            return $ok;
        }, 10, 3);
        $record = $this->flush();
        $this->assertSame(['status' => 1], $record['dropped']);
        $this->assertSame(['https://example.com/category/news/'], $this->lastBody()['urlList']);
        $this->assertArrayHasKey('https://example.com/category/news/', $seen);
        $this->assertNull($seen['https://example.com/category/news/']);
    }

    public function testT27RawUrlResolvingToADifferentPermalinkIsDropped(): void
    {
        $this->post(21);
        WpStubs::$urlToPost['https://example.com/?p=21'] = 21;
        $this->repo->add('https://example.com/?p=21', null, $this->clock->now());
        $record = $this->flush();
        $this->assertSame(['ambiguous' => 1], $record['dropped']);
    }

    public function testT28AllDroppedLogsNothingSubmittableAndLeavesNoRows(): void
    {
        $this->repo->add($this->url(30), 30, $this->clock->now());
        $this->repo->add('https://other.example/x/', null, $this->clock->now());
        $record = $this->flush();
        $this->assertSame('nothing_submittable', $record['outcome']);
        $this->assertSame(['gone' => 1, 'foreign' => 1], $record['dropped']);
        $this->assertSame(0, $this->repo->countPending());
        $this->assertSame('empty', $this->flush()['outcome']);
    }

    public function testT29BatchesOf1000And500(): void
    {
        for ($i = 0; $i < 2500; $i++) {
            $this->repo->add('https://example.com/p' . $i . '/', null, $this->clock->now());
        }
        $record = $this->flush();
        $sizes = array_map(
            static fn (array $r): int => count(json_decode((string) $r['args']['body'], true)['urlList']),
            WpStubs::$posts_sent
        );
        $this->assertSame([1000, 1000, 500], $sizes);
        $this->assertSame(3, $record['batches']);
        $this->assertSame(2500, $record['sent']);
        $this->assertSame(2500, $record['n_urls']);
        $this->assertCount(50, $record['urls']);
    }

    public function testT29RunSendsAtMostTheConfiguredBatches(): void
    {
        $this->settings(['batch_size' => 2, 'batches_per_run' => 2]);
        for ($i = 0; $i < 7; $i++) {
            $this->repo->add('https://example.com/p' . $i . '/', null, $this->clock->now());
        }
        $this->flush();
        $this->assertCount(2, WpStubs::$posts_sent);
        $this->assertSame(3, $this->repo->countPending());
    }

    public function testT29BatchSizeOver10000IsClampedAndBodyIsTrimmed(): void
    {
        $this->settings(['batch_size' => 50000]);
        $this->assertSame(10000, $this->plugin->settings()->batchSize());
        $kept = \IndianOpinion\IndexNowBatch\Flush\Submitter::fit('example.com', 'k', 'https://example.com/k.txt', ['https://example.com/a/', 'https://example.com/b/'], 115);
        $this->assertSame(['https://example.com/a/'], $kept);
    }

    public function testT30RequestShape(): void
    {
        $this->repo->add($this->url(40), null, $this->clock->now());
        $this->flush();
        $request = WpStubs::$posts_sent[0];
        $this->assertSame('https://api.indexnow.org/indexnow', $request['url']);
        $body = $this->lastBody();
        $this->assertSame(['host', 'key', 'keyLocation', 'urlList'], array_keys($body));
        $key = $this->plugin->keys()->key();
        $this->assertSame('example.com', $body['host']);
        $this->assertSame($key, $body['key']);
        $this->assertSame('https://example.com/' . $key . '.txt', $body['keyLocation']);
        $this->assertSame('application/json; charset=utf-8', $request['args']['headers']['Content-Type']);
        $this->assertSame(20, $request['args']['timeout']);
        $this->assertSame('POST', $request['args']['method']);
        $this->assertSame(0, $request['args']['redirection']);
        $this->assertTrue($request['args']['sslverify']);
        $this->assertSame(
            'indexnow-batch/0.1.0 (+https://github.com/IndianOpinion/indexnow-batch)',
            $request['args']['user-agent']
        );
    }

    public function testRequestArgsFilterCannotTurnOffTls(): void
    {
        add_filter('indexnow_batch_request_args', static fn (array $args): array => ['sslverify' => false] + $args);
        $this->repo->add($this->url(41), null, $this->clock->now());
        $this->flush();
        $this->assertTrue(WpStubs::$posts_sent[0]['args']['sslverify']);
    }

    public function testT37OldPendingRowsExpireEvenWhileNotPublic(): void
    {
        update_option('blog_public', '0');
        $now = $this->clock->now();
        $this->repo->add($this->url(50), null, $now - 8 * 86400);
        $this->repo->add($this->url(51), null, $now - 6 * 86400);
        $record = $this->flush();
        $this->assertSame('skipped_not_public', $record['outcome']);
        $this->assertSame(['expired' => 1], $record['dropped']);
        $this->assertSame([$this->url(51)], $this->pendingUrls());
    }

    public function testT38SentRowsExpireAfterTheIntervalPlusOneDay(): void
    {
        $now = $this->clock->now();
        $this->repo->add($this->url(52), null, $now - 90000, QueueRow::SENT, $now - (3600 + 86400) - 1);
        $this->repo->add($this->url(53), null, $now - 90000, QueueRow::SENT, $now - 3600);
        $this->flush();
        $sent = array_map(static fn (QueueRow $r): string => $r->url, $this->repo->inState(QueueRow::SENT));
        $this->assertSame([$this->url(53)], $sent);
    }

    public function testT40EveryOutcomeWritesOneRecordAndTheRingKeeps200(): void
    {
        $outcomes = [];
        $outcomes[] = $this->flush()['outcome'];
        update_option('blog_public', '0');
        $outcomes[] = $this->flush()['outcome'];
        update_option('blog_public', '1');
        $this->repo->add($this->url(60), null, $this->clock->now());
        $outcomes[] = $this->flush(['dry_run' => true])['outcome'];
        $outcomes[] = $this->flush()['outcome'];
        $this->assertSame(['empty', 'skipped_not_public', 'dry_run', 'submitted'], $outcomes);
        $this->assertCount(4, $this->plugin->runLog()->all());
        $this->assertSame(4, did_action('indexnow_batch_flushed'));

        for ($i = 0; $i < 210; $i++) {
            $this->flush();
        }
        $this->assertCount(200, $this->plugin->runLog()->all());
        $key = $this->plugin->keys()->key();
        $this->assertStringNotContainsString($key, (string) json_encode(get_option(Options::LOG)));
        $this->assertFalse(WpStubs::$autoload[1][Options::LOG]);
    }

    public function testT40KeyEchoedByTheEndpointIsMasked(): void
    {
        $key = $this->plugin->keys()->key();
        $this->repo->add($this->url(61), null, $this->clock->now());
        $this->respond(422, 'key ' . $key . ' does not match');
        $record = $this->flush();
        $this->assertStringNotContainsString($key, $record['error']);
        $this->assertStringNotContainsString($key, (string) json_encode(get_option(Options::LOG)));
    }

    public function testT41MissingLogFileKeepsRecordsWarnsAndCreatesNothing(): void
    {
        $path = sys_get_temp_dir() . '/inb-missing-' . bin2hex(random_bytes(4)) . '.log';
        $this->settings(['log_file' => $path]);
        $this->flush();
        $this->assertFileDoesNotExist($path);
        $this->assertCount(1, $this->plugin->runLog()->all());
        $this->assertStringContainsString('does not exist', implode(' ', $this->plugin->status()->collect()['warnings']));
    }

    public function testT41ExistingLogFileGetsOneJsonLinePerRun(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'inb');
        $this->settings(['log_file' => $path]);
        $this->flush();
        $this->flush();
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        unlink($path);
        $this->assertCount(2, $lines);
        $this->assertSame('empty', json_decode($lines[0], true)['outcome']);
    }

    public function testT42OldHostRowsAreDroppedAsForeign(): void
    {
        $this->repo->add('https://example.com/old/', null, $this->clock->now());
        WpStubs::$home = 'https://new.example/';
        $record = $this->flush();
        $this->assertSame(['foreign' => 1], $record['dropped']);
    }

    public function testT43HttpHomeWarns(): void
    {
        WpStubs::$home = 'http://example.com/';
        $this->plugin->refresh();
        $this->assertStringContainsString('uses http', implode(' ', $this->plugin->status()->collect()['warnings']));
    }

    public function testT44PermalinkFalseDropsAsGone(): void
    {
        $this->post(70);
        WpStubs::$permalinks[70] = false;
        $this->repo->add($this->url(70), 70, $this->clock->now());
        $this->assertSame(['gone' => 1], $this->flush()['dropped']);
    }

    public function testT45NotBefore30SecondsAheadCountsAsDue(): void
    {
        $row = $this->repo->add($this->url(71), null, $this->clock->now());
        $row->notBefore = $this->clock->now() + 30;
        $this->assertSame('submitted', $this->flush()['outcome']);
    }

    public function testT46StaleLastRunWarns(): void
    {
        $this->flush();
        $this->clock->advance(3 * 15 * 60 + 1);
        $this->assertStringContainsString('more than three intervals', implode(' ', $this->plugin->status()->collect()['warnings']));
    }

    public function testT54UrlsFilterAddingUnknownUrlsIsIgnoredWithAWarning(): void
    {
        $this->repo->add($this->url(80), null, $this->clock->now());
        $this->repo->add($this->url(81), null, $this->clock->now());
        add_filter('indexnow_batch_urls', fn (array $urls): array => [$this->url(80), 'https://example.com/injected/']);
        $record = $this->flush();
        $this->assertSame([$this->url(80)], $this->lastBody()['urlList']);
        $this->assertSame(['filter' => 1], $record['dropped']);
        $this->assertStringContainsString('not in the batch', implode(' ', $record['warnings']));
    }

    public function testDuplicateRowsAfterASlugChangeSendOnce(): void
    {
        $this->post(90, ['post_name' => 'new']);
        $this->repo->add('https://example.com/old/', 90, $this->clock->now());
        $this->repo->add('https://example.com/new/', 90, $this->clock->now());
        $record = $this->flush();
        $this->assertSame(['https://example.com/new/'], $this->lastBody()['urlList']);
        $this->assertSame(['duplicate' => 1], $record['dropped']);
    }

    public function testRecentlySentUrlIsDeferredAtFlush(): void
    {
        $now = $this->clock->now();
        $this->repo->add($this->url(91), null, $now - 7200, QueueRow::SENT, $now - 600);
        $this->repo->add($this->url(91), null, $now);
        $record = $this->flush();
        $this->assertSame('nothing_submittable', $record['outcome']);
        $this->assertSame(1, $record['deferred']);
        $this->assertSame($now - 600 + 3600, $this->repo->inState(QueueRow::PENDING)[0]->notBefore);
        $this->assertSame('submitted', $this->flush(['force' => true])['outcome']);
    }

    public function testSchemaErrorRefusesToFlush(): void
    {
        delete_option(Options::SCHEMA);
        update_option(Options::SCHEMA_ERROR, 'missing column(s): retry_group');
        $record = $this->flush();
        $this->assertSame('schema_error', $record['outcome']);
        $this->assertStringContainsString('retry_group', $record['error']);
    }

    public function testDryRunSendsAndChangesNothing(): void
    {
        $now = $this->clock->now();
        $this->repo->add($this->url(92), null, $now);
        $this->repo->add($this->url(93), 93, $now);
        $this->repo->add($this->url(94), null, $now - 9 * 86400);
        $before = serialize($this->repo->rows);
        $result = $this->plugin->flush(['dry_run' => true]);
        $this->assertSame('dry_run', $result->outcome());
        $this->assertSame([[$this->url(92)]], $result->batches);
        $this->assertEquals(['gone' => 1, 'expired' => 1], $result->record['dropped']);
        $this->assertSame($before, serialize($this->repo->rows));
        $this->assertSame([], WpStubs::$posts_sent);
        $this->assertSame([], $this->locks->rows);
    }
}
