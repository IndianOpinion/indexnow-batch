<?php

/**
 * Minimal WP_CLI stub: captures output, turns error() and halt() into exceptions.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace {
    final class CliExit extends RuntimeException
    {
    }

    final class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var array<string, object> */
        public static array $commands = [];

        public static function reset(): void
        {
            self::$out = [];
            self::$commands = [];
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }

        public static function line(string $message = ''): void
        {
            self::$out[] = $message;
        }

        public static function log(string $message): void
        {
            self::$out[] = $message;
        }

        public static function success(string $message): void
        {
            self::$out[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$out[] = 'Warning: ' . $message;
        }

        public static function error(string $message): never
        {
            self::$out[] = 'Error: ' . $message;
            throw new CliExit($message, 1);
        }

        public static function halt(int $code): never
        {
            throw new CliExit('halt', $code);
        }

        /**
         * @param array<string, mixed> $assoc
         */
        public static function confirm(string $question, array $assoc = []): void
        {
            if (empty($assoc['yes'])) {
                throw new CliExit('not confirmed', 1);
            }
        }

        public static function text(): string
        {
            return implode("\n", self::$out);
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<int, array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            $cells = [];
            foreach ($fields as $field) {
                $value = $item[$field] ?? '';
                $cells[] = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
            }
            \WP_CLI::$out[] = implode("\t", $cells);
        }
    }
}
