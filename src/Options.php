<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

/**
 * Every option, hook and table name the plugin owns, in one place.
 */
final class Options
{
    public const PREFIX = 'indexnow_batch_';

    public const SETTINGS = 'indexnow_batch_settings';
    public const KEY = 'indexnow_batch_key';
    public const KEY_CHECK = 'indexnow_batch_key_check';
    public const KEY_FILE = 'indexnow_batch_key_file';
    public const LOG = 'indexnow_batch_log';
    public const LOCK = 'indexnow_batch_lock';
    public const BACKOFF = 'indexnow_batch_backoff';
    public const PAUSE = 'indexnow_batch_pause_until';
    public const SCHEMA = 'indexnow_batch_schema';
    public const SCHEMA_ERROR = 'indexnow_batch_schema_error';
    public const EVICTED = 'indexnow_batch_evicted';

    /** WP-Cron event hook. */
    public const CRON_HOOK = 'indexnow_batch_run';

    /** WP-Cron recurrence name. */
    public const CRON_SCHEDULE = 'indexnow_batch_interval';

    /** Table name without any prefix. The table is shared by every site of a network. */
    public const TABLE = 'indexnow_batch_queue';

    public const VERSION = '0.1.0';

    public const USER_AGENT = 'indexnow-batch/' . self::VERSION
        . ' (+https://github.com/IndianOpinion/indexnow-batch)';
}
