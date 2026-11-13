<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Cli;

/**
 * Small helpers shared by the commands.
 */
final class Output
{
    /**
     * @param array<string, mixed> $assoc
     * @param list<string> $allowed
     */
    public static function format(array $assoc, string $default, array $allowed): string
    {
        $format = isset($assoc['format']) ? (string) $assoc['format'] : $default;
        if (!in_array($format, $allowed, true)) {
            \WP_CLI::error(sprintf('Unknown format "%s". Use one of: %s.', $format, implode(', ', $allowed)));
        }

        return $format;
    }

    /**
     * @param array<string, mixed> $assoc
     */
    public static function limit(array $assoc, int $default, int $max): int
    {
        if (!isset($assoc['limit'])) {
            return $default;
        }
        $value = $assoc['limit'];
        if (!is_numeric($value) || (int) $value < 1) {
            \WP_CLI::error('--limit must be a positive integer.');
        }

        return min($max, (int) $value);
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Flattens a value for a table cell.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return $value === [] ? '' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $assoc
     */
    public static function flag(array $assoc, string $name): bool
    {
        return isset($assoc[$name]) && $assoc[$name] !== false && $assoc[$name] !== 'false';
    }
}
