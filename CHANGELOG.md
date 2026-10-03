# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **A post save purges the feeds and the llms files.** The companion theme PR holds the posts feeds and the machine files at the edge for five minutes with stale serving (they left with `no-cache` or WordPress's nocache headers; the machine files bypassed the cache at about 0.4 s a read, and the feed is fetched about 2,300 times a month), so a save has to clear them. `sn_cf_post_purge_urls()` adds the theme's own list of cached feed addresses (`sn_edge_cache_feed_paths()`: rss2, rss, rdf, atom and json under `/feed/` and `/notes/feed/`, plus `/?feed=json`) and `/llms.txt`, `/llms-full.txt`. The list is read from the theme, not copied, so every feed variant that gets the header is purged and a format the theme adds needs no edit here; comment, tag and other feeds stay on `no-cache` in the theme and are never held. `/.well-known/agents.json` and `/opensearch.xml` stay out because a save changes neither. 24 URLs at 39 notes, still one purge call (Cloudflare takes 30 a call; past that the existing chunking applies). A theme without the list adds nothing. Not added: `/tag/<slug>/` archives, which were never in the list and are held for the same day as before. Pinned in `tests/cloudflare-purge-coverage.php`.
- **The edge posture reads Always Online.** Same settings answer, same token scope. It is judged only when the theme really sends `stale-while-revalidate` or `stale-if-error` to the edge: the check reads the `Cloudflare-CDN-Cache-Control` value the theme builds for public HTML and looks for a positive stale directive, so an older theme, or a filter that switches the header off or strips the directives, leaves it a reading on the "also" line. When judged, Always Online on makes Cloudflare ignore both directives, so real pages returned 503 with no stale copy would stay that way while every header looked right: it is a drift on the posture card and a finding in the edge posture health check. Pinned in `tests/cloudflare-posture.php`.
- **Generate PDF purges the PDF, not the site.** Pressing Generate ran the purge-everything ability so the edge would drop one file: every page, Varnish and Redis emptied, and the 20.7.0 purge ledger would have logged each Generate as a manual purge with a Redis flush. It now re-saves /resume as before, and that page save does the page's own purging (this plugin's per-URL Cloudflare purge for `/resume/` and its listings, Breeze's save handler for its stored copy), then sends three addresses through `sn_cf_purge_urls()`: the bare `…/resume/JuanLentino_Resume.pdf`, the new `?v=` URL, and the previous `?v=` URL an old copy of the page may still link. Nothing happens when Cloudflare is not configured. Pinned in `tests/resume-pdf.php`.
- **MailPoet's page type leaves the sitemap.** Core listed `wp-sitemap-posts-mailpoet_page-1.xml` because MailPoet registers `mailpoet_page` as public; the `wp_sitemaps_post_types` filter drops it. Pinned in `tests/sitemap.php`.

## [20.7.1] - 2026-10-03 — Breeze's timed purge is off

### Fixed
- **Breeze's timed purge is off.** Its "Purge Cache After" field will not take 0: the form saved 1440, and 1 would have purged every minute. So the setting cannot turn it off, and the plugin unhooks Breeze's `breeze_purge_cache` handler and its rescheduler and clears the event, as 20.7.0 did for Breeze's update purge (owner, 2026-10-03). Pages purge on save and once per update; the nightly run only emptied a correct cache. A Breeze settings save can schedule it again, and the next request clears it. The purge ledger's `breeze_nightly` reads `off`. Pinned in `tests/purge-ledger.php`.

