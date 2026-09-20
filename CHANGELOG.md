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
- **An empty map is `{}` at the door, never `[]`.** The MCP proxy validates every tool's output against its schema, and 17.1.0's first clean tag pass drew `data/notes must be object` from `jev-tags`: with zero misfits PHP's empty array encodes as a JSON list. Every object-typed map an ability can hand back empty is now cast at the door: `jev-tags` and `jev-tells` `notes` (and their no-pass states), `jev-query-fit` `judged_notes`, `jev-meter` `by_feature`, `rights-evidence` `months`. Pinned as the encoded bytes (`{}`), the shape the proxy checks; verified red without the cast.

## [17.1.0] - 2026-09-20 — what a reader of the archive gets

### Added
- **Jev: by tag, the pass pivoted per tag.** The tag-fit pass holds every note's score for every tag it carries (0 the subject is absent, 1 touches it, 2 is about it); the leaf and `jev-tags` surfaced only the absent end. What a reader of a tag archive feels is the middle: notes that only touch the tag. Content › Tags gains a "Jev: by tag" section on both surfaces, and `jev-tags` a `by_tag` list: per tag, the notes carrying it, the mean score, and the notes under 1 of 2 with score and confidence, by touching share descending. A reading from the stored pass, no Jev spend, no boxes: which tags a note carries stays the owner's call. `sn_jev_tags_by_tag()`; tests jev-tags 26, os-leaf-content-tags 48.

### Documentation
- Session doc: [the evidence outlives the sensor](docs/ops/session-2026-09-19-the-evidence-outlives-the-sensor.md) (16.9.1 to 17.0.0, the two workers, the ledger's verifier and the policy's 1.3).

