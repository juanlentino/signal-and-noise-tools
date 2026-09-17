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
- **Cron health said "1 expected but not scheduled" on every read since 14.7.0, and the Cron widget never showed it.** `sn_gsc_inspect_one` is an on-demand single event (day 3 and day 10 after a publish) and was never in `snt_cron_hook_is_on_demand()`, so it read as an expected recurring job with no schedule. Listed now; a derived test scans the owned-hooks registry for every hook its comment calls a single event and requires each to be on-demand, so the next one cannot repeat this.
- **The Cron widget judges, not only counts.** Its localized summary now carries the cron-health verdict and the soonest SN job; the card paints "Next: `<job>` · in 4 min" (a past due time reads "due", never a negative) and the health summary in amber when the verdict is not ok, with the dot tracking the verdict as well as orphans. The counts alone could not say a recurring job was missing, which is how the false alarm above stayed invisible behind a green dot.
- **Every "Open … →" lands on the leaf where its reading lives, not on the Dashboard tab.** Health → Monitoring › Health; Anchors → Tools › Provenance ("Open Provenance →"); Cache gains "Open Cloudflare →" (Connections › Cloudflare); Queue gains "Open Scheduled →" (Connections › Scheduled, which folds native future posts with the fragment queue). Uptime and Deploy keep the Dashboard, which is their leaf; the uptime card's link now says so instead of "Open Uptime".
- **The Cache card says what its verdict covers**: "Verdict covers the post's own URL" (the probe fetches the permalink; archive pages, the sitemap and the feed are purged, not probed). A fact, not a tally: the v13.87.3 ruling against standing counts on that card stands, pinned.
- **Site Views' top mover printed "▼ -16"**; the arrow carries the sign now.
- **SN Queue's section headings were uppercase and letter-spaced**, the one card that was; sentence case at 11px/.55 like every other card. The suite's uppercase ban had a hand-kept list of eight of eleven widget files, so the queue passed it; the list is now derived from the directory with a floor.

## [15.8.1] - 2026-09-17 — the queue reads what it is handed

### Fixed
- **SN Queue painted "Queue read failed: empty response" over a perfect payload.** The abilities run-path returns an ability's output as is; only abilities that wrap themselves (`get-rss-stats`) come back as `{ok, data}`, and the 15.8.0 widget read `res.data`. It now reads the bare payload, wrapped or not, and recognises it by its own keys. Found live in the owner's shell: the ability answered 26 scheduled, runs to Dec 27, every label right, and the card said empty.
- **SN Queue default height re-pinned to the measured card: 380, not the budgeted 300.** Measured 365 live at the docked width with the real queue (a two-line headline, the depth line, two headings, six rows), rounded up with the same slack as the other ten.
- **SN Anchors' Sweep now reports through the shell's toast too**, and the card only refreshes; the in-card note line (which grew the card by a row until the next refresh) stays as the fallback. The message now says the count is the worker's whole queue, notes and rights-signal documents, which is why the widget can say "1 pending" and the sweep "7 still pending" in the same breath.
- **Quick Actions results go to the shell's toast, not into the card.** The in-card strip was appended inside the widget, so every click grew the card by a row for 3.5 seconds and shrank it back. `wp.os.showToast` (Stable in OpenStation's JavaScript reference) paints at the top of the shell and never touches the card; the strip remains only as the fallback for a shell without it, and the shell call is tried first (pinned). Verified live: the toast renders in `<os-toast>`, the card's height does not move.

