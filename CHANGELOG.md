# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **A post save purges the feeds and the llms files.** The theme now gives the feeds and the machine files a five-minute shared lifetime with stale serving (they left with `no-cache` or WordPress's nocache headers; the machine files bypassed the cache at about 0.4 s a read, and the feed is fetched about 2,300 times a month), so the edge holds them and a save has to clear them. `sn_cf_post_purge_urls()` adds `/feed/`, `/feed/atom/`, `/notes/feed/atom/`, `/feed/json/`, `/notes/feed/json/`, `/?feed=json`, `/llms.txt` and `/llms-full.txt` beside `/notes/feed/`; `/.well-known/agents.json` and `/opensearch.xml` stay out because a save changes neither. The set is 18 URLs at 39 notes, still one purge call (Cloudflare takes 30 a call; past that the existing chunking applies). Not added: `/tag/<slug>/` archives, which were never in the list and are held for the same day as before. Pinned in `tests/cloudflare-purge-coverage.php`.
- **The edge posture reads Always Online.** Same settings answer, same token scope. With the theme's `stale-while-revalidate` / `stale-if-error` headers in place it is judged: Always Online on makes Cloudflare ignore both, so real pages returned 503 with no stale copy would stay that way while every header looked right. On is a drift on the posture card and a finding in the edge posture health check; without those headers it is a reading on the "also" line. Pinned in `tests/cloudflare-posture.php`.
- **MailPoet's page type leaves the sitemap.** Core listed `wp-sitemap-posts-mailpoet_page-1.xml` because MailPoet registers `mailpoet_page` as public; the `wp_sitemaps_post_types` filter drops it. Pinned in `tests/sitemap.php`.

## [20.7.1] - 2026-10-03 — Breeze's timed purge is off

### Fixed
- **Breeze's timed purge is off.** Its "Purge Cache After" field will not take 0: the form saved 1440, and 1 would have purged every minute. So the setting cannot turn it off, and the plugin unhooks Breeze's `breeze_purge_cache` handler and its rescheduler and clears the event, as 20.7.0 did for Breeze's update purge (owner, 2026-10-03). Pages purge on save and once per update; the nightly run only emptied a correct cache. A Breeze settings save can schedule it again, and the next request clears it. The purge ledger's `breeze_nightly` reads `off`. Pinned in `tests/purge-ledger.php`.

