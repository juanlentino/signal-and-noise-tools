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
- **A Provenance column in OpenStation's native Posts window.** `assets/os-posts-provenance.js` registers on the shell's `openstation.postsWindow.columns` filter (1.1.8, #779) and paints the Explorer's anchor-status badge — dot + `v{n}`, title with the status label — in the table, the writing-desk cards and the inspector, one node per render as the workspace requires. It reads the `sn_provenance` REST field the plugin already exposes on posts, so the value rides the `/wp/v2/posts` request the window already makes: no extra fetch, no PHP. An unsigned Note paints an empty node, never a gray badge (absent is not zero). Its own handle on every shell request with `wp-hooks` as the sole dependency — not the lazily-loaded Explorer bundle, which would leave the column absent until the Explorer had been opened. Idempotent against the filter running on every paint. The first Signal & Noise view moved onto the shell's surface; `statusSegments` was ruled out for "Needs attention" (its value is sent verbatim as `?status=`, and our queue is not a post status). Guarded in `tests/openstation-preferences.php` (100 → 108; the idempotence pin mutation-checked red).

## [14.2.0] - 2026-09-12 — both MCP doors speak the 2026-07-28 revision

### Added
- **Both MCP doors speak the `2026-07-28` revision, dual-era.** A request carrying the modern per-request `_meta` (or naming a modern version in `MCP-Protocol-Version`) is served statelessly by the new `inc/mcp/mcp-modern.php`: `server/discover` (every supported version, the three capabilities, the door's `serverInfo`, cache hints); the seven validation checks in spec order — `_meta.protocolVersion` (-32602/400), header equal to body (-32020/400), version supported (-32022/400 with `supported` + `requested`), `Mcp-Method` (-32020), `Mcp-Name` on `tools/call` / `resources/read` / `prompts/get` with the `=?base64?…?=` sentinel decoded (-32020), `clientCapabilities` (-32602), method known (-32601 paired with HTTP 404 so a client can tell "modern server, unknown method" from "no endpoint"); every result decorated with `resultType: "complete"` and `io.modelcontextprotocol/serverInfo`, list/read/discover results with `ttlMs: 3600000` / `cacheScope: "private"` (the doors are authenticated and per-site; "public" would let a shared cache serve one credential's answer to another). The legacy handshake path is untouched — the same `sn_mcp_list_tools` / `sn_mcp_call_tool` / resource / prompt handlers serve both eras and the legacy envelope is byte-identical; `sn_mcp_dispatch_body()` gains an optional `$headers` argument read from the three mirrored headers by `sn_mcp_request_headers()`. The legacy list also gains **`2025-11-25`**, the revision Claude's connector client actually opens with (it never had it; `initialize` used to answer `2025-06-18` to it). Check for check the same layer as the remote Worker's `src/modern.mjs` (sn-remote-mcp-worker 1.6.0); `tests/mcp-modern.php` (37 assertions) mirrors the Worker's suite, and three mutations — the header-equality check, the 404 pairing, the 2025-11-25 entry — each go red by name. Sweep 671 → 672 suites, 29,997 assertions.

