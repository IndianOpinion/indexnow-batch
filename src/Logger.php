<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * Writes `[indexnow-batch]` lines to the PHP error log.
 *
 * Errors (enqueue database failures, configuration errors) always go out.
 * Debug lines go out only when the debug setting is on.
 */
final class Logger
{
    /** @var callable(string): mixed */
    private $sink;

    /** @var array<string, true> */
    private array $once = [];

    /**
     * @param callable(string): mixed|null $sink Receives each formatted line. Defaults to error_log().
     */
    public function __construct(private bool $debug = false, ?callable $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function error(string $message): void
    {
        ($this->sink)('[indexnow-batch] ' . $message);
    }

    /**
     * Logs an error once per request for a given key.
     */
    public function errorOnce(string $key, string $message): void
    {
        if (isset($this->once[$key])) {
            return;
        }
        $this->once[$key] = true;
        $this->error($message);
    }

    public function debug(string $message): void
    {
        if ($this->debug) {
            ($this->sink)('[indexnow-batch] ' . $message);
        }
    }
}
