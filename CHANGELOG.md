# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

Hardening from the 2026-10-26 MCP doors audit.

### Security
- **Only the read door's tools are public to the official MCP Adapter.** `sn_mcp_adapter_public_floor()` runs on `wp_register_ability_args` and clears the flag on any `signal-noise/` or `signal-and-noise/` ability that is not on the read-door allowlist: it sets an explicit `meta.mcp.public: false` whenever the registration carries `meta.mcp.public` or core's top-level `meta.public` (the adapter's fallback). Three write abilities and four retired reads carried the flag, so an installed adapter would have run them through its execute tool without the write door's bound credential or rate limit. The adapter is not installed live; the connect page's "the read door's tools" is now true by construction. Pinned in `tests/mcp-adapter-public-floor.php`.
- **`/bridge` looks like an absent route.** A bad bearer already got core's own `rest_no_route` 404, but core added `Allow: POST` to it and answered OPTIONS with the route's description, so a prober could still tell an armed door from no door. OPTIONS now gets the empty 200 an absent route gets, and the Allow header is dropped on `/bridge`. Pinned in `tests/mcp-bridge-route.php`.
- **The dead bridge-token option is deleted.** Since 2026.10.71 the bridge secret is `SN_BRIDGE_TOKEN` only; a value the old fallback saved in `sn_bridge_token` is removed on the next admin load, and `bridge_token` leaves the site-secret rows. Pinned in `tests/keyring.php`.
- **A batch target is held to the same scope list as a single one.** sn-apply's `target` is an object or a list of objects, and only the object form carried the `scope` enum. WordPress also validates against the first type that fits, and every PHP array fits "object", so a list was checked as an object and its items were never checked at all. A list could therefore name `tags`, `cron` or `template_overrides`, which the resolver accepts, and reach `merge_tags`, `clear_template_overrides` and `schedule_cron_event`, three types the single-target form refuses and the description does not advertise. `target` now lists `array` first and each item carries the single target's properties, so all three refuse at input validation in either form (owner decision 2026-10-26: closed; opening them is one deliberate edit to the scope list). Checked with core's own validator; pinned in `tests/mcp-projection-schema-types.php`.

### Changed
- **sn-apply's description states each rule once.** The write door's one content-mutating tool carried a 19,998-character description (about 5k tokens, half the write door's `tools/list`) in which the fingerprint rule appeared five times and all-or-nothing, the 50-change limit and the scheduled-post guarantee twice each. It now opens with RULES FOR EVERY TYPE (gates, dry run, modes and the publish-only and revision-only lists, fingerprint, targets, idempotency, change class, prose delta, scheduled posts, multi-change) and gives each type only what differs: 16,406 characters, every gate, refusal and "never" kept. It also names `dismiss` (publish-only), which it accepted but never described. `merge_tags`, `clear_template_overrides` and `schedule_cron_event` stay unadvertised (an owner decision whether to open them). The fingerprint rule now says which types answer a missing fingerprint with 422 (the ones whose fingerprint the caller supplies) and which with 409 (the five candidate-driven ones), and `dismiss` names the three surfaces that have a dismissal store. Pinned in `tests/sn-apply-description.php`: every safety rule present, the shared ones exactly once, the publish-only and revision-only lists derived from `snt_sn_apply_mode_support()`.

### Fixed
- **Run-route audit rows record their arguments.** The run route's body is `{input: {...}}`, and the allowlist redactor dropped the envelope whole, so every app-password write through `/wp-abilities/v1/abilities/<slug>/run` logged no arguments. The row now gets the ability's `input`, read where core's run controller reads it: the query string for GET and DELETE, the JSON body otherwise. Pinned in `tests/mcp-rw-guard-run-route.php`.
- **`ai-generate-excerpt` is not annotated read-only.** Every run spends AI credit, so it is `readonly: false` and the run route takes it as POST; the editor's button follows through the localized verb map. Like `ai-generate-meta-description`, it is now write-class to the lifecycle guard: a run writes an rw audit row and is refused while the rw kill switch is engaged. Pinned in `tests/ai-abilities-contract.php`.
- **Dependabot resolves composer again.** `roave/security-advisories` now conflicts with PHP 8.3 below 8.3.36, and Dependabot resolved against a bare 8.3, so every composer update failed. `config.platform.php` is 8.3.36, the patched release of the line CI runs. When roave raises the 8.3 floor past it, bump the pin: Dependabot will not.

## [2026.10.72] - 2026-10-26 — keyring-status never serves part of a secret

### Security
- **`keyring-status` no longer serves any part of a secret.** The keyring's change log stored, and the read door's `keyring-status` returned, the last four characters of every value written to a keyring option (the Cloudflare token, the Anthropic admin key, the site secret, the owner key), against the tool's own "never a value". Each line now carries `fp` instead: an 8-hex HMAC-SHA256 of the value keyed with the site's own salt, so two values can still be told apart while no part of either is shown and a guess cannot be checked offline. Older lines are stripped on read and scrubbed from storage on the next write. Pinned in `tests/keyring-changes.php`. (2026-10-26 MCP doors audit, N3.)

