# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [20.1.0] - 2026-10-01 — retract a rights-evidence record


- **Rights evidence: retract a posted record, one signed retraction per click.** Monitoring > Machine Readers (native window and classic screen) lists every confirmed record that has owner-approved retraction text, shows the exact text that will be published (claimed, what was wrong, root cause, what changed) and a Retract button behind a confirm, with the note "Post one, wait for the ledger's checks, then the next." The click posts ONE body `{note_uid, version: 1, retracted_path, what_was_wrong, claimed, root_cause, what_changed, retracted_at}` to the provenance worker's `POST /retract`, HMAC-signed over the exact bytes, no redirects, behind the outbound gate (the signed POST is now one helper shared with the record post). `retracted_path` is the stored ledger path byte for byte. The text is fixed in `inc/rights-evidence-retractions.php` (August 2026: openai, anthropic, google-ai, commoncrawl) and is never a form input; a record without text, without a ledger path or not `confirmed` has no button and the handler refuses it. A 200 marks the record `retracted` and stores `retraction_path` and `retraction_hash`; a 409 or a failed call leaves the record untouched and flashes the worker's own error. A 409 that says already retracted (a lost 200) reads the ledger's `retractions/<uid>/v1.json` (read-only) and marks the record retracted only when its `payload.retracted_path` is this record's path. The button and the handler both need the worker URL and secret set (the handler's refusal has its own flash). The daily refresh treats `retracted` as final and never re-reads it (the v1 file still says confirmed), and it no longer writes back the copy it read: it re-reads the option right before writing and updates only status and block of entries still non-final, so a retraction landing mid-refresh survives. The `rights-evidence` read ability reports the status and the retraction path. Nothing is posted by this release: each retraction is an owner click. `tests/rights-evidence.php` (119) and `tests/rights-evidence-admin.php` (48) pin it, 23 mutations red.

- Test: the nightly-window pin in `tests/analytics-rollup-replace.php` expects the window's own five-minute edge skew, so it no longer fails within five minutes of New York midnight (it redded #1812 and main at 23:55 and 00:01 ET on 2026-10-01).


