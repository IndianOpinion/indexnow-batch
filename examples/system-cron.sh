#!/usr/bin/env bash
# Example: run the flush from the system crontab instead of WP-Cron.
#
#   */15 * * * * www-data /usr/local/bin/indexnow-flush.sh /var/www/example.com
#
# `wp indexnow flush` exits 1 when the run is refused, hits a transport
# error, or holds on an unverified key, so cron mail (or any wrapper)
# hears about it.
set -euo pipefail

site_root="${1:?usage: indexnow-flush.sh <wordpress root>}"

if ! output="$(wp --path="$site_root" indexnow flush 2>&1)"; then
    printf 'indexnow flush failed on %s:\n%s\n' "$site_root" "$output" >&2
    exit 1
fi
