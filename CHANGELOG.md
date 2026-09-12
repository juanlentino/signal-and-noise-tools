# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.1.1] - 2026-09-12 — the MCP doors answer 405 to a stream probe

### Fixed
- **The MCP doors answer 405 to GET/DELETE, not core's 404.** Streamable HTTP clients open a GET on the endpoint to ask for a server-push stream; the spec's answer from a server that offers none is 405 Method Not Allowed, which a client reads as "no stream, carry on". With only POST registered, core answered `404 rest_no_route`, and `mcp-remote` (the bridge Claude Desktop runs for `sn`/`sn-write`) treats anything but 405 as a transport error — two logged `Failed to open SSE stream: Not Found` and a backoff retry on **every** bridge start, 240 of them in the log since Sept 5. On 2026-09-12 that retry pushed the bridge's `initialize` to 10–11 s, past Claude Desktop's 10 s connect budget (`ensureAllConfiguredConnected timed out after 10000ms — announcing partial/empty set`), and the desktop published its tool set without the `sn:` namespace — after every restart. Root cause diagnosed from the bridge, host and desktop logs plus the installed `mcp-remote` source (`if (status === 405) return;`); WordPress itself answered the same calls in 0.7 s throughout. `/mcp` and `/mcp-rw` now register a GET/DELETE handler that returns 405 with `Allow: POST`, **gated by the same permission callback as the door's POST twin** (mcp-remote sends `Authorization` on the probe too), so the plugin's public REST surface stays at exactly three routes. `tests/rest-routes.php` count pin 19 → 21 with the reason on the line; `tests/mcp-endpoint.php` 48 → 61, the new pins mutation-checked red on both the status and the `Allow` header.

### Changed
- **README: the OpenStation section names the settings-tab seam and the upstream record.** The per-user native/classic preference is now described where it lives (a `Signal & Noise` tab in OpenStation Preferences, `openstation_register_settings_tab()` + `wp.os.registerSettingsTab()`, classic URLs remapped with `registerNativeUrlRemap()`), and a closing paragraph lists the six merged upstream PRs (#366, #530, #706, #791, #792, #793) plus #809 in review — every one a seam this integration crossed first. Docs only.
- **The OpenStation Preferences tab names its sidebar glyph.** The `Signal & Noise` tab rendered with an empty icon column because OpenStation's settings-tab registry had no icon field — no plugin could supply one ([WordPress/openstation#808](https://github.com/WordPress/openstation/issues/808)). The registration now passes `icon: 'bell'` (an OS icon-set name); OpenStation ≤ 1.1.8 ignores the key, 1.1.9+ draws it. Confirmed against 1.1.8: none of the 52 OpenStation symbols the plugin consumes changed between 1.1.7 and 1.1.8 — the five that appear in that diff are tests and docblocks only. Suite 99 → 100; the new guard mutation-checked red.

