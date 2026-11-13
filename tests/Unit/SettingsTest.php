<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Settings;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use WpStubs;

final class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $s = Settings::fromArray([]);
        $this->assertSame('https://api.indexnow.org/indexnow', $s->endpoint());
        $this->assertSame(15, $s->interval());
        $this->assertSame(1000, $s->batchSize());
        $this->assertSame(3, $s->batchesPerRun());
        $this->assertSame(['post', 'page'], $s->postTypes());
        $this->assertFalse($s->includeAttachments());
        $this->assertSame(60, $s->resubmitInterval());
        $this->assertSame(5, $s->maxAttempts());
        $this->assertSame(7, $s->maxAgeDays());
        $this->assertSame(10000, $s->queueCap());
        $this->assertSame(['production'], $s->environments());
        $this->assertSame(20, $s->timeout());
        $this->assertSame('', $s->logFile());
        $this->assertFalse($s->debug());
        $this->assertNull($s->constantKey());
        $this->assertSame([], $s->warnings());
    }

    public function testT52OutOfRangeValuesAreClampedAndWarned(): void
    {
        $s = Settings::fromArray([
            'interval' => 2,
            'batch_size' => 20000,
            'batches_per_run' => 0,
            'max_attempts' => 'lots',
            'queue_cap' => 5,
            'timeout' => 600,
            'environments' => ['moon'],
            'log_file' => 'relative.log',
        ]);
        $this->assertSame(5, $s->interval());
        $this->assertSame(10000, $s->batchSize());
        $this->assertSame(1, $s->batchesPerRun());
        $this->assertSame(5, $s->maxAttempts());
        $this->assertSame(100, $s->queueCap());
        $this->assertSame(60, $s->timeout());
        $this->assertSame(['production'], $s->environments());
        $this->assertSame('', $s->logFile());
        $this->assertCount(8, $s->warnings());
    }

    public function testT52NonHttpsEndpointFallsBackToTheDefault(): void
    {
        $this->assertSame(Settings::DEFAULT_ENDPOINT, Settings::fromArray(['endpoint' => 'http://insecure.example/indexnow'])->endpoint());
        $this->assertSame('https://www.bing.com/indexnow', Settings::fromArray(['endpoint' => 'https://www.bing.com/indexnow'])->endpoint());

        add_filter('indexnow_batch_endpoint', static fn (): string => 'http://proxy.example/');
        $this->plugin->refresh();
        $this->assertSame(Settings::DEFAULT_ENDPOINT, $this->plugin->submitter()->endpoint());
        $this->assertStringContainsString('non-https', $this->logLines[0]);
    }

    public function testT52WarningsReachTheRunRecord(): void
    {
        update_option(Options::SETTINGS, ['batch_size' => 0]);
        $record = $this->flush();
        $this->assertStringContainsString('batch_size', implode(' ', $record['warnings']));
    }

    public function testResolutionOrderStoredThenFilter(): void
    {
        update_option(Options::SETTINGS, ['interval' => 30, 'batch_size' => 50, 'unknown' => 'x']);
        add_filter('indexnow_batch_settings', static fn (array $s): array => ['batch_size' => 70] + $s);
        $s = Settings::resolve();
        $this->assertSame(30, $s->interval());
        $this->assertSame(70, $s->batchSize());
        $this->assertArrayNotHasKey('unknown', $s->toArray());
    }

    public function testSanitizeStoredKeepsOnlyStorableKeys(): void
    {
        $warnings = [];
        $clean = Settings::sanitizeStored(['interval' => '9999', 'key' => 'evil', 'log_file' => '/tmp/x', 'post_types' => ['post', 'Pa<ge']], $warnings);
        $this->assertSame(1440, $clean['interval']);
        $this->assertArrayNotHasKey('key', $clean);
        $this->assertArrayNotHasKey('log_file', $clean);
        $this->assertSame(['post', 'page'], $clean['post_types']);
        $this->assertFalse($clean['debug']);
        $this->assertCount(1, $warnings);
        $this->assertSame([], Settings::sanitizeStored('nope'));
    }

    public function testDebugSendsRunsToErrorLog(): void
    {
        $this->settings(['debug' => true]);
        $this->flush();
        $this->assertStringStartsWith('[indexnow-batch] run {', $this->logLines[0]);
        unset(WpStubs::$options[1]);
    }
}
