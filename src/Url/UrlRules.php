<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Url;

/**
 * URL acceptance rules and the de-duplication key (PRD rules 19 and 20).
 */
final class UrlRules
{
    public const MAX_LENGTH = 2048;

    private string $scheme;
    private string $host;
    private ?int $port;
    private string $path;

    public function __construct(string $homeUrl)
    {
        $parts = parse_url($homeUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Home URL has no host: ' . $homeUrl);
        }
        $this->scheme = strtolower($parts['scheme'] ?? 'https');
        $this->host = strtolower($parts['host']);
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = rtrim($parts['path'] ?? '', '/');
    }

    /** Lowercase host of the home URL, without the port. */
    public function host(): string
    {
        return $this->host;
    }

    /** Host as sent in the `host` field: with the port when the home URL names one. */
    public function hostField(): string
    {
        return $this->port === null ? $this->host : $this->host . ':' . $this->port;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    /** Path of the home URL without a trailing slash, '' at the root. */
    public function homePath(): string
    {
        return $this->path;
    }

    public function origin(): string
    {
        return $this->scheme . '://' . $this->hostField();
    }

    /** Path the key file is served at. */
    public function keyPath(string $key): string
    {
        return $this->path . '/' . $key . '.txt';
    }

    /** Absolute key file URL, used as `keyLocation`. */
    public function keyLocation(string $key): string
    {
        return $this->origin() . $this->keyPath($key);
    }

    /**
     * Returns null when the URL is acceptable, else the reason `foreign`.
     */
    public function check(string $url): ?string
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return 'foreign';
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '#')) {
            return 'foreign';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'foreign';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'foreign';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'foreign';
        }
        if (strtolower($parts['host']) !== $this->host) {
            return 'foreign';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (self::effectivePort($scheme, $port) !== self::effectivePort($this->scheme, $this->port)) {
            return 'foreign';
        }
        if ($this->path !== '') {
            $path = $parts['path'] ?? '';
            if ($path !== $this->path && !str_starts_with($path, $this->path . '/')) {
                return 'foreign';
            }
        }

        return null;
    }

    private static function effectivePort(string $scheme, ?int $port): int
    {
        if ($port !== null) {
            return $port;
        }

        return $scheme === 'http' ? 80 : 443;
    }

    /**
     * De-duplication key: lowercase scheme and host, no fragment, percent
     * escapes in uppercase hex. The rest of the URL is kept exactly.
     */
    public static function dedupeKey(string $url): string
    {
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?]*)(.*)$#s', $url, $m) === 1) {
            $url = strtolower($m[1]) . '://' . strtolower($m[2]) . $m[3];
        }

        return (string) preg_replace_callback(
            '/%[0-9a-fA-F]{2}/',
            static fn (array $match): string => strtoupper($match[0]),
            $url
        );
    }

    /** SHA-1 of the de-duplication key, 40 hex characters. */
    public static function hash(string $url): string
    {
        return sha1(self::dedupeKey($url));
    }
}
