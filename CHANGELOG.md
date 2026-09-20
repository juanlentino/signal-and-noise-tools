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
- **A breached password was refused on the classic profile screen and accepted from the native one.** Mode A (13.58.0) ran on `user_profile_update_errors` and `validate_password_reset`, the two hooks core's profile form and reset form fire. OpenStation's profile window saves through `PUT wp/v2/users/{id}`, and core's users controller hands the request's `password` to `wp_update_user()` without either hook, so on the owner's own path a breached or uncheckable password reached the hash with no check; the fail-closed promise in the file's header was false there. Now `rest_dispatch_request` runs the same guard over `$request['password']` on a write to the users controller (create, update, update-current) and hands core the refusal as the dispatch result (400, `params.password` naming the field, the same message the classic screen shows; the window maps both). It runs after the route's permission callback, so an unauthenticated request never reaches the breach client, and a read carrying `?password=` is not judged. Counts the rejection like any other; a clean password, no password, another controller, or an earlier filter's result pass through unread. NOT `rest_pre_insert_user`, the obvious seam and the wrong one: core's `update_item()` takes `prepare_item_for_database()`'s return without an `is_wp_error()` check, sets `->ID` on it and writes `(array)` of it, so a `WP_Error` there is a silent 200 that drops every field in the save; the file is pinned off that hook by name. Pinned: the three hooks and nothing else, breached and unreachable both refused at the door, clean, read, foreign-controller and password-less untouched; red without the filter. Found by the native-twin audit of 2026-09-20 (#1 of eleven); the seam by the workflow's core-flow skeptic, against my own review.

## [17.2.1] - 2026-09-20 — boxes share a row

### Fixed
- **Content › Tags is boxes on rows, not a scroll.** The owner opened 17.2.0 to a registry painted as twenty-six selects under a per-tag list of twenty-five, one box after another. Now: three paired rows in the house's two-column grid (duplicates beside the picker that folds them, Jev's tag fit beside the per-tag reading it comes from, the headings beside the unused tags), the glance and the recent list alone; a side that paints nothing leaves the other at full width. "Jev: by tag" is one summary line ("25 tags; 4 carry notes that only touch them: ...") and then only those tags with their notes. "Groups on /notes/tags" is a ledger (one line per heading naming its tags, "Not yet filed" only when a tag is) and ONE small form (a tag, a heading, File); `tag_group_apply` takes it beside the per-tag map. The classic page paints the same ledger and form. A registry is not a form; a reading is not a fold either, the owner said, when boxes can share a row.
  PHPStan reads a `function_exists` guard only in the function that holds it, so the ledger builders carry their own (the theme owns `sn_notes_tag_groups()`); the caller's guard keeps the message.

