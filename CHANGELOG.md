# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **In the installed PWA, same-origin links launched a second OpenStation (#1301).** A `target="_blank"` navigation, or `window.open( …, '_blank' )`, to any URL on this site is inside the app's scope, so the browser started the app again instead of opening a tab. Every front-end link out of a window did it ("View the note", "Verification docket", /now, /resume, /about/uses, a candidate's permalink), so did the Signal & Noise app's "View the note" and dossier URL actions, and the desktop dropzone's "open the draft". The shell's own interceptor bails on `target="_blank"` and claims only `/wp-admin/` paths, so the plugin routes these itself now, on one rule: same origin is a window (the shell iframes the front end the way it iframes previews), another origin is a tab, and a same-origin file (.xml/.csv/.json/.txt/.zip/.pdf) stays a navigation. The host rewrite turns same-origin non-admin anchors into doors even when the leaf authored `target="_blank"` for the classic page; the Dashboard and Analytics `door` handlers accept any same-origin URL and still refuse other origins; `snt_kit_link()` and list-row hrefs paint a door for same origin and a tab for external; the app has one `openLink()` (its only `window.open`); the dropzone opens the draft through `wp.os.windowManager`. The host's URL helpers moved to `inc/openstation-host-urls.php` (function_exists-guarded) so the kit can decide door-or-tab without loading the host. Pins: `tests/openstation-host.php` (three link shapes, HTML-API run), both host app suites (front end opens, other origins refused), six leaf suites re-pinned to door markup, `tests/openstation-app-client.php` (one `window.open`, both call sites through `openLink`, comments stripped). Mutation red: host branch off.

## [14.7.4] - 2026-09-14 — instants print in the site's timezone

### Fixed
- **Admin surfaces print instants in the site's timezone (#1298).** The Attention queue said "as of 2026-09-14 08:00:28 UTC" beside a clock reading 4:00 AM; the Provenance window's Commits copy and the RSS Monitoring leaf ("Time (UTC)", the most-recent request) did the same. WordPress already holds the zone (Settings › General: America/New_York); the owner reads in it. Every print site now goes through `wp_date()` with the zone's abbreviation for THAT instant on the page ("2026-09-14 04:00:28 EDT"), so DST is right and the zone is never assumed. Storage stays UTC. In the Attention queue the split matters: `attention_stamp()` is an identity key (acknowledgements hold against it, rows sort by it, the composer re-applies it over readers' output), so it stays UTC and idempotent, and the new `attention_local()` does the reading at the print sites; a first cut that converted the key itself shifted the instant four hours per pass (three passes read midnight), which the suite now pins. Tests run under the site's zone through `tests/lib/site-timezone-stub.php` instead of a `gmdate()` stand-in that only ever measured the UTC fallback: `tests/openstation-app-attention.php` 118 → 120 (labels in EDT, keys in UTC, idempotence), `tests/os-leaf-monitoring-rss.php` 36 → 38, `tests/os-leaf-tools-provenance.php` re-pinned. Mutation red: `attention_local()` printing UTC.

