<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Cli\KeyCommand;
use IndianOpinion\IndexNowBatch\Cli\QueueCommand;
use IndianOpinion\IndexNowBatch\Cli\RootCommand;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use WpStubs;

final class CliTest extends TestCase
{
    private function root(): RootCommand
    {
        return new RootCommand($this->plugin);
    }

    private function queue(): QueueCommand
    {
        return new QueueCommand($this->plugin);
    }

    private function key(): KeyCommand
    {
        return new KeyCommand($this->plugin);
    }

    /**
     * Runs a command and returns its exit code.
     */
    private function exitCode(callable $command): int
    {
        try {
            $command();
        } catch (\CliExit $e) {
            return (int) $e->getCode();
        }

        return 0;
    }

    public function testT50StatusTableAndJson(): void
    {
        $key = $this->plugin->keys()->key();
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->status([], [])));
        $text = \WP_CLI::text();
        $this->assertStringContainsString(substr($key, 0, 4) . str_repeat('*', 24) . substr($key, -4), $text);
        $this->assertStringNotContainsString($key, $text);
        \WP_CLI::reset();
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->status([], ['format' => 'json'])));
        $status = json_decode(\WP_CLI::text(), true);
        $this->assertSame(['pending' => 0, 'due' => 0, 'sent' => 0, 'failed' => 0], $status['queue']);
        $this->assertSame(1, $this->exitCode(fn () => $this->root()->status([], ['format' => 'xml'])));
    }

    public function testT50QueueAddListRemoveClear(): void
    {
        $this->post(5);
        $this->assertSame(0, $this->exitCode(fn () => $this->queue()->add(['5'], ['post' => true])));
        $this->assertSame(0, $this->exitCode(fn () => $this->queue()->add(['https://example.com/a/', 'https://other.example/'], [])));
        $text = \WP_CLI::text();
        $this->assertStringContainsString("https://other.example/\trefused\tforeign", $text);
        $this->assertStringContainsString('1 accepted, 1 refused', $text);
        $this->assertSame(1, $this->exitCode(fn () => $this->queue()->add(['abc'], ['post' => true])));
        $this->assertSame(2, $this->repo->countPending());

        \WP_CLI::reset();
        $this->assertSame(0, $this->exitCode(fn () => $this->queue()->list_([], ['format' => 'json'])));
        $this->assertCount(2, json_decode(\WP_CLI::text(), true));
        $this->assertSame(1, $this->exitCode(fn () => $this->queue()->list_([], ['state' => 'weird'])));

        $this->assertSame(0, $this->exitCode(fn () => $this->queue()->remove(['HTTPS://EXAMPLE.COM/a/'], [])));
        $this->assertSame(1, $this->repo->countPending());

        $this->assertSame(1, $this->exitCode(fn () => $this->queue()->clear([], [])), 'asks without --yes');
        $this->assertSame(1, $this->repo->countPending());
        $this->assertSame(0, $this->exitCode(fn () => $this->queue()->clear([], ['yes' => true])));
        $this->assertSame(0, $this->repo->countPending());
    }

    public function testT50FlushDryRunPrintsTheBatchAndChangesNothing(): void
    {
        $this->repo->add($this->url(1), null, $this->clock->now());
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->flush([], ['dry-run' => true])));
        $this->assertStringContainsString('  ' . $this->url(1), \WP_CLI::text());
        $this->assertSame([], WpStubs::$posts_sent);
        $this->assertSame(1, $this->repo->countPending());
        $this->assertSame(0, $this->repo->inState(QueueRow::PENDING)[0]->attempts);
    }

    public function testT50FlushExitCodes(): void
    {
        $this->repo->add($this->url(1), null, $this->clock->now());
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->flush([], ['limit' => '10'])));
        $this->assertSame(1, did_action('indexnow_batch_submitted'));

        $this->repo->add($this->url(2), null, $this->clock->now());
        $this->respond(422);
        $this->assertSame(1, $this->exitCode(fn () => $this->root()->flush([], [])), 'refused');

        $this->repo->add($this->url(3), null, $this->clock->now());
        WpStubs::$postResponses[] = new \WP_Error('x', 'timeout');
        $this->assertSame(1, $this->exitCode(fn () => $this->root()->flush([], [])), 'transport_error');

        $this->assertSame(0, $this->exitCode(fn () => $this->root()->flush([], [])), 'backing_off is not a failure');
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->flush([], ['force' => true, 'limit' => '1'])));

        WpStubs::$getResponder = static fn () => WpStubs::response(404);
        $this->plugin->verifier()->invalidate();
        $this->repo->add($this->url(4), null, $this->clock->now());
        $this->assertSame(1, $this->exitCode(fn () => $this->root()->flush([], [])), 'key_unverified');
        $this->assertSame(1, $this->exitCode(fn () => $this->root()->flush([], ['limit' => '0'])));
    }

    public function testT50KeyCommands(): void
    {
        $key = $this->plugin->keys()->key();
        $this->assertSame(0, $this->exitCode(fn () => $this->key()->show([], [])));
        $this->assertSame($key, \WP_CLI::$out[0]);

        $this->assertSame(0, $this->exitCode(fn () => $this->key()->verify([], [])));
        $this->assertSame('verified', $this->plugin->verifier()->cached()['state']);
        WpStubs::$getResponder = static fn () => new \WP_Error('x', 'loopback blocked');
        $this->assertSame(0, $this->exitCode(fn () => $this->key()->verify([], [])));
        WpStubs::$getResponder = static fn () => WpStubs::response(200, 'wrong');
        $this->assertSame(1, $this->exitCode(fn () => $this->key()->verify([], [])));
        WpStubs::$getResponder = null;

        $this->assertSame(1, $this->exitCode(fn () => $this->key()->rotate([], [])));
        $this->assertSame($key, $this->plugin->keys()->key());
        $this->assertSame(0, $this->exitCode(fn () => $this->key()->rotate([], ['yes' => true])));
        $this->assertNotSame($key, $this->plugin->keys()->key());

        $dir = sys_get_temp_dir() . '/inb-cli-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->plugin->useKeyFileDirectory($dir);
        $new = $this->plugin->keys()->key();
        $this->assertSame(0, $this->exitCode(fn () => $this->key()->write_file([], [])));
        $this->assertFileExists($dir . '/' . $new . '.txt');
        $this->assertSame(0, $this->exitCode(fn () => $this->key()->remove_file([], [])));
        $this->assertFileDoesNotExist($dir . '/' . $new . '.txt');
        $this->assertSame(0, $this->exitCode(fn () => $this->key()->remove_file([], [])));
        rmdir($dir);
    }

    public function testRotateRefusesAKeySetInCode(): void
    {
        $this->settings(['key' => 'FixedKey-1234']);
        $this->assertSame(1, $this->exitCode(fn () => $this->key()->rotate([], ['yes' => true])));
        $this->assertStringContainsString('INDEXNOW_BATCH_KEY', \WP_CLI::text());
    }

    public function testT50LogPauseResume(): void
    {
        $this->plugin->flush();
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->log([], ['format' => 'json', 'limit' => '5'])));
        $this->assertSame('empty', json_decode(\WP_CLI::text(), true)[0]['outcome']);
        \WP_CLI::reset();
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->log([], [])));
        $this->assertStringContainsString('empty', \WP_CLI::text());

        $this->assertSame(0, $this->exitCode(fn () => $this->root()->pause([], ['for' => '120'])));
        $this->assertSame($this->clock->now() + 120, get_option(Options::PAUSE));
        $this->assertSame(1, $this->exitCode(fn () => $this->root()->pause([], ['for' => 'soon'])));
        $this->assertSame(0, $this->exitCode(fn () => $this->root()->resume([], [])));
        $this->assertFalse(get_option(Options::PAUSE));
    }

    public function testQueueCommandsRefuseWithoutTheTable(): void
    {
        delete_option(Options::SCHEMA);
        $this->assertSame(1, $this->exitCode(fn () => $this->queue()->list_([], [])));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testCommandsAreRegisteredUnderWpCli(): void
    {
        define('WP_CLI', true);
        $plugin = new \IndianOpinion\IndexNowBatch\Plugin('/plugins/indexnow-batch/indexnow-batch.php');
        $plugin->boot();
        $this->assertSame(['indexnow', 'indexnow queue', 'indexnow key'], array_keys(\WP_CLI::$commands));
    }
}
