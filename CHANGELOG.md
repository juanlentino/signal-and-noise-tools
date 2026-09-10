# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- The 404 log's redirect suggester stopped proposing nonsense. Measured on the live log 2026-09-10: **192 paths** presented as actionable against **8** classified as scanner probes, and **every wrong suggestion scored exactly 66.7%** — `account`/`about`, `metrics`/`services`, `falsifiability`/`accessibility`, `users.js`/`uses`. `similar_text()`'s percent is `2*matched/(len1+len2)`, so weak pairs land on two thirds repeatedly and the 65.0 floor sat **1.7 points below where noise clusters**. The floor is now 72.0; the one genuine suggestion in the same log (`as-substrate.js` → `as-substrate`, 88.9%) is unaffected.
- Paths that were never content are no longer logged as broken links at all. A file extension this site does not publish is rejected outright — with an allowlist for things a human could genuinely have linked to (`pdf`, images, `txt`, `xml`, `ics`) — as are the infrastructure namespaces `/api/`, `/apis/`, `/_sn/`, `/v1/`, `/graphql`, `/rest/`, `/oauth`. This was not hypothetical harm: `/about.php7` was suggested → `/about` and **accepted**, so a vulnerability-scanner probe is now a permanent 301 in site configuration. `/metrics`, `/health`, `/status`, `/debug`, `/console` and `/swagger` join the single-segment scanner-guess list.
- A real broken link still surfaces. `/notes/desing-tokens` (a genuine typo), `/tag/falsifiability` (a real archive that was merely mis-suggested), `/notes/hero.png` and `/resume.pdf` are all still captured — pinned as negative controls, because a filter that quietly swallows real 404s would be worse than the noise it removes.

## [13.109.6] - 2026-09-10 — the write door stops eating backslashes

### Fixed
- The MCP write door stripped backslashes from everything it wrote. WordPress's slashing contract is asymmetric: `wp_update_post()`, `wp_insert_post()` and `update_post_meta()` **expect slashed input and unslash it internally**. The door handed them raw `serialize_block()` output, so every literal backslash was eaten — reported live on page 1490, where a block attribute carrying an escaped `<em>` stored as `u003cem` and rendered as literal text, and a className carrying a BEM `--modifier` lost both hyphens' escapes. All **14** write calls that reach core now pass `wp_slash()`. The fault was never in the caller: `serialize_block()` output is correct as sent, and the dry-run diff showed it intact because the loss happens inside core, after validation.
- The same fault reached further than the report. Beyond `block_replace`, it affected every dynamic block whose text lives in delimiter attributes, `create_draft`, `restore_revision`, and the surfaces path — `post_excerpt` plus five `update_post_meta` writes in `inc/abilities-update-post-surfaces.php`, so a meta description or OG card title containing a backslash was corrupted the same way.

### Added
- `tests/write-door-slash-contract.php` asserts on **stored** content, not on the dry-run diff, which is not an oracle here. It reproduces the reported corruption, then proves a slashed write round-trips byte for byte by length and sha256, and derives the census of write sites from source so a new unslashed call fails rather than waiting to be noticed live. The census reads each call's **own balanced argument list**: a fixed 300-character window was tried first and silently passed a real regression, having found a `wp_slash` belonging to the next statement.

