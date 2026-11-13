<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Admin;

use IndianOpinion\IndexNowBatch\Options;
use IndianOpinion\IndexNowBatch\Plugin;
use IndianOpinion\IndexNowBatch\Settings;

/**
 * Settings, IndexNow: status, the last 20 runs, settings and three actions
 * (section 5.5). Every action is a POST with a nonce and a capability check.
 */
final class StatusPage
{
    public const SLUG = 'indexnow-batch';
    public const CAPABILITY = 'manage_options';
    public const ACTIONS = ['verify', 'flush', 'rotate'];
    private const GROUP = 'indexnow_batch';

    public function __construct(private Plugin $plugin)
    {
    }

    public function addMenu(): void
    {
        add_options_page('IndexNow Batch', 'IndexNow', self::CAPABILITY, self::SLUG, [$this, 'render']);
    }

    public function registerSettings(): void
    {
        register_setting(self::GROUP, Options::SETTINGS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
            'show_in_rest' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        $warnings = [];
        $clean = Settings::sanitizeStored($input, $warnings);
        foreach ($warnings as $warning) {
            add_settings_error(Options::SETTINGS, 'indexnow_batch_clamped', $warning, 'warning');
        }

        return $clean;
    }

    public static function nonceAction(string $action): string
    {
        return 'indexnow_batch_' . $action;
    }

    /**
     * admin-post.php entry point: checks, performs, redirects back.
     */
    public function onAdminPost(): void
    {
        $action = isset($_POST['action']) ? sanitize_key((string) wp_unslash($_POST['action'])) : '';
        $action = (string) preg_replace('/^indexnow_batch_/', '', $action);
        $notice = $this->handle($action, $_POST);
        wp_safe_redirect(add_query_arg('indexnow_batch_notice', rawurlencode($notice), $this->pageUrl()));
        exit;
    }

    /**
     * Performs one action after the capability and nonce checks. Returns a
     * short notice for the page. Calls wp_die() on a failed check.
     *
     * @param array<string, mixed> $request
     */
    public function handle(string $action, array $request): string
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html('You do not have permission to do this.'), '', ['response' => 403]);
        }
        if (!in_array($action, self::ACTIONS, true)) {
            wp_die(esc_html('Unknown action.'), '', ['response' => 400]);
        }
        check_admin_referer(self::nonceAction($action));

        switch ($action) {
            case 'verify':
                $keys = $this->plugin->keys();
                if ($keys->configError() !== null) {
                    return 'Key configuration error: ' . $keys->configError();
                }
                $key = $keys->key();
                $url = $this->plugin->rules()->keyLocation($key);
                $result = $this->plugin->verifier()->check($key, $url);
                $this->plugin->verifier()->store($key, $url, $result['state'], $result['detail']);

                return 'Key ' . $result['state'] . ($result['detail'] !== '' ? ': ' . $result['detail'] : '');
            case 'flush':
                $record = $this->plugin->flush([])->record;

                return sprintf('Run finished: %s, sent %d.', $record['outcome'], $record['sent']);
            default:
                if (($request['confirm'] ?? '') !== '1') {
                    return 'Key not rotated: tick the confirmation box.';
                }
                try {
                    $this->plugin->rotateKey();
                } catch (\Throwable $e) {
                    return 'Key not rotated: ' . $e->getMessage();
                }

                return 'Key rotated. Verification cleared.';
        }
    }

    private function pageUrl(): string
    {
        return admin_url('options-general.php?page=' . self::SLUG);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', ['response' => 403]);
        }
        $status = $this->plugin->status()->collect();
        $settings = $this->plugin->settings()->toArray();
        $records = $this->plugin->runLog()->recent(20);
        $notice = isset($_GET['indexnow_batch_notice'])
            ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['indexnow_batch_notice'])))
            : '';

        echo '<div class="wrap"><h1>' . esc_html('IndexNow Batch') . '</h1>';
        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }
        settings_errors(Options::SETTINGS);
        foreach ($status['warnings'] as $warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html((string) $warning) . '</p></div>';
        }

        echo '<h2>' . esc_html('Status') . '</h2><table class="widefat striped"><tbody>';
        $verification = $status['verification'];
        $rows = [
            'Key' => $status['key'] . ' (' . $status['key_source'] . ')',
            'Key location' => $status['key_location'],
            'Verification' => $verification['state'] . ($verification['checked_at'] !== null ? ' at ' . $verification['checked_at'] : '')
                . ($verification['detail'] !== '' ? ': ' . $verification['detail'] : ''),
            'Endpoint' => $status['endpoint'],
            'Environment' => $status['environment'],
            'Gates' => $status['gates'] === [] ? 'none' : implode('; ', $status['gates']),
            'Pending' => (string) $status['queue']['pending'],
            'Due' => (string) $status['queue']['due'],
            'Sent (kept for resubmit checks)' => (string) $status['queue']['sent'],
            'Failed (kept for review)' => (string) $status['queue']['failed'],
            'Backing off' => $status['backing_off'] ? 'until ' . (string) $status['backoff']['until'] : 'no',
            'Paused until' => (string) ($status['paused_until'] ?? ''),
            'Next run' => (string) ($status['next_run'] ?? ''),
        ];
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html('Actions') . '</h2><div style="display:flex;gap:1em;flex-wrap:wrap">';
        $this->actionForm('verify', 'Verify key');
        $this->actionForm('flush', 'Run now');
        $this->actionForm('rotate', 'Rotate key', true);
        echo '</div>';

        echo '<h2>' . esc_html('Recent runs') . '</h2><table class="widefat striped"><thead><tr>';
        $fields = ['at', 'outcome', 'queue', 'due', 'sent', 'dropped', 'http', 'batches', 'error'];
        foreach ($fields as $field) {
            echo '<th>' . esc_html($field) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ($records === []) {
            echo '<tr><td colspan="' . esc_attr((string) count($fields)) . '">' . esc_html('No runs yet.') . '</td></tr>';
        }
        foreach ($records as $record) {
            echo '<tr>';
            foreach ($fields as $field) {
                $value = $record[$field] ?? '';
                $text = is_array($value) ? (string) wp_json_encode($value) : (is_scalar($value) ? (string) $value : '');
                echo '<td>' . esc_html($text) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        $this->settingsForm($settings);
        echo '</div>';
    }

    private function actionForm(string $action, string $label, bool $confirm = false): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr('indexnow_batch_' . $action) . '">';
        wp_nonce_field(self::nonceAction($action));
        if ($confirm) {
            echo '<label><input type="checkbox" name="confirm" value="1" required> '
                . esc_html('I understand the old key stops working') . '</label> ';
        }
        echo '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settingsForm(array $settings): void
    {
        $name = Options::SETTINGS;
        echo '<h2>' . esc_html('Settings') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields(self::GROUP);
        echo '<table class="form-table"><tbody>';

        $numbers = [
            'interval' => ['Flush interval (minutes)', 5, 1440],
            'batch_size' => ['Batch size', 1, 10000],
            'batches_per_run' => ['Batches per run', 1, 10],
            'resubmit_interval' => ['Resubmit interval (minutes)', 0, 1440],
            'max_attempts' => ['Maximum attempts', 1, 20],
            'max_age_days' => ['Pending maximum age (days)', 1, 30],
            'queue_cap' => ['Queue cap', 100, 100000],
            'timeout' => ['Request timeout (seconds)', 5, 60],
            'log_size' => ['Run records kept', 10, 1000],
            'pause_seconds' => ['Default pause (seconds)', 60, 604800],
        ];

        echo '<tr><th scope="row"><label for="indexnow-endpoint">' . esc_html('Endpoint') . '</label></th><td>'
            . '<input type="url" class="regular-text" id="indexnow-endpoint" name="' . esc_attr($name . '[endpoint]')
            . '" value="' . esc_attr((string) $settings['endpoint']) . '"></td></tr>';
        foreach ($numbers as $field => [$label, $min, $max]) {
            $id = 'indexnow-' . str_replace('_', '-', $field);
            echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>'
                . '<input type="number" id="' . esc_attr($id) . '" name="' . esc_attr($name . '[' . $field . ']')
                . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max)
                . '" value="' . esc_attr((string) $settings[$field]) . '"></td></tr>';
        }

        $types = get_post_types(['public' => true], 'names');
        echo '<tr><th scope="row">' . esc_html('Post types') . '</th><td>';
        foreach ($types as $type) {
            if ($type === 'attachment') {
                continue;
            }
            $checked = in_array($type, (array) $settings['post_types'], true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="' . esc_attr($name . '[post_types][]') . '" value="'
                . esc_attr((string) $type) . '"' . $checked . '> ' . esc_html((string) $type) . '</label><br>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html('Allowed environments') . '</th><td>';
        foreach (Settings::ENVIRONMENTS as $environment) {
            $checked = in_array($environment, (array) $settings['environments'], true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="' . esc_attr($name . '[environments][]') . '" value="'
                . esc_attr($environment) . '"' . $checked . '> ' . esc_html($environment) . '</label><br>';
        }
        echo '</td></tr>';

        foreach (['include_attachments' => 'Include attachments', 'debug' => 'Debug lines to error_log'] as $field => $label) {
            $checked = !empty($settings[$field]) ? ' checked' : '';
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="'
                . esc_attr($name . '[' . $field . ']') . '" value="1"' . $checked . '> ' . esc_html('On') . '</label></td></tr>';
        }
        echo '<tr><th scope="row">' . esc_html('Log file') . '</th><td>'
            . esc_html($settings['log_file'] !== '' ? (string) $settings['log_file'] : 'none (set INDEXNOW_BATCH_LOG_FILE)')
            . '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';
    }
}
