# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Groups on /notes/tags, from Content › Tags.** Theme 13.4.0 put the heading a tag files under on WordPress's own Posts › Tags screen, which the native view never shows. The same control now sits on Content › Tags, both surfaces: one select per tag with the heading it renders under today, unfiled tags first, one File tags button. The headings and the meta are the theme's (`sn_notes_tag_groups()`, `sn_tag_group`); without them the section says so and offers no form. `tag_group_apply` writes only what changes, only on a tag the user can `edit_term`, never junk (an unknown id unfiles), and unfiling a tag the theme's seed list still names is a no-op, since the page would keep filing it. Handler map 66. `docs/REFACTOR-admin-post-actions.md` and the flash lines follow.

### Fixed
- **An empty map is `{}` at the door, never `[]`.** The MCP proxy validates every tool's output against its schema, and 17.1.0's first clean tag pass drew `data/notes must be object` from `jev-tags`: with zero misfits PHP's empty array encodes as a JSON list. Every object-typed map an ability can hand back empty is now cast at the door: `jev-tags` and `jev-tells` `notes` (and their no-pass states), `jev-query-fit` `judged_notes`, `jev-meter` `by_feature`, `rights-evidence` `months`. Pinned as the encoded bytes (`{}`), the shape the proxy checks; verified red without the cast.

## [17.1.0] - 2026-09-20 — what a reader of the archive gets

### Added
- **Jev: by tag, the pass pivoted per tag.** The tag-fit pass holds every note's score for every tag it carries (0 the subject is absent, 1 touches it, 2 is about it); the leaf and `jev-tags` surfaced only the absent end. What a reader of a tag archive feels is the middle: notes that only touch the tag. Content › Tags gains a "Jev: by tag" section on both surfaces, and `jev-tags` a `by_tag` list: per tag, the notes carrying it, the mean score, and the notes under 1 of 2 with score and confidence, by touching share descending. A reading from the stored pass, no Jev spend, no boxes: which tags a note carries stays the owner's call. `sn_jev_tags_by_tag()`; tests jev-tags 26, os-leaf-content-tags 48.

### Documentation
- Session doc: [the evidence outlives the sensor](docs/ops/session-2026-09-19-the-evidence-outlives-the-sensor.md) (16.9.1 to 17.0.0, the two workers, the ledger's verifier and the policy's 1.3).

