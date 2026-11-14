<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Admin\StatusPage;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use WpStubs;

final class AdminTest extends TestCase
{
    private function page(): StatusPage
    {
        return new StatusPage($this->plugin);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function actions(): array
    {
        return ['verify' => ['verify'], 'flush' => ['flush'], 'rotate' => ['rotate']];
    }

    /**
     * @dataProvider actions
     */
    public function testT51ActionsRefuseWithoutTheCapability(string $action): void
    {
        WpStubs::$caps = [];
        $key = $this->plugin->keys()->key();
        try {
            $this->page()->handle($action, ['confirm' => '1']);
            $this->fail('expected wp_die');
        } catch (\WpDieException $e) {
            $this->assertStringContainsString('permission', $e->getMessage());
        }
        $this->assertSame($key, $this->plugin->keys()->key());
        $this->assertSame([], WpStubs::$posts_sent);
    }

    /**
     * @dataProvider actions
     */
    public function testT51ActionsRefuseWithABadNonce(string $action): void
    {
        WpStubs::$nonceValid = false;
        $key = $this->plugin->keys()->key();
        $this->expectException(\WpDieException::class);
        try {
            $this->page()->handle($action, ['confirm' => '1']);
        } finally {
            $this->assertSame($key, $this->plugin->keys()->key());
        }
    }

    public function testActionsWork(): void
    {
        $page = $this->page();
        $this->assertStringStartsWith('Key verified', $page->handle('verify', []));
        $this->repo->add($this->url(1), null, $this->clock->now());
        $this->assertSame('Run finished: submitted, sent 1.', $page->handle('flush', []));
        $old = $this->plugin->keys()->key();
        $this->assertStringContainsString('tick the confirmation', $page->handle('rotate', []));
        $this->assertSame($old, $this->plugin->keys()->key());
        $this->assertSame('Key rotated. Verification cleared.', $page->handle('rotate', ['confirm' => '1']));
        $this->assertNotSame($old, $this->plugin->keys()->key());
    }

    public function testUnknownActionDies(): void
    {
        $this->expectException(\WpDieException::class);
        $this->page()->handle('drop_table', []);
    }

    public function testRenderEscapesAndShowsStatus(): void
    {
        update_option(Options::LOG, [['at' => '2026-01-01T00:00:00Z', 'outcome' => '<script>x</script>', 'dropped' => []]]);
        ob_start();
        $this->page()->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('name="indexnow_batch_settings[interval]"', $html);
        $this->assertStringContainsString('indexnow_batch_rotate', $html);
        $this->assertStringContainsString('nonce-indexnow_batch_flush', $html);
    }

    public function testRenderRequiresTheCapability(): void
    {
        WpStubs::$caps = [];
        $this->expectException(\WpDieException::class);
        $this->page()->render();
    }

    public function testRegistersPageAndSettingWithSanitizer(): void
    {
        $page = $this->page();
        $page->addMenu();
        $page->registerSettings();
        $this->assertSame(['page' => 'indexnow-batch', 'cap' => 'manage_options'], WpStubs::$registered[0]);
        $this->assertSame(Options::SETTINGS, WpStubs::$registered[1]['setting']);
        $clean = $page->sanitize(['batch_size' => 99999]);
        $this->assertSame(10000, $clean['batch_size']);
        $this->assertArrayHasKey('settings_error', WpStubs::$registered[2]);
    }
}
