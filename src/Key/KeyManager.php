<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Key;

use IndianOpinion\IndexNowBatch\Options;

/**
 * Owns the IndexNow key (PRD rules 1 to 3).
 */
final class KeyManager
{
    public const SOURCE_CONSTANT = 'code';
    public const SOURCE_STORED = 'stored';

    /**
     * @param string|null $constantKey Value of INDEXNOW_BATCH_KEY, or null when it is not defined.
     */
    public function __construct(private ?string $constantKey = null)
    {
    }

    /** The protocol's key rule: 8 to 128 characters of a-z, A-Z, 0-9 and '-'. */
    public static function isValid(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key) === 1;
    }

    /** 32 lowercase hexadecimal characters from a secure source. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function source(): string
    {
        return $this->constantKey !== null ? self::SOURCE_CONSTANT : self::SOURCE_STORED;
    }

    /**
     * A configuration error that stops every send, or null.
     */
    public function configError(): ?string
    {
        if ($this->constantKey !== null && !self::isValid($this->constantKey)) {
            return 'INDEXNOW_BATCH_KEY must be 8 to 128 characters of a-z, A-Z, 0-9 and -';
        }

        return null;
    }

    /**
     * The current key. Generates and stores one on first need.
     */
    public function key(): string
    {
        if ($this->constantKey !== null) {
            return $this->constantKey;
        }
        $stored = $this->stored();
        if ($stored !== null) {
            return $stored;
        }
        $key = self::generate();
        // add_option does nothing when another request stored a key first.
        add_option(Options::KEY, $key, '', false);

        return $this->stored() ?? $key;
    }

    /**
     * The current key when it is usable, else null. Never generates one.
     */
    public function usableKey(): ?string
    {
        $key = $this->constantKey ?? $this->stored();

        return $key !== null && self::isValid($key) ? $key : null;
    }

    private function stored(): ?string
    {
        $value = get_option(Options::KEY, '');

        return is_string($value) && self::isValid($value) ? $value : null;
    }

    /**
     * Stores a fresh key and returns it. Callers clear verification and
     * rewrite the physical file.
     *
     * @throws \LogicException When the key is set by the constant.
     */
    public function rotate(): string
    {
        if ($this->constantKey !== null) {
            throw new \LogicException('The key is set by INDEXNOW_BATCH_KEY; change the constant to rotate it.');
        }
        do {
            $key = self::generate();
        } while ($key === $this->stored());
        update_option(Options::KEY, $key, false);

        return $key;
    }

    /** First and last four characters, the rest masked. */
    public static function mask(string $key): string
    {
        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4) . str_repeat('*', $length - 8) . substr($key, -4);
    }
}
