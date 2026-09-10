# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.20] - 2026-09-10 — the guards see what they are guarding

### Fixed
- **The ability permission policy enforced itself over 93 of 103 abilities.** `tests/ability-permission-policy.php` is the guard that asserts every registered ability carries an appropriate permission callback, and its parser globbed `inc/abilities-*.php` only. Three abilities registered from feature files — `get-404-log`, `provenance-integrity-status`, `uptime-status` — and seven registered through `wp_register_ability( $slug, … )` inside a `foreach` over a definition map (three remote search twins, four Search Console reads) were outside the population it checks. All ten hold correct permissions today; none of them was *asserted*, so a change would have gone unnoticed. The registry now walks `inc/` recursively via `snt_test_inc_files()`, strips comments so a registration discussed in a docblock is not counted as one, and resolves variable-slug sites against their definition map — reporting any it cannot resolve rather than skipping it, so a new loop shape fails here instead of quietly shrinking the population again.
- The same guard's floor was `count >= 70` against an actual 93: twenty-three points of slack, enough to delete a fifth of the registry unnoticed. Raised to 100 against a measured 103. Both new pins were verified failable — stripping the callback from `get-404-log` (previously invisible) now reds, and renaming the looped map's keys reds both the floor and the new unresolved-site pin.
- Found by an ecosystem inventory rather than a symptom, and the fix nearly repeated the bug it was fixing: the first version hand-rolled a two-level `glob( 'inc/*.php' )`, which `tests/inc-population-guard.php` — the #987 meta-guard against exactly that shape — caught immediately.

### Changed
- Removed a `cursor: pointer` from S&N Analytics that had never applied. OpenStation's `desktop.css` sets `body.os-active, body.os-active * { cursor: default !important }` across the whole shell, so the declaration matched and lost — measured on the running product: 50/50 real `a[href]`, 50/50 `<button>` and all of our own links compute `default`. Nothing in the shell shows a pointer cursor, by design, and these links should read like their neighbours. The rule's `color` declaration is live and stays; only the dead line went, with a comment recording why so it is not re-added. Found by writing the rule, **injecting it into the live page, and watching the computed value not move** — the same check that would have caught the 820px leaf cap.

