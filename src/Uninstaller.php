<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

use IndianOpinion\IndexNowBatch\Key\PhysicalKeyFile;
use IndianOpinion\IndexNowBatch\Queue\Schema;

/**
 * Removes everything the plugin created (section 6.5). It never touches an
 * operator-supplied log file, and removes a physical key file only when the
 * plugin wrote it and it still holds the key.
 */
final class Uninstaller
{
    public function __construct(private \wpdb $db)
    {
    }

    public function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                try {
                    $this->removeSite();
                } finally {
                    restore_current_blog();
                }
            }
        } else {
            $this->removeSite();
        }
        (new Schema($this->db))->drop();
    }

    private function removeSite(): void
    {
        (new PhysicalKeyFile(''))->remove();
        wp_clear_scheduled_hook(Options::CRON_HOOK);
        $names = $this->db->get_col($this->prepare(
            "SELECT option_name FROM {$this->db->options} WHERE option_name LIKE %s",
            $this->db->esc_like(Options::PREFIX) . '%'
        ));
        foreach ($names as $name) {
            delete_option((string) $name);
        }
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
