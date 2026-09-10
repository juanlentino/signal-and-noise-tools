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
- **`get-narration` could not tell a dead provider from an empty cache.** Both returned `null`. The module already carried a complete failure-recording trio — `snt_narration_store_last_error()`, `snt_narration_last_error()`, `snt_narration_clear_last_error()` — defined, unit-tested in isolation, and **never called by any production path**: `snt_narration_cron_run()` discarded `snt_narration_run()`'s `WP_Error` on the floor. Found under a genuinely depleted API account on 2026-09-10, the one condition that cannot be reproduced on purpose. The cron handler now stores the failure and a successful run clears it; `get-narration` returns the digest stamped `state:ready`, or `state:unavailable` with `reason` / `message` / `failed_at` and an empty body when the last run failed. A cold cache with no recorded failure still returns `null` — the case every existing reader handles, and not a fault.
- The stored failure lived 15 minutes, which was right for the admin-notice flash it was written for and wrong for a state an agent reads a day later. It now persists as long as the digest it stands in for would have been cached, and is cleared only by the next success.

### Changed
- **Remote MCP contract `4` → `5`.** `remote-get-narration` mirrors the admin `output_schema` byte-identically (the parity pin enforces it), so the twin's payload shape moved and `tests/remote-contract-shapes.php` went RED with the new hash — the intended workflow. Additive: every prior key keeps its type. **The worker half is owed:** `sn-remote-mcp-worker` `CONTRACT_VERSION` must move to `5` too; until it does the deploy probe reports `contract_match: false`, which is observed, never refused, by design.

### Added
- Pins in `tests/abilities-narration.php` for all three states, including that a cached digest outranks a lingering failure row and that the cold case did not become a fault. Pins in `tests/insights-narration.php` drive the **real** `snt_narration_run()` through the stubbed generator, so the wiring itself is load-bearing — a helper tested in isolation and never called is a guard that cannot go red. All four verified failable, one of them twice: the first mutation removed an `if` and orphaned its `elseif`, and a parse error reds every test without proving anything.

## [13.109.20] - 2026-09-10 — the guards see what they are guarding

### Fixed
- **The ability permission policy enforced itself over 93 of 103 abilities.** `tests/ability-permission-policy.php` is the guard that asserts every registered ability carries an appropriate permission callback, and its parser globbed `inc/abilities-*.php` only. Three abilities registered from feature files — `get-404-log`, `provenance-integrity-status`, `uptime-status` — and seven registered through `wp_register_ability( $slug, … )` inside a `foreach` over a definition map (three remote search twins, four Search Console reads) were outside the population it checks. All ten hold correct permissions today; none of them was *asserted*, so a change would have gone unnoticed. The registry now walks `inc/` recursively via `snt_test_inc_files()`, strips comments so a registration discussed in a docblock is not counted as one, and resolves variable-slug sites against their definition map — reporting any it cannot resolve rather than skipping it, so a new loop shape fails here instead of quietly shrinking the population again.
- The same guard's floor was `count >= 70` against an actual 93: twenty-three points of slack, enough to delete a fifth of the registry unnoticed. Raised to 100 against a measured 103. Both new pins were verified failable — stripping the callback from `get-404-log` (previously invisible) now reds, and renaming the looped map's keys reds both the floor and the new unresolved-site pin.
- Found by an ecosystem inventory rather than a symptom, and the fix nearly repeated the bug it was fixing: the first version hand-rolled a two-level `glob( 'inc/*.php' )`, which `tests/inc-population-guard.php` — the #987 meta-guard against exactly that shape — caught immediately.

### Changed
- Removed a `cursor: pointer` from S&N Analytics that had never applied. OpenStation's `desktop.css` sets `body.os-active, body.os-active * { cursor: default !important }` across the whole shell, so the declaration matched and lost — measured on the running product: 50/50 real `a[href]`, 50/50 `<button>` and all of our own links compute `default`. Nothing in the shell shows a pointer cursor, by design, and these links should read like their neighbours. The rule's `color` declaration is live and stays; only the dead line went, with a comment recording why so it is not re-added. Found by writing the rule, **injecting it into the live page, and watching the computed value not move** — the same check that would have caught the 820px leaf cap.

