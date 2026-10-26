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
- **Leaf tables stack on a phone through OpenStation itself.** OpenStation 1.1.16 shipped `<os-table stack-on-phone>` (WordPress/openstation #981, the fix for our report #900), which also keeps the cards through a repaint and lifts pinned columns on a phone. From that version the kit emits the native attribute and the dashboard no longer loads the plugin's own `assets/os-kit-stack.js` shim; on an older OpenStation the shim path is unchanged. The shim goes when the plugin stops supporting OpenStation before 1.1.16.

### Fixed
- **An analytics export no longer opens an empty app window.** Both export forms, the native S&N Analytics toolbar's and the classic toolbar's as the OpenStation host keeps it, posted with `target="_blank"`. Inside the installed PWA a same-origin new-window target relaunches the app (the host recorded this for links in 14.7.5), so every export opened an empty window behind the save dialog. The forms now submit in the same window: the handler answers with an attachment, which downloads without leaving the desktop. If the form's security token has expired (a window left open over a day), WordPress's expiry page replaces the desktop for that one click; Back returns to it.

## [2026.10.67] - 2026-10-25 — a defect hunt: export, hidden login, /stats, honest widgets

### Fixed (analytics, /stats and login, a defect hunt on 2026-10-25)
- **The S&N Analytics export works again.** Its handler moved into `inc/admin-post-actions/` on 2026-08-23 and kept requiring `__DIR__ . '/analytics-export.php'`, a file that is one directory up, so every CSV or JSON export since then died on a missing file. The path is fixed, and `tests/no-dangling-requires.php` now resolves every plugin `require`/`include` against the file that holds it, so a dangling one fails the sweep.
- **The hidden login page cannot be reached by percent-encoding its name.** `/wp-login.php` answered 404 as meant, but `/wp-login%2ephp` and `/%77p-login.php` served the real login form (checked live): the checks compared the raw path while the web server decodes it before mapping it to a file. So did `/wp-json/%2e%2e/wp-login.php` (allowlisted for its `/wp-json/` while the server resolved the dot segment; found in review, checked live). `sn_login_request_path()` now decodes once, merges the slashes a decoded `%2F` makes and resolves `.` and `..` segments without climbing above the root, for every check that shares it (the door, the allowlist, wp-admin and core's login scripts). An encoded `?` never moves the query.
- **/stats never lists the path of a note that is not public.** WordPress resolves only published posts from a URL, so a note unpublished, scheduled, made private or trashed resolved to nothing and its bare path, slug and all, could list under Most read. A path whose last segment names such a post or page is dropped; so is a password-protected post, which core counts as viewable.
- **Machine readers: the feed figure is for the view's window.** The hero tile read "feed fetches, Nd" over the 30-day total at every window (7, 14, 30 and 90 days). It now reads the window it names.
- **The remote door switch says what it did.** It answered enabled, disabled or locked by `SN_MCP_REMOTE_DISABLED`, and no notice showed any of them.
- **SN Traffic's feed row says that 304s count since Oct 24** while its 30-day window still reaches back before that day, as the RSS screens already do; the note retires itself after 30 days.

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

