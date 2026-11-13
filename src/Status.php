<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

use IndianOpinion\IndexNowBatch\Key\KeyManager;
use IndianOpinion\IndexNowBatch\Key\KeyVerifier;
use IndianOpinion\IndexNowBatch\Queue\QueueRow;

/**
 * One screen of state, shared by `wp indexnow status` and the status page (section 11).
 */
final class Status
{
    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $plugin = $this->plugin;
        $settings = $plugin->settings();
        $now = $plugin->clock()->now();
        $keys = $plugin->keys();
        $rules = $plugin->rules();
        $schemaReady = $plugin->schemaReady();

        $keyError = $keys->configError();
        $key = $keyError === null ? $keys->key() : '';
        $keyUrl = $key !== '' ? $rules->keyLocation($key) : '';

        $verification = ['state' => 'not checked', 'checked_at' => null, 'detail' => ''];
        $cached = $plugin->verifier()->cached();
        if ($cached !== null) {
            $current = $key !== '' && $cached['fp'] === KeyVerifier::fingerprint($key, $keyUrl);
            $verification = [
                'state' => $current ? $cached['state'] : 'stale (key or home URL changed)',
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $cached['at']),
                'detail' => $cached['detail'],
            ];
        }

        $counts = [QueueRow::PENDING => 0, QueueRow::SENT => 0, QueueRow::FAILED => 0];
        $due = 0;
        if ($schemaReady) {
            $counts = array_merge($counts, $plugin->repo()->countByState());
            $due = $plugin->repo()->countDue($now + 60);
        }

        $backoff = $plugin->backoff()->state();
        $backoffUntil = $plugin->backoff()->activeUntil($now);
        $pausedUntil = $plugin->pause()->activeUntil($now);

        $gates = [];
        if (!$schemaReady) {
            $gates[] = 'queue table not ready: ' . (string) get_option(Options::SCHEMA_ERROR, 'not installed');
        }
        if ($settings->disabled()) {
            $gates[] = 'disabled by INDEXNOW_BATCH_DISABLE';
        }
        if ((string) get_option('blog_public', '1') === '0') {
            $gates[] = 'site discourages search engines (blog_public is 0): queueing, not sending';
        }
        $environment = wp_get_environment_type();
        if (!in_array($environment, $settings->environments(), true)) {
            $gates[] = 'environment ' . $environment . ' is not in ' . implode(', ', $settings->environments());
        }
        if ($keyError !== null) {
            $gates[] = 'key configuration error: ' . $keyError;
        }
        if ($backoff['key_hold']) {
            $gates[] = 'held after a 403 until the key file verifies';
        }
        if ($backoffUntil !== null) {
            $gates[] = 'backing off until ' . gmdate('Y-m-d\TH:i:s\Z', $backoffUntil) . ' (' . $backoff['reason'] . ')';
        }
        if ($cached !== null && $cached['state'] === KeyVerifier::UNVERIFIED && $verification['state'] === KeyVerifier::UNVERIFIED) {
            $gates[] = 'key file did not verify: ' . $cached['detail'];
        }

        $warnings = $settings->warnings();
        if ($rules->scheme() === 'http') {
            $warnings[] = 'The home URL uses http. URLs are sent as http; if the site is served over https, fix the home URL.';
        }
        $fileWarning = $plugin->runLog()->fileWarning();
        if ($fileWarning !== null) {
            $warnings[] = $fileWarning;
        }
        $last = $plugin->runLog()->last();
        $next = $plugin->scheduler()->next();
        $stale = 3 * $settings->interval() * 60;
        $lastAt = $last !== null ? strtotime((string) ($last['at'] ?? '')) : false;
        if ($lastAt !== false && $lastAt < $now - $stale) {
            $warnings[] = sprintf(
                'The last run was %d minutes ago, more than three intervals. Check that WP-Cron or a system cron is running.',
                intdiv($now - $lastAt, 60)
            );
        } elseif ($last === null && $next !== null && $next < $now - $stale) {
            $warnings[] = 'The flush event is overdue by more than three intervals and has never run. Check WP-Cron.';
        }
        if ($next === null && $schemaReady) {
            $warnings[] = 'No flush event is scheduled. Deactivate and reactivate the plugin, or run `wp indexnow flush` from a system cron.';
        }

        return [
            'version' => Options::VERSION,
            'key' => $key !== '' ? KeyManager::mask($key) : '',
            'key_source' => $keys->source(),
            'key_location' => $key !== '' ? $rules->keyLocation(KeyManager::mask($key)) : '',
            'verification' => $verification,
            'endpoint' => $plugin->submitter()->endpoint(),
            'environment' => $environment,
            'gates' => $gates,
            'queue' => [
                'pending' => $counts[QueueRow::PENDING],
                'due' => $due,
                'sent' => $counts[QueueRow::SENT],
                'failed' => $counts[QueueRow::FAILED],
            ],
            'backing_off' => $backoffUntil !== null,
            'backoff' => [
                'until' => $backoffUntil !== null ? gmdate('Y-m-d\TH:i:s\Z', $backoffUntil) : null,
                'failures' => $backoff['failures'],
                'reason' => $backoff['reason'],
                'key_hold' => $backoff['key_hold'],
            ],
            'paused_until' => $pausedUntil !== null ? gmdate('Y-m-d\TH:i:s\Z', $pausedUntil) : null,
            'last_run' => $last,
            'next_run' => $next !== null ? gmdate('Y-m-d\TH:i:s\Z', $next) : null,
            'warnings' => $warnings,
        ];
    }
}
