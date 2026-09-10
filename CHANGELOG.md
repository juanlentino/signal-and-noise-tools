# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.4] - 2026-09-10 — the webmention route's registration is pinned

### Added
- The webmention receiver's **registration** is now pinned. `tests/citations-endpoint.php` exercised the handler directly, which covers its branches but skips the layer deciding whether the handler is reachable at all: `sn_cit_register_route()` had never been called by any test, leaving the namespace, the path, the POST-only method list and the deliberately public `permission_callback` unpinned. A refactor could move the route, add GET, or "tighten" the permission callback and every existing assertion would still pass while discovery broke. Four mutations confirm the new guards redden: moving the namespace (3 failures), adding GET (2), hardcoding the advertised href so it drifts (2), and making the inbox non-public (1).
- Discovery is now asserted **computed** rather than literal. The advertisement checks matched a hardcoded `/wp-json/signal-noise/v1/webmention`; the `<link rel="webmention">` href is now compared against `rest_url()` built from the same constants the emitter uses, and separately against the namespace and route as actually registered, so markup and route cannot disagree.

