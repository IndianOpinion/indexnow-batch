<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

use IndianOpinion\IndexNowBatch\Logger;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Settings;

/**
 * One record per flush (rules 40 and 41): a ring buffer in one
 * non-autoloaded option, an optional JSON-lines file, optional error_log
 * lines, and the `indexnow_batch_flushed` action.
 */
final class RunLog
{
    public function __construct(private Settings $settings, private Logger $logger)
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function write(array $record): void
    {
        unset($record['key']);
        $records = $this->all();
        $records[] = $record;
        $size = $this->settings->logSize();
        if (count($records) > $size) {
            $records = array_slice($records, -$size);
        }
        update_option(Options::LOG, $records, false);

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $line = $line === false ? '{}' : $line;
        $this->appendFile($line);
        $this->logger->debug('run ' . $line);
        do_action('indexnow_batch_flushed', $record);
    }

    /**
     * @return list<array<string, mixed>> Oldest first.
     */
    public function all(): array
    {
        $value = get_option(Options::LOG, []);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @return list<array<string, mixed>> Newest first.
     */
    public function recent(int $limit): array
    {
        return array_slice(array_reverse($this->all()), 0, max(1, $limit));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        $all = $this->all();

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * A warning when a log file is configured but cannot be used, else null.
     * The plugin never creates the file.
     */
    public function fileWarning(): ?string
    {
        $path = $this->settings->logFile();
        if ($path === '') {
            return null;
        }
        if (!is_file($path)) {
            return 'Log file ' . $path . ' does not exist; the plugin does not create it. Records are kept in the database.';
        }
        if (!is_writable($path)) {
            return 'Log file ' . $path . ' is not writable. Records are kept in the database.';
        }

        return null;
    }

    private function appendFile(string $line): void
    {
        $path = $this->settings->logFile();
        if ($path === '' || $this->fileWarning() !== null) {
            return;
        }
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            $this->logger->errorOnce('log_file', 'could not open log file ' . $path);

            return;
        }
        if (flock($handle, LOCK_EX)) {
            fwrite($handle, $line . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}
