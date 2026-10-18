# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.42] - 2026-10-18 — impostor crawler reads are counted but not credited


- **Impostor reads are counted but not credited.** A read that names a crawler Cloudflare verifies when genuine (the rights-signals taxonomy's new `cf_verified`, taxonomy 1.5.0: the agents Cloudflare's verified-bots directory lists) and that Cloudflare did not verify, on a day verification was recorded, now stays in `total` and is credited to no vendor, family or purpose, exactly like a control token. It is listed apart as `impostors` (`{agent, hits, networks, basis: inferred}`; a rule decided it, so never `claimed`), crosstab cells gain `impostor_hits`, and lanes file it as `claimed_bot` / `impostor`. A verified read is never one, whatever its network (Claude-User from Google Cloud stays credited), nor is a read before the since date, this site's own tools, an agent the directory does not list (PerplexityBot, CCBot, Bytespider, cohere-ai stay credited, unverified), or a row from a sensor that sends no `cf_verified`. Reader anomalies keep impostor reads in their series, so the co-movement that found them still fires, and the "is this crawler real" table still shows them. Machine Readers' Reader evidence (both twins), the public stats page and the desktop widget each add one line: "Claimed a known crawler but failed Cloudflare's check". Over the 14 days to 2026-10-18 that is about 7,600 reads, among them 1,378 of ClaudeBot's 1,436 and 768 of Googlebot's 1,202. Remote contract 19: the machine-readers summary twin gains `impostors` (additive); the remote MCP worker's `CONTRACT_VERSION` bump is a separate PR.


