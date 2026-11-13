<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Key;

/**
 * The key file response, built separately from sending it so it can be tested.
 */
final class KeyFileResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * Sends the response and ends the request.
     */
    public function emit(bool $withBody = true): never
    {
        status_header($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($withBody) {
            echo $this->body; // phpcs:ignore -- the body is the validated key, plain text.
        }
        exit;
    }
}
