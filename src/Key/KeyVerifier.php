<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Key;

use IndianOpinion\IndexNowBatch\Clock;
use IndianOpinion\IndexNowBatch\Options;

/**
 * Fetches the site's own key URL and caches the verdict (PRD rule 9).
 *
 * - verified: status 200 and the body equals the key.
 * - unverified: wrong body, a 4xx, or a redirect to another host. Sends hold.
 * - unknown: the request could not be made, or the server answered 5xx.
 *   Sends proceed, with a warning.
 *
 * Verified and unknown are cached for 24 hours. Unverified is not cached
 * for sending purposes, so a fix is picked up on the next run.
 */
final class KeyVerifier
{
    public const VERIFIED = 'verified';
    public const UNVERIFIED = 'unverified';
    public const UNKNOWN = 'unknown';

    public const TTL = 86400;
    private const TIMEOUT = 5;

    public function __construct(private Clock $clock)
    {
    }

    /**
     * @return array{state: string, at: int, fp: string, detail: string}|null
     */
    public function cached(): ?array
    {
        $value = get_option(Options::KEY_CHECK, null);
        if (!is_array($value) || !isset($value['state'], $value['at'], $value['fp'])) {
            return null;
        }

        return [
            'state' => (string) $value['state'],
            'at' => (int) $value['at'],
            'fp' => (string) $value['fp'],
            'detail' => (string) ($value['detail'] ?? ''),
        ];
    }

    public static function fingerprint(string $key, string $keyUrl): string
    {
        return sha1($key . '|' . $keyUrl);
    }

    /**
     * The cached verdict for this key and URL when it is still fresh.
     */
    public function fresh(string $key, string $keyUrl): ?string
    {
        $cached = $this->cached();
        if (
            $cached === null
            || $cached['fp'] !== self::fingerprint($key, $keyUrl)
            || $cached['at'] + self::TTL <= $this->clock->now()
            || $cached['state'] === self::UNVERIFIED
        ) {
            return null;
        }

        return $cached['state'];
    }

    /**
     * Returns a cached verdict when fresh, else checks now.
     *
     * @param bool $store Whether to cache a new verdict (false for dry runs).
     * @return array{state: string, detail: string, checked: bool}
     */
    public function ensure(string $key, string $keyUrl, bool $store = true): array
    {
        $fresh = $this->fresh($key, $keyUrl);
        if ($fresh !== null) {
            return ['state' => $fresh, 'detail' => '', 'checked' => false];
        }
        $result = $this->check($key, $keyUrl);
        if ($store) {
            $this->store($key, $keyUrl, $result['state'], $result['detail']);
        }

        return $result + ['checked' => true];
    }

    /**
     * Fetches the key URL now. Follows one redirect on the same host.
     *
     * @return array{state: string, detail: string}
     */
    public function check(string $key, string $keyUrl): array
    {
        $result = $this->fetch($key, $keyUrl);
        // The detail is stored, shown and logged: never with the key in it.
        $result['detail'] = str_replace($key, KeyManager::mask($key), $result['detail']);

        return $result;
    }

    /**
     * @return array{state: string, detail: string}
     */
    private function fetch(string $key, string $keyUrl): array
    {
        $url = $keyUrl;
        $host = strtolower((string) parse_url($keyUrl, PHP_URL_HOST));
        for ($hop = 0; $hop < 2; $hop++) {
            $response = wp_remote_get($url, [
                'timeout' => self::TIMEOUT,
                'redirection' => 0,
                'user-agent' => Options::USER_AGENT,
                'headers' => ['Cache-Control' => 'no-cache'],
            ]);
            if (is_wp_error($response)) {
                return ['state' => self::UNKNOWN, 'detail' => 'request failed: ' . $response->get_error_message()];
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                $location = is_array($location) ? (string) reset($location) : $location;
                $next = self::resolveLocation($url, $location);
                if ($next === null || strtolower((string) parse_url($next, PHP_URL_HOST)) !== $host) {
                    return ['state' => self::UNVERIFIED, 'detail' => 'redirect off host: ' . $location];
                }
                $url = $next;
                continue;
            }
            if ($code >= 500 || $code === 0) {
                return ['state' => self::UNKNOWN, 'detail' => 'self-check answered ' . $code];
            }
            if ($code !== 200) {
                return ['state' => self::UNVERIFIED, 'detail' => 'self-check answered ' . $code];
            }
            $body = trim((string) wp_remote_retrieve_body($response));
            if (!hash_equals($key, $body)) {
                return ['state' => self::UNVERIFIED, 'detail' => 'key file body does not match the key'];
            }

            return ['state' => self::VERIFIED, 'detail' => ''];
        }

        return ['state' => self::UNVERIFIED, 'detail' => 'too many redirects'];
    }

    private static function resolveLocation(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return null;
    }

    public function store(string $key, string $keyUrl, string $state, string $detail = ''): void
    {
        update_option(Options::KEY_CHECK, [
            'state' => $state,
            'at' => $this->clock->now(),
            'fp' => self::fingerprint($key, $keyUrl),
            'detail' => $detail,
        ], false);
    }

    public function invalidate(): void
    {
        delete_option(Options::KEY_CHECK);
    }
}
