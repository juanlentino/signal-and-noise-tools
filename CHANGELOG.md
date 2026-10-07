# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The analytics 2.0 gate checks that the figures agree, not that the stored rows are identical** (owner rule 2026-10-07). The 22.9.2 diagnostic showed Analytics Engine samples each dataset on its own when it stores rows, human visitor-days included (014c96dd on Oct 7: scroll rows 1 standing for 2 in one dataset, 2 standing for 4 in the other), and drops the odd row on either side with no code difference. Exact equality cannot happen on a busy day. Two things are now accepted, and nothing else:
  - an exactly counted event other than pageviews whose two sides differ by at most 2 rows (and 2 visitors) is named in `allowed`, not `differs` (Oct 5's `tm` 6 vs 7 and Oct 6's `vi` 2 vs 0 both fit);
  - a sampled day is a `match` when the human views and visits a reader sees, read from each pageview dataset under the same human rule every figure uses, agree within 1% or 2, whichever is larger. Both readings are shown in `figures`. They are read only with a complete over-cap list, and two more Analytics Engine reads run only on a sampled day.
  Pageviews never get the allowance, a gap past it is still a mismatch, and agreeing figures never cover one. The set-aside rule (22.9.1) stays; it can only add matches. It was approved on a premise that held for one event on one day: the human figures WERE affected.

## [22.9.2] - 2026-10-07 — an event the analytics 2.0 check leaves sampled says why

### Added
- **An event the analytics 2.0 check leaves `sampled` says why** (`sampled_why` per day). After 22.9.1 was installed, Oct 7 still read `sampled` with nothing set aside, and the check could not say what blocked it. Each pageviews-side event that stays sampled now names the first reason the set-aside rule failed: no complete over-cap list, sampled on one side only, nothing to set aside, the counted visitor-days that differ (with both readings), or the totals left after setting aside (both sides). Read-only; it changes no state.

