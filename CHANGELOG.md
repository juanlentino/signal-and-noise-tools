# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed (desktop widgets, a defect hunt on 2026-10-25)
- **SN Traffic: a failed analytics read is an error, never "No views".** The shared readers answer a failed query with an empty list and zeros, which the card painted as an empty 14 days and cached for 15 minutes under a fresh "Read at" time. Each read is now checked by its own answer (a failed totals read returns its honest fields as null) and by the database's own error, as SN Reading already does; a failure answers 500 (Cloudflare replaces an origin 503 page), nothing is cached, and the card keeps its last reading and says so.
- **SN Traffic: the "vs. prior 14 days" delta compares full days.** It set 13 full days plus a partial today against 14 full days, so flat traffic read 4 to 7 percent down. The delta now compares the 14 full days through yesterday with the 14 before them; the headline still counts through today, and the delta's hover text says which days it compares. A change in method: deltas before this release read slightly low.
- **SN Traffic: the sparkline places each day at its date.** Days were spaced by position, so a day with no row squeezed the two around it together and the window looked shorter than 14 days. Each day now sits at its date and a missing day breaks the line; a lone day is a dot.
- **SN Provenance says when a background re-read failed.** A failed quiet re-read of the anchor status returned silently, so "All anchored" stayed with nothing saying it was the last reading. It now reads "Anchor status not re-read; showing the HH:MM reading", as SN Systems does, and the note survives the repaint when the machine-readers answer lands after it.
- **SN Deploy Status ages the deploy line itself.** The site sent only "2 minutes ago", which a failed poll repainted as it was, under a footer saying the reading was 20 minutes old. `get-deploy-status` adds `last_deploy_at` (ISO 8601 UTC, additive, local door only so the remote contract does not move) and the card ages from it, as the worker line already did.
- **Date-only watches ripen at the site's midnight.** They ripened at UTC midnight, 7 or 8 PM the evening before in New York, so a watch due Nov 25 showed due on the evening of Nov 24.

### Fixed
- **The prompt-caching probe knows the current models' floor.** Its table of minimum cacheable prefixes stopped at Claude Sonnet 5, so the model the site calls now, `claude-sonnet-5-5`, read "floor unknown" and the probe's verdict rested on September's `claude-sonnet-5` calls. Added Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.1 and Mythos 5.1 at 512 tokens, per Anthropic's prompt-caching docs (Cache limitations). An unknown id still reads as unknown, never a guess.

### Changed
- **A cron job that failed and has run fine since no longer turns the Systems card amber.** The card read every recorded failure in the last 24 hours as something to look at, so the one `prov_orphan_sweep` blip on 2026-10-25 kept it amber through hundreds of clean five-minute runs. A job whose last failure was followed by a successful run that started at least 5 seconds later now reads in the text color as "failed, ran fine since"; a job still failing stays amber and leads the headline. The 5-second margin exists because wp-cron.php records a reschedule error and then runs the job in the same request, so that run's own success proves nothing. Recovery is judged per job and its arguments, so for a per-item job (a Zenodo push, a scheduled fire) one item running fine never vouches for another that failed. The day's count of failed runs is unchanged.

## [2026.10.66] - 2026-10-25 — overlapping cron runners are not a failed job

### Fixed
- **Overlapping cron runners no longer read as a failed job.** Since 2026.10.64 the plugin records core's cron errors, and on 2026-10-25 the Systems card showed `sn_prov_orphan_sweep` failed: three cron runners overlapped while 2026.10.65 was installing, the second rescheduled an event the first had already saved, `update_option()` saw no change and core reported `could_not_set`. The runs before and after succeeded and the event stayed on its five-minute schedule. A `could_not_set` is now recorded only when the cron list is not as the step meant to leave it: after a reschedule, no future copy of the event; after an unschedule, the due copy still there. The check reads the stored `cron` row, not the process cache, so a runner that cached the options before the other one saved still reads the saved list. Any other error code is recorded as before.

