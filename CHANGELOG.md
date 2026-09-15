# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The credentials sweep: two more leaves lose their fields.** A sweep of every leaf for a credential sitting beside readings found three. AI › Models & budget carried the Workers AI token in the budget form; it is a keyring row now (`workers_ai_token`, group Cloudflare, home `ml.embeddings_token`, verified with its own bytes), and the leaf reads it with a set/not-set badge and the door to the keyring; the status pills (Configured, no account ID, not configured) stay on both leaves. Connections › Discography carried the Spotify client id and secret in the sync form; both were keyring rows already, so the form keeps the Muso profile and the featured release and the Spotify block reads the two sources as facts. Search Console keeps its service-account JSON box (a file, not a value; its own paste and Test connection are the right shape) and IndexNow keeps its public key. The keyring is seventeen rows; the AI save handler and the music save handler no longer write those options; `sn_music_render_cred_field()` and `sn_music_save_cred()` are gone. Two stale pointers to Connections › Cloudflare for the zone id now say Connections › Credentials.
- **Security › Firewall is two columns**, what happened left (events by action, the top rules under a heading they never had, the notes, Refresh) and to what right (top paths, top countries under "Acted on"). It was one narrow card over an empty half. `cloudflare_firewall_parts()` hands a leaf the pieces.
- **Measurement › Analytics: Edge, 7 days heads the left column** instead of a full-width band over five short folds and an empty half; the stats grid wraps to the column. Same on the classic hub. Measurement › Insights: Scan status moves under Run Analysis, whose result it is; the left column was one card.

## [15.3.0] - 2026-09-15 — the readings where their questions are asked

### Added
- **Security › Firewall.** What Cloudflare stopped before WordPress ran, on the tab that asks what is being kept out: events by action, top rules, the log's top paths and countries, the raw-dataset note, its own Refresh. The first live read showed the WAF rule blocking the abilities route 487 times in a day, which is the witness the health check now reads; that belongs next to Login defense, not under a credentials tab. Native leaf `security-firewall.php` through the monitor's shared part; classic leaf in `inc/cloudflare-readings-admin.php`. Parity suite: 11.

### Changed
- **Edge, 7 days moved to Measurement › Analytics**, a section above the hub's two columns, next to the analytics it belongs with (the Edge view already reads the same zone): the five figures, the 5xx split, the monitor's Refresh. Painted only once the monitor has a configured record, so a site without Cloudflare keeps the hub as it was. Classic card the same, after the pipeline strip. Pinned on both leaves.
- **Connections › Cloudflare is wiring and cache.** Credentials (read-only, from the keyring), Token with the monitor's Refresh, Cache. Edge and Firewall left for the tabs above; one stored record feeds all three leaves, the daily cron and the ability unchanged.

