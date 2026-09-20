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
- **Content › Tags is boxes on rows, not a scroll.** The owner opened 17.2.0 to a registry painted as twenty-six selects under a per-tag list of twenty-five, one box after another. Now: three paired rows in the house's two-column grid (duplicates beside the picker that folds them, Jev's tag fit beside the per-tag reading it comes from, the headings beside the unused tags), the glance and the recent list alone; a side that paints nothing leaves the other at full width. "Jev: by tag" is one summary line ("25 tags; 4 carry notes that only touch them: ...") and then only those tags with their notes. "Groups on /notes/tags" is a ledger (one line per heading naming its tags, "Not yet filed" only when a tag is) and ONE small form (a tag, a heading, File); `tag_group_apply` takes it beside the per-tag map. The classic page paints the same ledger and form. A registry is not a form; a reading is not a fold either, the owner said, when boxes can share a row.

## [17.2.0] - 2026-09-20 — the heading is on the leaf

### Added
- **Groups on /notes/tags, from Content › Tags.** Theme 13.4.0 put the heading a tag files under on WordPress's own Posts › Tags screen, which the native view never shows. The same control now sits on Content › Tags, both surfaces: one select per tag with the heading it renders under today, unfiled tags first, one File tags button. The headings and the meta are the theme's (`sn_notes_tag_groups()`, `sn_tag_group`); without them the section says so and offers no form. `tag_group_apply` writes only what changes, only on a tag the user can `edit_term`, never junk (an unknown id unfiles), and unfiling a tag the theme's seed list still names is a no-op, since the page would keep filing it. Handler map 66. `docs/REFACTOR-admin-post-actions.md` and the flash lines follow.

### Fixed
- **An empty map is `{}` at the door, never `[]`.** The MCP proxy validates every tool's output against its schema, and 17.1.0's first clean tag pass drew `data/notes must be object` from `jev-tags`: with zero misfits PHP's empty array encodes as a JSON list. Every object-typed map an ability can hand back empty is now cast at the door: `jev-tags` and `jev-tells` `notes` (and their no-pass states), `jev-query-fit` `judged_notes`, `jev-meter` `by_feature`, `rights-evidence` `months`. Pinned as the encoded bytes (`{}`), the shape the proxy checks; verified red without the cast.

