<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Cli;

use IndianOpinion\IndexNowBatch\Plugin;

/**
 * Batched IndexNow submissions: status, flush, log and the import pause.
 */
final class RootCommand
{
    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * Shows the key, verification, gates, queue counts and the last run.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $format = Output::format($assoc, 'table', ['table', 'json']);
        $status = $this->plugin->status()->collect();
        if ($format === 'json') {
            \WP_CLI::line(Output::json($status));

            return;
        }
        $last = is_array($status['last_run']) ? $status['last_run'] : null;
        $rows = [
            ['field' => 'key', 'value' => $status['key'] . ' (' . $status['key_source'] . ')'],
            ['field' => 'key location', 'value' => $status['key_location']],
            ['field' => 'verification', 'value' => $status['verification']['state']
                . ($status['verification']['checked_at'] !== null ? ' at ' . $status['verification']['checked_at'] : '')
                . ($status['verification']['detail'] !== '' ? ': ' . $status['verification']['detail'] : '')],
            ['field' => 'endpoint', 'value' => $status['endpoint']],
            ['field' => 'environment', 'value' => $status['environment']],
            ['field' => 'gates', 'value' => $status['gates'] === [] ? 'none' : implode('; ', $status['gates'])],
            ['field' => 'pending', 'value' => (string) $status['queue']['pending']],
            ['field' => 'due', 'value' => (string) $status['queue']['due']],
            ['field' => 'sent (kept)', 'value' => (string) $status['queue']['sent']],
            ['field' => 'failed (kept)', 'value' => (string) $status['queue']['failed']],
            ['field' => 'backing off', 'value' => $status['backing_off'] ? 'until ' . $status['backoff']['until'] : 'no'],
            ['field' => 'paused until', 'value' => Output::cell($status['paused_until'])],
            ['field' => 'last run', 'value' => $last === null ? 'never' : $last['at'] . ' ' . $last['outcome']
                . ' (sent ' . Output::cell($last['sent'] ?? 0) . ', http ' . Output::cell($last['http'] ?? null) . ')'],
            ['field' => 'next run', 'value' => Output::cell($status['next_run'])],
        ];
        foreach ($status['warnings'] as $warning) {
            $rows[] = ['field' => 'warning', 'value' => (string) $warning];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
    }

    /**
     * Runs a flush now.
     *
     * Exits 1 when the outcome is transport_error, refused, key_unverified
     * or schema_error, so a system cron wrapper can alert.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Run every check and print the batches, send nothing, change nothing.
     *
     * [--force]
     * : Ignore back-off and not-before times. Never ignores the visibility or environment gates.
     *
     * [--limit=<n>]
     * : Batch size for this run.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function flush(array $args, array $assoc): void
    {
        $dry = Output::flag($assoc, 'dry-run');
        $runArgs = ['dry_run' => $dry, 'force' => Output::flag($assoc, 'force')];
        if (isset($assoc['limit'])) {
            $runArgs['limit'] = Output::limit($assoc, 1000, 10000);
        }
        $result = $this->plugin->flush($runArgs);
        $record = $result->record;

        if ($dry) {
            foreach ($result->batches as $i => $batch) {
                \WP_CLI::line(sprintf('Batch %d: %d URL(s)', $i + 1, count($batch)));
                foreach ($batch as $url) {
                    \WP_CLI::line('  ' . $url);
                }
            }
        }
        $summary = sprintf(
            'Outcome %s: queue %d, due %d, sent %d, batches %d, dropped %s%s%s',
            $record['outcome'],
            $record['queue'],
            $record['due'],
            $record['sent'],
            $record['batches'],
            $record['dropped'] === [] ? 'none' : Output::cell($record['dropped']),
            $record['http'] !== null ? ', http ' . $record['http'] : '',
            $record['error'] !== '' ? ', error: ' . $record['error'] : ''
        );
        foreach ($record['warnings'] as $warning) {
            \WP_CLI::warning((string) $warning);
        }
        if ($result->isFailure()) {
            \WP_CLI::error($summary);
        }
        \WP_CLI::success($summary);
    }

    /**
     * Shows recent run records, newest first.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : How many records.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        $format = Output::format($assoc, 'table', ['table', 'json']);
        $records = $this->plugin->runLog()->recent(Output::limit($assoc, 20, 1000));
        if ($format === 'json') {
            \WP_CLI::line(Output::json($records));

            return;
        }
        $fields = ['at', 'outcome', 'queue', 'due', 'sent', 'dropped', 'http', 'batches', 'duration_ms', 'error'];
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = Output::cell($record[$field] ?? null);
            }
            $rows[] = $row;
        }
        \WP_CLI\Utils\format_items('table', $rows, $fields);
    }

    /**
     * Stops queueing posts for a bulk import.
     *
     * ## OPTIONS
     *
     * [--for=<seconds>]
     * : How long. Defaults to the pause setting (one hour).
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function pause(array $args, array $assoc): void
    {
        $seconds = $this->plugin->settings()->pauseSeconds();
        if (isset($assoc['for'])) {
            if (!is_numeric($assoc['for']) || (int) $assoc['for'] < 1) {
                \WP_CLI::error('--for must be a positive number of seconds.');
            }
            $seconds = (int) $assoc['for'];
        }
        $until = $this->plugin->pause()->pause($seconds, $this->plugin->clock()->now());
        \WP_CLI::success('Enqueue paused until ' . gmdate('Y-m-d\TH:i:s\Z', $until) . '.');
    }

    /**
     * Resumes queueing. Posts published while paused are not back-filled.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        $this->plugin->pause()->resume();
        \WP_CLI::success('Enqueue resumed.');
    }
}
