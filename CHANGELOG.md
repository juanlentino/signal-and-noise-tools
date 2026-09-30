# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Rights evidence, schema 2: the reservation in force, reads split by purpose.** The August OpenAI record cited tdm-policy v8, anchored in September, as in force for August. The reservation block is now `{window, signals:{<slug>:[{block, content_hash, valid_from, valid_to, version}]}}`: every rights-signal version in force at any point of the month, walked from the ledger's `rights-signals/<slug>/vN.json`, `valid_from` being the time of the version's Bitcoin block (from blockstream.info, the explorer the provenance worker already reads; cached for good once confirmed). `rights_reads` is now purpose `train` only; a new `retrieval_reads` block carries search and user reads (owner ruling D2), and `unlabelled_reads` carries rows with no recorded purpose, claimed by neither, since an unlabelled row could be a training crawler the taxonomy missed; `crawling.by_surface` is keyed by purpose, the unlabelled under `unlabelled`. The rights stream is read once per family with `exclude_purpose=dev,ops` through the Worker 1.29.0 filter (allowlisted, its own cache key, flushed with the rest); `snt_mr_fetch()` refuses any other family or purpose with `bad_filter` and never widens to an unfiltered read. An empty sensor taxonomy (now read from the aggregate envelope) refuses the record. `schema: 2` rides the payload.
- **Rights evidence: the pass refreshes, and the backlog keeps what it holds.** Every posted record not yet confirmed is re-read from its ledger file each pass (read-only, at most 12 per pass, before the hold, so held passes refresh too) and takes the ledger's status and block. A held month is queued before the readiness check, so an outage on the day it closes cannot drop it; stored unposted bytes are re-sent whatever their age (the 90-day window guards composition only) and whatever family the sensor lists today; a backlog month leaves only when nothing of it is unposted.
- **Rights evidence: a dry run, and the hold lifted from the admin.** `sn_rights_evidence_dry_run( 'YYYY-MM' )` and `wp sn rights-evidence dry-run <YYYY-MM> [--v2-draft]` print the canonical payloads a month would carry today (and, with the flag, schema-2 v2 candidates that supersede the month's v1 records), from live reads, posting nothing (pinned by behaviour and by source). Monitoring > Machine Readers gains a Rights evidence section on both the native window and the classic screen: held months, backlog and next pass; per held month a View payloads door (a JSON download) and, when the worker is set up and the sensor answers, a Lift hold button behind a confirm that takes the month off the hold and runs nothing. `tests/rights-evidence.php` (80) and `tests/rights-evidence-admin.php` (19) pin it.

## [19.9.0] - 2026-09-30 — résumé drafts, nothing goes live on save


- **Résumé drafts: saving the form no longer changes the live /resume page.** Content → Resume Page (both the native window and the classic screen) now saves a draft (`sn_resume_draft`, autoload off) and paints it back, with a status line saying whether the draft differs from live. New controls appear only when they can act: Preview page (the draft as an autosave of the /resume Page, opened in WordPress's own preview), Preview PDF (the draft's PDF under the public phone rule, streamed, never stored), Publish (today's save, then the PDF is rebuilt if one was ever generated, else /resume is purged; a draft equal to live publishes nothing), Discard, and Revert (swaps live with the version the last Publish replaced, each keeping its own date, so Revert twice returns). A stored draft that can no longer be read says so ("Draft could not be read; discard it.") and keeps its Discard button. Saving never publishes: the old save-and-publish route (`resume_save`, its handler and its four flash codes) is gone. This also fixes the existing "Download private copy (with phone)" control in the native window: it was a form, and a window replays a form's handler inside a dispatch, so the streamed PDF never reached the reader; it is now a door (and a new-tab link on the classic screen), like the previews. `tests/resume-draft.php` (41) pins the data layer, the Preview page autosave (slashed, as the Page sync hands `wp_update_post`) and redirect, and the handlers' flash codes.

- CI: the "Parity cron still firing" check reads each workflow's scheduled-run list three times and keeps the newest stamp. On 2026-09-30 GitHub twice served a stale list (newest run 8 and 25 days old while both crons had fired that morning) and redded #1806; one stale answer can no longer do that, and a cron that has really stopped still fails.


