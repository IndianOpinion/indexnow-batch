<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch;

use IndianOpinion\IndexNowBatch\Queue\Schema;
use IndianOpinion\IndexNowBatch\Queue\SchemaException;

/**
 * Activation, must-use install on first init, deactivation, and network sites.
 */
final class Installer
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function activate(bool $networkWide = false): void
    {
        $this->forEachSite($networkWide, fn () => $this->installSite());
    }

    public function deactivate(bool $networkWide = false): void
    {
        $this->forEachSite($networkWide, fn () => $this->plugin->scheduler()->clear());
    }

    /**
     * Creates the table, the key and the schedule for the current site.
     * Records a schema error instead of throwing, so a failed install never
     * breaks the request; the plugin then refuses to queue or flush.
     */
    public function installSite(): bool
    {
        try {
            $this->plugin->schema()->install();
        } catch (SchemaException $e) {
            update_option(Options::SCHEMA_ERROR, $e->getMessage(), true);
            $this->plugin->logger()->error('configuration error: ' . $e->getMessage());

            return false;
        }
        update_option(Options::SCHEMA, Schema::VERSION, true);
        delete_option(Options::SCHEMA_ERROR);
        $this->plugin->refresh();
        if ($this->plugin->keys()->configError() === null) {
            $this->plugin->keys()->key();
        }
        $this->plugin->scheduler()->ensure($this->plugin->settings()->interval(), $this->plugin->clock()->now());

        return true;
    }

    /**
     * On `init`: installs when the schema version is missing or old. This is
     * how must-use installs, where activation hooks never fire, get their
     * table and schedule. After a failed install it retries only in admin
     * and CLI requests, so a broken database does not cost every page view.
     */
    public function maybeInstall(): void
    {
        if (get_option(Options::SCHEMA) === Schema::VERSION) {
            return;
        }
        $error = get_option(Options::SCHEMA_ERROR, '');
        if (is_string($error) && $error !== '' && !is_admin() && !(defined('WP_CLI') && constant('WP_CLI'))) {
            return;
        }
        $this->installSite();
    }

    /**
     * wp_initialize_site: a new site of a network where the plugin is network active.
     */
    public function onNewSite(\WP_Site $site): void
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active_for_network(plugin_basename($this->plugin->file()))) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        try {
            $this->installSite();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * wp_uninitialize_site: drops that site's rows from the shared table.
     */
    public function onDeleteSite(\WP_Site $site): void
    {
        if ($this->plugin->schemaReady()) {
            $this->plugin->repo()->deleteSite((int) $site->blog_id);
        }
    }

    private function forEachSite(bool $networkWide, callable $work): void
    {
        if (!$networkWide || !is_multisite()) {
            $work();

            return;
        }
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                $this->plugin->refresh();
                $work();
            } finally {
                restore_current_blog();
                $this->plugin->refresh();
            }
        }
    }
}
