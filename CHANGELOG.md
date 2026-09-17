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
- **Zenodo DOIs for the signed documents.** Every note and pillar essay gets a DOI minted on Zenodo once its anchor is Bitcoin-confirmed, with the Markdown the site itself serves, the signed ledger record and the `.ots` proof filed beside it (`docs/zenodo-doi-design.md`). Decisions: CC BY-ND 4.0, `publication_type: other`, the record title is the H1, one record per document and one version per provenance version; the papers stay with SSRN. Two environments, two keyring tokens (`zenodo_token`, `zenodo_sandbox_token`, scopes `deposit:write` + `deposit:actions`, probed by listing depositions), an environment switch defaulting to sandbox; sandbox DOIs (`10.5072`) never reach a public surface.
- **The flow**: `inc/zenodo-client.php` (bearer requests, 15 s, no redirects, `{ok, code, body, error}`, nothing throws), `inc/zenodo-records.php` (the pure metadata builder, readiness, the bundle, the deposit that resumes an interrupted draft rather than minting a duplicate, the ledger), the trigger `sn_prov_confirmed` (new action fired when the sweep's callback confirms a commit) booking a single event, and an hourly backfill pass of up to five documents.
- **The flow-back**: the Article schema gains a `DOI` `PropertyValue` and `sameAs` the `doi.org` URL; `/notes/index.json` carries `doi` on notes and pillars.
- **Connections › Zenodo** (classic and kit): the environment, one deposit action, the ledger as the house table, the status rail. **Health check 29 `zenodo_doi`**: confirmed documents without a production DOI, skipped (never a pass) without a token or in sandbox. **`signal-noise/zenodo-status`** on the read door. Cron registries carry both hooks (the pass token-gated; the single event on-demand).
- Tests: `tests/zenodo.php` (45: metadata, environments, the client's request shaping, readiness, the six-step flow in order, the resume path, the bundle gate, flow-back, ledger, status, the health check's tiers, triggers), `tests/os-leaf-connections-zenodo.php` (15).

## [16.0.1] - 2026-09-17 — the verdict in its own colours

### Fixed
- **Connections › Scheduled paints its posts as the house table.** The leaf folded native future posts and scheduled fragments into one list of seven cramped cells with an "Actions: native" column that said nothing. The native posts (26 today, no op to carry: WordPress publishes them itself) now take `<os-table>`, the Cron leaf's shape, with Title, Type, Publishes, In and ID; the fragments keep the list, because each of their rows carries a live form (Run now, Re-purge) a data-driven table cannot hold, in their own fold with their own count. Pinned.
- **The native Home's Caches tile painted its verdict unreadable.** 15.9.0 made the filler run (the host now sees a root that earns its identity late), and what it wrote arrived in the light admin's colours: the value span borrowed the classic `.sn-glance-card__value` class so the filler could find it, and that class carries `color: var(--sn-text)`, near-black on the dark leaf; the classic `.sn-pill--ok` chip read as a pastel block. The value now carries a `data-snt-freshness-value` attribute (no style), the filler finds it by that attribute first, and on the native leaf the badge is the kit's `<os-badge tone>`, the element every other tile uses. The classic page is unchanged. Pinned.

