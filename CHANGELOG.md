# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Queue on publish, scheduled publish and update, storing the post ID so the URL is recomputed at send time.
- Core exclusions: non-published statuses, passwords, revisions, autosaves, menu items, attachments (setting), non-viewable and unlisted post types.
- `indexnow_batch_should_submit` filter at enqueue and at flush, `indexnow_batch_urls` per batch; a throwing filter refuses its URL.
- URL rules: same host and home path, http or https, no fragment, at most 2,048 bytes; de-duplication by a normalised key.
- Resubmit interval, queue cap with eviction, maximum age.
- Scheduled flush with an atomic database lock, visibility and environment gates that keep the queue, send-time re-check with primed caches.
- Batches up to 10,000 URLs and 10 MB, at most a set number per run.
- Response handling: 200 and 202 sent; 429, 5xx and transport errors back off site-wide (`Retry-After` honoured); 403 holds until the key verifies; 400 and 422 batches halved and retried once, then marked failed; other codes give up after five attempts.
- Key generation, `INDEXNOW_BATCH_KEY`, rotation, key file served from PHP without rewrite rules, optional physical key file, self-check with a 24 hour cache.
- One run record per flush in a ring buffer of 200, an optional JSON-lines file, debug lines, and actions.
- `wp indexnow` commands: status, queue list/add/remove/clear, flush, key show/verify/rotate/write-file/remove-file, log, pause, resume.
- Settings, IndexNow status page with Verify key, Run now and Rotate key.
- Multisite: per-site key, host, schedule and rows in one shared table with a site column.
- Must-use mode, uninstall that removes everything the plugin created.

[Unreleased]: https://github.com/IndianOpinion/indexnow-batch/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/indexnow-batch/releases/tag/v0.1.0
