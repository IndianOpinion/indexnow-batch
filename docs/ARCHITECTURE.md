# indexnow-batch: architecture

This document maps the PRD onto the code and records every decision the
PRD left open. Section and rule numbers refer to `docs/PRD.md`.

## Modules

All classes live under `IndianOpinion\IndexNowBatch` in `src/`.

| Class | Role | PRD |
|---|---|---|
| `Plugin` | Builds services lazily, registers hooks, shared operations (flush, key rotation). Test seams: `useRepository`, `useClock`, `useLockStore`, `useLogger`, `useKeyFileDirectory`. | 5, 10 |
| `Options` | Every option, hook and table name, the version and the User-Agent. | 6.3 |
| `Settings` | Defaults, stored option, constants, filter; clamping with warnings. | 7 |
| `Logger` | `[indexnow-batch]` lines to `error_log`; errors always, debug on demand. | 11 |
| `Clock`, `SystemClock` | Current UTC time; tests use a fixed clock. | 4.8 rule 45 |
| `Pause` | Bulk import pause flag with expiry. | rule 31 |
| `SiteFilter` | Calls `indexnow_batch_should_submit`, turning a throw into a refusal. | rule 15 |
| `Scheduler` | The recurring WP-Cron event and its custom interval. | rule 22 |
| `Installer` | Activation, must-use install on `init`, deactivation, network sites. | 10, rule 48 |
| `Uninstaller` | Removes everything the plugin created, on every site. | 6.5 |
| `Status` | One screen of state for the CLI and the status page. | 11 |
| `Url\UrlRules` | URL acceptance and the de-duplication key. | rules 19, 20 |
| `Key\KeyManager` | Generate, validate, read, rotate and mask the key. | rules 1 to 3 |
| `Key\KeyVerifier` | Self-check of the key URL, 24 hour cache. | rule 9 |
| `Key\KeyFileHandler`, `Key\KeyFileResponse` | Early match of `{home}/{key}.txt` and the response. | rules 4 to 7 |
| `Key\PhysicalKeyFile` | Optional real key file with an ownership marker. | rule 8 |
| `Queue\Schema` | Table definition, `dbDelta`, `SHOW COLUMNS` verification. | 6.1, lesson 14 |
| `Queue\QueueRepository`, `Queue\WpdbQueueRepository` | Storage interface and its SQL implementation. | 6.1 |
| `Queue\Eligibility` | Core WordPress rules for a post. | rule 13 |
| `Queue\Enqueuer` | Publish hooks, `queue_post`, `queue_url`, archives. | rules 10 to 21 |
| `Flush\Flusher` | Lock, retention, gates, key check, re-check, batching, responses, record. | rules 22 to 40 |
| `Flush\Submitter` | Payload, body size fitting, the HTTP request. | rules 29, 30 |
| `Flush\Backoff` | Site-wide back-off and the 403 key hold. | rules 32, 33 |
| `Flush\Lock`, `Flush\LockStore`, `Flush\WpdbLockStore` | One flush at a time through atomic SQL on the options table. | rules 23, 47 |
| `Flush\RunLog` | Ring buffer, JSON-lines file, debug lines, the flushed action. | rules 40, 41 |
| `Admin\StatusPage` | Settings, IndexNow page and its three POST actions. | 5.5 |
| `Cli\RootCommand`, `Cli\QueueCommand`, `Cli\KeyCommand` | `wp indexnow`. | 5.4 |

`src/functions.php` holds the six public helpers. `src/autoload.php` is
the PSR-4 autoloader used when Composer is not.

## Data flow

```
publish / update ──> Enqueuer ──(core rules, URL rules, site filter)──> one upsert into the queue table
                                                                         │
WP-Cron / wp indexnow flush ──> Flusher                                  │
   lock ─> purge old rows ─> gates ─> key check ─> fetch due rows <──────┘
   ─> reload posts, recompute permalinks, re-apply every rule and the filter
   ─> indexnow_batch_urls ─> Submitter (POST) ─> response table
   ─> rows marked sent / attempt counted / halved / failed / kept ─> one run record
```

Rows carry the post ID, so the flush always works from the post as it is
now (lesson 3). Rows are removed or moved by ID only, so URLs queued while
a request is in flight are never lost (lesson 8).

## Decisions

### Rulings on the open questions

**Q1. Storage: a custom table, created on activation and verified with
SHOW COLUMNS.** `Schema::install()` runs `dbDelta` and then
`Schema::verify()`, which reads `SHOW COLUMNS` and `SHOW INDEX` and
throws `SchemaException` naming any missing column or a missing unique
key (`dbDelta` skips an index it cannot add without an error, and every
upsert depends on that key). The error is stored in an
option; queueing refuses with reason `schema`, the flush returns the
outcome `schema_error` (exit 1 in the CLI), and the status page shows it.
Reason: `dbDelta` has silently skipped column adds in production, and a
queue that half works is worse than one that refuses. No post-meta
fallback: the table is the only design that gives a one-statement atomic
upsert and indexed due-row queries.

**Q2. While the site is not public: keep queueing, never send, the caps
still apply.** Enqueue ignores `blog_public`; the flush returns
`skipped_not_public` with the queue size. Age expiry runs before the
gates and the cap applies at enqueue, so the backlog stays bounded.
Reason: a gated flush must never destroy work (lesson 6), and the count
in the record is the visible proof the gate holds.

**Q3. Announcing removed pages: off in 0.1.0.** No code path sends a URL
that is not live and indexable. Reason: the ruling; it can come behind a
setting in a later release.

**Q4. Category and author archives: off by default, behind a filter.**
`indexnow_batch_include_archives` (default `false`) adds the post's
category archives and author archive as raw URL rows when a post is
queued; `indexnow_batch_related_urls` lets a site add or replace them.
These rows go through the URL rules and the site filter like any raw URL,
at enqueue and at flush. This is the one case where an enqueue writes
more than one row (rule 18), and only when a site opts in.

**Q5. Split strategy for a refused batch: halve and retry once, then mark
the URLs failed with the response code.** On a 400 or 422 the rows get an
attempt and a retry group: two halves for a batch of more than one, one
group for a single URL. The next run sends each group as its own request
before ordinary rows. A group refused again moves to state `failed` with
`last_http` set, counted in the record as `dropped.failed` and listed by
`wp indexnow queue list --state=failed`. Failed rows are kept for the
pending maximum age, then purged. This replaces "split, then give up at
max attempts" from rule 35 for 400 and 422 only; other unexpected codes
still count attempts and give up at `max_attempts` (rule 34). Reason: one
bad URL cannot sink its neighbours, and a permanently bad batch costs two
requests, not five.

**Q6. User-Agent: `indexnow-batch/<version>
(+https://github.com/IndianOpinion/indexnow-batch)`.** Sent on
submissions and on the self-check (`Options::USER_AGENT`).
`indexnow_batch_request_args` can change it.

**Q7. Multisite: per-site key file, host and queue rows; one shared table
with a site column.** The table is `{base_prefix}indexnow_batch_queue`
with `site_id`, part of the unique key `(site_id, url_hash, state)`.
Every repository query is scoped to `get_current_blog_id()`. Options,
key, schedule and key file are per site, so a mapped domain serves its
own key under its own home URL. Network activation installs every site;
`wp_initialize_site` installs new ones when the plugin is network active
(must-use installs get it on the site's first `init`);
`wp_uninitialize_site` deletes that site's rows. Uninstall cleans every
site and then drops the shared table. This overrides the PRD's "each site
has its own table" in section 10.

### Builder decisions

1. **Unique key on `(site_id, url_hash, state)` with three states.**
   `pending`, `sent` and `failed`. Moving rows to `sent` or `failed` is a
   delete by ID plus a multi-row `INSERT ... ON DUPLICATE KEY UPDATE`, in
   a transaction, so an older sent row for the same URL is replaced
   rather than colliding. `url_hash` is `char(40)` hex SHA-1, readable in
   a SQL console. An enqueue that refreshes a pending row also moves its
   `queued_at` forward, and the move deletes a pending row only when its
   `queued_at` is unchanged since it was fetched. So a save that lands
   while that row's request is in flight leaves it pending, and the next
   run defers it by the resubmit interval instead of losing it (lesson 8).
2. **Enqueue is one indexed read plus one upsert.** The read returns the
   last send time for the hash (rule 21) and the pending count (rule 39)
   in one statement. The PRD budget says "no extra SELECT"; one indexed
   read is the price of setting not-before at enqueue, which T21 asks for.
   Eviction (a delete) and its counter (an option) are written only when
   the cap is hit. A request-level memo skips the database for a second
   save of the same post (rule 11).
3. **Rule 21 is also applied at flush.** A row whose URL was sent within
   the interval gets a not-before time and is counted as `deferred`, not
   dropped. This covers a permalink that changed into a recently sent
   URL. `force` skips it.
4. **Extra drop reasons.** Besides the PRD list: `duplicate` (two rows
   recompute to one URL in a batch, for example after a slug change),
   `ambiguous` (a raw URL resolves to a post whose permalink differs),
   `failed` (ruling Q5). Expired and evicted rows are removed in bulk, so
   `indexnow_batch_dropped` does not fire for them.
5. **Extra record fields and outcome.** `deferred`, `warnings`,
   `dry_run`, `forced`, `resume_at` (when backing off) and `note` (on
   202). Outcome `schema_error` (ruling Q1). Outcome `error` when a
   database statement fails mid-run or a hook throws: the move to `sent`
   or `failed` is rolled back, the lock is released, the run record is
   still written, and the CLI exits 1.
6. **Outcome of a multi-batch run** is the worst batch: `key_unverified`,
   then `transport_error`, then `refused`, then `accepted_pending`, then
   `submitted`. 403 records `key_unverified`; 429 and 5xx record
   `refused` with the code and back off; a transport failure records
   `transport_error`.
7. **Success codes.** Only 200 and 202 mark rows sent and reset back-off.
   Other 2xx codes are "any other code" per the PRD table.
8. **Self-check caching.** `verified` and `unknown` are cached for 24
   hours, keyed by a fingerprint of the key and the key URL, so a home
   URL change or rotation invalidates it on its own (rule 42) as well as
   explicitly. `unverified` is stored for the status page but re-checked
   every run, so a fixed key file is picked up at once. A 5xx from the
   site itself counts as `unknown`; a 4xx as `unverified`. One redirect
   on the same host is followed.
9. **403 hold.** A 403 sets a key hold and clears the cached verdict. The
   next run re-checks the key: `unverified` keeps holding; `verified` or
   `unknown` releases the hold with a one hour back-off. Treating
   `unknown` as a release keeps hosts that cannot fetch themselves from
   holding forever; they retry hourly.
10. **Gate order.** Lock, schema, retention, then disabled, not public,
    environment, key configuration, back-off, empty queue, key check.
    The empty check comes before the self-check, so an idle site makes no
    loopback request.
11. **Dry run.** Takes no lock, purges nothing, writes no rows, sends no
    request, caches no verdict, fires no per-URL actions. It still
    performs the self-check when no verdict is cached, and it writes its
    run record (outcome `dry_run`) to the log, because the log is not
    queue state and an operator wants to see it. Rows past the maximum
    age are counted as `expired` in the dry-run record.
12. **Lock.** `INSERT IGNORE` on `option_name`, compare-and-swap takeover
    of an expired lock, delete-if-ours release, through `$wpdb`, then the
    options cache entries are deleted. `add_option()` checks and then
    inserts, which races, and it reads through the object cache.
13. **Clock.** PHP `time()` in UTC, stored as `DATETIME` in UTC. Reading
    the database clock costs a query per decision; nodes are expected to
    run NTP, and not-before comparisons tolerate 60 seconds.
14. **Key file matching.** A pattern test on the path (`[A-Za-z0-9-]{8,128}.txt`
    under the home path) runs before the key option is read, so ordinary
    page views cost nothing. Then `hash_equals` on the full path. Only GET
    and HEAD. Headers: `Content-Type`, `Cache-Control`, `Content-Length`
    and `X-Robots-Tag: noindex` (the key file should not be indexed).
    Hooked on `do_parse_request` at priority 0, which runs before the
    main query. A malformed key constant serves nothing.
15. **Key from code.** The status shows source `code` for a key from
    `INDEXNOW_BATCH_KEY` (or the settings filter). Rotation refuses with
    a clear message. The status masks the key in `key_location` too.
16. **Schema version option is autoloaded.** It is a one character value
    read on every `init` to detect must-use installs and upgrades; not
    autoloading it would cost a query per page view. Every other option
    is non-autoloaded. After a failed install, front-end requests do not
    retry it (admin and CLI requests do), so a broken database does not
    run `dbDelta` on every page view.
17. **Schedule upkeep.** The event is checked and rescheduled when the
    interval changes on settings save, on activation and install, and on
    `init` only in admin, cron and CLI requests, to keep page views free.
18. **Pause scope.** The pause suppresses post enqueue (hooks and
    `indexnow_batch_queue_post`). Raw URLs from `queue_url` or `wp indexnow
    queue add` are explicit operator intent and are not paused.
19. **Attachments.** With the setting on, `add_attachment` and
    `edit_attachment` queue attachment pages whose status is `inherit` or
    `publish` and whose parent, if any, is published without a password.
    WordPress 6.4 redirects attachment pages to the file by default; such
    file URLs on another host are dropped as `foreign`.
20. **TLS and redirects cannot be filtered off.** After
    `indexnow_batch_request_args`, `sslverify` is forced on and
    `redirection` to 0.
21. **Body size.** URLs are trimmed from the end of a batch until the
    JSON body is under 10 MB; trimmed rows stay pending for the next
    request. With the 2,048 byte URL limit this never triggers at the
    10,000 URL maximum, but it is enforced anyway.
22. **Settings storage.** The settings form stores only operator keys;
    `log_file`, `disabled` and `key` come from constants (or the filter).
    `log_size` and `pause_seconds` are settings so that "all configurable"
    in 6.4 holds.
23. **CLI `queue add`.** Refused inputs are reported in the table, not
    treated as errors: exit 0 unless an argument is invalid (a non-numeric
    ID with `--post`) or the table is not ready.

## Testing

PHPUnit 10 runs against a stub layer in `tests/stubs/` (WordPress
functions, a recording `wpdb`, WP-CLI). Behaviour tests run the real
services against `tests/Support/MemoryQueueRepository`, an in-memory
repository with the same semantics as the SQL one (including the unique
key). `WpdbRepositoryTest` checks the SQL the real repository issues, and
`LifecycleTest` runs `WpdbLockStore` against the fake `wpdb`'s options
table emulation. Neither replaces a run against a real MySQL; an
integration suite against `wp-env` is a candidate for 0.2.

Each acceptance test from PRD section 12 is named after its number
(`testT21...`).
