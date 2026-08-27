# Build backlog — items that don't belong on the board

Small, self-evident-once-shipped work. The rule from the 2026-08-27 brainstorm: a board
row is for capability *programs*; these are builds where a row would outlive the work.
Grep-verified unbuilt on 2026-08-27 — re-verify before starting any item, and check the
denied-list perimeter in
[2026-08-27-roadmap-brainstorm.md](proposals/2026-08-27-roadmap-brainstorm.md) before
adding new items here.

Precedent for this file: the theme's `docs/superpowers/specs/2026-06-14-additions-backlog.md`
(items with triggers). This is the living cross-repo successor; that file stays as record.

## Reader-facing (theme)

| # | Item | Size | First step / notes |
|---|------|------|--------------------|
| 1 | Hover previews for internal note links | S–M | Reuse the `assets/js/footnotes-popover.js` pattern; progressive enhancement, honors reduced-motion |
| 2 | Print stylesheet | S | One `@media print` fragment exists in `inc/block-styles.php`; extend to a full typeset page — provenance footer, URL shown after links, no nav chrome |
| 3 | Topic hubs for the 23-tag vocabulary | M | No taxonomy template exists. HARD PRECONDITION: one written sentence per tag before shipping, or the pages trip the contentless-page SEO trap on record |
| 4 | Reply-by-email on notes | S | Reuse the `inc/contact-email.php` DOM-assembled mailto (no scrapeable address); subject prefilled with the note title |

## CI / tooling (both repos — public, so minutes are free; argue runner-hold, not money)

| # | Item | Size | First step / notes |
|---|------|------|--------------------|
| 5 | Next-PHP lane | S | Both repos pin PHP 8.3 only. One matrix lane on the next PHP RC, `continue-on-error` at STEP level (the non-blocking-CI rule), `timeout-minutes` set |
| 6 | Editor smoke vs WordPress nightly | M | The pre-publish gate and draft echoes ride `@wordpress` packages; scheduled job against WP nightly so a core release breaks a cron, not a writing session |
| 7 | Stub-parity sweep | M | Diff the test stubs' function signatures against the pinned WP source; the stub-drift trap is 13× bitten. Turns the ambush into a red CI line |

## Log

- 2026-08-27 — file created; items 1–7 seeded from the roadmap brainstorm (round two).
