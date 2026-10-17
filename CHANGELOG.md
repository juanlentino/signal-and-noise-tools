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
- **An update refreshes the cached feeds too, and a refused REST route is never cached.** A code update marks the Cloudflare zone stale, and Cloudflare serves a stale feed for up to an hour while it refetches it: after 2026.10.33, `/feed/` and `/notes/feed/` kept their pre-update headers (no `X-Robots-Tag`) until a manual purge. The update purge now also deletes the edge-cached feeds (`/feed/` and `/notes/feed/` in every format, `/llms.txt`, `/llms-full.txt`), after the zone call's outcome is recorded, so the purge ledger still reads the update as a mark-stale; pages keep the stale-safe refresh. Separately, Cloudflare stored the `rest_no_route` 404 that `/owner/revoked` and `/analytics/refresh` give a caller without the token (2026.10.26) and served it to the next request whatever its token, so the analytics Worker's authorized fetch of the revocation list could read a cached 404. Every `rest_no_route` answer, core's own included, now carries `Cache-Control: no-store`.

## [2026.10.33] - 2026-10-17 — Search Console cleanup: MailPoet and feeds noindexed, five old paths 301


- **Search Console cleanup: MailPoet's page and the feeds leave the index, and five old paths 301.** MailPoet's subscription page (a request that resolves to the `mailpoet_page` post type; the bare parameter on another URL does not count, so nobody can de-index a page by linking to it with `?mailpoet_page=`) now gets `noindex` in the robots meta, an `X-Robots-Tag: noindex` header, and no canonical; the canonical was this plugin's own, printing the page's `?mailpoet_page=` permalink. Every feed (`/feed/`, `/notes/feed/`, tag and page feeds, `/feed/json/`) sends `X-Robots-Tag: noindex, follow` and keeps working, `<link rel="alternate">` tags included. `/provenance/verify` goes to `/verify/`, `/es/` to `/inicio/` and `/es/about/` to `/sobre-mi/` through the 404-only old-page-path map (`inc/maturity-legacy-redirects.php`, which now matches whole paths and keeps the query string, so an old `/provenance/verify?note=&v=` link still verifies its note), and `/sitemap/` joins `/sitemap.xml` on its way to `/wp-sitemap.xml`. `/es/notes/` stays a 404; `/notes/` has no Spanish page.
- **A provenance version now says what kind of edit minted it.** sn-apply takes an optional `change.change_class` (`reading_aid`, `correction`, `copy`, `claim`, `unclassified`) on any write that can mint a version, batch included. The class is stored on the chain entry beside the signed payload, never in it: the payload, its hash and `sn-normalize-v2` are unchanged, and the Worker never receives it. The record's edit list shows it as a dashed label under the version (an unclassified version shows nothing), /verify adds it to the verdict line as the author's label and not as a check, the credential carries it as `evidence[0].changeClass`, the dossier gains an Edits table, and anchor-status reports it on in-flight rows and as a per-class count. An unknown value is a 422 that names the valid ones; a write that adds or replaces a correction notice cannot be classed `reading_aid` or `copy`. The sn-apply call log records the class. Every existing version stays unclassified until the owner approves a backfill.


