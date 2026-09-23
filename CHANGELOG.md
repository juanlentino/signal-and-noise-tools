# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **Facts lists are the kit's `<os-facts>` (OpenStation 1.1.11 is now the floor).** `snt_kit_kv()`, the helper behind every label/value readout in the native window, painted its own `<dl class="snt-kv">` because the kit had nothing of that shape. Upstream #889 shipped `<os-facts>` / `<os-fact>` in OpenStation 1.1.11, so the helper paints those. Each row is `<os-fact label>` with its value in a `.snt-kv__v` span, which keeps the tone colour, the inline-code wrap and the provenance rules on the same hook, and the list is still a real `<dl>` for screen readers. Only the house rhythm (16px column gap, 12px labels) stays in `assets/os-app.css`. **1.1.11 is a floor, not an option**: on an older station every facts row would lose its label silently. `docs/openstation-compat.md` is re-verified at the v1.1.11 tag (42 names, clean). The 53 assertions across ten leaf suites that pinned the old markup now pin the new shape, and all 53 fail against the old helper.
- **The S&N Home refresh button is named by its `aria-label` alone.** `os-button` forwards a host `aria-label` to its inner button since OpenStation 1.1.11 (#857), so the hidden slotted copy of the name and the `.snt-sr-only` rule it needed are gone.

## [17.8.1] - 2026-09-23 — the 5xx rows read back

### Fixed
- **The Edge panels show which 5xx failed and who answered (#1002).** The daily edge rollup has recorded each 5xx's path and its responder (`edge=503 origin=503` means the origin failed, `origin=-` means Cloudflare answered by itself) since 13.96.3, but no surface read them, so a steady ten 503s a day stayed unexplained. `sn_edge_errors_reading()` / `sn_edge_errors_range()` read them back. The native Edge section (Measurement › Analytics) lists who answered and which paths failed under the zone's 5xx figures, the classic Edge panel adds two cards over its own range, and `cloudflare-status` gains `errors_5xx` (seven days), so the reading is available through the door without opening the admin. Responders are shown in plain words, and a quiet week paints nothing extra.

### Changed
- **The MCP adapter watch waits for the adapter's first plugin release.** `mcp_adapter_read_door` used to ripen on the adapter class being loaded at all, but 0.6.x is a Composer library and was never the thing to port the read door onto. WordPress/mcp-adapter ships as an installable plugin from 0.7.0, so the watch now ripens on `McpAdapter::VERSION` 0.7.0 or later (`SNT_MCP_ADAPTER_MIN`). It reads the class constant rather than a plugin header, so if the adapter later moves into core it still counts. A loaded 0.6.x stays quiet and its note names the version it is waiting for, and pre-releases don't count. The plan when it ripens is unchanged, except that the read door retires only after the adapter's door is verified serving the same calls.

