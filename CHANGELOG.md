# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.0.0] - 2026-09-19 — the evidence outlives the sensor

### Added
- **Rights evidence: one anchored record per crawler family per month.** The ledger held the reservation (the rights-signal files, versioned and Bitcoin-anchored) and the notes; it did not hold the evidence a dispute would need, which is the reservation, who fetched it and when, and what was crawled anyway, and the sensor forgets all three after ninety days. On the first daily pass after a month closes, for every AI-training family the sensor saw that month, the plugin composes one canonical JSON (`kind: rights-evidence`): the reservation in force from the public ledger's own index (every rights-signal's version, hash, anchor), that family's fetches of `/.well-known/tdmrep.json`, `/license.xml` and `/tdm-policy` (per path, first and last), and its crawling per day with the training share and per surface, each block saying whether the sensor read covered the whole month. No user-agent string. The record's id is the UUIDv5 of its own URL (site, family, month), so the path is deterministic; the bytes are stored before the POST and re-sent verbatim on a failure, so a lost response can never mint a second record; a five-minute lock keeps cron and the on-demand pass from composing the same month twice, and a 409 (the ledger already holds other bytes at that path) is a terminal `conflict` whose record stands, never a retry. Signed and OpenTimestamps-anchored by the provenance worker (1.21.0) under `rights-evidence/<uuid>/v1`; the verify index leaves it out, a verifier reads the record and its `.ots`. `rights-evidence` (read door 46 to 47) is the stored ledger without the bytes; `rights-evidence-now` (rw door 15 to 16) runs the pass now; `sn_rights_evidence_daily` on cron, gated on the worker, its secret and the sensor. `inc/rights-evidence-compose.php` (pure), `inc/rights-evidence.php`, `inc/abilities-rights-evidence.php`, `tests/rights-evidence.php` (34; the canonical bytes were run through the worker's own canonicalizer and came back byte-identical).
- **Two ledger reads on the read door: `get-machine-readers-crosstab` and `get-rights-reads`.** The summary collapses the sensor's rows into per-family and per-purpose totals, so "which purpose did each family read for" and "who fetched the rights files, when, and how regularly" needed shell access to the origin. The crosstab folds the aggregate rows into family x purpose x agent cells (hits, distinct days seen, hits per surface), with `taxonomy_absent` when the edge sent no taxonomy and `truncated` when the read hit the edge's row cap. The rights reads carry every fetch of `/.well-known/tdmrep.json`, `/license.xml` and `/tdm-policy` from the full-fidelity stream (observed_at, family, vendor, purpose, path, hits; the user-agent string and Accept header never leave the plugin), a cadence fold per family and path (median interval, regularity as the coefficient of variation of the gaps, `poller` at three reads or more within 0.5), and `ai_rights` counted twice, from the aggregate and from the stream, because the two are written by different paths at the edge and a gap between them is a sensor finding. Both are folds over `snt_mr_fetch()`; nothing new is fetched, no remote twin's shape moves, and the durable snapshot gains `by_family_purpose` (family => purpose => hits) beside `by_family`. Read door 44 to 46. `inc/machine-readers-ledger.php`, `inc/abilities-machine-readers-ledger.php`, `tests/machine-readers-ledger.php` (40).

