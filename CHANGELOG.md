# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Removed
- **The four credential handlers the keyring replaced** (`sn_handle_cf_save`, `sn_handle_analytics_save`, `sn_handle_analytics_use_central_token`, `sn_handle_monitoring_save`), the two field helpers only their forms called (`sn_uptime_status_token_field_html`, `sn_spend_watch_settings_fields_html`), the spend-watch save helper only the monitoring handler called, and their six flash codes. Deprecated in 15.2.0, unreachable since; 221 lines gone. What they did on a change (drop the uptime snapshot, the spend usage caches, the Spotify token cache, the Machine Readers cache) the keyring handler now does from the registry: rows carry `flush` (transient keys) and `flush_fn`, and `sn_keyring_flush()` runs on every save or switch, so a rotated key never serves a stale reading.

## [15.2.0] - 2026-09-15 — every credential in one place

### Added
- **The keyring: every credential in one place, Connections › Credentials.** About twenty credentials lived across eight tabs, each with its own field, handler and hint, and none said which of the others it was NOT; on 2026-09-15 a Cloudflare token was pasted over the sensor's shared secret and the Machine Readers tile read 401 for an hour. `inc/keyring.php` is the one registry (sixteen rows: the site secret and the four worker handshakes, Cloudflare's token/zone/account/override, and the tokens other services issue), each row naming its constant, its home, what it feeds, what it is NOT, and for a shared secret the worker's secret name and the `wrangler secret put` command that sets the other half. Resolution never changes: constant, then the saved value. One leaf paints it (native and classic, `key_<id>` fields, `keyring_save`), one handler saves it (paste to update, `clear` to remove, `site` to derive, obscured to keep; a constant is never written). The Cloudflare, Analytics, Machine Readers and Webhooks leaves lost their credential fields and read the sources instead, pointing here; `cf_save`, `analytics_save`, `analytics_use_central_token` and `monitoring_save` left the handler map (their functions stay one release, deprecated). Pinned: 43 (registry, resolution, switch, filters, the handler's verbs), 18 (leaf parity, never a raw value); mutation red on constants-win and on the site derivation.
- **The site secret: one key for every plugin↔worker handshake.** The Machine Readers read token, the analytics server token (`SN_SRV_TOKEN`), the login-guard bridge token and the provenance HMAC secret are shared passwords between this plugin and its workers, and each can now derive from one site secret: type `site` in the row, then run the command the row prints so the worker carries the same value. Saved values keep working untouched until a row is switched; the four accessors gained filters (`sn_mr_read_token`, `sn_server_token`, `sn_bridge_secret`, `sn_prov_hmac_secret`) the keyring fills only when the value is empty. The beacon token stays out: it is public by design. Tokens other services issue (Cloudflare, Better Stack, Cloudways, Spotify, GitHub, IndexNow) cannot share a value and stay their own rows.
- **Verify all: each credential's own probe, its verdict in words.** `inc/keyring-verify.php` runs one probe per row and stores the verdicts with a timestamp: Cloudflare through the monitor's verify (status, kind); the sensor with the read token, where 200 is accepted, 401 is "this value and the worker's SN_MR_READ_TOKEN differ", 502 is "the worker's SN_MR_SQL_TOKEN needs Account › Account Analytics › Read" and 503 is the worker's own secrets missing; Better Stack, Spotify (after dropping the cached token, so a rotated secret cannot pass on cache), GitHub. A row without a probe says so, never a pass; an unset row is never probed. The leaf paints the badge and the sentence per row and "Last verified"; the Machine Readers pill and tile read the same code map. A rotation is now: paste here, run the printed commands, press Verify all, fix the row that still says refused. Pinned: 19; mutation red on the 401 mapping.
- **`signal-noise/keyring-status`** on the read door (sn-status section `keyring`, read door 35 → 36): every row's id, group, source, set, derives, verdict and sentence; NEVER a value. Remote verdict false (a map of the perimeter's keys).

