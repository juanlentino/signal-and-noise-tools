# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- The webmention receiver's **registration** is now pinned. `tests/citations-endpoint.php` exercised the handler directly, which covers its branches but skips the layer deciding whether the handler is reachable at all: `sn_cit_register_route()` had never been called by any test, leaving the namespace, the path, the POST-only method list and the deliberately public `permission_callback` unpinned. A refactor could move the route, add GET, or "tighten" the permission callback and every existing assertion would still pass while discovery broke. Four mutations confirm the new guards redden: moving the namespace (3 failures), adding GET (2), hardcoding the advertised href so it drifts (2), and making the inbox non-public (1).
- Discovery is now asserted **computed** rather than literal. The advertisement checks matched a hardcoded `/wp-json/signal-noise/v1/webmention`; the `<link rel="webmention">` href is now compared against `rest_url()` built from the same constants the emitter uses, and separately against the namespace and route as actually registered, so markup and route cannot disagree.

## [13.109.3] - 2026-09-10 — the validator grades the description that ships

### Fixed
- `sn_validate` now grades the meta description a page actually **ships**, not whichever string happens to be stored on it. The front page, `/notes` and `/provenance` take their description from `seo_copy.*` settings and never emit `_sn_meta_description` at all, but the validator read the post meta on every post — so on `/provenance` it reported a 175-character length while the page served 83, naming a string that appears nowhere in the HTML and that no edit to either value alone could satisfy. The three route-served pages now resolve through the settings key; every other post still reads post meta. An empty route setting skips the surface rather than quietly falling back to the meta row, because "which store" and "is it filled" are different questions.
- The route table that decides this lived inside `sn_seo_description_for_post()`, where no other caller could ask it. It is now `sn_seo_description_setting_key()` and is read by both the description resolver and the validator, so a fourth route-served page is added in one place instead of two that drift apart.

