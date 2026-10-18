# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Impostor reads beyond Cloudflare's directory, each with its reason.** Unverified reads naming PerplexityBot, Perplexity-User, CCBot, Bytespider or cohere-ai stayed credited because Cloudflare's directory does not list them. Three more signals now decide, in one ladder (`snt_mr_credit_verdict()`): `vendor_verified` (rights-signals taxonomy 1.6.0, blob16: the client IP checked against Perplexity's and Common Crawl's own published ranges) credits a read in range as verified, with its own basis `vendor_verified`; `vendor_ranges` (out of range AND off the vendor's network; an out-of-range IP alone is not enough), `off_home_network` (the network is not the vendor's: Amazon for Perplexity and CCBot, ByteDance, TikTok or BytePlus for Bytespider) and `rotator_network` (a network presenting `SN_MR_ROTATOR_MIN_AGENTS`, five, distinct vendor agents in the window, none verified) join `cf_directory` as impostor reasons. A row before the since date is never reclassified, and fields an older sensor does not send read as not measured. The rotator set is window-dependent, so a credit context is built once per fold and every per-row call requires it. `impostors[]` gains `reasons` (hits per reason), the verification split gains `vendor_verified` (part of `verified`), and a vendor-verified read counts as verified everywhere verified is shown (who reads, the claim against proof, the daily columns, lanes as `verified_bot` / `vendor_verified`, "is this crawler real", the desktop split). The impostor line on Reader evidence (both twins), /stats and the desktop widget now reads "Claimed a known crawler but failed a check: N reads", then the count per reason. Over the 14 days to 2026-10-18 about 2,245 more reads move from credited to impostor: Perplexity-User 468, Bytespider 463, CCBot 439 and PerplexityBot 432 (off_home_network, from "Google LLC"; Bytespider's 2 from Amazon Singapore too), cohere-ai 443 (rotator_network). `vendor_ranges` takes over from `off_home_network` for Perplexity and CCBot as blob16 fills. Remote contract 20 (additive); the remote MCP worker's `CONTRACT_VERSION` bump is a separate PR, merged after this cut installs.

## [2026.10.42] - 2026-10-18 — impostor crawler reads are counted but not credited


- **Impostor reads are counted but not credited.** A read that names a crawler Cloudflare verifies when genuine (the rights-signals taxonomy's new `cf_verified`, taxonomy 1.5.0: the agents Cloudflare's verified-bots directory lists) and that Cloudflare did not verify, on a day verification was recorded, now stays in `total` and is credited to no vendor, family or purpose, exactly like a control token. It is listed apart as `impostors` (`{agent, hits, networks, basis: inferred}`; a rule decided it, so never `claimed`), crosstab cells gain `impostor_hits`, and lanes file it as `claimed_bot` / `impostor`. A verified read is never one, whatever its network (Claude-User from Google Cloud stays credited), nor is a read before the since date, this site's own tools, an agent the directory does not list (PerplexityBot, CCBot, Bytespider, cohere-ai stay credited, unverified), or a row from a sensor that sends no `cf_verified`. Reader anomalies keep impostor reads in their series, so the co-movement that found them still fires, and the "is this crawler real" table still shows them. Machine Readers' Reader evidence (both twins), the public stats page and the desktop widget each add one line: "Claimed a known crawler but failed Cloudflare's check". Over the 14 days to 2026-10-18 that is about 7,600 reads, among them 1,378 of ClaudeBot's 1,436 and 768 of Googlebot's 1,202. Remote contract 19: the machine-readers summary twin gains `impostors` (additive); the remote MCP worker's `CONTRACT_VERSION` bump is a separate PR.


