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
- **Every deposit names the ledger's own DOI.** Zenodo's GitHub integration is on for the ledger repository and a monthly snapshot release mints a concept DOI for the whole ledger (provenance repo #26). The Zenodo leaf's environment form takes that DOI (`sn_zenodo_ledger_doi`, normalized from a pasted `doi.org` URL, refused when it is not a DOI), and `sn_zenodo_metadata_for` adds it to `related_identifiers` as `isPartOf` a dataset, so a note's record points at the ledger it belongs to. Empty until the first snapshot mints. Pinned.

## [16.1.0] - 2026-09-18 — a note becomes a citable record

### Added
- **Zenodo DOIs for the signed documents.** Every note and pillar essay gets a DOI minted on Zenodo once its anchor is Bitcoin-confirmed, with the Markdown the site itself serves, the signed ledger record and the `.ots` proof filed beside it (`docs/zenodo-doi-design.md`). Decisions: CC BY-ND 4.0, `publication_type: other`, the record title is the H1, one record per document and one version per provenance version; the papers stay with SSRN. Two environments, two keyring tokens (`zenodo_token`, `zenodo_sandbox_token`, scopes `deposit:write` + `deposit:actions`, probed by listing depositions), an environment switch defaulting to sandbox; sandbox DOIs (`10.5072`) never reach a public surface.
- **The flow**: `inc/zenodo-client.php` (bearer requests, 15 s, no redirects, `{ok, code, body, error}`, nothing throws), `inc/zenodo-records.php` (the pure metadata builder, readiness, the bundle, the deposit that resumes an interrupted draft rather than minting a duplicate, the ledger), the trigger `sn_prov_confirmed` (new action fired when the sweep's callback confirms a commit) booking a single event, and an hourly backfill pass of up to five documents.
- **The flow-back**: the Article schema gains a `DOI` `PropertyValue` and `sameAs` the `doi.org` URL; `/notes/index.json` carries `doi` on notes and pillars.
- **Connections › Zenodo** (classic and kit): the environment, one deposit action, the ledger as the house table, the status rail. **Health check 29 `zenodo_doi`**: confirmed documents without a production DOI, skipped (never a pass) without a token or in sandbox. **`signal-noise/zenodo-status`** on the read door. Cron registries carry both hooks (the pass token-gated; the single event on-demand).
- Tests: `tests/zenodo.php` (45: metadata, environments, the client's request shaping, readiness, the six-step flow in order, the resume path, the bundle gate, flow-back, ledger, status, the health check's tiers, triggers), `tests/os-leaf-connections-zenodo.php` (15).

