<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Key;

use IndianOpinion\IndexNowBatch\Url\UrlRules;

/**
 * Serves `{home}/{key}.txt` from PHP, matched before the main query (rules 4 to 7).
 *
 * No rewrite rule is used. A cheap pattern test runs first so that ordinary
 * requests never read the key option.
 */
final class KeyFileHandler
{
    /**
     * @param \Closure(): KeyManager $keys Built only when a request looks like a key file.
     */
    public function __construct(private \Closure $keys, private UrlRules $rules)
    {
    }

    public function match(string $requestUri): ?KeyFileResponse
    {
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        $prefix = $this->rules->homePath() . '/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $file = substr($path, strlen($prefix));
        if (preg_match('/^[A-Za-z0-9\-]{8,128}\.txt$/', $file) !== 1) {
            return null;
        }
        $keys = ($this->keys)();
        if ($keys->configError() !== null) {
            return null;
        }
        $key = $keys->key();
        if (!hash_equals($this->rules->keyPath($key), $path)) {
            return null;
        }

        return new KeyFileResponse(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',
            'Content-Length' => (string) strlen($key),
        ], $key);
    }
}
