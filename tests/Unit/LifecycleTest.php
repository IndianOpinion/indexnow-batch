<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Flush\Lock;
use IndianOpinion\IndexNowBatch\Flush\WpdbLockStore;
use IndianOpinion\IndexNowBatch\Key\KeyVerifier;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Plugin;
use IndianOpinion\IndexNowBatch\Queue\Schema;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use IndianOpinion\IndexNowBatch\Uninstaller;
use WpStubs;

final class LifecycleTest extends TestCase
{
    public function testT47LockWorksThroughTheDatabaseWithOrWithoutAnObjectCache(): void
    {
        global $wpdb;
        $now = $this->clock->now();
        $first = new Lock(new WpdbLockStore($wpdb));
        $second = new Lock(new WpdbLockStore($wpdb));
        $this->assertTrue($first->acquire($now));
        // A stale object cache value says nothing: the lock row is read from the table.
        WpStubs::$options[1][Options::LOCK] = 'stale cached value';
        $this->assertFalse($second->acquire($now));
        $this->assertContains('options:' . Options::LOCK, WpStubs::$cacheDeletes);
        $first->release();
        $this->assertTrue($second->acquire($now));
        $this->assertFalse($first->acquire($now + Lock::TTL - 1));
        $this->assertTrue($first->acquire($now + Lock::TTL), 'an expired lock is taken over');
        $this->assertStringStartsWith('INSERT IGNORE INTO wp_options', $wpdb->queries[0]);
    }

    public function testT48DeactivateUnschedulesAndStopsTheKeyFileButKeepsData(): void
    {
        $this->plugin->installer()->installSite();
        $key = $this->plugin->keys()->key();
        $this->repo->add($this->url(1), null, $this->clock->now());
        $this->assertNotFalse(wp_next_scheduled(Options::CRON_HOOK));

        do_action('deactivate_indexnow-batch/indexnow-batch.php', false);
        $this->assertFalse(wp_next_scheduled(Options::CRON_HOOK));
        $this->assertFalse(has_filter('do_parse_request', [$this->plugin, 'onParseRequest']));
        $this->assertSame($key, get_option(Options::KEY));
        $this->assertSame(1, $this->repo->countPending());

        do_action('activate_indexnow-batch/indexnow-batch.php', false);
        $this->assertNotFalse(wp_next_scheduled(Options::CRON_HOOK));
        $this->assertSame($key, $this->plugin->keys()->key());
    }

    public function testT49UninstallRemovesEverythingItCreatedOnEverySite(): void
    {
        global $wpdb;
        $dir = sys_get_temp_dir() . '/inb-un-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $log = $dir . '/indexnow.log';
        file_put_contents($log, "{}\n");

        WpStubs::$multisite = true;
        WpStubs::$sites = [1, 2];
        $this->plugin->useKeyFileDirectory($dir);
        foreach ([1, 2] as $site) {
            switch_to_blog($site);
            $this->plugin->installer()->installSite();
            $this->plugin->verifier()->store('k', 'u', KeyVerifier::VERIFIED);
            restore_current_blog();
        }
        $key = $this->plugin->keys()->key();
        $this->plugin->physicalKeyFile()->write($key);
        $foreign = $dir . '/' . str_repeat('b', 32) . '.txt';
        file_put_contents($foreign, str_repeat('b', 32));
        update_option('blogname', 'Not ours');

        (new Uninstaller($wpdb))->run();

        foreach ([1, 2] as $site) {
            $left = array_filter(array_keys(WpStubs::$options[$site] ?? []), static fn (string $n): bool => str_starts_with($n, 'indexnow_batch_'));
            $this->assertSame([], array_values($left), 'site ' . $site);
            $this->assertArrayNotHasKey(Options::CRON_HOOK, WpStubs::$cron[$site] ?? []);
        }
        $this->assertSame('Not ours', WpStubs::$options[1]['blogname']);
        $this->assertContains('DROP TABLE IF EXISTS `wp_indexnow_batch_queue`', $wpdb->queries);
        $this->assertFileDoesNotExist($dir . '/' . $key . '.txt');
        $this->assertFileExists($foreign);
        $this->assertFileExists($log);

        unlink($foreign);
        unlink($log);
        rmdir($dir);
    }

    public function testT55MustUseModeInstallsOnFirstInit(): void
    {
        delete_option(Options::SCHEMA);
        $this->plugin->refresh();
        do_action('init');
        $this->assertSame(Schema::VERSION, get_option(Options::SCHEMA));
        $this->assertTrue(WpStubs::$autoload[1][Options::SCHEMA]);
        $this->assertNotFalse(wp_next_scheduled(Options::CRON_HOOK));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) get_option(Options::KEY));
        global $wpdb;
        $this->assertNotEmpty(array_filter($wpdb->queries, static fn (string $q): bool => str_starts_with($q, 'CREATE TABLE wp_indexnow_batch_queue')));
        $this->assertContains('SHOW COLUMNS FROM `wp_indexnow_batch_queue`', $wpdb->queries);
    }

    public function testMissingColumnRecordsASchemaErrorAndRefuses(): void
    {
        delete_option(Options::SCHEMA);
        WpStubs::$columns = array_values(array_diff(Schema::COLUMNS, ['retry_group']));
        $this->assertFalse($this->plugin->installer()->installSite());
        $this->assertFalse($this->plugin->schemaReady());
        $this->assertStringContainsString('retry_group', (string) get_option(Options::SCHEMA_ERROR));
        $this->assertStringContainsString('retry_group', $this->logLines[0]);

        // Front-end requests do not retry the install after a failure.
        global $wpdb;
        $count = count($wpdb->queries);
        do_action('init');
        $this->assertCount($count, $wpdb->queries);
    }

    public function testNetworkActivationInstallsEverySiteAndNewSites(): void
    {
        WpStubs::$multisite = true;
        WpStubs::$sites = [1, 2, 3];
        do_action('activate_indexnow-batch/indexnow-batch.php', true);
        foreach ([1, 2, 3] as $site) {
            $this->assertSame(Schema::VERSION, WpStubs::$options[$site][Options::SCHEMA] ?? null);
            $this->assertArrayHasKey(Options::CRON_HOOK, WpStubs::$cron[$site]);
        }
        $this->assertNotSame(WpStubs::$options[1][Options::KEY], WpStubs::$options[2][Options::KEY], 'each site has its own key');
        $this->assertSame(1, WpStubs::$blogId);

        WpStubs::$networkActive = ['indexnow-batch/indexnow-batch.php'];
        do_action('wp_initialize_site', new \WP_Site(4));
        $this->assertArrayHasKey(Options::KEY, WpStubs::$options[4]);
    }

    public function testEachSiteServesItsOwnHostAndKey(): void
    {
        WpStubs::$multisite = true;
        $one = $this->plugin->keys()->key();
        switch_to_blog(2);
        WpStubs::$home = 'https://mapped.example/';
        $two = $this->plugin->keys()->key();
        $this->assertNotSame($one, $two);
        $this->assertNotNull($this->plugin->keyFileHandler()->match('/' . $two . '.txt'));
        $this->assertNull($this->plugin->keyFileHandler()->match('/' . $one . '.txt'));
        $this->assertSame('mapped.example', $this->plugin->rules()->host());
        restore_current_blog();
    }

    public function testPluginInstanceIsRequiredForHelpers(): void
    {
        Plugin::setInstance(null);
        $this->expectException(\LogicException::class);
        indexnow_batch_key();
    }
}
