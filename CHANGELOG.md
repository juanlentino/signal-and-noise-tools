# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.6.1] - 2026-09-22 — every ability states its contract

### Documentation
- **ADR-0001 takes a first-party WordPress amendment.** `WordPress/agent-skills` is published by the organisation that ships core, the block editor and OpenStation, GPL, with an eval harness in the repository, so the registry failure mode the ADR was written against (a silent post-install update from an unaccountable publisher) does not apply. Six are installed globally as reference material after a read of four of them against this plugin and the theme (documentation only, no network calls, no credential handling, nothing addressed to an agent); three are skipped as inapplicable, and everything outside the WordPress and Anthropic organisations stays under the original rule. Two revisit triggers added, one of them the withdrawal condition.

### Changed
- **Private product repos are no longer named in the docs or one code comment.** `docs/adr/adr-0001-third-party-agent-skills.md` (scope line, stack sentence, extraction note; one sentence removed, the rule kept as "private product repos are zero-tolerance: Anthropic first-party skills only"), `docs/ops/session-2026-09-15-the-key-that-was-not-a-key.md` and `docs/ops/notes-structural-sweep-2026-08-15.md` (repo names in the Dependabot sweep counts and the "nothing touched" line), and the docblock of `inc/tools-sw-tombstone.php` (the repo the `/tools/` tombstone does not touch) all say "private repos" now. History not rewritten; docs and a comment only, no behaviour change, no version bump.

### Fixed
- **Every ability registration states all three annotations and its MCP meta; 78 of 118 blocks left at least one unsaid, and the rw guard reads one of them.** Read against 17.6.0: `sn_mcp_rw_guard_ability_is_readonly()` (`inc/mcp/mcp-rw-guard.php`) decides the read/write verdict with `! empty( $decl['readonly'] )`, so an omitted `readonly` and a deliberate `false` are the same byte to the guard and different facts to a reader, and the Abilities API reads a missing annotation as "behaviour unknown" rather than as a default. `meta.mcp.public` and `meta.mcp.type`, the two keys the bundled MCP adapter reads, were set on no ability at all, so the adapter's opt-in set was empty by silence rather than by decision. Changed: all 118 `annotations` blocks across the 56 files that register an ability now state `readonly`, `destructive` and `idempotent` explicitly, each derived from the execute callback; 46 blocks that already said `readonly: true` gained `destructive: false`; 21 writes gained `readonly: false` (the three corpus scans among them: `block-migrations-scan`, `corpus-integrity-scan` and `pattern-adoption-scan` each `set_transient()` a per-user cache, so they are writes, not reads); and 11 model-calling suggest/generate abilities plus `regenerate-og-card`, `run-insights-scan` and `run-narration` gained `readonly: false, destructive: false` (each writes only a derived artefact that re-running recreates). No `destructive` moved to `true`: the 15 that carry it carried it already, and no verdict the rw guard reaches today changes. `meta.mcp` is now stated on every block, `public` true on the 45 blocks covering the 47 slugs of Door 1's read allowlist (`inc/mcp/mcp-capabilities.php` is still the source of truth; this restates it per ability for when the hand-maintained list retires into mcp-adapter) and false everywhere else, `type` `'tool'` throughout, which is what both doors already project them as. The Connect page's Door 2 copy said "none of ours opt in" on both its classic and its kit twin; that claim is now "the abilities on Door 1's read allowlist opt in", and the pin that derives it from the registrations derives the new one. Pinned in `tests/ability-annotations-complete.php`: every literal registration carries an annotations block, no block omits any of the three keys, every block states `meta.mcp.public` + `meta.mcp.type`, and the representative read (`sn-status`, public true) and write (`sn-apply`, public false) by value; 6 red with `inc/` swapped back to 17.6.0, naming all 78 short blocks by file and line. #1662.

