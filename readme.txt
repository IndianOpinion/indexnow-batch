=== IndexNow Batch ===
Contributors: indianopinion
Tags: indexnow, seo, bing, search engines, indexing
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Queues URLs on publish and update, re-checks them at send time, and submits them to IndexNow in batches with retries and a log.

== Description ==

IndexNow lets a site tell participating search engines (Bing, Yandex, Naver, Seznam and others) that a page was added or changed. This plugin does it in batches on a schedule, never on the publish path, and re-checks every URL just before it is sent, so drafts, private posts, noindexed pages and anything your own rules refuse are never announced.

* One request per interval instead of one per post.
* Send-time re-check against the core rules and your own filter.
* Holds while "Discourage search engines" is ticked or the environment is not production, without losing the queue.
* Bounded retries with back-off driven by the response code; refused batches are halved and retried once.
* Key generated, served and verified automatically, no rewrite rules.
* A run record for every flush, readable with WP-CLI and on the status page.
* `wp indexnow` commands for every action.

Google does not take part in IndexNow; this plugin does not call Google.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the `indexnow-batch` directory to `wp-content/plugins/` and activate it.
2. Run `wp indexnow key verify`, or open Settings, IndexNow and press Verify key.
3. Add your own index rules with the `indexnow_batch_should_submit` filter.

To run it as a must-use plugin, see the loader in `examples/mu-loader.php`.

If your web server answers `.txt` requests itself and never reaches PHP, run `wp indexnow key write-file`.

== Frequently Asked Questions ==

= Nothing is being sent =

Run `wp indexnow status`. The gates line says why.

= Does it read my SEO plugin's noindex setting? =

Not directly. Connect it through the `indexnow_batch_should_submit` filter; the README has examples for common SEO plugins.

= What does uninstall remove? =

The queue table, every option the plugin created on every site, the schedule, and a key file only if the plugin wrote it. Never your log file.

== Changelog ==

= 0.1.0 =
* First release.
