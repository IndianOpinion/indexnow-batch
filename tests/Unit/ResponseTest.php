<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Flush\Backoff;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use WpStubs;

final class ResponseTest extends TestCase
{
    private function queue(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->repo->add($this->url($i), null, $this->clock->now() + $i);
        }
    }

    public function testT32Status200MarksSent(): void
    {
        $this->queue(2);
        $this->respond(200);
        $record = $this->flush();
        $this->assertSame('submitted', $record['outcome']);
        $this->assertSame(200, $record['http']);
        $this->assertCount(2, $this->repo->inState(QueueRow::SENT));
        $this->assertSame(0, $this->repo->countPending());
        $this->assertSame(1, did_action('indexnow_batch_submitted'));
    }

    public function testT32Status202MarksSentAndNotesIt(): void
    {
        $this->queue(1);
        $this->respond(202);
        $record = $this->flush();
        $this->assertSame('accepted_pending', $record['outcome']);
        $this->assertArrayHasKey('note', $record);
        $this->assertCount(1, $this->repo->inState(QueueRow::SENT));
    }

    public function testT32Status400CountsAnAttempt(): void
    {
        $this->queue(1);
        $this->respond(400, 'bad request');
        $record = $this->flush();
        $this->assertSame('refused', $record['outcome']);
        $this->assertSame('bad request', $record['error']);
        $row = $this->repo->inState(QueueRow::PENDING)[0];
        $this->assertSame(1, $row->attempts);
        $this->assertSame(400, $row->lastHttp);
        $this->assertNull($this->plugin->backoff()->activeUntil($this->clock->now()));
    }

    public function testT32Status403HoldsUntilReverifiedThenBacksOffOneHour(): void
    {
        $this->queue(1);
        $this->respond(403);
        $record = $this->flush();
        $this->assertSame('key_unverified', $record['outcome']);
        $this->assertSame(0, $this->repo->inState(QueueRow::PENDING)[0]->attempts);
        $this->assertTrue($this->plugin->backoff()->keyHold());
        $this->assertNull($this->plugin->verifier()->cached());

        WpStubs::$getResponder = static fn () => WpStubs::response(404);
        $this->assertSame('key_unverified', $this->flush()['outcome']);

        WpStubs::$getResponder = null;
        $record = $this->flush();
        $this->assertSame('backing_off', $record['outcome']);
        $this->assertSame($this->clock->now() + 3600, $this->plugin->backoff()->activeUntil($this->clock->now()));
        $this->clock->advance(3601);
        $this->assertSame('submitted', $this->flush()['outcome']);
    }

    public function testT32Status422CountsAnAttempt(): void
    {
        $this->queue(1);
        $this->respond(422);
        $this->assertSame('refused', $this->flush()['outcome']);
        $this->assertSame(1, $this->repo->inState(QueueRow::PENDING)[0]->attempts);
    }

    public function testT32Status429BacksOffKeepingRows(): void
    {
        $this->queue(2);
        $this->respond(429, '', ['retry-after' => '120']);
        $record = $this->flush();
        $this->assertSame('refused', $record['outcome']);
        $this->assertSame(2, $this->repo->countPending());
        $this->assertSame(0, $this->repo->inState(QueueRow::PENDING)[0]->attempts);
        $this->assertSame($this->clock->now() + 120, $this->plugin->backoff()->activeUntil($this->clock->now()));
        $this->assertSame('backing_off', $this->flush()['outcome']);
    }

    public function testT32Status5xxAndTransportErrorBackOff(): void
    {
        $this->queue(1);
        $this->respond(503);
        $this->assertSame('refused', $this->flush()['outcome']);
        $this->assertSame(0, $this->repo->inState(QueueRow::PENDING)[0]->attempts);

        $this->clock->advance(Backoff::BASE);
        WpStubs::$postResponses[] = new \WP_Error('http_request_failed', 'Operation timed out');
        $record = $this->flush();
        $this->assertSame('transport_error', $record['outcome']);
        $this->assertSame('Operation timed out', $record['error']);
        $this->assertSame(1, $this->repo->countPending());
        $this->assertSame($this->clock->now() + 2 * Backoff::BASE, $this->plugin->backoff()->activeUntil($this->clock->now()));
    }

    public function testT32UnexpectedCodeCountsAnAttempt(): void
    {
        $this->queue(1);
        $this->respond(418);
        $this->assertSame('refused', $this->flush()['outcome']);
        $this->assertSame(1, $this->repo->inState(QueueRow::PENDING)[0]->attempts);
    }

    public function testT33ExponentialBackoffResetsOn2xxAndHonoursRetryAfter(): void
    {
        $this->assertSame([900, 1800, 3600, 7200, 14400, 21600, 21600], array_map(
            [Backoff::class, 'delayFor'],
            [1, 2, 3, 4, 5, 6, 30]
        ));
        $this->queue(1);
        $expected = [900, 1800, 3600];
        foreach ($expected as $delay) {
            $this->respond(500);
            $this->flush();
            $until = $this->plugin->backoff()->activeUntil($this->clock->now());
            $this->assertSame($this->clock->now() + $delay, $until);
            $this->clock->time = (int) $until;
        }
        $this->respond(200);
        $this->assertSame('submitted', $this->flush()['outcome']);
        $this->assertSame(0, $this->plugin->backoff()->state()['failures']);

        $now = $this->clock->now();
        $this->assertSame(3600, Backoff::parseRetryAfter('3600', $now));
        $this->assertSame(86400, Backoff::parseRetryAfter('999999', $now));
        $this->assertSame(600, Backoff::parseRetryAfter(gmdate('D, d M Y H:i:s', $now + 600) . ' GMT', $now));
        $this->assertSame(86400, Backoff::parseRetryAfter(gmdate('D, d M Y H:i:s', $now + 7 * 86400) . ' GMT', $now));
        $this->assertNull(Backoff::parseRetryAfter('soon', $now));
    }

    public function testT34RowRefusedFiveTimesIsRemovedAsGaveUp(): void
    {
        $this->queue(1);
        $record = [];
        for ($i = 0; $i < 5; $i++) {
            $this->respond(404);
            $record = $this->flush();
        }
        $this->assertSame(['gave_up' => 1], $record['dropped']);
        $this->assertSame(0, $this->repo->countPending());
    }

    public function testT35RefusedBatchOfFourIsHalvedThenFailed(): void
    {
        $this->queue(4);
        $this->respond(422);
        $this->flush();
        $this->assertCount(1, WpStubs::$posts_sent);

        // Next run: two halves of two.
        $this->respond(200);
        $this->respond(422, 'not your host');
        $record = $this->flush();
        $sizes = array_map(
            static fn (array $r): int => count(json_decode((string) $r['args']['body'], true)['urlList']),
            array_slice(WpStubs::$posts_sent, 1)
        );
        $this->assertSame([2, 2], $sizes);
        $this->assertCount(2, $this->repo->inState(QueueRow::SENT));
        $failed = $this->repo->inState(QueueRow::FAILED);
        $this->assertCount(2, $failed);
        $this->assertSame(422, $failed[0]->lastHttp);
        $this->assertSame(['failed' => 2], $record['dropped']);
        $this->assertSame(0, $this->repo->countPending());
    }

    public function testT35SingleUrlIsRetriedOnceThenFailed(): void
    {
        $this->queue(1);
        $this->respond(400);
        $this->flush();
        $this->respond(400);
        $this->flush();
        $this->assertCount(2, WpStubs::$posts_sent);
        $this->assertCount(1, $this->repo->inState(QueueRow::FAILED));
        $this->assertSame('empty', $this->flush()['outcome']);
    }

    public function testT36RedirectIsNotFollowedAndCountsAsUnexpected(): void
    {
        $this->queue(1);
        $this->respond(301, '', ['location' => 'https://elsewhere.example/']);
        $record = $this->flush();
        $this->assertSame('refused', $record['outcome']);
        $this->assertSame(301, $record['http']);
        $this->assertCount(1, WpStubs::$posts_sent);
        $this->assertSame(0, WpStubs::$posts_sent[0]['args']['redirection']);
        $this->assertSame(1, $this->repo->inState(QueueRow::PENDING)[0]->attempts);
    }
}
