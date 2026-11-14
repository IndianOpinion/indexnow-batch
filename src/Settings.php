<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * Resolved, validated settings.
 *
 * Resolution order, last wins: defaults, stored settings, constants, the
 * `indexnow_batch_settings` filter. Every value is then clamped into its
 * range; each clamp adds a warning.
 */
final class Settings
{
    public const DEFAULT_ENDPOINT = 'https://api.indexnow.org/indexnow';

    public const ENVIRONMENTS = ['production', 'staging', 'development', 'local'];

    /** Protocol limit on URLs per request. */
    public const MAX_BATCH = 10000;

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'endpoint' => self::DEFAULT_ENDPOINT,
        'interval' => 15,
        'batch_size' => 1000,
        'batches_per_run' => 3,
        'post_types' => ['post', 'page'],
        'include_attachments' => false,
        'resubmit_interval' => 60,
        'max_attempts' => 5,
        'max_age_days' => 7,
        'queue_cap' => 10000,
        'environments' => ['production'],
        'timeout' => 20,
        'debug' => false,
        'log_size' => 200,
        'pause_seconds' => 3600,
        'log_file' => '',
        'disabled' => false,
        'key' => null,
    ];

    /** @var array<string, array{0: int, 1: int}> */
    private const RANGES = [
        'interval' => [5, 1440],
        'batch_size' => [1, self::MAX_BATCH],
        'batches_per_run' => [1, 10],
        'resubmit_interval' => [0, 1440],
        'max_attempts' => [1, 20],
        'max_age_days' => [1, 30],
        'queue_cap' => [100, 100000],
        'timeout' => [5, 60],
        'log_size' => [10, 1000],
        'pause_seconds' => [60, 604800],
    ];

    /** Keys an operator may store through the settings form or option. */
    public const STORABLE = [
        'endpoint', 'interval', 'batch_size', 'batches_per_run', 'post_types',
        'include_attachments', 'resubmit_interval', 'max_attempts', 'max_age_days',
        'queue_cap', 'environments', 'timeout', 'debug', 'log_size', 'pause_seconds',
    ];

    /**
     * @param array<string, mixed> $values
     * @param list<string> $warnings
     */
    private function __construct(private array $values, private array $warnings)
    {
    }

    /**
     * Reads the stored option, the constants and the filter.
     */
    public static function resolve(): self
    {
        $stored = get_option(Options::SETTINGS, []);
        $raw = is_array($stored) ? array_intersect_key($stored, array_flip(self::STORABLE)) : [];

        if (defined('INDEXNOW_BATCH_LOG_FILE')) {
            $raw['log_file'] = constant('INDEXNOW_BATCH_LOG_FILE');
        }
        if (defined('INDEXNOW_BATCH_DISABLE')) {
            $raw['disabled'] = constant('INDEXNOW_BATCH_DISABLE');
        }
        if (defined('INDEXNOW_BATCH_KEY')) {
            $raw['key'] = constant('INDEXNOW_BATCH_KEY');
        }

        $merged = array_merge(self::DEFAULTS, $raw);
        $filtered = apply_filters('indexnow_batch_settings', $merged);
        if (is_array($filtered)) {
            $merged = array_merge($merged, $filtered);
        }

        return self::fromArray($merged);
    }

    /**
     * Builds settings from an array of raw values, clamping each one.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $warnings = [];
        $values = self::clean(array_merge(self::DEFAULTS, $raw), $warnings);

        return new self($values, $warnings);
    }

    /**
     * Sanitises input from the settings form. Unknown keys are dropped.
     *
     * @param mixed $input
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    public static function sanitizeStored(mixed $input, array &$warnings = []): array
    {
        if (!is_array($input)) {
            return [];
        }
        $input = array_intersect_key($input, array_flip(self::STORABLE));
        foreach (['include_attachments', 'debug'] as $flag) {
            $input[$flag] = !empty($input[$flag]);
        }
        $clean = self::clean(array_merge(self::DEFAULTS, $input), $warnings);

        return array_intersect_key($clean, array_flip(self::STORABLE));
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private static function clean(array $raw, array &$warnings): array
    {
        $out = [];
        foreach (self::RANGES as $name => [$min, $max]) {
            $out[$name] = self::clampInt($name, $raw[$name], $min, $max, $warnings);
        }

        $endpoint = is_string($raw['endpoint']) ? trim($raw['endpoint']) : '';
        $out['endpoint'] = $endpoint;
        if (!self::isHttpsUrl($endpoint)) {
            $warnings[] = 'endpoint must be an https URL; using the default';
            $out['endpoint'] = self::DEFAULT_ENDPOINT;
        }

        $types = [];
        foreach (self::stringList($raw['post_types']) as $type) {
            $clean = strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $type));
            if ($clean !== '' && !in_array($clean, $types, true)) {
                $types[] = $clean;
            }
        }
        $out['post_types'] = $types;

        $envs = array_values(array_intersect(self::ENVIRONMENTS, self::stringList($raw['environments'])));
        if ($envs === []) {
            $warnings[] = 'environments must name at least one of ' . implode(', ', self::ENVIRONMENTS)
                . '; using production';
            $envs = ['production'];
        }
        $out['environments'] = $envs;

        $out['include_attachments'] = (bool) $raw['include_attachments'];
        $out['debug'] = (bool) $raw['debug'];
        $out['disabled'] = (bool) $raw['disabled'];

        $logFile = is_string($raw['log_file']) ? trim($raw['log_file']) : '';
        if ($logFile !== '' && !self::isAbsolutePath($logFile)) {
            $warnings[] = 'log file must be an absolute path; ignored';
            $logFile = '';
        }
        $out['log_file'] = $logFile;

        $out['key'] = $raw['key'] === null ? null : (is_scalar($raw['key']) ? (string) $raw['key'] : '');

        return $out;
    }

    /**
     * @param list<string> $warnings
     */
    private static function clampInt(string $name, mixed $value, int $min, int $max, array &$warnings): int
    {
        if (is_bool($value) || !is_numeric($value)) {
            $default = (int) self::DEFAULTS[$name];
            $warnings[] = sprintf('%s is not a number; using %d', $name, $default);

            return (int) $default;
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            $clamped = max($min, min($max, $int));
            $warnings[] = sprintf('%s %d is outside %d to %d; clamped to %d', $name, $int, $min, $max, $clamped);

            return $clamped;
        }

        return $int;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    public static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== '';
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = $this->values;
        unset($out['key']);

        return $out;
    }

    public function endpoint(): string
    {
        return (string) $this->values['endpoint'];
    }

    /** Flush interval in minutes. */
    public function interval(): int
    {
        return (int) $this->values['interval'];
    }

    public function batchSize(): int
    {
        return (int) $this->values['batch_size'];
    }

    public function batchesPerRun(): int
    {
        return (int) $this->values['batches_per_run'];
    }

    /**
     * @return list<string>
     */
    public function postTypes(): array
    {
        /** @var list<string> $types */
        $types = $this->values['post_types'];

        return $types;
    }

    public function includeAttachments(): bool
    {
        return (bool) $this->values['include_attachments'];
    }

    /** Resubmit interval in minutes. */
    public function resubmitInterval(): int
    {
        return (int) $this->values['resubmit_interval'];
    }

    public function maxAttempts(): int
    {
        return (int) $this->values['max_attempts'];
    }

    public function maxAgeDays(): int
    {
        return (int) $this->values['max_age_days'];
    }

    public function queueCap(): int
    {
        return (int) $this->values['queue_cap'];
    }

    /**
     * @return list<string>
     */
    public function environments(): array
    {
        /** @var list<string> $envs */
        $envs = $this->values['environments'];

        return $envs;
    }

    public function timeout(): int
    {
        return (int) $this->values['timeout'];
    }

    public function debug(): bool
    {
        return (bool) $this->values['debug'];
    }

    public function logSize(): int
    {
        return (int) $this->values['log_size'];
    }

    public function pauseSeconds(): int
    {
        return (int) $this->values['pause_seconds'];
    }

    public function logFile(): string
    {
        return (string) $this->values['log_file'];
    }

    public function disabled(): bool
    {
        return (bool) $this->values['disabled'];
    }

    /** Key from the INDEXNOW_BATCH_KEY constant, or null when the constant is not defined. */
    public function constantKey(): ?string
    {
        $key = $this->values['key'];

        return is_string($key) ? $key : null;
    }
}
