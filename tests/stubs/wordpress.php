<?php

/**
 * A small WordPress stub layer: just enough of the API for the plugin to run
 * under PHPUnit without WordPress. State lives in WpStubs and is reset
 * before every test.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/indexnow-batch-abspath/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class WpDieException extends RuntimeException
{
}

final class WpRedirectException extends RuntimeException
{
}

final class WpStubs
{
    /** @var array<int, array<string, mixed>> Options per blog. */
    public static array $options = [];
    /** @var array<int, array<string, bool>> Autoload flag per option, per blog. */
    public static array $autoload = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $actionsFired = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $actionLog = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, string|false> */
    public static array $permalinks = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    /** @var array<string, int> */
    public static array $urlToPost = [];
    public static string $home = 'https://example.com/';
    public static string $environment = 'production';
    public static int $blogId = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    public static bool $multisite = false;
    /** @var list<int> */
    public static array $sites = [1];
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $posts_sent = [];
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $gets = [];
    /** @var list<array<string, mixed>|WP_Error> */
    public static array $postResponses = [];
    /** @var (callable(string, array<string, mixed>): (array<string, mixed>|WP_Error))|null */
    public static $getResponder = null;
    /** @var array<int, array<string, array{timestamp: int, schedule: string, interval: int}>> */
    public static array $cron = [];
    /** @var array<string, bool> */
    public static array $caps = [];
    public static bool $nonceValid = true;
    public static bool $isAdmin = false;
    /** @var list<string> */
    public static array $viewableTypes = ['post', 'page', 'attachment', 'product'];
    /** @var list<int> */
    public static array $primed = [];
    public static int $primeCalls = 0;
    /** @var list<string> */
    public static array $redirects = [];
    /** @var list<array<string, mixed>> */
    public static array $registered = [];
    /** @var list<string> */
    public static array $cacheDeletes = [];
    public static string $homePath = '';
    /** @var array<int, list<object>> */
    public static array $categories = [];
    /** @var list<string> */
    public static array $networkActive = [];
    /** @var list<string> Columns SHOW COLUMNS reports. */
    public static array $columns = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$autoload = [];
        self::$hooks = [];
        self::$actionsFired = [];
        self::$actionLog = [];
        self::$posts = [];
        self::$permalinks = [];
        self::$meta = [];
        self::$urlToPost = [];
        self::$home = 'https://example.com/';
        self::$environment = 'production';
        self::$blogId = 1;
        self::$blogStack = [];
        self::$multisite = false;
        self::$sites = [1];
        self::$posts_sent = [];
        self::$gets = [];
        self::$postResponses = [];
        self::$getResponder = null;
        self::$cron = [];
        self::$caps = ['manage_options' => true];
        self::$nonceValid = true;
        self::$isAdmin = false;
        self::$viewableTypes = ['post', 'page', 'attachment', 'product'];
        self::$primed = [];
        self::$primeCalls = 0;
        self::$redirects = [];
        self::$registered = [];
        self::$cacheDeletes = [];
        self::$homePath = '';
        self::$categories = [];
        self::$networkActive = [];
        self::$columns = \IndianOpinion\IndexNowBatch\Queue\Schema::COLUMNS;
        $GLOBALS['wpdb'] = new wpdb();
    }

    public static function response(int $code, string $body = '', array $headers = []): array
    {
        return ['response' => ['code' => $code, 'message' => ''], 'headers' => $headers, 'body' => $body];
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_password = '';
    public int $post_parent = 0;
    public int $post_author = 1;
    public string $post_name = '';

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $name => $value) {
            $this->{$name} = $value;
        }
    }
}

class WP_Site
{
    public int $blog_id = 1;

    public function __construct(int $blogId)
    {
        $this->blog_id = $blogId;
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

/**
 * Fake $wpdb. Records every query. Emulates the options table for the
 * statements the plugin issues against it, and hands any other query to a
 * test-supplied responder.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $rows_affected = 0;
    /** @var list<string> */
    public array $queries = [];
    /** @var (callable(string, string): mixed)|null Receives (method, sql). */
    public $responder = null;
    /** @var array<string, string> */
    public array $optionRows = [];

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;

        return (string) preg_replace_callback('/%[dsf]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }
            if ($m[0] === '%f') {
                return (string) (float) $value;
            }

            return "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    private function options(string $sql): mixed
    {
        if (preg_match("/^INSERT IGNORE INTO wp_options .* VALUES \\('((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)', 'no'\\)$/s", $sql, $m)) {
            $name = stripslashes($m[1]);
            if (isset($this->optionRows[$name])) {
                return 0;
            }
            $this->optionRows[$name] = stripslashes($m[2]);

            return 1;
        }
        if (preg_match("/^SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)'$/s", $sql, $m)) {
            return $this->optionRows[stripslashes($m[1])] ?? null;
        }
        if (preg_match("/^UPDATE wp_options SET option_value = '((?:[^'\\\\]|\\\\.)*)' WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' AND option_value = '((?:[^'\\\\]|\\\\.)*)'$/s", $sql, $m)) {
            $name = stripslashes($m[2]);
            if (($this->optionRows[$name] ?? null) !== stripslashes($m[3])) {
                return 0;
            }
            $this->optionRows[$name] = stripslashes($m[1]);

            return 1;
        }
        if (preg_match("/^DELETE FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' AND option_value = '((?:[^'\\\\]|\\\\.)*)'$/s", $sql, $m)) {
            $name = stripslashes($m[1]);
            if (($this->optionRows[$name] ?? null) !== stripslashes($m[2])) {
                return 0;
            }
            unset($this->optionRows[$name]);

            return 1;
        }
        if (preg_match("/^SELECT option_name FROM wp_options WHERE option_name LIKE '(.*)%'$/", $sql, $m)) {
            $prefix = stripcslashes(stripslashes($m[1]));

            return array_values(array_filter(
                array_keys(WpStubs::$options[WpStubs::$blogId] ?? []),
                static fn (string $name): bool => str_starts_with($name, $prefix)
            ));
        }

        return '__unhandled__';
    }

    private function respond(string $method, string $sql): mixed
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'wp_options')) {
            $result = $this->options($sql);
            if ($result !== '__unhandled__') {
                return $result;
            }
        }
        if ($this->responder !== null) {
            return ($this->responder)($method, $sql);
        }
        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            return WpStubs::$columns;
        }

        return null;
    }

    public function query(string $sql): int|bool
    {
        $result = $this->respond('query', $sql);
        if ($result === false) {
            return false;
        }
        $this->rows_affected = (int) $result;

        return (int) $result;
    }

    public function get_var(string $sql): mixed
    {
        return $this->respond('get_var', $sql);
    }

    public function get_row(string $sql, string $output = 'OBJECT'): mixed
    {
        return $this->respond('get_row', $sql);
    }

    /**
     * @return list<mixed>
     */
    public function get_col(string $sql, int $column = 0): array
    {
        $result = $this->respond('get_col', $sql);

        return is_array($result) ? array_values($result) : [];
    }

    public function get_results(string $sql, string $output = 'OBJECT'): mixed
    {
        return $this->respond('get_results', $sql);
    }
}

// Hooks.

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStubs::$hooks[$hook][$priority][] = [$callback, $args];

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    foreach (WpStubs::$hooks[$hook][$priority] ?? [] as $i => [$registered]) {
        if ($registered == $callback) {
            unset(WpStubs::$hooks[$hook][$priority][$i]);

            return true;
        }
    }

    return false;
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    return remove_filter($hook, $callback, $priority);
}

function has_filter(string $hook, mixed $callback = false): bool|int
{
    foreach (WpStubs::$hooks[$hook] ?? [] as $priority => $callbacks) {
        foreach ($callbacks as [$registered]) {
            if ($callback === false || $registered == $callback) {
                return $callback === false ? true : $priority;
            }
        }
    }

    return false;
}

function has_action(string $hook, mixed $callback = false): bool|int
{
    return has_filter($hook, $callback);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $callbacks = WpStubs::$hooks[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$callback, $count]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $count));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStubs::$actionsFired[$hook] = (WpStubs::$actionsFired[$hook] ?? 0) + 1;
    WpStubs::$actionLog[] = [$hook, $args];
    $callbacks = WpStubs::$hooks[$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
}

function did_action(string $hook): int
{
    return WpStubs::$actionsFired[$hook] ?? 0;
}

// Options.

function get_option(string $name, mixed $default = false): mixed
{
    if ($name === 'home' || $name === 'siteurl') {
        return rtrim(WpStubs::$home, '/');
    }

    return array_key_exists($name, WpStubs::$options[WpStubs::$blogId] ?? [])
        ? WpStubs::$options[WpStubs::$blogId][$name]
        : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $old = get_option($name, null);
    $exists = array_key_exists($name, WpStubs::$options[WpStubs::$blogId] ?? []);
    WpStubs::$options[WpStubs::$blogId][$name] = $value;
    if ($autoload !== null || !$exists) {
        WpStubs::$autoload[WpStubs::$blogId][$name] = $autoload === null ? true : (bool) $autoload && $autoload !== 'no';
    }
    if ($exists) {
        do_action('update_option_' . $name, $old, $value, $name);
    } else {
        do_action('add_option_' . $name, $name, $value);
    }

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = true): bool
{
    if (array_key_exists($name, WpStubs::$options[WpStubs::$blogId] ?? [])) {
        return false;
    }
    WpStubs::$options[WpStubs::$blogId][$name] = $value;
    WpStubs::$autoload[WpStubs::$blogId][$name] = (bool) $autoload && $autoload !== 'no';
    do_action('add_option_' . $name, $name, $value);

    return true;
}

function delete_option(string $name): bool
{
    if (!array_key_exists($name, WpStubs::$options[WpStubs::$blogId] ?? [])) {
        return false;
    }
    unset(WpStubs::$options[WpStubs::$blogId][$name], WpStubs::$autoload[WpStubs::$blogId][$name]);

    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    WpStubs::$cacheDeletes[] = $group . ':' . $key;

    return true;
}

// URLs and posts.

function home_url(string $path = ''): string
{
    return rtrim(WpStubs::$home, '/') . '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return 'https://example.com/wp-admin/' . ltrim($path, '/');
}

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return WpStubs::$posts[(int) $post] ?? null;
}

function get_permalink(mixed $post = 0): string|false
{
    $id = $post instanceof WP_Post ? $post->ID : (int) $post;
    if (array_key_exists($id, WpStubs::$permalinks)) {
        return WpStubs::$permalinks[$id];
    }
    $object = WpStubs::$posts[$id] ?? null;
    if ($object === null) {
        return false;
    }

    return home_url($object->post_name . '/');
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $value = WpStubs::$meta[$id][$key] ?? null;

    return $single ? ($value ?? '') : ($value === null ? [] : [$value]);
}

function _prime_post_caches(array $ids, bool $terms = true, bool $meta = true): void
{
    WpStubs::$primeCalls++;
    WpStubs::$primed = array_merge(WpStubs::$primed, $ids);
}

function is_post_type_viewable(mixed $type): bool
{
    return in_array(is_object($type) ? $type->name : $type, WpStubs::$viewableTypes, true);
}

function wp_is_post_revision(mixed $post): int|false
{
    $object = get_post($post);

    return $object !== null && $object->post_type === 'revision' ? $object->post_parent : false;
}

function wp_is_post_autosave(mixed $post): int|false
{
    $object = get_post($post);

    return $object !== null && $object->post_type === 'revision' && str_contains($object->post_name, 'autosave')
        ? $object->post_parent
        : false;
}

function url_to_postid(string $url): int
{
    return WpStubs::$urlToPost[$url] ?? 0;
}

function get_post_types(array $args = [], string $output = 'names'): array
{
    return array_combine(WpStubs::$viewableTypes, WpStubs::$viewableTypes);
}

function is_object_in_taxonomy(string $type, string $taxonomy): bool
{
    return $type === 'post' && $taxonomy === 'category';
}

function get_the_category(int $id): array
{
    return WpStubs::$categories[$id] ?? [];
}

function get_category_link(mixed $term): string
{
    return home_url('category/' . $term->slug . '/');
}

function get_author_posts_url(int $author): string
{
    return home_url('author/user' . $author . '/');
}

function wp_get_environment_type(): string
{
    return WpStubs::$environment;
}

// HTTP.

function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    WpStubs::$posts_sent[] = ['url' => $url, 'args' => $args];
    if (WpStubs::$postResponses === []) {
        return WpStubs::response(200);
    }

    return array_shift(WpStubs::$postResponses);
}

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    WpStubs::$gets[] = ['url' => $url, 'args' => $args];
    if (WpStubs::$getResponder !== null) {
        return (WpStubs::$getResponder)($url, $args);
    }
    // By default the site serves its own key file correctly.
    $key = (string) basename((string) parse_url($url, PHP_URL_PATH), '.txt');

    return WpStubs::response(200, $key);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(mixed $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_header(mixed $response, string $header): string|array
{
    if (!is_array($response)) {
        return '';
    }
    foreach ($response['headers'] ?? [] as $name => $value) {
        if (strtolower((string) $name) === strtolower($header)) {
            return $value;
        }
    }

    return '';
}

function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

// Cron.

function wp_get_schedules(): array
{
    return apply_filters('cron_schedules', []);
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    $schedules = wp_get_schedules();
    if (!isset($schedules[$recurrence])) {
        return false;
    }
    WpStubs::$cron[WpStubs::$blogId][$hook] = [
        'timestamp' => $timestamp,
        'schedule' => $recurrence,
        'interval' => (int) $schedules[$recurrence]['interval'],
    ];

    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return WpStubs::$cron[WpStubs::$blogId][$hook]['timestamp'] ?? false;
}

function wp_get_scheduled_event(string $hook, array $args = [], ?int $timestamp = null): object|false
{
    $event = WpStubs::$cron[WpStubs::$blogId][$hook] ?? null;

    return $event === null ? false : (object) (['hook' => $hook] + $event);
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $had = isset(WpStubs::$cron[WpStubs::$blogId][$hook]) ? 1 : 0;
    unset(WpStubs::$cron[WpStubs::$blogId][$hook]);

    return $had;
}

function wp_doing_cron(): bool
{
    return false;
}

// Multisite.

function get_current_blog_id(): int
{
    return WpStubs::$blogId;
}

function is_multisite(): bool
{
    return WpStubs::$multisite;
}

function get_sites(array $args = []): array
{
    return WpStubs::$sites;
}

function switch_to_blog(int $id): bool
{
    WpStubs::$blogStack[] = WpStubs::$blogId;
    WpStubs::$blogId = $id;
    do_action('switch_blog', $id);

    return true;
}

function restore_current_blog(): bool
{
    WpStubs::$blogId = array_pop(WpStubs::$blogStack) ?? 1;
    do_action('switch_blog', WpStubs::$blogId);

    return true;
}

function is_plugin_active_for_network(string $plugin): bool
{
    return in_array($plugin, WpStubs::$networkActive, true);
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

// Admin, capabilities, escaping.

function is_admin(): bool
{
    return WpStubs::$isAdmin;
}

function current_user_can(string $capability): bool
{
    return WpStubs::$caps[$capability] ?? false;
}

function check_admin_referer(string $action = '', string $arg = '_wpnonce'): int
{
    if (!WpStubs::$nonceValid) {
        throw new WpDieException('The link you followed has expired.');
    }

    return 1;
}

function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WpDieException($message);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

function add_query_arg(string $key, string $value, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce'): string
{
    echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';

    return '';
}

function submit_button(): void
{
    echo '<button type="submit">Save</button>';
}

function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . $group . '">';
}

function settings_errors(string $setting = ''): void
{
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WpStubs::$registered[] = ['settings_error' => $message];
}

function add_options_page(string $title, string $menu, string $cap, string $slug, callable $callback): string
{
    WpStubs::$registered[] = ['page' => $slug, 'cap' => $cap];

    return $slug;
}

function register_setting(string $group, string $name, array $args = []): void
{
    WpStubs::$registered[] = ['setting' => $name, 'group' => $group, 'args' => $args];
}

function wp_safe_redirect(string $url): bool
{
    WpStubs::$redirects[] = $url;

    return true;
}

function register_activation_hook(string $file, callable $callback): void
{
    add_action('activate_' . plugin_basename($file), $callback);
}

function register_deactivation_hook(string $file, callable $callback): void
{
    add_action('deactivate_' . plugin_basename($file), $callback);
}

function get_home_path(): string
{
    return WpStubs::$homePath;
}

function dbDelta(string $sql): array
{
    global $wpdb;
    $wpdb->query($sql);

    return [];
}

function status_header(int $code): void
{
}

WpStubs::reset();

function __return_true(): bool
{
    return true;
}
