# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.
- **Preview PDF previews the résumé PDF.** With résumé settings in the draft it streams the one résumé's layout (two pages, What I've Built, verified credits), the file Publish would build, instead of the music layout nothing publishes (`sn_resume_pdf_draft_parts()`, layout shared with Generate through `sn_resume_ops_pdf_layout()`). Test: `tests/resume-ops-pdf-build.php`.

## [Unreleased]

### Added
- **The Core Web Vitals read names which page elements account for the not-good CLS readings.** `signal-noise/get-analytics-vitals` (sn-metrics' `analytics_vitals`) gains `cls_sources`: for CLS readings over the good threshold (0.1) that carried a shift source, rows `{path, source, readings, needs_improvement, poor, worst}` grouped by path and source, most readings first, at most 50 (readings are sample-weighted, the two bands are counts, worst is a score). `cls_sources_unattributed` counts the not-good readings that carried no source, so an empty list is not read as "nothing shifted". The source is the selector analytics worker 2.4.0 stores in blob17 of a `vc` row (page markup only: tag, id, class names), read only where `blob1 = 'vc'`; the list stays empty until that worker and a theme release carrying the field are live. A failed sources read nulls both keys, says why in `cls_sources_failure` and `sn_analytics_vitals_failure()`, and leaves the rest of the payload standing (kept 5 minutes, not 15). Local door only; no screen changes (`inc/analytics-vitals-sources.php`, new). Test: `tests/analytics-vitals-sources.php` (new, 43; red before the change, and with the `vc` filter, the cap, the score scaling or the failed-leg check removed).

### Security
- **The cron orphan cleanup workflow refuses a `hooks` value that is not hook names.** ssh joins its arguments and the remote shell parses them again, so a value with a space lost every name after it and one with `;` or `$()` would have run on the server (only someone who can dispatch the workflow could send one). The runner now accepts letters, digits, underscores and commas only, the remote side checks each name again and passes it to PHP through the environment, and a hook whose handler check could not run is kept, not deleted (`.github/workflows/cron-orphan-cleanup.yml`).

## [2026.11.54] - 2026-11-22 — The ingest check names a failed canary send; a factory pilot for the repository

### Added
- **A factory pilot for this repository (maintainer tooling, nothing ships).** `tools/factory/` turns a GitHub issue labeled `factory` into a reviewed pull request and stops at ready to merge: it never merges and never cuts. `job` keeps each job's stage, refuses a move the stage table does not allow or whose evidence file is missing (an accepting intake, a spec, a READY build, a gate that passed on the worktree's current HEAD, a review that says PASS), refuses a public surface without the owner, caps a job at three rounds, and performs the GitHub side effects. `gate` runs the checks before review (clean tree, `php -l`, a CHANGELOG bullet, no em dash, a touched test, `tests/run.sh`). `SKILL.md` is the orchestrator a Claude Code session follows. Modeled on OpenStation's software factory (GPL-2.0), cut down for one maintainer. See `tools/factory/README.md`. Test: `tests/factory-job.php` (new; red with the public-surface, round-limit or review guard removed).

### Changed
- **The Analytics ingest health check says when the canary send itself failed.** When the check already raises a finding and the last stored canary send (`sn_ingest_canary_last`) was not a clean 204, the finding's note adds one sentence with the HTTP status, the reason, and how long ago the send was, so a rotated beacon key or a timeout no longer reads only as a canary that did not arrive. A 204, a missing option or an unreadable value adds nothing, and no finding is raised or removed because of it. Test: `tests/health-check-analytics-ingest.php` (+17; the 403 case and the wrapper case go red with the change removed).

