# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.8] - 2026-09-10 — the unbounded stacks, folded and capped

### Changed
- **Broken links is one line per path, not one form per path.** A section per broken path is the right shape for one and the wrong shape for twenty: measured live 2026-09-10, 20 paths at ~450px each painted a **9,101px leaf — nine screens of scrolling** to reach the Clear button, because every path carried a create-redirect form open by default. Each path is now a fold headed by the path and hinted with its hits and last-seen date; the form opens on the one you are acting on. Same primitive the audit log already uses for its login list.
- **Both lists are capped.** The 404 log lists the 25 busiest paths and the redirect map the 50 most recent, each stating the true total — the log's real count comes from the status line above it, so a cap can never make the log look smaller than it is. The two caps are defined once, in `inc/redirects-404-log.php`, because the classic renderer and the OpenStation leaf are parity-tested against each other and two copies of the number would pass review and drift on the first edit.

### Fixed
- The app stylesheets were linked twice. OpenStation auto-loads `apps/<id>/<id>.css` under its own handle; we pushed ours at the same file into the window's styles list unconditionally, so the admin carried two `<link>` tags each for `sn-dashboard.css` and `sn-analytics.css`. The extra request is the small half — the two cache-bust on **different inputs** (theirs on `filemtime`, ours on `SNT_VERSION`), and ours loaded **last**, so a CSS edit without a version bump served a fresh copy that was overridden by a stale cached one. Ours is now dropped only when theirs is actually present, so an OpenStation without the auto-loader still gets a styled window. The same guard already existed for the `signal-noise` app and had never been extended to the two host windows.
