<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

/**
 * Lock rows in the options table, written with atomic SQL.
 *
 * add_option() checks then inserts, which races. INSERT IGNORE on the
 * unique option_name does not, and it bypasses any object cache, so the
 * lock behaves the same with or without one.
 */
final class WpdbLockStore implements LockStore
{
    public function __construct(private \wpdb $db)
    {
    }

    public function add(string $name, string $value): bool
    {
        $result = $this->db->query($this->prepare(
            "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            $value
        ));
        $this->forget($name);

        return $result === 1;
    }

    public function get(string $name): ?string
    {
        $value = $this->db->get_var($this->prepare(
            "SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
            $name
        ));

        return is_string($value) ? $value : null;
    }

    public function replace(string $name, string $expected, string $value): bool
    {
        $result = $this->db->query($this->prepare(
            "UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            $name,
            $expected
        ));
        $this->forget($name);

        return $result === 1;
    }

    public function delete(string $name, string $value): bool
    {
        $result = $this->db->query($this->prepare(
            "DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s",
            $name,
            $value
        ));
        $this->forget($name);

        return $result === 1;
    }

    private function forget(string $name): void
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * wpdb::prepare() for a query built from this class's own SQL and table
     * name. Every value goes through a placeholder.
     */
    private function prepare(string $query, mixed ...$args): string
    {
        /** @var literal-string $query */
        $sql = $this->db->prepare($query, ...$args);
        if (!is_string($sql)) {
            throw new \RuntimeException('wpdb::prepare() rejected a query: ' . $query);
        }

        return $sql;
    }
}
