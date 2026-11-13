<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

use IndianOpinion\IndexNowBatch\Options;

/**
 * Creates and verifies the queue table.
 *
 * The table is shared by every site of a network and carries a `site_id`
 * column. dbDelta can silently skip a column, so creation is always
 * followed by SHOW COLUMNS, and the plugin refuses to queue or flush when a
 * column is missing.
 */
final class Schema
{
    /** Bumped whenever the table definition changes. */
    public const VERSION = '1';

    public const COLUMNS = [
        'id', 'site_id', 'url', 'url_hash', 'post_id', 'state', 'queued_at',
        'not_before', 'attempts', 'last_http', 'sent_at', 'retry_group',
    ];

    public function __construct(private \wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->base_prefix . Options::TABLE;
    }

    public function sql(): string
    {
        $table = $this->table();
        $charset = $this->db->get_charset_collate();

        // dbDelta wants two spaces after PRIMARY KEY and one definition per line.
        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL DEFAULT 1,
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  post_id bigint(20) unsigned NULL DEFAULT NULL,
  state varchar(10) NOT NULL DEFAULT 'pending',
  queued_at datetime NOT NULL,
  not_before datetime NULL DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_http smallint(5) unsigned NULL DEFAULT NULL,
  sent_at datetime NULL DEFAULT NULL,
  retry_group varchar(40) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY site_hash_state (site_id,url_hash,state),
  KEY site_state_due (site_id,state,not_before,queued_at),
  KEY post_id (post_id)
) {$charset};";
    }

    /**
     * Creates or upgrades the table, then verifies it.
     *
     * @throws SchemaException When a column is missing afterwards.
     */
    public function install(): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($this->sql());
        $this->verify();
    }

    /**
     * @throws SchemaException
     */
    public function verify(): void
    {
        $table = $this->table();
        $columns = $this->db->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        $missing = array_values(array_diff(self::COLUMNS, array_map('strval', $columns)));
        if ($missing !== []) {
            throw new SchemaException(sprintf(
                'Table %s is missing column(s): %s. Last database error: %s',
                $table,
                implode(', ', $missing),
                $this->db->last_error !== '' ? $this->db->last_error : 'none'
            ));
        }
    }

    public function drop(): void
    {
        $table = $this->table();
        $this->db->query("DROP TABLE IF EXISTS `{$table}`");
    }
}
