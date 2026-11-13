<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Unit;

use IndianOpinion\IndexNowBatch\Key\KeyVerifier;
use IndianOpinion\IndexNowBatch\Key\PhysicalKeyFile;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Tests\TestCase;
use WpStubs;

final class KeyTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function tempDir(): string
    {
        $this->dir = sys_get_temp_dir() . '/inb-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        return $this->dir;
    }

    public function testT1GeneratesAndKeepsA32HexKey(): void
    {
        $key = $this->plugin->keys()->key();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        $this->assertSame($key, get_option(Options::KEY));
        $this->assertFalse(WpStubs::$autoload[1][Options::KEY]);
        $this->plugin->refresh();
        $this->assertSame($key, $this->plugin->keys()->key());
        $this->assertSame($key, indexnow_batch_key());
    }

    public function testT2ValidConstantWins(): void
    {
        update_option(Options::KEY, str_repeat('a', 32));
        $this->settings(['key' => 'Custom-Key-123']);
        $this->assertSame('Custom-Key-123', $this->plugin->keys()->key());
        $this->assertSame('code', $this->plugin->keys()->source());
        $this->assertNull($this->plugin->keys()->configError());
    }

    public function testT2MalformedConstantHoldsTheFlush(): void
    {
        $this->settings(['key' => 'bad key!']);
        $this->repo->add($this->url(1), null, $this->clock->now());
        $record = $this->flush();
        $this->assertSame('key_unverified', $record['outcome']);
        $this->assertStringContainsString('INDEXNOW_BATCH_KEY', $record['error']);
        $this->assertSame([], WpStubs::$posts_sent);
        $this->assertStringContainsString('configuration error', implode("\n", $this->logLines));
        $this->assertNull($this->plugin->keyFileHandler()->match('/bad%20key!.txt'));
    }

    public function testT3RotateMakesANewKeyClearsVerificationAndFires(): void
    {
        $old = $this->plugin->keys()->key();
        $verifier = $this->plugin->verifier();
        $verifier->store($old, 'https://example.com/' . $old . '.txt', KeyVerifier::VERIFIED);
        $new = $this->plugin->rotateKey();
        $this->assertNotSame($old, $new);
        $this->assertNull($verifier->cached());
        $this->assertSame(1, did_action('indexnow_batch_key_rotated'));
        $this->assertNull($this->plugin->keyFileHandler()->match('/' . $old . '.txt'));
        $this->assertNotNull($this->plugin->keyFileHandler()->match('/' . $new . '.txt'));
    }

    public function testT4ServesTheKeyFile(): void
    {
        $key = $this->plugin->keys()->key();
        $response = $this->plugin->keyFileHandler()->match('/' . $key . '.txt?x=1');
        $this->assertNotNull($response);
        $this->assertSame(200, $response->status);
        $this->assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('public, max-age=300', $response->headers['Cache-Control']);
        $this->assertSame($key, $response->body);
    }

    public function testT5WorksWithoutRewriteRules(): void
    {
        $this->assertFalse(has_filter('rewrite_rules_array'));
        $this->assertSame(0, has_filter('do_parse_request', [$this->plugin, 'onParseRequest']));
        $key = $this->plugin->keys()->key();
        $this->assertNotNull($this->plugin->keyFileHandler()->match('/' . $key . '.txt'));
    }

    public function testT6OtherKeyFilesFallThrough(): void
    {
        $this->plugin->keys()->key();
        $this->assertNull($this->plugin->keyFileHandler()->match('/' . str_repeat('0', 32) . '.txt'));
        $this->assertNull($this->plugin->keyFileHandler()->match('/robots.txt'));
        $this->assertNull($this->plugin->keyFileHandler()->match('/some-post/'));
    }

    public function testOrdinaryRequestsDoNotReadTheKeyOption(): void
    {
        $this->assertNull($this->plugin->keyFileHandler()->match('/2026/01/some-post/'));
        $this->assertArrayNotHasKey(Options::KEY, WpStubs::$options[1]);
    }

    public function testT7HomeWithAPathServesUnderItAndSendsThatKeyLocation(): void
    {
        WpStubs::$home = 'https://example.com/blog/';
        $this->plugin->refresh();
        $key = $this->plugin->keys()->key();
        $this->assertNull($this->plugin->keyFileHandler()->match('/' . $key . '.txt'));
        $this->assertNotNull($this->plugin->keyFileHandler()->match('/blog/' . $key . '.txt'));

        $this->repo->add('https://example.com/blog/hello/', null, $this->clock->now());
        $this->flush();
        $body = $this->lastBody();
        $this->assertSame('https://example.com/blog/' . $key . '.txt', $body['keyLocation']);
    }

    public function testT8WriteFileWritesMarkerRefusesForeignAndRewritesOnRotation(): void
    {
        $dir = $this->tempDir();
        $this->plugin->useKeyFileDirectory($dir);
        $key = $this->plugin->keys()->key();
        $file = $this->plugin->physicalKeyFile();

        $this->assertSame(PhysicalKeyFile::WRITTEN, $file->write($key));
        $this->assertSame($key, file_get_contents($dir . '/' . $key . '.txt'));
        $this->assertSame(['path' => $dir . '/' . $key . '.txt', 'key' => $key], $file->marker());

        $new = $this->plugin->rotateKey();
        $this->assertFileDoesNotExist($dir . '/' . $key . '.txt');
        $this->assertSame($new, file_get_contents($dir . '/' . $new . '.txt'));

        // A foreign file at the path of the next key is never overwritten.
        delete_option(Options::KEY_FILE);
        file_put_contents($dir . '/' . $new . '.txt', 'someone else');
        $this->expectException(\RuntimeException::class);
        $file->write($new);
    }

    public function testT9WrongBodyHolds(): void
    {
        WpStubs::$getResponder = static fn () => WpStubs::response(200, 'not the key');
        $this->repo->add($this->url(1), null, $this->clock->now());
        $record = $this->flush();
        $this->assertSame('key_unverified', $record['outcome']);
        $this->assertSame([], WpStubs::$posts_sent);
        $this->assertSame(1, $this->repo->countPending());
    }

    public function testT9NotFoundAndOffHostRedirectHold(): void
    {
        $key = $this->plugin->keys()->key();
        $url = 'https://example.com/' . $key . '.txt';
        WpStubs::$getResponder = static fn () => WpStubs::response(404);
        $this->assertSame(KeyVerifier::UNVERIFIED, $this->plugin->verifier()->check($key, $url)['state']);
        WpStubs::$getResponder = static fn () => WpStubs::response(301, '', ['location' => 'https://other.example/x.txt']);
        $this->assertSame(KeyVerifier::UNVERIFIED, $this->plugin->verifier()->check($key, $url)['state']);
    }

    public function testT9SameHostRedirectIsFollowedOnce(): void
    {
        $key = $this->plugin->keys()->key();
        WpStubs::$getResponder = static function (string $url) use ($key) {
            return str_contains($url, '/final/')
                ? WpStubs::response(200, $key . "\n")
                : WpStubs::response(302, '', ['location' => '/final/' . $key . '.txt']);
        };
        $this->assertSame(KeyVerifier::VERIFIED, $this->plugin->verifier()->check($key, 'https://example.com/' . $key . '.txt')['state']);
    }

    public function testT9TransportErrorProceedsWithAWarning(): void
    {
        WpStubs::$getResponder = static fn () => new \WP_Error('http_request_failed', 'cURL error 7');
        $this->repo->add($this->url(1), null, $this->clock->now());
        $record = $this->flush();
        $this->assertSame('submitted', $record['outcome']);
        $this->assertStringContainsString('self-check could not complete', implode(' ', $record['warnings']));

        // Cached for a day: no second loopback, no second warning.
        $this->repo->add($this->url(2), null, $this->clock->now());
        $this->clock->advance(3600);
        $record = $this->flush();
        $this->assertSame('submitted', $record['outcome']);
        $this->assertCount(1, WpStubs::$gets);
        $this->assertSame([], $record['warnings']);
    }

    public function testT9SuccessIsCachedFor24Hours(): void
    {
        $this->repo->add($this->url(1), null, $this->clock->now());
        $this->flush();
        $this->assertCount(1, WpStubs::$gets);
        $this->clock->advance(3600 * 23);
        $this->repo->add($this->url(2), null, $this->clock->now());
        $this->flush();
        $this->assertCount(1, WpStubs::$gets);
        $this->clock->advance(3600 * 2);
        $this->repo->add($this->url(3), null, $this->clock->now());
        $this->flush();
        $this->assertCount(2, WpStubs::$gets);
    }

    public function testT42HomeChangeClearsVerification(): void
    {
        $key = $this->plugin->keys()->key();
        $this->plugin->verifier()->store($key, 'https://example.com/' . $key . '.txt', KeyVerifier::VERIFIED);
        WpStubs::$options[1]['home'] = 'https://example.com';
        WpStubs::$home = 'https://new.example/';
        update_option('home', 'https://new.example');
        $this->assertNull($this->plugin->verifier()->cached());
    }

    public function testMaskShowsFirstAndLastFour(): void
    {
        $this->assertSame('abcd' . str_repeat('*', 24) . 'wxyz', \IndianOpinion\IndexNowBatch\Key\KeyManager::mask('abcd' . str_repeat('m', 24) . 'wxyz'));
    }
}
