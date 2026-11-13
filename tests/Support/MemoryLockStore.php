<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Tests\Support;

use IndianOpinion\IndexNowBatch\Flush\LockStore;

final class MemoryLockStore implements LockStore
{
    /** @var array<string, string> */
    public array $rows = [];

    public function add(string $name, string $value): bool
    {
        if (isset($this->rows[$name])) {
            return false;
        }
        $this->rows[$name] = $value;

        return true;
    }

    public function get(string $name): ?string
    {
        return $this->rows[$name] ?? null;
    }

    public function replace(string $name, string $expected, string $value): bool
    {
        if (($this->rows[$name] ?? null) !== $expected) {
            return false;
        }
        $this->rows[$name] = $value;

        return true;
    }

    public function delete(string $name, string $value): bool
    {
        if (($this->rows[$name] ?? null) !== $value) {
            return false;
        }
        unset($this->rows[$name]);

        return true;
    }
}
