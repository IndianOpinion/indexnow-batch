# indexnow-batch: product requirements

Status: draft for review. Version target: 0.1.0.

This document is the whole specification. A builder who has never seen the
code it came from should be able to build, test and ship the plugin from it.
Names in section 5 are the public interface of this plugin and are final
unless section 13 says otherwise.

## 1. Problem

IndexNow lets a site tell participating search engines (Bing, Yandex, Naver,
Seznam and others) that a URL was added or changed, so they crawl it in
minutes rather than days. Google does not take part. Done naively, it goes
wrong in five ways.

1. **One request per publish.** A busy news site makes thousands of HTTP
   calls a day, slows the publish path, and gets rate limited.
2. **It announces pages that should not be indexed.** Drafts, private and
   password-protected posts, withdrawn pages that answer 410, pages that
   redirect, and pages the site marks noindex all get pushed. Telling a
   search engine to crawl a page you have told it not to index undoes the
   noindex in one request.
3. **The queue goes stale.** A URL is checked when it is queued, but the
   post changes state before the batch goes out (drafted, withdrawn, marked
   noindex by a later review). Only a check at send time is correct.
4. **It ignores the site's own visibility.** A staging copy, or a site with
   "Discourage search engines" ticked, still pings the engines.
5. **Failures are silent.** WordPress cron output usually goes nowhere, so
   a refused batch, a bad key or a timeout leaves no trace, and a refused
   batch is retried every run forever.

Measured in production on a site that publishes many hundreds of posts a
day: one batched request every 15 minutes replaced one request per post.
When the site came out of a long noindex period, the queue held a large
number of URLs banked while it was dark, many of them by then withdrawn or
noindexed; only a send-time re-check kept them from going out.

## 2. Goals and non-goals

### Goals

- G1. Queue a URL whenever a post that should be indexed is published or
  updated. Never make an HTTP call on the publish path.
- G2. Re-check every queued URL at send time against the same rules, plus
  one site-specific filter, and drop the ones that fail.
- G3. De-duplicate: a URL is queued at most once, and a URL sent recently
  is not sent again until a minimum interval has passed.
- G4. Submit in batches that respect the IndexNow protocol limits, on a
  schedule, with bounded retries and back-off driven by the response code.
- G5. Generate, store and serve the key file, and verify it from the site
  itself before relying on it.
- G6. Stay silent while the site is not public or not in production.
- G7. Record every run in a log an operator can read without shell access
  to the web server's error log.
- G8. Give operators a WP-CLI command for every action and a read-mostly
  status page.
- G9. Remove everything it created on uninstall.

### Non-goals

- N1. Google. It has no IndexNow endpoint, and its Indexing API is limited
  to job postings and live streams. The plugin does not call it.
- N2. Deciding what is indexable in general. The plugin applies the core
  WordPress rules in section 4.3 and asks the site's filter for the rest.
  It does not read any SEO plugin's settings directly.
- N3. Announcing deleted or withdrawn URLs. The protocol allows it, but
  0.1.0 only announces live, indexable URLs (see section 13, Q3).
- N4. XML sitemaps, robots.txt, or any change to how pages are rendered,
  except the key file response.
- N5. Submitting URLs on hosts other than the site's own home host.
- N6. A per-engine fan-out. One endpoint receives each batch; the protocol
  shares submissions between participating engines.

## 3. Users and setting

- **Who installs it.** A site operator or developer on a self-hosted
  WordPress site. Typical sites publish from tens to thousands of posts a
  day, many through automated imports.
- **Where it runs.** A normal plugin (`wp-content/plugins/indexnow-batch/`)
  or a must-use plugin via a one-line loader in `wp-content/mu-plugins/`.
  Single site first; see section 10 for multisite.
- **Who reads its output.** The operator, through `wp indexnow status`,
  `wp indexnow log`, the status page under Settings, and optionally a log
  file. Developers extend it through filters and actions.
- **Scheduling.** WordPress cron by default. Sites that run WP-CLI cron
  from the system crontab, or call `wp indexnow flush` from it, are fully
  supported.

## 4. Behaviour

### 4.1 Key

1. On first need (activation, first flush, or the first key file request),
   the plugin generates a key of 32 lowercase hexadecimal characters from a
   cryptographically secure source and stores it. It is never regenerated
   on its own.
2. A key may instead be supplied by a constant (section 7). When the
   constant is defined, it wins, the stored key is ignored, and the status
   page says the key is set in code. A constant that does not match the
   protocol's key rule (8 to 128 characters, only `a-z`, `A-Z`, `0-9` and
   `-`) is rejected: the plugin logs a configuration error and does not
   submit until it is fixed (fail closed, because every request with a bad
   key is refused).
3. Rotation (CLI or status page) generates a new key, stores it, clears the
   cached verification result, and logs the change. The old key file stops
   being served at once.

### 4.2 Key file and its verification

4. The key file is served at `{home}/{key}.txt`, where `{home}` is the
   site's home URL. The response is status 200, `Content-Type:
   text/plain; charset=utf-8`, body exactly the key with no trailing
   newline, and `Cache-Control: public, max-age=300` so an edge cache does
   not keep a rotated key for long.
5. The request is matched early (before the main query runs) by comparing
   the request path with the current key. No rewrite rule is used, so no
   rewrite flush is needed and the file works the moment the plugin loads.
6. Only the exact current key path is answered. A request for any other
   `{32 hex}.txt`, or for a previous key, falls through to WordPress and
   gets its normal 404. The comparison is constant time.
7. When the home URL has a path (WordPress in a subdirectory, for example
   `https://example.com/blog/`), the key file is served at
   `https://example.com/blog/{key}.txt` and `keyLocation` in every
   submission names that URL. The protocol then only accepts URLs under
   that path; section 4.4 enforces this.
8. Optional physical file mode: `wp indexnow key write-file` writes the key
   file into the directory that serves the home URL, for hosts whose web
   server answers `.txt` requests itself and never reaches PHP. The plugin
   records that it wrote the file, rewrites it on rotation, and removes it
   on uninstall. It never overwrites a file it did not write.
9. Verification. Before the first submission, after a rotation, after a
   403 response, and on demand, the plugin fetches its own key URL with a
   short timeout and checks: status 200, body equal to the key after
   trimming whitespace, no redirect to a different host. The result and
   the time are cached for 24 hours.
   - Body wrong, 404, or redirect off host: the key is **unverified**. The
     flush holds (fail closed) and logs `key_unverified`, because the
     engines would refuse every batch and repeated bad submissions can
     count against the site.
   - Transport failure (the host blocks loopback requests, DNS inside a
     container, a self-signed certificate on staging): the result is
     **unknown**. The flush proceeds (fail open) and logs a warning once a
     day, because many healthy hosts cannot call themselves and the
     engines' own check is the real one.

### 4.3 What enters the queue

10. Publish: a post moving into `publish` from any other status queues its
    permalink. This includes scheduled posts going live through cron.
11. Update: a post saved while already `publish` (status `publish` to
    `publish`) queues its permalink. Saves that happen twice for one edit
    (the block editor saves the post and then its meta boxes) collapse
    into one row through rule 20.
12. A permalink change on a published post (slug edited) queues the new
    URL. The old URL is not announced (it now redirects).
13. Excluded at enqueue, silently and without a log line:
    - statuses other than `publish`: `draft`, `pending`, `private`,
      `future`, `trash`, `auto-draft`, `inherit`;
    - posts with a non-empty password;
    - revisions, autosaves and `nav_menu_item`, whatever their status;
    - attachments (default off; a setting turns them on);
    - post types that are not publicly viewable (`is_post_type_viewable`
      false), and post types not in the configured list (default: `post`
      and `page`);
    - any post while a bulk import has set the pause flag (rule 31).
14. A transition out of `publish` (to draft, private, trash) does not
    queue anything. If the URL is already queued, it stays and is dropped
    at flush by rule 26.
15. The site filter (section 5.2) is called at enqueue with the context
    `enqueue`. If it says no, the URL is not queued. A filter that throws
    is caught; the URL is not queued and the exception message is logged
    once per run (fail closed for that URL only).
16. Programmatic additions: a public function and the CLI accept a post ID
    or a raw URL. A post ID goes through rules 13 and 15. A raw URL goes
    through the URL checks in 4.4 and the filter with no post.
17. The queue stores the post ID where there is one, so the permalink is
    recomputed at send time. This is what lets the flush see slug changes,
    redirects and state written after the publish hook ran (for example a
    language or noindex marker another plugin sets a moment later).
18. Nothing at enqueue makes an HTTP request, reads remote data, or writes
    more than one row.

### 4.4 URL rules (enqueue and flush)

19. A URL is accepted only if it is absolute, `http` or `https`, has no
    fragment, is at most 2,048 bytes, and its host equals the home URL's
    host (case-insensitive, `www.` is not stripped: `example.com` and
    `www.example.com` are different hosts to the protocol). If the home URL
    has a path, the URL must start with it. Anything else is dropped and
    counted as `foreign`.
20. De-duplication key: the URL with a lowercase scheme and host, the
    fragment removed, and percent-encoding normalised to uppercase hex.
    The path is otherwise kept exactly (slugs in non-Latin scripts are
    stored percent-encoded by WordPress, and the encoded form is what the
    engines crawl). A second enqueue of the same key while a row is
    pending does nothing except refresh the row's post ID.
21. Resubmit interval: if the same key was sent successfully less than the
    minimum interval ago (default 60 minutes), the row is queued with a
    "not before" time of the last send plus the interval. An edit storm
    therefore produces at most one submission per interval.

### 4.5 The flush

22. A scheduled event runs the flush every interval (default 15 minutes,
    minimum 5). `wp indexnow flush` runs the same code on demand.
23. One flush at a time. The flush takes a lock with a 10 minute expiry
    using an atomic insert, so two cron runners, or cron and the CLI,
    never send the same rows. A run that cannot take the lock logs
    `locked` and exits.
24. Visibility gate. If the site is set to discourage search engines
    (`blog_public` is 0), the flush logs `skipped_not_public` with the
    queue size and returns. The queue is **kept**, not emptied: the count
    in the log is the visible proof the gate works, and nothing is
    destroyed. Rows still expire by age (rule 37).
25. Environment gate. If `wp_get_environment_type()` is not `production`,
    the flush logs `skipped_environment` and returns, unless a setting
    allows other environment types. This stops a staging clone, which
    copies the database and the key, from announcing its own host.
26. Send-time re-check. For each due row (not-before passed, not backing
    off), oldest first, up to the batch size:
    - with a post ID: reload the post (priming post and meta caches for the
      whole batch in one query each); drop it if it no longer exists, is
      not `publish`, has a password, its type is no longer allowed, or its
      permalink now fails rule 19;
    - recompute the permalink and re-apply rules 19 to 21 to it;
    - call the site filter with context `flush` (rule 15 applies to
      exceptions);
    - then the bulk filter once over the surviving list (section 5.2).
    Dropped rows are removed and counted by reason in the run record.
27. Raw URL rows (no post ID) are resolved with `url_to_postid`. If that
    finds a post, the post checks apply. If it finds none, only the URL
    rules and the filter apply. A URL that resolves ambiguously is
    dropped, which is the safe direction to be wrong in: the cost is one
    URL not announced, and IndexNow is an accelerator, not the only route
    to the index.
28. If nothing survives, the run logs `nothing_submittable` and the
    dropped rows are gone. The run does not spin on them again.
29. Batch. One request carries at most the batch size (default 1,000,
    hard maximum 10,000, the protocol limit) and the JSON body must stay
    under 10 MB; the builder trims the batch if a pathological set of long
    URLs would exceed it. A run sends at most the configured number of
    batches (default 3), so one run cannot monopolise a cron worker.
30. Request. `POST` to the endpoint (default
    `https://api.indexnow.org/indexnow`), `Content-Type: application/json;
    charset=utf-8`, timeout 20 seconds, body:
    `{"host": <home host>, "key": <key>, "keyLocation": <key file URL>,
    "urlList": [<urls>]}`. All URLs in one batch share the host by rule 19.
31. Bulk import pause. A public function (and `wp indexnow pause`) sets a
    flag that suppresses enqueue (rule 13) until resumed or until it
    expires (default 1 hour), so a migration of 50,000 old posts does not
    flood the queue. Resuming does not back-fill.

### 4.6 Responses, retries and back-off

32. Outcome per response, as the protocol documents them:

    | Response | Meaning | Rows | Next run |
    |---|---|---|---|
    | 200 | Submitted | marked sent | normal |
    | 202 | Accepted, key check pending | marked sent, record notes it | normal |
    | 400 | Bad request (format) | attempts plus 1 | normal |
    | 403 | Key not valid (file missing or wrong) | unchanged, no attempt counted | held until key re-verified, then 1 hour back-off |
    | 422 | URLs do not belong to the host, or key does not match | attempts plus 1 | normal |
    | 429 | Too many requests | unchanged | back off: `Retry-After` if present (seconds or HTTP date, capped at 24 hours), else exponential |
    | 5xx | Server error | unchanged | exponential back-off |
    | transport error or timeout | No answer | unchanged | exponential back-off |
    | any other code | Unexpected | attempts plus 1 | normal |

33. Exponential back-off is site-wide: 15 minutes, doubling per
    consecutive failure, capped at 6 hours, reset by any 2xx. While backing
    off, the flush logs `backing_off` with the time it resumes and returns.
34. A row whose attempts reach the maximum (default 5) is removed and
    counted as `gave_up` in the record. This replaces the failure mode
    where a refused batch is kept and resent every run forever.
35. On 400 or 422 with more than one URL in the batch, the next run splits
    that batch in two (the attempts are already counted), so one bad URL
    cannot sink its neighbours for five rounds. A single-URL batch is not
    split further.
36. Redirects from the endpoint are not followed for `POST`; a 3xx is an
    unexpected code (rule 32, last row).

### 4.7 Retention and the log

37. Queued rows older than the maximum age (default 7 days) are removed at
    the start of each flush, even while gates hold. A URL a week old has
    been crawled through the sitemap anyway. This also bounds the backlog
    a site releases when it goes public after a long dark period.
38. Sent rows are kept for the resubmit interval plus one day (they feed
    rule 21), then removed.
39. The queue is capped (default 10,000 pending rows). An enqueue over the
    cap removes the oldest pending row, so the newest content always wins.
    The run record counts evictions.
40. Every flush writes exactly one run record (section 6.2), whatever the
    outcome, including gates and lock misses. The record is stored, sent to
    a log file if one is configured and writable, passed to `error_log`
    when the debug log setting is on, and announced with an action.
41. The log file is never created by the plugin. If the path is set but
    the file is missing or not writable, the record still goes to the
    database and the status page shows a warning. Writes append with an
    exclusive lock.

### 4.8 Edge cases

42. The home URL changes (domain move): pending rows on the old host fail
    rule 19 at flush and are dropped as `foreign`; the key verification
    cache is cleared because the key URL changed.
43. `http` home URL on a site served over `https` by a proxy: URLs are
    sent as the home URL defines them; the status page warns when the home
    URL is `http`.
44. Very large post IDs, deleted posts, and posts whose permalink function
    returns false are dropped as `gone`.
45. Clock skew between web nodes: all times are stored in UTC from the
    database server's clock where the builder can, else PHP's `time()`;
    not-before comparisons tolerate 60 seconds.
46. WordPress cron disabled and no system cron: the status page and
    `wp indexnow status` show the last run time and warn when it is older
    than three intervals.
47. Object cache present or absent: the lock and the settings work the
    same either way (the lock is a database row, not a transient).
48. The plugin is deactivated: the event is unscheduled, the key file
    stops being served, the data stays. Reactivation resumes.

### 4.9 Failure modes summary

| Failure | Behaviour | Why |
|---|---|---|
| Filter throws | Drop that URL, log once | A broken rule must not leak noindex pages |
| Key constant malformed | Hold all sends | Every request would be refused |
| Key file wrong or 404 on self-check | Hold all sends | Same |
| Self-check cannot connect | Send anyway, warn daily | Loopback is often blocked on healthy hosts |
| Site not public, or not production | Hold, keep queue | Never undo a noindex; never lose work |
| Endpoint 5xx, timeout, 429 | Keep rows, back off | Transient |
| Endpoint 400, 422 | Count attempt, split, give up at max | Permanent for that batch |
| Database write fails on enqueue | Log via `error_log`, never break the save | Publishing matters more than pinging |

## 5. Public interface

All names below are owned by this plugin. PHP namespace
`IndianOpinion\IndexNowBatch`. Global helper functions carry the prefix
`indexnow_batch_`.

### 5.1 Functions

- `indexnow_batch_queue_post(int $post_id): bool` queues a post through
  rules 13 and 15. Returns whether a row was added or refreshed.
- `indexnow_batch_queue_url(string $url): bool` queues a raw URL through
  rules 19 to 21 and the filter.
- `indexnow_batch_flush(array $args = []): array` runs a flush and
  returns the run record. Arguments: `dry_run` (bool), `force` (bool,
  ignores back-off and not-before but never the visibility or environment
  gates), `limit` (int, overrides batch size for this run).
- `indexnow_batch_pause(int $seconds = 3600): void` and
  `indexnow_batch_resume(): void` for imports.
- `indexnow_batch_key(): string` returns the current key.

Internally the builder is free to use classes (queue repository, URL
normaliser, submitter, key manager, logger); their names are the builder's
choice and are recorded in `docs/ARCHITECTURE.md`.

### 5.2 Filters

- `indexnow_batch_should_submit` (bool `$submit`, string `$url`,
  `?WP_Post $post`, string `$context` of `enqueue` or `flush`): the site's
  own index rules. This is the one place to express "withdrawn",
  "restored archive, readable but not offered to search engines",
  "translated editions are noindex", "this URL now redirects" and the
  like. It must be cheap; post and meta caches are primed for the batch
  before it is called at flush. Default true.
- `indexnow_batch_urls` (string[] `$urls`): the surviving list for one
  batch, once, for set-based rules (one query over many URLs). Returning
  fewer URLs drops them; returning URLs not in the input is ignored with a
  logged warning.
- `indexnow_batch_post_types` (string[]): default `['post', 'page']`.
- `indexnow_batch_settings` (array): the merged settings, last word.
- `indexnow_batch_endpoint` (string): default
  `https://api.indexnow.org/indexnow`; must be `https`, else the default
  is used and a warning logged.
- `indexnow_batch_request_args` (array, array `$batch`): the
  `wp_remote_post` arguments, for proxies and custom user agents.

### 5.3 Actions

- `indexnow_batch_queued` (string `$url`, ?int `$post_id`).
- `indexnow_batch_dropped` (string `$url`, string `$reason`).
- `indexnow_batch_submitted` (string[] `$urls`, int `$http_code`).
- `indexnow_batch_flushed` (array `$record`): after every run.
- `indexnow_batch_key_rotated` (string `$new_key`).

### 5.4 WP-CLI: `wp indexnow`

| Command | Does |
|---|---|
| `status` | Key (masked to first and last 4), verification state and age, endpoint, gates in force, queue counts (pending, due, sent, backing off), last run record, next scheduled run. `--format=json` |
| `queue list [--state=<pending\|sent\|all>] [--limit=<n>] [--format=<table\|csv\|json>]` | Lists rows |
| `queue add <url-or-id>... [--post]` | Queues URLs, or post IDs with `--post`; prints accepted and refused with reasons |
| `queue remove <url>...` | Removes pending rows |
| `queue clear --yes` | Empties pending rows (asks without `--yes`) |
| `flush [--dry-run] [--force] [--limit=<n>]` | Runs a flush now. `--dry-run` runs every check and prints the batch it would send, sends nothing, changes nothing |
| `key show` | Prints the full key |
| `key verify` | Runs the self-check and prints the result |
| `key rotate --yes` | New key; clears verification |
| `key write-file` / `key remove-file` | Physical key file (rule 8) |
| `log [--limit=<n>] [--format=<table\|json>]` | Recent run records, newest first |
| `pause [--for=<seconds>]` / `resume` | Rule 31 |

Exit code 0 on success, 1 on any error, and `flush` exits 1 if the run
outcome is `transport_error`, `refused` or `key_unverified`, so a system
cron wrapper can alert.

### 5.5 Status page

Settings, IndexNow. Capability `manage_options`. Shows what `status`
shows plus the last 20 run records, and the settings form (section 7).
Buttons: Verify key, Run now, Rotate key (with confirm). Every action is a
POST with a nonce and a capability check. All output escaped. No
JavaScript framework.

### 5.6 Constants

- `INDEXNOW_BATCH_KEY`: supplies the key (rule 2).
- `INDEXNOW_BATCH_LOG_FILE`: absolute path of the optional log file.
- `INDEXNOW_BATCH_DISABLE`: when true, enqueue still works but the flush
  never sends (logs `disabled`). For maintenance.

## 6. Data

### 6.1 Queue table

One custom table, `{prefix}indexnow_batch_queue`, created with `dbDelta`
on activation and on a schema version change, then checked with
`SHOW COLUMNS` (section 14, rule 14).

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned, auto | primary key |
| url | text | as sent |
| url_hash | binary(20) or char(40) | SHA-1 of the de-duplication key; unique with `state` |
| post_id | bigint unsigned, null | null for raw URLs |
| state | varchar(10) | `pending` or `sent` |
| queued_at | datetime, UTC | |
| not_before | datetime, UTC, null | rule 21 |
| attempts | smallint unsigned | rule 34 |
| last_http | smallint unsigned, null | last response code |
| sent_at | datetime, UTC, null | |

Indexes: unique (`url_hash`, `state`), (`state`, `not_before`,
`queued_at`), (`post_id`).

### 6.2 Run record

Fields: `at` (UTC ISO 8601), `outcome` (`submitted`, `accepted_pending`,
`refused`, `transport_error`, `nothing_submittable`, `empty`,
`skipped_not_public`, `skipped_environment`, `disabled`, `key_unverified`,
`backing_off`, `locked`, `dry_run`), `queue` (pending before), `due`,
`sent`, `dropped` (map of reason to count: `status`, `password`, `type`,
`filter`, `foreign`, `gone`, `expired`, `gave_up`, `evicted`), `http`,
`error` (first 160 characters), `batches`, `duration_ms`, `urls` (at most
50 listed, with `n_urls` for the full count).

Stored as a ring buffer of the last 200 records in one non-autoloaded
option. The key is never written into a record.

### 6.3 Options

Non-autoloaded: settings, key, key verification result and time, ring
buffer, lock (as its own option row, added atomically), back-off state,
pause expiry, physical-file marker, schema version. Option names use the
`indexnow_batch_` prefix.

### 6.4 Retention

Pending rows: 7 days or until sent, dropped or evicted. Sent rows: the
resubmit interval plus 1 day. Run records: the last 200. All configurable.

### 6.5 Uninstall

`uninstall.php` drops the table, deletes every option with the prefix,
unschedules the event, and removes the physical key file only if the
plugin's marker says it wrote it and the file still holds the key. It does
not touch an operator-supplied log file. On multisite it does this for
every site. Deactivation removes nothing but the schedule.

## 7. Configuration and sensible defaults

| Setting | Default | Range or values |
|---|---|---|
| Endpoint | `https://api.indexnow.org/indexnow` | any `https` URL |
| Flush interval | 15 minutes | 5 to 1,440 |
| Batch size | 1,000 | 1 to 10,000 |
| Batches per run | 3 | 1 to 10 |
| Post types | `post`, `page` | viewable types |
| Include attachments | off | on, off |
| Resubmit interval | 60 minutes | 0 to 1,440 |
| Maximum attempts | 5 | 1 to 20 |
| Pending maximum age | 7 days | 1 to 30 |
| Queue cap | 10,000 | 100 to 100,000 |
| Allowed environments | `production` | any of the four core types |
| Request timeout | 20 seconds | 5 to 60 |
| Log file | none | absolute path (constant only) |
| Debug to `error_log` | off | on, off |

Settings resolve in this order, last wins: defaults, the stored settings,
constants, the `indexnow_batch_settings` filter. Out-of-range values are
clamped and a warning logged.

## 8. Security and privacy

- The key is a proof of ownership, not a secret in the usual sense: it is
  public in the key file. It is still masked in `status`, never written to
  run records, and never sent anywhere but the configured endpoint.
- The key file handler answers only the exact current path, compares in
  constant time, and sends nothing but the key.
- Only public, published, unprotected URLs on the site's own host are ever
  sent. No user data, no cookies, no titles or content.
- The endpoint must be `https`. TLS verification stays on.
- Admin actions need `manage_options` and a valid nonce. CLI commands that
  destroy queue state need `--yes`.
- Every input (settings, CLI arguments, filter returns) is validated and
  clamped; every output on the status page is escaped.
- No `eval`, no shell, no `@` error suppression.

## 9. Performance budget

- Enqueue: one `INSERT ... ON DUPLICATE KEY UPDATE`, under 2 ms on a
  normal database, no HTTP, no extra `SELECT` beyond what WordPress has
  already loaded for the post.
- Flush of 1,000 rows: at most one row query, one post query, one meta
  priming query, one update per outcome class, and one HTTP request per
  batch. Under 1 second of PHP time excluding the HTTP wait.
- Memory: under 16 MB extra for a 10,000 URL batch.
- The key file response does no database query beyond loading options.
- No autoloaded option is larger than a few hundred bytes.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later.
- Works as a regular plugin or as a must-use plugin through a loader file.
  In must-use mode activation hooks do not fire, so the table and schedule
  are created on the first `init` when the schema version option is
  missing.
- Multisite: each site has its own table, key, queue and schedule, because
  IndexNow keys are per host. Network activation creates them for each
  site; new sites get them on creation.
- Web servers: the dynamic key file needs `.txt` requests at the site root
  to reach PHP when no such file exists. Nginx configurations that use
  `try_files $uri $uri/ /index.php?$args` do; ones with a static-only
  location for `.txt` do not, and need rule 8's physical file. The README
  says so.
- Page caches and CDNs: the key file response carries a five minute
  `Cache-Control`; rotation therefore takes up to five minutes to be seen.
- Persistent object caches: supported; nothing relies on them.
- WordPress cron, WP-CLI cron, and a system cron calling
  `wp indexnow flush` all work.
- SEO plugins: none is required or read. Their noindex decisions reach this
  plugin only through the filter; the README shows short examples.

## 11. Observability

- `wp indexnow status` and the status page answer, in one screen: is it
  running, when did it last run, what happened, how many are waiting, and
  why it is not sending if it is not.
- `wp indexnow log` and the ring buffer hold the last 200 run records.
- Optional log file, one JSON line per run, for log shippers.
- `error_log` lines (prefix `[indexnow-batch]`) when debug is on, and
  always for enqueue database errors and configuration errors.
- Actions in 5.3 for sites that ship metrics elsewhere.
- Healthy looks like: `submitted` every interval when there is new
  content, `empty` otherwise, `dropped.filter` above zero on sites that use
  the filter, no `backing_off` for more than a few runs.

## 12. Acceptance tests

Each test runs under PHPUnit 10 against the stub layer, with HTTP mocked.
The case numbers refer to section 4.

| Test | Case |
|---|---|
| T1. First call generates a 32 character lowercase hex key and stores it; a second call returns the same key | 1 |
| T2. A valid key constant wins; a malformed one holds the flush with `key_unverified` and a config error | 2 |
| T3. Rotate produces a different key, clears verification, fires the action; the old key path now 404s | 3 |
| T4. Request for `/{key}.txt` returns 200, `text/plain; charset=utf-8`, body equal to the key, the cache header | 4 |
| T5. The handler works with no rewrite rules registered | 5 |
| T6. Request for a different 32 hex `.txt` falls through | 6 |
| T7. Home URL with a path serves the key under that path and sends that `keyLocation` | 7 |
| T8. `key write-file` writes the file and the marker; refuses to overwrite a foreign file; rotation rewrites it | 8 |
| T9. Self-check: wrong body holds (`key_unverified`); transport error proceeds with a warning; success caches for 24 hours | 9 |
| T10. Draft to publish queues the permalink; future to publish via cron queues it | 10 |
| T11. Publish to publish queues; two saves in one request leave one row | 11 |
| T12. Slug change queues the new URL, not the old | 12 |
| T13. Each excluded status, a password, a revision, an autosave, an attachment (default), a non-viewable type and a type not in the list all leave the queue empty | 13 |
| T14. Publish to draft queues nothing; a queued URL for that post is dropped at flush with reason `status` | 14, 26 |
| T15. Filter false at enqueue keeps the URL out; a throwing filter keeps it out and logs | 15 |
| T16. `queue_post` and `queue_url` follow the same rules; a raw URL reaches the filter with a null post | 16 |
| T17. A marker written after the publish hook (filter reads post meta) drops the URL at flush | 17 |
| T18. Enqueue performs no HTTP request and one write | 18 |
| T19. Foreign host, `www` mismatch, fragment, non-HTTP scheme, over-long URL, URL outside the home path are refused | 19 |
| T20. Case and percent-encoding variants of one URL make one row; a non-Latin percent-encoded slug is kept as sent | 20 |
| T21. A URL sent 10 minutes ago is queued with not-before at sent plus 60 minutes and is not due before then | 21 |
| T22. The event is scheduled at the configured interval; CLI flush runs the same path | 22 |
| T23. A second concurrent flush logs `locked`; an expired lock is taken over | 23 |
| T24. `blog_public` 0 logs `skipped_not_public` with the count; the queue is unchanged | 24 |
| T25. Environment `staging` logs `skipped_environment`; allowed after the setting changes | 25 |
| T26. A batch with a deleted post, a private post, a passworded post and a filter-refused URL sends only the good ones and counts each reason | 26 |
| T27. Raw URL resolving to a post applies post checks; unresolvable applies URL rules and filter only | 27 |
| T28. All rows dropped logs `nothing_submittable` and leaves no pending rows | 28 |
| T29. 2,500 due rows, batch 1,000, 3 batches: three requests of 1,000, 1,000, 500; batch size over 10,000 is clamped | 29 |
| T30. The request body has exactly `host`, `key`, `keyLocation`, `urlList`, the JSON content type and the timeout | 30 |
| T31. While paused, publishes queue nothing; after expiry they queue again; resume does not back-fill | 31 |
| T32. One test per row of the response table: rows, attempts, record outcome and next-run state as specified | 32 |
| T33. Consecutive 5xx back off 15, 30, 60 minutes up to 6 hours; a 2xx resets; `Retry-After` in seconds and as a date are honoured and capped | 33 |
| T34. A row refused five times is removed and counted `gave_up` | 34 |
| T35. A 422 on a batch of 4 splits into two batches of 2 next run | 35 |
| T36. A 3xx from the endpoint is not followed and counts as unexpected | 36 |
| T37. Pending rows older than 7 days are removed even while `blog_public` is 0 | 37 |
| T38. Sent rows older than the interval plus one day are removed | 38 |
| T39. Enqueue at the cap evicts the oldest pending row and counts it | 39 |
| T40. Every outcome writes exactly one record; the ring buffer keeps 200; `urls` capped at 50 with `n_urls`; the key is absent from every record | 40 |
| T41. Missing or unwritable log file: record still stored, warning shown, no file created | 41 |
| T42. Home host change drops old-host rows as `foreign` and clears verification | 42 |
| T43. `http` home URL shows the warning | 43 |
| T44. A permalink returning false drops the row as `gone` | 44 |
| T45. Not-before 30 seconds in the future counts as due | 45 |
| T46. Last run older than three intervals produces the warning | 46 |
| T47. Lock works with and without an object cache stub | 47 |
| T48. Deactivate unschedules and stops the key file; data kept; reactivate resumes | 48 |
| T49. Uninstall drops the table, deletes every prefixed option, unschedules, removes only its own key file, leaves the log file | 6.5 |
| T50. CLI: every command in 5.4 runs, `--dry-run` sends and changes nothing, exit codes as specified | 5.4 |
| T51. Status page actions refuse without the capability or with a bad nonce | 5.5, 8 |
| T52. Settings out of range are clamped and warned; a non-`https` endpoint falls back to the default | 7, 5.2 |
| T53. Enqueue database failure logs and does not stop the post save | 4.9 |
| T54. `indexnow_batch_urls` returning a URL not in the input ignores it and warns | 5.2 |
| T55. Must-use mode creates the table and schedule on first `init` | 10 |

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

- Q1. Custom table (as specified) or a post-meta and option design for
  small sites? The table is the default; say whether a fallback is worth
  having.
- Q2. Should the queue keep accepting rows while `blog_public` is 0, or
  stop at enqueue as well? Specified: keep, bounded by age and cap.
- Q3. Announcing removals (trash, 410) as the protocol allows: off in
  0.1.0. Should 0.2 add it behind a setting?
- Q4. Should taxonomy archive and author URLs be queued when a post in
  them is published? Specified: no.
- Q5. Exact split strategy for 400 and 422 (halves, as specified, or
  per-URL isolation after the first split).
- Q6. Whether to send a custom `User-Agent` naming the plugin and its
  version by default.
- Q7. Multisite with domain mapping: confirm each mapped domain gets its
  own key file and host.

## 14. Lessons from production

1. Batch. Never call IndexNow on the publish path. One request per
   interval carries everything.
2. Check at send time, not only at queue time. Posts change state between
   the two, and a queue can hold URLs banked long before the rules that
   would exclude them existed.
3. Store the post, not just the URL. State that decides indexability is
   often written a moment after the publish hook fires, and permalinks
   change.
4. Never announce a page you have told engines not to index, and never
   announce a URL that redirects or answers 410. One ping undoes a
   noindex.
5. Honour the site's visibility switch and the environment type. A staging
   copy carries the production database, so it carries the queue and the
   key too.
6. Hold, do not destroy. A gated flush keeps the queue and logs its size;
   age and a cap bound it instead.
7. When the site goes public after a dark period, the first flush sends
   the whole backlog. Bound it by age, and re-check it at send time.
8. Remove only the rows you sent. Clearing the whole queue after a request
   loses URLs queued while the request was in flight.
9. Give up eventually. A refused batch kept and resent every run never
   recovers and hides every later failure.
10. Cron output usually goes nowhere. A log that lives only in the PHP
    error log is no log: persist one record per run where an operator can
    read it with WP-CLI.
11. Drop what you cannot positively identify only when the safe direction
    is dropping. A missed announcement costs a slower crawl; a wrong one
    costs an index entry you did not want.
12. Non-Latin slugs are stored and served percent-encoded. Compare in one
    canonical encoding, and send the form WordPress emits.
13. A rewrite rule for the key file needs a rewrite flush, and some web
    servers never pass `.txt` to PHP. Match the path early in PHP, and
    offer a physical file.
14. Database schema helpers can silently skip a column. After creating or
    upgrading the table, confirm the columns exist and refuse to run if
    they do not.
