# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed (analytics, /stats and login, a defect hunt on 2026-10-25)
- **The S&N Analytics export works again.** Its handler moved into `inc/admin-post-actions/` on 2026-08-23 and kept requiring `__DIR__ . '/analytics-export.php'`, a file that is one directory up, so every CSV or JSON export since then died on a missing file. The path is fixed, and `tests/no-dangling-requires.php` now resolves every plugin `require`/`include` against the file that holds it, so a dangling one fails the sweep.
- **The hidden login page cannot be reached by percent-encoding its name.** `/wp-login.php` answered 404 as meant, but `/wp-login%2ephp` and `/%77p-login.php` served the real login form (checked live): the checks compared the raw path while the web server decodes it before mapping it to a file. `sn_login_request_path()` now decodes once and merges the slashes a decoded `%2F` makes, for every check that shares it (the door, the allowlist, wp-admin and core's login scripts). An encoded `?` never moves the query.
- **/stats never lists the path of a note that is not public.** WordPress resolves only published posts from a URL, so a note unpublished, scheduled, made private or trashed resolved to nothing and its bare path, slug and all, could list under Most read. A path whose last segment names such a post or page is dropped; so is a password-protected post, which core counts as viewable.
- **Machine readers: the feed figure is for the view's window.** The hero tile read "feed fetches, Nd" over the 30-day total at every window (7, 14, 30 and 90 days). It now reads the window it names.
- **The remote door switch says what it did.** It answered enabled, disabled or locked by `SN_MCP_REMOTE_DISABLED`, and no notice showed any of them.
- **SN Traffic's feed row says that 304s count since Oct 24** while its 30-day window still reaches back before that day, as the RSS screens already do; the note retires itself after 30 days.

### Changed
- **A cron job that failed and has run fine since no longer turns the Systems card amber.** The card read every recorded failure in the last 24 hours as something to look at, so the one `prov_orphan_sweep` blip on 2026-10-25 kept it amber through hundreds of clean five-minute runs. A job whose last failure was followed by a successful run that started at least 5 seconds later now reads in the text color as "failed, ran fine since"; a job still failing stays amber and leads the headline. The 5-second margin exists because wp-cron.php records a reschedule error and then runs the job in the same request, so that run's own success proves nothing. Recovery is judged per job and its arguments, so for a per-item job (a Zenodo push, a scheduled fire) one item running fine never vouches for another that failed. The day's count of failed runs is unchanged.

## [2026.10.66] - 2026-10-25 — overlapping cron runners are not a failed job

### Fixed
- **Overlapping cron runners no longer read as a failed job.** Since 2026.10.64 the plugin records core's cron errors, and on 2026-10-25 the Systems card showed `sn_prov_orphan_sweep` failed: three cron runners overlapped while 2026.10.65 was installing, the second rescheduled an event the first had already saved, `update_option()` saw no change and core reported `could_not_set`. The runs before and after succeeded and the event stayed on its five-minute schedule. A `could_not_set` is now recorded only when the cron list is not as the step meant to leave it: after a reschedule, no future copy of the event; after an unschedule, the due copy still there. The check reads the stored `cron` row, not the process cache, so a runner that cached the options before the other one saved still reads the saved list. Any other error code is recorded as before.

