# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.71] - 2026-10-26 — the MCP doors audit: contract 24, compact results, constant-only bridge secret


MCP doors audit, 2026-10-26 (docs/audits/2026-10-26-mcp-doors.md).

### Changed
- **Remote contract 24: every remote tool declares its output shape (F2, origin half).** The twins' output schemas are projected the laptop doors' way (`sn_mcp_project_output_schema()`): the five whose root is not exactly an object (`remote-get-analytics-events`, a list; insights, narration, health scan and provenance integrity, which can be null) are wrapped as `{result: …}`. The raw schemas did not change, so from 24 the contract hash covers the projected schemas (`sn_remote_contract_wire_schemas()`), which is what a client validates. `php tests/remote-contract-shapes.php --write` writes `tests/fixtures/remote-output-schemas.json`, the file the Worker carries; the suite pins it byte for byte and now also counts provenance integrity among the nullable twins. The Worker's half (CONTRACT_VERSION 24) ships with it.
- **Laptop-door results are compact JSON (F4).** `sn_mcp_success_result()` dropped `JSON_PRETTY_PRINT` (slashes stay unescaped): 28 to 34% fewer bytes per text block on real payloads (`sn-posts` 80,475 → 57,670 B). `structuredContent` is unchanged. Pinned in `tests/mcp-tools.php`.

### Security
- **The bridge secret is the `SN_BRIDGE_TOKEN` constant, nothing else (F5).** Since 15.2.0 the keyring could fill an empty constant from the `sn_bridge_token` option or the site secret, against the bridge's own rule that a database read must not open it. The row is now wp-config only (no option, no site derivation, locked on the Credentials screen) and the `sn_bridge_secret` filter is gone. Live, the value already came from the constant, so nothing changes on the site. The revoke runbook's step 4 now revokes in every case. Pinned in `tests/keyring.php`, `tests/os-leaf-connections-keyring.php`, and a source pin on `sn_bridge_secret()` in `tests/mcp-bridge-route.php`.
- **The setup page pins the MCP proxy (F6).** Both setup surfaces said `npx -y @automattic/mcp-wordpress-remote@latest`, which runs whatever is newest with the admin Application Password in its environment. Now `@0.5.4`, and the page says why. Pinned in `tests/mcp-connect-render.php`, `tests/os-leaf-ai-mcp-connect.php`.

### Docs
- **Hard numbers that rotted now point at the tests that pin them (F7):** the README (51 tools, 17 twins, contract "14"), `docs/ai-abilities-catalog.md` (49/16/18/15; four retired tools still marked READ; four missing rows added), and the docblocks that said "read-door 25", "35 rw slugs", rate limit "per credential" (it is per user), remote ceiling "fail-OPEN" (closed since 13.50.0) and "three public routes". The threat model gains dated notes; `docs/FILTERS.md` drops the removed `sn_bridge_secret` filter.

