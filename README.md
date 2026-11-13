# indexnow-batch

A WordPress plugin that tells IndexNow search engines (Bing, Yandex, Naver, Seznam and others) about new and updated pages in batches, on a schedule, never on the publish path. It re-checks every queued URL at send time, so drafts, private posts, noindexed pages and anything your own rules refuse are never announced.

Measured in production on a site that publishes many hundreds of posts a day: one batched request every 15 minutes replaced one request per post, and the send-time re-check kept a backlog of since-withdrawn URLs from going out when the site came out of a long noindex period.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later. No runtime dependencies.

**Without Composer.** Download a release, unzip it to `wp-content/plugins/indexnow-batch/`, and activate it under Plugins. The plugin carries its own autoloader.

**With Composer** (for example with a `composer/installers` setup):

```sh
composer require indianopinion/indexnow-batch
```

**As a must-use plugin.** Put the plugin directory at `wp-content/mu-plugins/indexnow-batch/` and copy [`examples/mu-loader.php`](examples/mu-loader.php) to `wp-content/mu-plugins/indexnow-batch-loader.php`:

```php
<?php
require_once WPMU_PLUGIN_DIR . '/indexnow-batch/indexnow-batch.php';
```

Must-use plugins never run activation hooks, so the plugin creates its table, key and schedule on the first `init` instead.

## Quick start

1. Activate the plugin. It creates its table, a 32 character key, and a flush every 15 minutes.
2. Check the key file is reachable: `wp indexnow key verify`.
3. Publish something, then look: `wp indexnow queue list`, `wp indexnow flush --dry-run`, `wp indexnow status`.
4. Teach it your own index rules with one filter (see below).

Nothing is sent while **Settings, Reading, Discourage search engines** is ticked, or while `wp_get_environment_type()` is not `production`. URLs keep queueing meanwhile, bounded by age and a cap, and are re-checked when sends start.

## Your index rules

The plugin applies the core WordPress rules (published, no password, a public post type in the list). Everything else is yours, through one filter that runs when a URL is queued (`enqueue`) and again just before it is sent (`flush`):

```php
add_filter('indexnow_batch_should_submit', function (bool $submit, string $url, ?WP_Post $post, string $context): bool {
    if ($post && get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
        return false;
    }
    return $submit;
}, 10, 4);
```

The plugin does not read any SEO plugin's settings itself. [`examples/site-filter.php`](examples/site-filter.php) has Yoast, Rank Math and set-based examples. A filter that throws refuses that URL and logs the message once.

## Configuration

Settings live under **Settings, IndexNow**, and can be overridden in code with the `indexnow_batch_settings` filter. Order, last wins: defaults, stored settings, constants, the filter. Out-of-range values are clamped and a warning is shown and logged.

| Setting | Key | Default | Range |
|---|---|---|---|
| Endpoint | `endpoint` | `https://api.indexnow.org/indexnow` | any `https` URL |
| Flush interval (minutes) | `interval` | 15 | 5 to 1,440 |
| Batch size | `batch_size` | 1,000 | 1 to 10,000 |
| Batches per run | `batches_per_run` | 3 | 1 to 10 |
| Post types | `post_types` | `post`, `page` | viewable types |
| Include attachments | `include_attachments` | off | on, off |
| Resubmit interval (minutes) | `resubmit_interval` | 60 | 0 to 1,440 |
| Maximum attempts | `max_attempts` | 5 | 1 to 20 |
| Pending maximum age (days) | `max_age_days` | 7 | 1 to 30 |
| Queue cap | `queue_cap` | 10,000 | 100 to 100,000 |
| Allowed environments | `environments` | `production` | `production`, `staging`, `development`, `local` |
| Request timeout (seconds) | `timeout` | 20 | 5 to 60 |
| Run records kept | `log_size` | 200 | 10 to 1,000 |
| Default pause (seconds) | `pause_seconds` | 3,600 | 60 to 604,800 |
| Debug lines to `error_log` | `debug` | off | on, off |

Constants, in `wp-config.php`:

| Constant | Effect |
|---|---|
| `INDEXNOW_BATCH_KEY` | Supplies the key (8 to 128 characters of `a-z A-Z 0-9 -`). A malformed value holds every send and logs a configuration error. |
| `INDEXNOW_BATCH_LOG_FILE` | Absolute path of a JSON-lines log, one line per run. The plugin never creates it; create it and make it writable. |
| `INDEXNOW_BATCH_DISABLE` | `true` keeps queueing but never sends (outcome `disabled`). For maintenance. |

### Filters and actions

| Hook | Type | Purpose |
|---|---|---|
| `indexnow_batch_should_submit` | filter | `(bool $submit, string $url, ?WP_Post $post, string $context)`: your index rules. |
| `indexnow_batch_urls` | filter | `(string[] $urls)`: once per batch, for set-based rules. URLs not in the input are ignored with a warning. |
| `indexnow_batch_post_types` | filter | `(string[] $types)`: post types to announce. |
| `indexnow_batch_settings` | filter | `(array $settings)`: the merged settings, last word. |
| `indexnow_batch_endpoint` | filter | `(string $endpoint)`: must be `https`, else the default is used. |
| `indexnow_batch_request_args` | filter | `(array $args, array $payload)`: `wp_remote_post` arguments, for proxies. TLS verification stays on. |
| `indexnow_batch_include_archives` | filter | `(bool $include, WP_Post $post)`: default `false`. `true` also queues the post's category and author archive URLs. |
| `indexnow_batch_related_urls` | filter | `(string[] $urls, WP_Post $post)`: extra URLs to queue with a post. |
| `indexnow_batch_key_file_directory` | filter | `(string $dir)`: where `wp indexnow key write-file` writes. Default: the WordPress home path. |
| `indexnow_batch_queued` | action | `(string $url, ?int $post_id)` |
| `indexnow_batch_dropped` | action | `(string $url, string $reason)` |
| `indexnow_batch_submitted` | action | `(string[] $urls, int $http_code)` |
| `indexnow_batch_flushed` | action | `(array $record)` after every run |
| `indexnow_batch_key_rotated` | action | `(string $new_key)` |

### Functions

```php
indexnow_batch_queue_post(int $post_id): bool;
indexnow_batch_queue_url(string $url): bool;
indexnow_batch_flush(array $args = []): array;   // dry_run, force, limit
indexnow_batch_pause(int $seconds = 3600): void; // for bulk imports
indexnow_batch_resume(): void;                   // does not back-fill
indexnow_batch_key(): string;
```

## WP-CLI

| Command | Does |
|---|---|
| `wp indexnow status [--format=json]` | Key (masked), verification, endpoint, gates in force, queue counts, last run, next run, warnings |
| `wp indexnow queue list [--state=pending\|sent\|failed\|all] [--limit=<n>] [--format=table\|csv\|json]` | Lists rows |
| `wp indexnow queue add <url-or-id>... [--post]` | Queues URLs, or post IDs with `--post`; prints accepted and refused with reasons |
| `wp indexnow queue remove <url>...` | Removes pending rows |
| `wp indexnow queue clear [--yes]` | Empties the pending queue |
| `wp indexnow flush [--dry-run] [--force] [--limit=<n>]` | Runs a flush now. `--dry-run` prints the batches and changes nothing |
| `wp indexnow key show` | Prints the full key |
| `wp indexnow key verify` | Fetches the key file from the site and prints the result |
| `wp indexnow key rotate [--yes]` | New key; the old key file stops being served at once |
| `wp indexnow key write-file` / `remove-file` | Physical key file, for servers that answer `.txt` themselves |
| `wp indexnow log [--limit=<n>] [--format=table\|json]` | Recent run records, newest first |
| `wp indexnow pause [--for=<seconds>]` / `resume` | Bulk import pause |

Exit code 0 on success and 1 on any error. `flush` also exits 1 when the run outcome is `transport_error`, `refused`, `key_unverified` or `schema_error`, so a system cron wrapper can alert ([`examples/system-cron.sh`](examples/system-cron.sh)).

## How it works

1. **Queue.** On any move into `publish` (a new post, a scheduled post going live, an update) the plugin checks the core rules and your filter, then writes one row: the URL and the post ID. No HTTP request. A second save in the same request does nothing; a second save later refreshes the same row.
2. **Flush.** Every interval, one runner at a time (a lock row in the options table, ten minute expiry):
   - removes pending rows older than the maximum age, and old sent and failed rows;
   - stops, keeping the queue, when the site discourages search engines, the environment is not allowed, `INDEXNOW_BATCH_DISABLE` is on, the key is malformed, or it is backing off;
   - checks the key file from the site itself (cached for a day);
   - takes due rows oldest first, reloads each post (caches primed once per batch), recomputes its permalink, re-applies every rule and your filter, and drops what fails;
   - posts `{"host", "key", "keyLocation", "urlList"}` to the endpoint, at most `batches_per_run` batches.
3. **Answer.** 200 and 202 mark rows sent. 429, 5xx and timeouts keep rows and back off site-wide (15 minutes doubling to 6 hours, or `Retry-After` up to a day). 403 holds everything until the key file verifies again, then waits an hour. A 400 or 422 batch is halved and each half retried once; rows refused again are marked `failed` with the code. Any other code counts an attempt; at five the row is given up.
4. **Record.** Every run writes one record: outcome, counts, drops by reason, HTTP code, error, duration, the first 50 URLs. The last 200 are kept in one non-autoloaded option, shown by `wp indexnow log` and the status page.

The key file is answered from PHP at `{home}/{key}.txt` before the main query, with no rewrite rule, so it works the moment the plugin loads. Only the exact current key path is answered.

### Web servers

The dynamic key file needs `.txt` requests to reach PHP when no such file exists. Nginx with `try_files $uri $uri/ /index.php?$args;` does. A configuration with a static-only `location ~ \.txt$` does not: run `wp indexnow key write-file` to write a real file, which the plugin rewrites on rotation and removes on uninstall. Page caches and CDNs may keep the key file for five minutes (`Cache-Control: public, max-age=300`).

## Limitations

- Google does not take part in IndexNow. This plugin does not call Google.
- It announces live pages only. Removals (trash, 410) are not announced in 0.1.0.
- One endpoint receives each batch; participating engines share submissions.
- Only URLs on the site's own home host (and under its path) are sent.
- Raw URLs that resolve to a post with a different permalink are dropped as `ambiguous`: a missed announcement costs a slower crawl, a wrong one costs an index entry you did not want.

## FAQ

**Nothing is being sent.** Run `wp indexnow status`. The `gates` line says why: the site discourages search engines, a non-production environment, a key problem, back-off, or the table not being ready.

**The key does not verify.** `wp indexnow key verify` shows what the site answered. A 404 usually means `.txt` never reaches PHP: use `wp indexnow key write-file`. If the site cannot fetch itself (loopback blocked), the result is `unknown` and sends go ahead with a daily warning.

**I imported 50,000 old posts and they all queued.** Use `wp indexnow pause` before an import, or `wp indexnow queue clear --yes` after. Old content reaches search engines through your sitemap.

**Can I run the flush from the system crontab?** Yes. `wp indexnow flush` runs the same code as WP-Cron and shares its lock.

**Multisite?** Each site has its own key, key file, host, queue rows and schedule; the queue table is shared and carries a site column. Network activation sets up every site; new sites are set up on creation. Mapped domains get their own key file because each site serves its own home URL.

**What does uninstall remove?** The table, every `indexnow_batch_` option on every site, the schedule, and a physical key file only if the plugin wrote it and it still holds the key. It never touches your log file. Deactivation removes only the schedule.

## Licence

MIT. See [LICENSE](LICENSE).
