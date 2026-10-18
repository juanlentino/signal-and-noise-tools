# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.43] - 2026-10-18 — impostor reads by vendor ranges, home network and rotator


- **Impostor reads beyond Cloudflare's directory, each with its reason.** Unverified reads naming PerplexityBot, Perplexity-User, CCBot, Bytespider or cohere-ai stayed credited because Cloudflare's directory does not list them. Three more signals now decide, in one ladder (`snt_mr_credit_verdict()`): `vendor_verified` (rights-signals taxonomy 1.6.0, blob16: the client IP checked against Perplexity's and Common Crawl's own published ranges) credits a read in range as verified, with its own basis `vendor_verified`; `vendor_ranges` (out of range AND off the vendor's network; an out-of-range IP alone is not enough), `off_home_network` (the network is not the vendor's: Amazon for Perplexity and CCBot, ByteDance, TikTok or BytePlus for Bytespider) and `rotator_network` (a network presenting `SN_MR_ROTATOR_MIN_AGENTS`, five, distinct vendor agents in the window, none verified) join `cf_directory` as impostor reasons. A row before the since date is never reclassified, and fields an older sensor does not send read as not measured. The rotator set is window-dependent, so a credit context is built once per fold and every per-row call requires it. `impostors[]` gains `reasons` (hits per reason), the verification split gains `vendor_verified` (part of `verified`), and a vendor-verified read counts as verified everywhere verified is shown (who reads, the claim against proof, the daily columns, lanes as `verified_bot` / `vendor_verified`, "is this crawler real", the desktop split). The impostor line on Reader evidence (both twins), /stats and the desktop widget now reads "Claimed a known crawler but failed a check: N reads", then the count per reason. Over the 14 days to 2026-10-18 about 2,245 more reads move from credited to impostor: Perplexity-User 468, Bytespider 463, CCBot 439 and PerplexityBot 432 (off_home_network, from "Google LLC"; Bytespider's 2 from Amazon Singapore too), cohere-ai 443 (rotator_network). `vendor_ranges` takes over from `off_home_network` for Perplexity and CCBot as blob16 fills. Remote contract 20 (additive); the remote MCP worker's `CONTRACT_VERSION` bump is a separate PR, merged after this cut installs.


