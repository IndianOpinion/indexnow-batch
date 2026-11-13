<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

use IndianOpinion\IndexNowBatch\Logger;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Settings;

/**
 * Sends one batch to the IndexNow endpoint (rule 30).
 */
final class Submitter
{
    /** The protocol caps the body at 10 MB; stay a little under. */
    public const MAX_BODY = 10485760 - 1024;

    public function __construct(private Settings $settings, private Logger $logger)
    {
    }

    public function endpoint(): string
    {
        $endpoint = apply_filters('indexnow_batch_endpoint', $this->settings->endpoint());
        if (!is_string($endpoint) || !Settings::isHttpsUrl($endpoint)) {
            $this->logger->errorOnce('endpoint', 'indexnow_batch_endpoint returned a non-https URL; using the default');

            return Settings::DEFAULT_ENDPOINT;
        }

        return $endpoint;
    }

    /**
     * @param list<string> $urls
     * @return array{host: string, key: string, keyLocation: string, urlList: list<string>}
     */
    public static function payload(string $host, string $key, string $keyLocation, array $urls): array
    {
        return ['host' => $host, 'key' => $key, 'keyLocation' => $keyLocation, 'urlList' => $urls];
    }

    /**
     * @param array{host: string, key: string, keyLocation: string, urlList: list<string>} $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Drops URLs from the end until the body fits. Returns the URLs kept.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public static function fit(string $host, string $key, string $keyLocation, array $urls, int $max = self::MAX_BODY): array
    {
        $size = strlen(self::encode(self::payload($host, $key, $keyLocation, [])));
        $kept = [];
        foreach ($urls as $url) {
            // Each URL adds its JSON string plus a comma.
            $add = strlen(json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) + 1;
            if ($size + $add > $max) {
                break;
            }
            $size += $add;
            $kept[] = $url;
        }

        return $kept;
    }

    /**
     * @param array{host: string, key: string, keyLocation: string, urlList: list<string>} $payload
     */
    public function send(array $payload): SubmitResponse
    {
        $args = [
            'method' => 'POST',
            'timeout' => $this->settings->timeout(),
            'redirection' => 0,
            'user-agent' => Options::USER_AGENT,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => self::encode($payload),
            'data_format' => 'body',
            'sslverify' => true,
        ];
        $filtered = apply_filters('indexnow_batch_request_args', $args, $payload);
        if (is_array($filtered)) {
            $args = $filtered;
        }
        // TLS verification stays on whatever a filter says.
        $args['sslverify'] = true;
        $args['redirection'] = 0;

        $response = wp_remote_post($this->endpoint(), $args);
        if (is_wp_error($response)) {
            return new SubmitResponse(0, $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 0) {
            return new SubmitResponse(0, 'no status code in response');
        }
        $retryAfter = wp_remote_retrieve_header($response, 'retry-after');
        $error = '';
        if ($code < 200 || $code >= 300) {
            $error = trim((string) wp_remote_retrieve_body($response));
            if ($error === '') {
                $error = 'HTTP ' . $code;
            }
        }

        return new SubmitResponse($code, $error, is_string($retryAfter) ? $retryAfter : '');
    }
}
