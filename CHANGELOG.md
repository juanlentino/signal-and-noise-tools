# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- Test: the nightly-window pin in `tests/analytics-rollup-replace.php` expects the window's own five-minute edge skew, so it no longer fails within five minutes of New York midnight (it redded #1812 and main at 23:55 and 00:01 ET on 2026-10-01).
## [20.0.0] - 2026-09-30 — the reservation in force


- **Rights evidence, schema 2: the reservation in force, reads split by purpose.** The August OpenAI record cited tdm-policy v8, anchored in September, as in force for August. The reservation block is now `{window, signals:{<slug>:[{block, content_hash, valid_from, valid_to, version}]}}`: every rights-signal version in force at any point of the month, walked from the ledger's `rights-signals/<slug>/vN.json`, `valid_from` being the time of the version's Bitcoin block (from blockstream.info, the explorer the provenance worker already reads; cached for good once confirmed). `rights_reads` is now purpose `train` only; a new `retrieval_reads` block carries search and user reads (owner ruling D2), and `unlabelled_reads` carries rows with no recorded purpose, claimed by neither, since an unlabelled row could be a training crawler the taxonomy missed; `crawling.by_surface` is keyed by purpose, the unlabelled under `unlabelled`. The rights stream is read once per family with `exclude_purpose=dev,ops` through the Worker 1.29.0 filter (allowlisted, its own cache key, flushed with the rest); `snt_mr_fetch()` refuses any other family or purpose with `bad_filter` and never widens to an unfiltered read. An empty sensor taxonomy (now read from the aggregate envelope) refuses the record. `schema: 2` rides the payload. A filtered read must come back with the worker's matching `filter` echo or it is refused (`filter_not_applied`), a ledger version without a sha256 `content_hash` refuses the reservation, and the version walk stops at 200.
- **Rights evidence: the pass refreshes, and the backlog keeps what it holds.** Every posted record not yet confirmed is re-read from its ledger file each pass (read-only, at most 12 per pass, before the hold, so held passes refresh too) and takes the ledger's status and block. A held month is queued before the readiness check, so an outage on the day it closes cannot drop it; stored unposted bytes are re-sent whatever their age (the 90-day window guards composition only) and whatever family the sensor lists today; a backlog month leaves only when nothing of it is unposted.
- **Rights evidence: a dry run, and the hold lifted from the admin.** `sn_rights_evidence_dry_run( 'YYYY-MM' )` and `wp sn rights-evidence dry-run <YYYY-MM> [--erratum]` print the canonical payloads a month would carry today (and, with `--erratum`, the reservation in force for each posted v1 record, for the ledger's erratum: a correction there is a retraction, never a v2), from live reads, posting nothing (pinned by behaviour and by source). Monitoring > Machine Readers gains a Rights evidence section on both the native window and the classic screen: held months, backlog and next pass; per held month a View payloads door (a JSON download) and, when the worker is set up and the sensor answers, a Lift hold button behind a confirm that takes the month off the hold and runs nothing. `tests/rights-evidence.php` (80) and `tests/rights-evidence-admin.php` (19) pin it.


