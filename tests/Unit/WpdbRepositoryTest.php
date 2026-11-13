<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Queue\EnqueueResult;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Queue\Schema;
use IndianOpinion\IndexNowBatch\Queue\WpdbQueueRepository;
use IndianOpinion\IndexNowBatch\Url\UrlRules;
use PHPUnit\Framework\TestCase;
use WpStubs;

/**
 * The SQL the repository issues, checked against a recording $wpdb.
 */
final class WpdbRepositoryTest extends TestCase
{
    private \wpdb $db;

    protected function setUp(): void
    {
        WpStubs::reset();
        global $wpdb;
        $this->db = $wpdb;
    }

    public function testEnqueueIsOneReadAndOneUpsert(): void
    {
        $this->db->responder = static fn (string $method, string $sql) => $method === 'get_row'
            ? ['last_sent' => null, 'pending' => '3']
            : 1;
        $repo = new WpdbQueueRepository($this->db);
        $url = "https://example.com/it's/";
        $result = $repo->enqueue($url, UrlRules::hash($url), 7, 1767225600, 3600, 10000);
        $this->assertSame(EnqueueResult::ADDED, $result->status);
        $this->assertCount(2, $this->db->queries);
        $this->assertStringContainsString("FROM wp_indexnow_batch_queue WHERE site_id = 1", $this->db->queries[0]);
        $insert = $this->db->queries[1];
        $this->assertStringStartsWith('INSERT INTO wp_indexnow_batch_queue', $insert);
        $this->assertStringContainsString("'https://example.com/it\\'s/'", $insert);
        $this->assertStringContainsString(', 7, ', $insert);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE post_id = COALESCE(VALUES(post_id), post_id)', $insert);
    }

    public function testEnqueueSetsNotBeforeAndEvictsOverTheCap(): void
    {
        $now = 1767225600;
        $this->db->responder = static fn (string $method, string $sql) => $method === 'get_row'
            ? ['last_sent' => gmdate('Y-m-d H:i:s', $now - 600), 'pending' => '100']
            : 1;
        $repo = new WpdbQueueRepository($this->db);
        $result = $repo->enqueue('https://example.com/a/', 'h', null, $now, 3600, 100);
        $this->assertSame($now + 3000, $result->notBefore);
        $this->assertSame(1, $result->evicted);
        $this->assertStringContainsString("NULL, 'pending'", $this->db->queries[1]);
        $this->assertStringContainsString("'" . gmdate('Y-m-d H:i:s', $now + 3000) . "', 0)", $this->db->queries[1]);
        $this->assertStringContainsString("ORDER BY queued_at ASC, id ASC LIMIT 1", $this->db->queries[2]);
    }

    public function testEnqueueReportsDatabaseFailure(): void
    {
        $this->db->responder = function (string $method) {
            if ($method === 'get_row') {
                return ['last_sent' => null, 'pending' => 0];
            }
            $this->db->last_error = 'Table is full';

            return false;
        };
        $result = (new WpdbQueueRepository($this->db))->enqueue('https://example.com/a/', 'h', 1, 0, 0, 10);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('Table is full', $result->error);
    }

    public function testDuplicateIsRefreshed(): void
    {
        $this->db->responder = static fn (string $method) => $method === 'get_row' ? ['last_sent' => null, 'pending' => 1] : 2;
        $result = (new WpdbQueueRepository($this->db))->enqueue('https://example.com/a/', 'h', 1, 0, 0, 10);
        $this->assertSame(EnqueueResult::REFRESHED, $result->status);
    }

    public function testFetchMoveAndHousekeepingQueries(): void
    {
        $this->db->responder = static fn (string $method, string $sql) => $method === 'get_results'
            ? [['id' => '4', 'url' => 'https://example.com/a/', 'url_hash' => 'h', 'post_id' => null, 'state' => 'pending',
                'queued_at' => '2026-01-01 00:00:00', 'not_before' => null, 'attempts' => '1', 'last_http' => '422',
                'sent_at' => null, 'retry_group' => 'g-a']]
            : 1;
        $repo = new WpdbQueueRepository($this->db);
        $rows = $repo->fetchDue(1767225600, 1000, false, [1, 2]);
        $this->assertSame(4, $rows[0]->id);
        $this->assertNull($rows[0]->postId);
        $this->assertSame(1767225600, $rows[0]->queuedAt);
        $this->assertSame('g-a', $rows[0]->retryGroup);
        $sql = end($this->db->queries);
        $this->assertStringContainsString('retry_group IS NULL', $sql);
        $this->assertStringContainsString('AND id NOT IN (1, 2)', $sql);
        $this->assertStringContainsString('LIMIT 1000', $sql);

        $this->db->queries = [];
        $repo->moveTo(QueueRow::SENT, $rows, 200, 1767225600);
        $this->assertSame('START TRANSACTION', $this->db->queries[0]);
        $this->assertStringContainsString('DELETE FROM wp_indexnow_batch_queue WHERE site_id = 1 AND id IN (4)', $this->db->queries[1]);
        $this->assertStringContainsString("'sent'", $this->db->queries[2]);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $this->db->queries[2]);
        $this->assertSame('COMMIT', $this->db->queries[3]);

        $this->db->queries = [];
        $repo->purge(100, 200, 300);
        $this->assertCount(3, $this->db->queries);
        $this->assertStringContainsString("state = 'pending' AND queued_at <", $this->db->queries[0]);
        $this->assertStringContainsString("state = 'sent' AND sent_at <", $this->db->queries[1]);

        $this->expectException(\InvalidArgumentException::class);
        $repo->moveTo('pending', $rows, 200, 0);
    }

    public function testRowsAreScopedToTheCurrentSite(): void
    {
        WpStubs::$blogId = 3;
        $this->db->responder = static fn () => 0;
        (new WpdbQueueRepository($this->db))->countPending();
        $this->assertStringContainsString('site_id = 3', $this->db->queries[0]);
    }

    public function testSchemaSqlAndVerify(): void
    {
        $schema = new Schema($this->db);
        $sql = $schema->sql();
        foreach (Schema::COLUMNS as $column) {
            $this->assertMatchesRegularExpression('/^\s+' . $column . ' /m', $sql);
        }
        $this->assertStringContainsString('UNIQUE KEY site_hash_state (site_id,url_hash,state)', $sql);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $sql);
        $schema->verify();
        WpStubs::$columns = ['id', 'url'];
        $this->expectException(\IndianOpinion\IndexNowBatch\Queue\SchemaException::class);
        $schema->verify();
    }
}
