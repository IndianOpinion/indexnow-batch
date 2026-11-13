<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

use IndianOpinion\IndexNowBatch\Admin\StatusPage;
use IndianOpinion\IndexNowBatch\Cli\KeyCommand;
use IndianOpinion\IndexNowBatch\Cli\QueueCommand;
use IndianOpinion\IndexNowBatch\Cli\RootCommand;
use IndianOpinion\IndexNowBatch\Flush\Backoff;
use IndianOpinion\IndexNowBatch\Flush\Flusher;
use IndianOpinion\IndexNowBatch\Flush\FlushResult;
use IndianOpinion\IndexNowBatch\Flush\Lock;
use IndianOpinion\IndexNowBatch\Flush\LockStore;
use IndianOpinion\IndexNowBatch\Flush\RunLog;
use IndianOpinion\IndexNowBatch\Flush\Submitter;
use IndianOpinion\IndexNowBatch\Flush\WpdbLockStore;
use IndianOpinion\IndexNowBatch\Key\KeyFileHandler;
use IndianOpinion\IndexNowBatch\Key\KeyManager;
use IndianOpinion\IndexNowBatch\Key\KeyVerifier;
use IndianOpinion\IndexNowBatch\Key\PhysicalKeyFile;
use IndianOpinion\IndexNowBatch\Queue\Eligibility;
use IndianOpinion\IndexNowBatch\Queue\Enqueuer;
use IndianOpinion\IndexNowBatch\Queue\QueueRepository;
use IndianOpinion\IndexNowBatch\Queue\Schema;
use IndianOpinion\IndexNowBatch\Queue\WpdbQueueRepository;
use IndianOpinion\IndexNowBatch\Url\UrlRules;

/**
 * Wires the services and the WordPress hooks.
 *
 * Services are built lazily and cached for the request; refresh() drops
 * the cache when settings, the home URL or the current site change.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private ?QueueRepository $repository = null;
    private ?LockStore $lockStore = null;
    private Clock $clock;
    private Logger $logger;
    private ?string $keyFileDirectory = null;

    public function __construct(private string $file)
    {
        $this->clock = new SystemClock();
        $this->logger = new Logger();
    }

    /**
     * Called once from the main plugin file.
     */
    public static function bootstrap(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
            self::$instance->boot();
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('indexnow-batch is not loaded.');
        }

        return self::$instance;
    }

    /** Replaces the shared instance; for tests and custom bootstraps. */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function boot(): void
    {
        register_activation_hook($this->file, fn (bool $network = false) => $this->installer()->activate($network));
        register_deactivation_hook($this->file, fn (bool $network = false) => $this->deactivate($network));

        add_action('transition_post_status', [$this, 'onTransition'], 10, 3);
        add_action('add_attachment', [$this, 'onAttachment']);
        add_action('edit_attachment', [$this, 'onAttachment']);
        add_filter('do_parse_request', [$this, 'onParseRequest'], 0, 1);
        add_filter('cron_schedules', [$this, 'onCronSchedules']);
        add_action(Options::CRON_HOOK, [$this, 'onCron']);
        add_action('init', [$this, 'onInit'], 5);
        add_action('update_option_home', [$this, 'onHomeChanged']);
        add_action('update_option_' . Options::SETTINGS, [$this, 'onSettingsChanged']);
        add_action('add_option_' . Options::SETTINGS, [$this, 'onSettingsChanged']);
        add_action('switch_blog', [$this, 'refresh']);
        add_action('wp_initialize_site', [$this, 'onNewSite'], 200);
        add_action('wp_uninitialize_site', [$this, 'onDeleteSite']);

        if (is_admin()) {
            $page = new StatusPage($this);
            add_action('admin_menu', [$page, 'addMenu']);
            add_action('admin_init', [$page, 'registerSettings']);
            foreach (StatusPage::ACTIONS as $action) {
                add_action('admin_post_indexnow_batch_' . $action, [$page, 'onAdminPost']);
            }
        }

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('indexnow', new RootCommand($this));
            \WP_CLI::add_command('indexnow queue', new QueueCommand($this));
            \WP_CLI::add_command('indexnow key', new KeyCommand($this));
        }
    }

    /**
     * Deactivation: removes the schedule and the key file handler. Data stays.
     */
    public function deactivate(bool $network = false): void
    {
        $this->installer()->deactivate($network);
        remove_filter('do_parse_request', [$this, 'onParseRequest'], 0);
    }

    // Hook callbacks.

    public function onTransition(string $new, string $old, mixed $post): void
    {
        if ($post instanceof \WP_Post) {
            $this->enqueuer()->onTransition($new, $old, $post);
        }
    }

    public function onAttachment(mixed $postId): void
    {
        $this->enqueuer()->onAttachment((int) $postId);
    }

    public function onParseRequest(mixed $doParse): mixed
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (($method !== 'GET' && $method !== 'HEAD') || !isset($_SERVER['REQUEST_URI'])) {
            return $doParse;
        }
        $response = $this->keyFileHandler()->match((string) $_SERVER['REQUEST_URI']);
        if ($response !== null) {
            $response->emit($method === 'GET');
        }

        return $doParse;
    }

    /**
     * @param mixed $schedules
     * @return array<string, mixed>
     */
    public function onCronSchedules(mixed $schedules): array
    {
        return Scheduler::addSchedule(is_array($schedules) ? $schedules : [], $this->settings()->interval());
    }

    public function onCron(): void
    {
        $this->flush([]);
    }

    public function onInit(): void
    {
        $this->installer()->maybeInstall();
        $context = is_admin() || wp_doing_cron() || (defined('WP_CLI') && constant('WP_CLI'));
        if ($context && $this->schemaReady()) {
            $this->scheduler()->ensure($this->settings()->interval(), $this->clock->now());
        }
    }

    public function onHomeChanged(): void
    {
        $this->refresh();
        $this->verifier()->invalidate();
    }

    public function onSettingsChanged(): void
    {
        $this->refresh();
        if ($this->schemaReady()) {
            $this->scheduler()->ensure($this->settings()->interval(), $this->clock->now());
        }
    }

    public function onNewSite(mixed $site): void
    {
        if ($site instanceof \WP_Site) {
            $this->installer()->onNewSite($site);
        }
    }

    public function onDeleteSite(mixed $site): void
    {
        if ($site instanceof \WP_Site) {
            $this->installer()->onDeleteSite($site);
        }
    }

    // Operations shared by the public functions, the CLI and the status page.

    /**
     * @param array{dry_run?: bool, force?: bool, limit?: int|string|null} $args
     */
    public function flush(array $args = []): FlushResult
    {
        return $this->flusher()->run($args);
    }

    /**
     * New key, verification cleared, physical file rewritten, action fired.
     */
    public function rotateKey(): string
    {
        $key = $this->keys()->rotate();
        $this->verifier()->invalidate();
        $this->physicalKeyFile()->rotate($key);
        $this->logger->error('key rotated; the old key file is no longer served');
        do_action('indexnow_batch_key_rotated', $key);

        return $key;
    }

    public function schemaReady(): bool
    {
        return get_option(Options::SCHEMA) === Schema::VERSION;
    }

    // Test seams.

    public function useRepository(QueueRepository $repository): void
    {
        $this->repository = $repository;
        $this->refresh();
    }

    public function useLockStore(LockStore $store): void
    {
        $this->lockStore = $store;
        $this->refresh();
    }

    public function useClock(Clock $clock): void
    {
        $this->clock = $clock;
        $this->refresh();
    }

    public function useLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->refresh();
    }

    public function useKeyFileDirectory(string $directory): void
    {
        $this->keyFileDirectory = $directory;
        $this->refresh();
    }

    /** Drops cached services. */
    public function refresh(): void
    {
        $this->services = [];
    }

    // Services.

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(): T $factory
     * @return T
     */
    private function service(string $class, callable $factory): object
    {
        if (!isset($this->services[$class])) {
            $this->services[$class] = $factory();
        }
        /** @var T $service */
        $service = $this->services[$class];

        return $service;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function logger(): Logger
    {
        $this->logger->setDebug($this->settings()->debug());

        return $this->logger;
    }

    public function settings(): Settings
    {
        return $this->service(Settings::class, static fn () => Settings::resolve());
    }

    public function db(): \wpdb
    {
        global $wpdb;

        return $wpdb;
    }

    public function schema(): Schema
    {
        return $this->service(Schema::class, fn () => new Schema($this->db()));
    }

    public function repo(): QueueRepository
    {
        return $this->service(QueueRepository::class, fn () => $this->repository ?? new WpdbQueueRepository($this->db()));
    }

    public function rules(): UrlRules
    {
        return $this->service(UrlRules::class, static fn () => new UrlRules(home_url('/')));
    }

    public function keys(): KeyManager
    {
        return $this->service(KeyManager::class, fn () => new KeyManager($this->settings()->constantKey()));
    }

    public function verifier(): KeyVerifier
    {
        return $this->service(KeyVerifier::class, fn () => new KeyVerifier($this->clock));
    }

    public function keyFileHandler(): KeyFileHandler
    {
        return $this->service(KeyFileHandler::class, fn () => new KeyFileHandler(fn () => $this->keys(), $this->rules()));
    }

    public function physicalKeyFile(): PhysicalKeyFile
    {
        return $this->service(PhysicalKeyFile::class, function () {
            $directory = $this->keyFileDirectory;
            if ($directory === null) {
                if (!function_exists('get_home_path')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                $directory = get_home_path();
            }
            $filtered = apply_filters('indexnow_batch_key_file_directory', $directory);

            return new PhysicalKeyFile(is_string($filtered) && $filtered !== '' ? $filtered : $directory);
        });
    }

    public function eligibility(): Eligibility
    {
        return $this->service(Eligibility::class, fn () => new Eligibility($this->settings()));
    }

    public function pause(): Pause
    {
        return $this->service(Pause::class, static fn () => new Pause());
    }

    public function backoff(): Backoff
    {
        return $this->service(Backoff::class, static fn () => new Backoff());
    }

    public function runLog(): RunLog
    {
        return $this->service(RunLog::class, fn () => new RunLog($this->settings(), $this->logger()));
    }

    public function submitter(): Submitter
    {
        return $this->service(Submitter::class, fn () => new Submitter($this->settings(), $this->logger()));
    }

    public function scheduler(): Scheduler
    {
        return $this->service(Scheduler::class, static fn () => new Scheduler());
    }

    public function lock(): Lock
    {
        return $this->service(Lock::class, fn () => new Lock($this->lockStore ?? new WpdbLockStore($this->db())));
    }

    public function installer(): Installer
    {
        return $this->service(Installer::class, fn () => new Installer($this));
    }

    public function status(): Status
    {
        return $this->service(Status::class, fn () => new Status($this));
    }

    public function enqueuer(): Enqueuer
    {
        return $this->service(Enqueuer::class, fn () => new Enqueuer(
            $this->settings(),
            $this->repo(),
            $this->rules(),
            $this->eligibility(),
            $this->pause(),
            $this->clock,
            $this->logger(),
            fn (): bool => $this->schemaReady(),
        ));
    }

    public function flusher(): Flusher
    {
        return $this->service(Flusher::class, fn () => new Flusher(
            $this->settings(),
            $this->repo(),
            $this->rules(),
            $this->eligibility(),
            $this->keys(),
            $this->verifier(),
            $this->submitter(),
            $this->backoff(),
            $this->lock(),
            $this->runLog(),
            $this->clock,
            $this->logger(),
            fn (): bool => $this->schemaReady(),
        ));
    }
}
