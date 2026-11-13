<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Cli;

use IndianOpinion\IndexNowBatch\Plugin;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Url\UrlRules;

/**
 * Lists, adds and removes queue rows.
 */
final class QueueCommand
{
    public function __construct(private Plugin $plugin)
    {
    }

    private function requireSchema(): void
    {
        if (!$this->plugin->schemaReady()) {
            \WP_CLI::error('The queue table is not ready. Run `wp indexnow status` for the reason.');
        }
    }

    /**
     * Lists queue rows.
     *
     * ## OPTIONS
     *
     * [--state=<state>]
     * : pending, sent, failed or all.
     * ---
     * default: pending
     * ---
     *
     * [--limit=<n>]
     * : How many rows.
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : table, csv or json.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $this->requireSchema();
        $state = isset($assoc['state']) ? (string) $assoc['state'] : QueueRow::PENDING;
        if (!in_array($state, [QueueRow::PENDING, QueueRow::SENT, QueueRow::FAILED, 'all'], true)) {
            \WP_CLI::error('--state must be pending, sent, failed or all.');
        }
        $format = Output::format($assoc, 'table', ['table', 'csv', 'json']);
        $rows = array_map(
            static fn (QueueRow $row): array => $row->toArray(),
            $this->plugin->repo()->list($state, Output::limit($assoc, 50, 100000))
        );
        if ($format === 'json') {
            \WP_CLI::line(Output::json($rows));

            return;
        }
        $fields = ['id', 'state', 'url', 'post_id', 'queued_at', 'not_before', 'attempts', 'last_http', 'sent_at'];
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    /**
     * Queues URLs, or post IDs with --post.
     *
     * Prints each input as accepted or refused, with the reason.
     *
     * ## OPTIONS
     *
     * <url-or-id>...
     * : URLs, or post IDs with --post.
     *
     * [--post]
     * : Treat the arguments as post IDs.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function add(array $args, array $assoc): void
    {
        $this->requireSchema();
        if ($args === []) {
            \WP_CLI::error('Give at least one URL or post ID.');
        }
        $byPost = Output::flag($assoc, 'post');
        $enqueuer = $this->plugin->enqueuer();
        $rows = [];
        $accepted = 0;
        foreach ($args as $arg) {
            if ($byPost) {
                if (!ctype_digit($arg)) {
                    \WP_CLI::error(sprintf('"%s" is not a post ID.', $arg));
                }
                $outcome = $enqueuer->queuePost((int) $arg);
            } else {
                $outcome = $enqueuer->queueUrl($arg);
            }
            $accepted += $outcome->accepted ? 1 : 0;
            $rows[] = [
                'input' => $arg,
                'result' => $outcome->accepted ? 'accepted' : 'refused',
                'reason' => $outcome->reason,
                'not_before' => $outcome->notBefore !== null ? gmdate('Y-m-d\TH:i:s\Z', $outcome->notBefore) : '',
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['input', 'result', 'reason', 'not_before']);
        \WP_CLI::success(sprintf('%d accepted, %d refused.', $accepted, count($args) - $accepted));
    }

    /**
     * Removes pending rows for the given URLs.
     *
     * ## OPTIONS
     *
     * <url>...
     * : URLs to remove.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function remove(array $args, array $assoc): void
    {
        $this->requireSchema();
        if ($args === []) {
            \WP_CLI::error('Give at least one URL.');
        }
        $hashes = array_map(static fn (string $url): string => UrlRules::hash(trim($url)), $args);
        $removed = $this->plugin->repo()->removePending($hashes);
        \WP_CLI::success(sprintf('Removed %d pending row(s).', $removed));
    }

    /**
     * Empties the pending queue.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function clear(array $args, array $assoc): void
    {
        $this->requireSchema();
        \WP_CLI::confirm('Remove every pending row?', $assoc);
        $removed = $this->plugin->repo()->clearPending();
        \WP_CLI::success(sprintf('Removed %d pending row(s).', $removed));
    }
}
