# Handoff, 2026-10-26: the MCP doors audit

Picks up after plugin **2026.10.70** and Worker **2.9.3**. Two branches, two
pull requests, not cut: plugin `fix/mcp-doors-audit` and Worker
`feat/contract-24-output-schemas`. The full record is
[docs/audits/2026-10-26-mcp-doors.md](audits/2026-10-26-mcp-doors.md).

**Provenance of this document:** a brief written in claude.ai from a read-only
audit was re-verified here against both repos and the live site; where it was
wrong, the audit record says so.

## What shipped

- **F2, contract 24, both repos.** Every remote tool declares the origin's
  outputSchema. Plugin: `sn_remote_contract_wire_schemas()`, the hash now over
  the projected schemas, and `tests/fixtures/remote-output-schemas.json`
  (`php tests/remote-contract-shapes.php --write`). Worker: the same file as
  `src/remote-output-schemas.json`, `structuredFor()`, and
  `test/output-schemas.test.mjs`, which re-derives the plugin's hash.
- **F4.** Compact text blocks on the laptop doors.
- **F5.** The bridge secret is `SN_BRIDGE_TOKEN` or nothing.
- **F6.** The setup page pins `@automattic/mcp-wordpress-remote@0.5.4`.
- **F7.** Hard numbers point at the tests that pin them.

## Order to land

1. Merge and cut the plugin; the owner installs it. Live, the remote door keeps
   working: the Worker on 23 still answers, and the deploy probe shows
   `contract_match` false (observed, never refused).
2. Then merge the Worker; Workers Builds deploys main. The probe reads 24/24.
3. If the plugin ever regenerates the schema file, copy it to the Worker in the
   same change set; the Worker's test fails on any difference from the pinned
   hash.

## Waiting on the owner

- F1: code mode stays off. Recommended if it goes on:
  `BRIDGE_RATE_LIMIT_PER_MIN=30`, `RATE_LIMIT_PER_MIN=60`.
- The portal's "Require user auth" setting, and who its Access policy admits.
- The audit's further findings, recorded privately while open (this
  repository is public).
- F3: the description trim (proposed; numbers in the audit record).
- N6: branch protection on the Worker repo; whether the Workers Builds
  command runs the tests.
- `mcp-remote`'s change of maintainer (geelen to punkpeye) before any reinstall.
- Archive or delete the empty `signal-and-noise-updater` repository.

## Do not undo

- The `{result: …}` wrap on the five list-or-null twins, and the
  `output_schema_mismatch` refusal: a declared schema makes `structuredContent`
  required.
- The constant-only bridge secret: a database read must not open `/bridge`.
