# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Added:** WebMCP bridge v2, arc one, the plugin's half (`docs/webmcp-bridge-v2-design.md`). Two public documents for the reader's agent: the related-notes manifest, a data-shaped `<script id="sn-related">` on every note beside the verification manifest, from the same kernel rows the block paints (never built, nothing related and matches are three distinct answers); and `/notes/index.json`, the machine twin of the site, built at each ML rebuild and served on a flush-free route with a five-minute public max-age: pillars with their notes, every published note (title, url, dates, tags, pillar, signed), every published page, the papers (`sn_site_map_papers`), the feeds and the rights pointers; drafts, password and noindex out. The bridge's `related-notes` and `get-site-map` tools read these once the rights-signals worker ships them; `get-citation` reads the page's JSON-LD, which already carries what it needs. The Machine Readers tile and the classic tab gain an "agent tool calls" figure: the bridge's beacon will write family `webmcp` to the sensor's dataset, and the fetch splits those rows off the reads (a call is not a page read) before any reader sums them; it reads zero until the worker ships, and zero is the honest reading. The figure is not in the summary ability's payload yet: that payload is a remote-MCP contract twin, and a field that reads zero is not worth a contract bump; it joins in arc two with a number.

## [15.4.1] - 2026-09-16 — the edge posture, measured


- **Fixed:** the edge posture's "Also set" line read `TLS 1.3 zrt`, Cloudflare's value for TLS 1.3 on with 0-RTT, as an API id; it reads `on (0-RTT)`. The three posture scopes on Connections › Credentials are `measured`: all three answered on 2026-09-16 (the ruleset read is also satisfied by Account WAF Read at the account level, which the schema lists beside Zone WAF Read). What the first live read found, for the record: SSL mode `full` (the origin served Cloudways' wildcard certificate, so strict would have failed) and DNSSEC `disabled`; by the end of the day the origin presents a Cloudflare Origin CA certificate for juanlentino.com, the zone is on Full (strict), the DS record is at the registry and DNSSEC reads `active`. Five green dots.


