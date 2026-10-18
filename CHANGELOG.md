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

- **The machine-reader evidence reads as rows on every surface.** The classification grew (vendor-verified reads, control tokens, four impostor reasons) and the surfaces had bolted sentences on. Now each part is a short label with one right-aligned number, nested under the part of the bar it sits in, and the long sentence moves to the row's title (or a note on /stats). One vocabulary, `snt_mr_reader_labels()` in `inc/machine-readers-impostor-copy.php`, gives every surface the same words.
  - **SN Provenance (desktop):** Verified splits into "by Cloudflare" and "by the vendor's published ranges"; "Named themselves, not verified" carries "failed a check" with one row per reason (Cloudflare's check, outside the vendor's published ranges, outside the vendor's network, network posing as many crawlers) and "control token (Google-Extended)". AI-training reads show the verified figure ("83 verified"); the claimed figure and its parts are the row's title. The card's words arrive in its payload (`labels`, `impostor_reasons`, `ai_training_title`); the script names none of them.
  - **Reader evidence (both twins, one painter):** a "Failed a check" tile beside Control tokens, and a "Failed a check" box (Check, What it means, Reads) paired with the control-token box, in place of the run-on sentence in the lead. The Verified tile names its sources once vendor ranges are measured. The Machine readers hero's AI-training tile and the Dashboard widget lead with the verified figure, the claimed figure beside it.
  - **/stats:** Who the machines are nests the same rows (shares of all machine reads, "<1%" for a real part under one percent) with one plain-words note under them; the AI-training key splits verified by source once measured. The two sentences under the key are gone.
  - **The zero rule, pinned in tests:** the four parts of the bar as before; "by the vendor's published ranges" shows once any read in the window carried the field (0 is then a real figure, never shown before); a reason, the failed-a-check row and the control-token row show only with reads.
  - **Data, additive and outside the remote contract:** the desktop identity split gains `vendor_verified` (null when not measured) and `control_token` (the control-token reads among the unverified), which /stats' snapshot carries too. The summary ability and the remote contract (20) are unchanged.

## [2026.10.43] - 2026-10-18 — impostor reads by vendor ranges, home network and rotator


- **Impostor reads beyond Cloudflare's directory, each with its reason.** Unverified reads naming PerplexityBot, Perplexity-User, CCBot, Bytespider or cohere-ai stayed credited because Cloudflare's directory does not list them. Three more signals now decide, in one ladder (`snt_mr_credit_verdict()`): `vendor_verified` (rights-signals taxonomy 1.6.0, blob16: the client IP checked against Perplexity's and Common Crawl's own published ranges) credits a read in range as verified, with its own basis `vendor_verified`; `vendor_ranges` (out of range AND off the vendor's network; an out-of-range IP alone is not enough), `off_home_network` (the network is not the vendor's: Amazon for Perplexity and CCBot, ByteDance, TikTok or BytePlus for Bytespider) and `rotator_network` (a network presenting `SN_MR_ROTATOR_MIN_AGENTS`, five, distinct vendor agents in the window, none verified) join `cf_directory` as impostor reasons. A row before the since date is never reclassified, and fields an older sensor does not send read as not measured. The rotator set is window-dependent, so a credit context is built once per fold and every per-row call requires it. `impostors[]` gains `reasons` (hits per reason), the verification split gains `vendor_verified` (part of `verified`), and a vendor-verified read counts as verified everywhere verified is shown (who reads, the claim against proof, the daily columns, lanes as `verified_bot` / `vendor_verified`, "is this crawler real", the desktop split). The impostor line on Reader evidence (both twins), /stats and the desktop widget now reads "Claimed a known crawler but failed a check: N reads", then the count per reason. Over the 14 days to 2026-10-18 about 2,245 more reads move from credited to impostor: Perplexity-User 468, Bytespider 463, CCBot 439 and PerplexityBot 432 (off_home_network, from "Google LLC"; Bytespider's 2 from Amazon Singapore too), cohere-ai 443 (rotator_network). `vendor_ranges` takes over from `off_home_network` for Perplexity and CCBot as blob16 fills. Remote contract 20 (additive); the remote MCP worker's `CONTRACT_VERSION` bump is a separate PR, merged after this cut installs.


