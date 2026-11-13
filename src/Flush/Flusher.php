<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Flush;

use IndianOpinion\IndexNowBatch\Clock;
use IndianOpinion\IndexNowBatch\Key\KeyManager;
use IndianOpinion\IndexNowBatch\Key\KeyVerifier;
use IndianOpinion\IndexNowBatch\Logger;
use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Queue\Eligibility;
use IndianOpinion\IndexNowBatch\Queue\QueueRepository;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;
use IndianOpinion\IndexNowBatch\Settings;
use IndianOpinion\IndexNowBatch\SiteFilter;
use IndianOpinion\IndexNowBatch\Url\UrlRules;

/**
 * The flush (rules 22 to 40): lock, retention, gates, key check, send-time
 * re-check, batching, response handling and the run record.
 */
final class Flusher
{
    /** Not-before comparisons tolerate this much clock skew between nodes. */
    public const SKEW = 60;

    /** Outcomes ranked worst first, for runs that send more than one batch. */
    private const RANK = ['key_unverified' => 5, 'transport_error' => 4, 'refused' => 3, 'accepted_pending' => 2, 'submitted' => 1];

    /** @var array<string, mixed> */
    private array $record = [];

    /** @var list<string> */
    private array $outcomes = [];

    /** @var list<list<string>> */
    private array $batches = [];

    /** @var list<string> */
    private array $sentUrls = [];

    private bool $dry = false;

    public function __construct(
        private Settings $settings,
        private QueueRepository $repo,
        private UrlRules $rules,
        private Eligibility $eligibility,
        private KeyManager $keys,
        private KeyVerifier $verifier,
        private Submitter $submitter,
        private Backoff $backoff,
        private Lock $lock,
        private RunLog $log,
        private Clock $clock,
        private Logger $logger,
        private ?\Closure $schemaReady = null,
    ) {
    }

    /**
     * @param array{dry_run?: bool, force?: bool, limit?: int|string|null} $args
     */
    public function run(array $args = []): FlushResult
    {
        $started = microtime(true);
        $now = $this->clock->now();
        $this->dry = !empty($args['dry_run']);
        $force = !empty($args['force']);
        $limit = $this->settings->batchSize();
        if (isset($args['limit']) && is_numeric($args['limit'])) {
            $limit = max(1, min(Settings::MAX_BATCH, (int) $args['limit']));
        }

        $this->outcomes = [];
        $this->batches = [];
        $this->sentUrls = [];
        $this->record = [
            'at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'outcome' => '',
            'queue' => 0,
            'due' => 0,
            'sent' => 0,
            'deferred' => 0,
            'dropped' => [],
            'http' => null,
            'error' => '',
            'batches' => 0,
            'duration_ms' => 0,
            'urls' => [],
            'n_urls' => 0,
            'warnings' => $this->settings->warnings(),
            'dry_run' => $this->dry,
            'forced' => $force,
        ];

        if (!$this->dry && !$this->lock->acquire($now)) {
            return $this->finish('locked', $started);
        }
        try {
            $outcome = $this->runLocked($now, $force, $limit);
        } catch (\Throwable $e) {
            // A database failure or a throwing hook still ends in one run
            // record (rule 40), and the CLI exits 1 on it.
            $this->record['error'] = '';
            $this->setError(get_class($e) . ': ' . $e->getMessage());
            $this->logger->error('flush failed: ' . (string) $this->record['error']);
            $outcome = 'error';
        } finally {
            if (!$this->dry) {
                $this->lock->release();
            }
        }

        return $this->finish($outcome, $started);
    }

    private function runLocked(int $now, bool $force, int $limit): string
    {
        if ($this->schemaReady !== null && !($this->schemaReady)()) {
            $error = get_option(Options::SCHEMA_ERROR, '');
            $this->record['error'] = is_string($error) && $error !== '' ? $error : 'queue table is not installed';

            return 'schema_error';
        }
        if (!$this->dry) {
            $maxAge = $this->settings->maxAgeDays() * 86400;
            $purged = $this->repo->purge(
                $now - $maxAge,
                $now - ($this->settings->resubmitInterval() * 60 + 86400),
                $now - $maxAge
            );
            $this->dropCount('expired', $purged['pending']);
            $evicted = (int) get_option(Options::EVICTED, 0);
            if ($evicted > 0) {
                $this->dropCount('evicted', $evicted);
                delete_option(Options::EVICTED);
            }
        }

        $this->record['queue'] = $this->repo->countPending();
        $this->record['due'] = $force ? $this->record['queue'] : $this->repo->countDue($now + self::SKEW);

        if ($this->settings->disabled()) {
            return 'disabled';
        }
        if ((string) get_option('blog_public', '1') === '0') {
            return 'skipped_not_public';
        }
        $environment = wp_get_environment_type();
        if (!in_array($environment, $this->settings->environments(), true)) {
            $this->record['error'] = 'environment is ' . $environment;

            return 'skipped_environment';
        }
        $configError = $this->keys->configError();
        if ($configError !== null) {
            $this->record['error'] = $configError;
            $this->logger->errorOnce('key_config', 'configuration error: ' . $configError);

            return 'key_unverified';
        }
        if (!$force) {
            $until = $this->backoff->activeUntil($now);
            if ($until !== null) {
                $this->record['resume_at'] = gmdate('Y-m-d\TH:i:s\Z', $until);

                return 'backing_off';
            }
        }
        if ($this->record['due'] === 0) {
            return 'empty';
        }

        $key = $this->keys->key();
        $keyUrl = $this->rules->keyLocation($key);
        if ($this->backoff->keyHold()) {
            // After a 403 the key must be seen again before anything is sent.
            $check = $this->verifier->check($key, $keyUrl);
            if (!$this->dry) {
                $this->verifier->store($key, $keyUrl, $check['state'], $check['detail']);
            }
            if ($check['state'] === KeyVerifier::UNVERIFIED) {
                $this->record['error'] = 'held after 403: ' . $check['detail'];

                return 'key_unverified';
            }
            if (!$this->dry) {
                $until = $this->backoff->releaseKeyHold($now);
                if (!$force) {
                    $this->record['resume_at'] = gmdate('Y-m-d\TH:i:s\Z', $until);

                    return 'backing_off';
                }
            }
        } else {
            $check = $this->verifier->ensure($key, $keyUrl, !$this->dry);
            if ($check['state'] === KeyVerifier::UNVERIFIED) {
                $this->record['error'] = 'key file check failed: ' . $check['detail'];

                return 'key_unverified';
            }
            if ($check['state'] === KeyVerifier::UNKNOWN && $check['checked']) {
                // Cached for 24 hours, so this warning appears about once a day.
                $warning = 'key self-check could not complete (' . $check['detail'] . '); sending anyway';
                $this->record['warnings'][] = $warning;
                $this->logger->error($warning);
            }
        }

        return $this->sendBatches($now, $force, $limit, $key, $keyUrl);
    }

    private function sendBatches(int $now, bool $force, int $limit, string $key, string $keyUrl): string
    {
        $processed = [];
        $requests = 0;
        $iterations = 0;
        $sawRows = false;
        $maxRequests = $this->settings->batchesPerRun();

        while ($requests < $maxRequests && $iterations < $maxRequests * 4) {
            $iterations++;
            $rows = $this->repo->fetchNextGroup($processed);
            $isGroup = $rows !== [];
            if (!$isGroup) {
                $rows = $this->repo->fetchDue($now + self::SKEW, $limit, $force, $processed);
            }
            if ($rows === []) {
                break;
            }
            $sawRows = true;
            foreach ($rows as $row) {
                $processed[] = $row->id;
            }

            $survivors = $this->bulkFilter($this->recheck($rows, $now, $force));
            if ($survivors === []) {
                continue;
            }

            $urls = array_map(static fn (QueueRow $row): string => $row->url, $survivors);
            $kept = Submitter::fit($this->rules->hostField(), $key, $keyUrl, $urls);
            if (count($kept) < count($urls)) {
                // Rows that did not fit stay pending for the next request.
                $left = array_slice($survivors, count($kept));
                $leftIds = array_map(static fn (QueueRow $row): int => $row->id, $left);
                $processed = array_values(array_diff($processed, $leftIds));
                $survivors = array_slice($survivors, 0, count($kept));
                $urls = $kept;
            }

            $requests++;
            $this->batches[] = $urls;
            array_push($this->sentUrls, ...$urls);
            if ($this->dry) {
                continue;
            }
            $payload = Submitter::payload($this->rules->hostField(), $key, $keyUrl, $urls);
            $response = $this->submitter->send($payload);
            if ($this->handle($response, $survivors, $isGroup, $now)) {
                break;
            }
        }

        $this->record['batches'] = $requests;
        if ($this->dry) {
            return 'dry_run';
        }
        if ($requests === 0) {
            return $sawRows ? 'nothing_submittable' : 'empty';
        }
        $worst = 'submitted';
        foreach ($this->outcomes as $outcome) {
            if (self::RANK[$outcome] > self::RANK[$worst]) {
                $worst = $outcome;
            }
        }

        return $worst;
    }

    /**
     * Send-time re-check (rules 26 and 27). Returns the rows that may go,
     * each carrying the URL and hash recomputed now.
     *
     * @param list<QueueRow> $rows
     * @return list<QueueRow>
     */
    private function recheck(array $rows, int $now, bool $force): array
    {
        $postIds = [];
        foreach ($rows as $row) {
            if ($row->postId !== null) {
                $postIds[] = $row->postId;
            }
        }
        if ($postIds !== [] && function_exists('_prime_post_caches')) {
            _prime_post_caches(array_values(array_unique($postIds)), false, true);
        }

        $drops = [];
        $candidates = [];
        $seen = [];
        $expiry = $now - $this->settings->maxAgeDays() * 86400;
        foreach ($rows as $row) {
            if ($row->state === QueueRow::PENDING && $row->queuedAt < $expiry) {
                // Purged at the start of a real run; a dry run counts it here.
                $drops[] = [$row, 'expired', $row->url];
                continue;
            }
            $post = null;
            if ($row->postId !== null) {
                $post = get_post($row->postId);
                if (!$post instanceof \WP_Post) {
                    $drops[] = [$row, 'gone', $row->url];
                    continue;
                }
                $reason = $this->eligibility->check($post);
                if ($reason !== null) {
                    $drops[] = [$row, $reason, $row->url];
                    continue;
                }
                // get_permalink() can return false through filters.
                $url = (string) get_permalink($post);
                if ($url === '') {
                    $drops[] = [$row, 'gone', $row->url];
                    continue;
                }
            } else {
                $url = $row->url;
                $resolved = url_to_postid($url);
                if ($resolved > 0) {
                    $post = get_post($resolved);
                    if (!$post instanceof \WP_Post) {
                        $drops[] = [$row, 'gone', $url];
                        continue;
                    }
                    $reason = $this->eligibility->check($post);
                    if ($reason !== null) {
                        $drops[] = [$row, $reason, $url];
                        continue;
                    }
                    $permalink = (string) get_permalink($post);
                    if ($permalink === '' || UrlRules::dedupeKey($permalink) !== UrlRules::dedupeKey($url)) {
                        // Resolves to a post whose own URL is different: the
                        // raw URL redirects or is ambiguous. Dropping is the
                        // safe direction to be wrong in.
                        $drops[] = [$row, 'ambiguous', $url];
                        continue;
                    }
                }
            }
            if ($this->rules->check($url) !== null) {
                $drops[] = [$row, 'foreign', $url];
                continue;
            }
            $hash = UrlRules::hash($url);
            if (isset($seen[$hash])) {
                $drops[] = [$row, 'duplicate', $url];
                continue;
            }
            $seen[$hash] = true;
            $candidates[] = [$row, $url, $hash, $post];
        }

        $resubmit = $this->settings->resubmitInterval() * 60;
        $lastSent = [];
        if (!$force && $resubmit > 0 && $candidates !== []) {
            $lastSent = $this->repo->lastSent(array_map(static fn (array $c): string => $c[2], $candidates));
        }

        $survivors = [];
        foreach ($candidates as [$row, $url, $hash, $post]) {
            if (isset($lastSent[$hash]) && $lastSent[$hash] + $resubmit > $now + self::SKEW) {
                if (!$this->dry) {
                    $this->repo->setNotBefore($row->id, $lastSent[$hash] + $resubmit);
                }
                $this->record['deferred']++;
                continue;
            }
            [$allowed, $error] = SiteFilter::allows($url, $post, SiteFilter::FLUSH);
            if ($error !== '') {
                $this->warnOnce($error);
            }
            if (!$allowed) {
                $drops[] = [$row, 'filter', $url];
                continue;
            }
            $copy = clone $row;
            $copy->url = $url;
            $copy->hash = $hash;
            $survivors[] = $copy;
        }

        $this->applyDrops($drops);

        return $survivors;
    }

    /**
     * The `indexnow_batch_urls` filter, once per batch.
     *
     * @param list<QueueRow> $rows
     * @return list<QueueRow>
     */
    private function bulkFilter(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $urls = array_map(static fn (QueueRow $row): string => $row->url, $rows);
        $filtered = apply_filters('indexnow_batch_urls', $urls);
        if (!is_array($filtered)) {
            $this->warnOnce('indexnow_batch_urls did not return an array; ignored');

            return $rows;
        }
        $input = array_flip($urls);
        $keep = [];
        $unknown = 0;
        foreach ($filtered as $url) {
            if (is_string($url) && isset($input[$url])) {
                $keep[$url] = true;
            } else {
                $unknown++;
            }
        }
        if ($unknown > 0) {
            $this->warnOnce(sprintf('indexnow_batch_urls returned %d URL(s) not in the batch; ignored', $unknown));
        }
        $out = [];
        $drops = [];
        foreach ($rows as $row) {
            if (isset($keep[$row->url])) {
                $out[] = $row;
            } else {
                $drops[] = [$row, 'filter', $row->url];
            }
        }
        $this->applyDrops($drops);

        return $out;
    }

    /**
     * Applies the endpoint's answer to the rows of one batch (rules 32 to 36).
     *
     * @param list<QueueRow> $rows
     * @return bool Whether the run must stop.
     */
    private function handle(SubmitResponse $response, array $rows, bool $isGroup, int $now): bool
    {
        $code = $response->code;
        $this->record['http'] = $code === 0 ? null : $code;
        $ids = array_map(static fn (QueueRow $row): int => $row->id, $rows);
        $urls = array_map(static fn (QueueRow $row): string => $row->url, $rows);

        if ($response->isTransportError()) {
            $until = $this->backoff->fail($now, 'transport error');
            $this->record['resume_at'] = gmdate('Y-m-d\TH:i:s\Z', $until);
            $this->setError($response->error);
            $this->outcomes[] = 'transport_error';

            return true;
        }

        if ($code === 200 || $code === 202) {
            $this->repo->moveTo(QueueRow::SENT, $rows, $code, $now);
            $this->backoff->reset();
            $this->record['sent'] += count($rows);
            if ($code === 202) {
                $this->record['note'] = 'accepted; the engine has not yet validated the key';
            }
            do_action('indexnow_batch_submitted', $urls, $code);
            $this->outcomes[] = $code === 202 ? 'accepted_pending' : 'submitted';

            return false;
        }

        $this->setError($response->error);
        if ($code === 403) {
            $this->backoff->holdForKey('403 from endpoint');
            $this->verifier->invalidate();
            $this->outcomes[] = 'key_unverified';

            return true;
        }
        if ($code === 429) {
            $retryAfter = Backoff::parseRetryAfter($response->retryAfter, $now);
            $until = $this->backoff->fail($now, 'HTTP 429', $retryAfter);
            $this->record['resume_at'] = gmdate('Y-m-d\TH:i:s\Z', $until);
            $this->outcomes[] = 'refused';

            return true;
        }
        if ($code >= 500 && $code <= 599) {
            $until = $this->backoff->fail($now, 'HTTP ' . $code);
            $this->record['resume_at'] = gmdate('Y-m-d\TH:i:s\Z', $until);
            $this->outcomes[] = 'refused';

            return true;
        }

        $this->repo->recordAttempt($ids, $code);
        $this->outcomes[] = 'refused';

        if ($code === 400 || $code === 422) {
            if ($isGroup) {
                // Already halved and retried once: give these up as failed.
                $failed = [];
                foreach ($rows as $row) {
                    $copy = clone $row;
                    $copy->attempts++;
                    $failed[] = $copy;
                }
                $this->repo->moveTo(QueueRow::FAILED, $failed, $code, $now);
                $this->dropCount('failed', count($rows), $urls);

                return false;
            }
            $group = bin2hex(random_bytes(8));
            if (count($ids) === 1) {
                $this->repo->assignGroup($ids, $group . '-a');
            } else {
                [$first, $second] = array_chunk($ids, max(1, (int) ceil(count($ids) / 2)));
                $this->repo->assignGroup($first, $group . '-a');
                $this->repo->assignGroup($second, $group . '-b');
            }

            return false;
        }

        // Any other code, 3xx included: count the attempt, give up at the maximum.
        $max = $this->settings->maxAttempts();
        $gaveUp = $this->repo->deleteAtAttempts($ids, $max);
        if ($gaveUp > 0) {
            $gone = [];
            foreach ($rows as $row) {
                if ($row->attempts + 1 >= $max) {
                    $gone[] = $row->url;
                }
            }
            $this->dropCount('gave_up', $gaveUp, $gone);
        }

        return false;
    }

    /**
     * @param list<array{0: QueueRow, 1: string, 2: string}> $drops
     */
    private function applyDrops(array $drops): void
    {
        if ($drops === []) {
            return;
        }
        $ids = [];
        foreach ($drops as [$row, $reason, $url]) {
            $ids[] = $row->id;
            $this->dropCount($reason, 1, [$url]);
        }
        if (!$this->dry) {
            $this->repo->delete($ids);
        }
    }

    /**
     * @param list<string> $urls
     */
    private function dropCount(string $reason, int $count, array $urls = []): void
    {
        if ($count <= 0) {
            return;
        }
        $this->record['dropped'][$reason] = ($this->record['dropped'][$reason] ?? 0) + $count;
        if ($this->dry) {
            return;
        }
        foreach ($urls as $url) {
            do_action('indexnow_batch_dropped', $url, $reason);
        }
    }

    private function warnOnce(string $warning): void
    {
        if (!in_array($warning, $this->record['warnings'], true)) {
            $this->record['warnings'][] = $warning;
            $this->logger->error($warning);
        }
    }

    private function setError(string $error): void
    {
        if ($error === '') {
            return;
        }
        // Mask the key before truncating, so a cut cannot leave part of it.
        $error = $this->scrub($error);
        if (preg_match('/^.{0,160}/su', $error, $m) === 1) {
            $error = $m[0];
        } else {
            $error = substr($error, 0, 160);
        }
        $this->record['error'] = $error;
    }

    /**
     * The key is never written into a record, even when an endpoint echoes it.
     */
    private function scrubKey(): void
    {
        $this->record['error'] = $this->scrub((string) $this->record['error']);
        foreach ($this->record['warnings'] as $i => $warning) {
            $this->record['warnings'][$i] = $this->scrub((string) $warning);
        }
    }

    private function scrub(string $text): string
    {
        $key = $this->keys->usableKey();

        return $key === null ? $text : str_replace($key, KeyManager::mask($key), $text);
    }

    private function finish(string $outcome, float $started): FlushResult
    {
        $this->record['outcome'] = $outcome;
        $this->record['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $this->record['n_urls'] = count($this->sentUrls);
        $this->record['urls'] = array_slice($this->sentUrls, 0, 50);
        $this->scrubKey();
        if ($this->record['error'] !== '') {
            $this->setError((string) $this->record['error']);
        }
        $this->log->write($this->record);

        return new FlushResult($this->record, $this->batches);
    }
}
