# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.36] - 2026-10-18 — the login door stays hidden, and quieter error logs


- **`/wp-signup.php` and `/wp-activate.php` no longer point at the login door.** After the `/login` fix, two more core scripts still did: on a single site `wp-signup.php` redirects to `wp_registration_url()`, which the mask rewrites to the custom slug (`302 /sn-login?action=register` live on 2026-10-17), and `wp-activate.php` redirects to `wp-login.php?action=register`. Both now take the same direct-access 404 as `wp-login.php`, through one path-anchored, case-insensitive predicate, `sn_login_request_is_core_login_file()`; a query-string value never matches. `tests/login-admin-locations.php` gains 12 cases (red before).

- **`/login` no longer hands out the hidden login URL.** WordPress core redirects `/login` to `wp_login_url()`, and the login mask rewrites that to the custom slug, so `GET /login` answered `302 /sn-login` on the live site: anyone who typed `/login` was given the secret door, defeating the point of masking it (the owner found it on 2026-10-17). `inc/login-hide.php` now unhooks core's `wp_redirect_admin_locations` while the mask is active, so `/login`, `/admin` and `/dashboard` 404 like `/wp-login.php` and `/wp-admin/`. The emergency bypass and the wps-hide-login stand-down still return first, leaving core's redirect in place when the mask is off. New `tests/login-admin-locations.php` (red before: 2 of 3 failed).

### Fixed
- **The app error log holds days again, not about 4 hours.** Object Cache Pro prefetches a request's cache keys before plugins load, so the WordPress AI client's cached model objects (group `wp_ai_client`) came back as incomplete classes, and OCP logged "The cache key `ai_client_...` is incompatible with prefetching" on every request. On 2026-10-17 that notice was about 980 of the error log's last 1000 lines, so a fatal from earlier the same day was already gone when the edge-errors investigation looked. The plugin now registers `wp_ai_client` as non-prefetchable through OCP's own `wp_cache_add_non_prefetchable_groups()` at load (new `inc/object-cache-groups.php`); without OCP it does nothing. No wp-config change: OCP filters each request's stored prefetch list by these groups at shutdown, so lists stored earlier heal as each URL is visited again (flushing the object cache clears them at once). The one `transient`-group notice (a MailPoet key) is left alone. Pinned by the new tests/object-cache-groups.php.

### Added
- **edge-errors-summary says, per day, how many installs there were and whether they explain the 5xx.** A new `installs_by_day` list (oldest first) gives each day's plugin and theme installs from the deploy history and `explained`: true when every 5xx that day is a 503 on the owner's dashboard paths, at most 3 per install, the same rule and the same functions the SN Systems card uses. `installs` is null without a deploy history (unknown, never 0), and `explained` is null for a day not read yet or with no 5xx. `sn-status{edge_errors}` carries it too, since it reads the same ability. Why: the 2026-10-12 to 10-18 investigation had to rebuild install times from git tags to see that 10-15 (58 errors against 7 installs) was over budget. Local door only, like get-deploy-status's `runtime`: the remote twin keeps the shared schema and callback, so the remote contract and the worker do not move. Pinned by the new tests/edge-errors-installs-by-day.php and a strip pin in tests/abilities-remote-set.php.

