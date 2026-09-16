# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Added:** AI › Agent tools, every tool an agent can call on this site, by door, and whether it does (`inc/agent-tools.php`, `inc/agent-tools-admin.php`, `apps/sn-dashboard/parts/leaves/ai-agent-tools.php`; one model, two painters). On the page: the WebMCP bridge's five tools with calls and outcomes (ok, absent, error) over 30 days, from the beacon's rows the fetch splits off the reads (`snt_mr_split_webmcp()` now keeps outcomes per tool, read from the purpose slot), under the caption "reported by browsers"; a tool seen in the rows but not registered is painted, starred; beside them, what the tools read with a verdict each: `/notes/index.json` (built when, how many), the related manifest on the latest note, the bridge's anchoring from the rights-anchoring check's remembered state. Through MCP: the call log open under the section and the four doors paired inside one fold; the door inventories and the call log moved here from MCP Clients, which keeps how to connect. Through Copilot: the retired Copilot Usage leaf's whole content. The AI tab reads config, config, observation: Models & Budget, MCP Clients, Agent tools.

## [15.5.0] - 2026-09-16 — the reader's agent gets the site's own arithmetic


- **Added:** WebMCP bridge v2, arc one, the plugin's half (`docs/webmcp-bridge-v2-design.md`). Two public documents for the reader's agent: the related-notes manifest, a data-shaped `<script id="sn-related">` on every note beside the verification manifest, from the same kernel rows the block paints (never built, nothing related and matches are three distinct answers); and `/notes/index.json`, the machine twin of the site, built at each ML rebuild and served on a flush-free route with a five-minute public max-age: pillars with their notes, every published note (title, url, dates, tags, pillar, signed), every published page, the papers (`sn_site_map_papers`), the feeds and the rights pointers; drafts, password and noindex out. The bridge's `related-notes` and `get-site-map` tools read these once the rights-signals worker ships them; `get-citation` reads the page's JSON-LD, which already carries what it needs. The Machine Readers tile and the classic tab gain an "agent tool calls" figure: the bridge's beacon will write family `webmcp` to the sensor's dataset, and the fetch splits those rows off the reads (a call is not a page read) before any reader sums them; it reads zero until the worker ships, and zero is the honest reading. The figure is not in the summary ability's payload yet: that payload is a remote-MCP contract twin, and a field that reads zero is not worth a contract bump; it joins in arc two with a number.


