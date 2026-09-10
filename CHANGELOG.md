# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.6] - 2026-09-10 — the write door stops eating backslashes

### Fixed
- The MCP write door stripped backslashes from everything it wrote. WordPress's slashing contract is asymmetric: `wp_update_post()`, `wp_insert_post()` and `update_post_meta()` **expect slashed input and unslash it internally**. The door handed them raw `serialize_block()` output, so every literal backslash was eaten — reported live on page 1490, where a block attribute carrying an escaped `<em>` stored as `u003cem` and rendered as literal text, and a className carrying a BEM `--modifier` lost both hyphens' escapes. All **14** write calls that reach core now pass `wp_slash()`. The fault was never in the caller: `serialize_block()` output is correct as sent, and the dry-run diff showed it intact because the loss happens inside core, after validation.
- The same fault reached further than the report. Beyond `block_replace`, it affected every dynamic block whose text lives in delimiter attributes, `create_draft`, `restore_revision`, and the surfaces path — `post_excerpt` plus five `update_post_meta` writes in `inc/abilities-update-post-surfaces.php`, so a meta description or OG card title containing a backslash was corrupted the same way.

### Added
- `tests/write-door-slash-contract.php` asserts on **stored** content, not on the dry-run diff, which is not an oracle here. It reproduces the reported corruption, then proves a slashed write round-trips byte for byte by length and sha256, and derives the census of write sites from source so a new unslashed call fails rather than waiting to be noticed live. The census reads each call's **own balanced argument list**: a fixed 300-character window was tried first and silently passed a real regression, having found a `wp_slash` belonging to the next statement.

