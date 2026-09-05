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
- A watch on the origin 503 count, due 2026-09-12. The baseline is **10 per 24h**,
  measured BEFORE v13.97.2 stopped a visitor's pageview paying for a cron run
  averaging 10.6s. If the count has not fallen, the remaining cause is the
  FPM pool's size rather than the work removed from it — and a server resize
  would then be buying a bigger pool, not more RAM. Resizing before that
  reading spends against an untested guess.

  Registered `date_only`, which this file reserves for "a re-read of a number
  that will not announce itself" — the 503s are counted by the HOST, so there
  is no state here for the site to notice on its own. Read it from Cloudways
  app traffic → `top_statuses`, and compare against 10. (#1002)

## [13.97.2] - 2026-09-05 — the pageview that paid for cron

### Changed
- WP-Cron no longer runs in the request path. Cloudways PHP analytics measured
  `/wp-cron.php` at **62 runs, 10.6s average, 51.7s peak** in 24 hours on a
  2 GB / 2 vCPU box sitting at ~90% memory — and with `DISABLE_WP_CRON` unset,
  that cost lands on whichever visitor's pageview happens to spawn it. Varnish
  answers the requests it cannot place with 503; ten were recorded in the same
  window (#1002). The constant is now defined at plugin load, which is the only
  window that matters since `spawn_cron()` reads it during `init`.
  Safe only because an external driver was verified present first: Cloudways'
  Cron Optimizer already hits `wp-cron.php` every five minutes. Worth knowing —
  that optimizer installs the system cron but leaves `DISABLE_WP_CRON` unset,
  so the site had BOTH the external tick and in-request spawning. Only the
  second one hurt. Turn it off with `snt_offload_wp_cron`, or by defining the
  constant yourself; an existing definition is never overridden, in either
  direction, and it does not fire under WP-CLI. (#1032)

### Internal
- The failure mode here is silent and total — no external cron means nothing
  runs and nothing complains — so the change leans on `snt_cron_health_model()`,
  which already marks a hook whose time has passed as `overdue` and elevates
  cron health to critical. Tests pin that detector still exists rather than
  assuming it, and that the bootstrap requires the offload before any `init`
  hook is registered. (#1032)

