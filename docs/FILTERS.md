# Developer filter reference

Code-level seams for the analytics settings hub (Measurement → Analytics).
These aren't exposed as UI knobs — the settings leaf links here instead of
listing them inline (moved out at v9.45.0, §4 of the settings-leaf prune).
Constants beyond these stay internal by policy (design spec §7's
knob-exposure rule).

Linked from the leaf's "Developer filter seams →" line
(`snt_analytics_render_filter_reference()`, `inc/analytics-render-settings.php`).

| Filter | What it overrides |
| --- | --- |
| `sn_analytics_signal_config` | Predictive engine opts: baseline_days, z (post-filter clamped). |
| `sn_analytics_session_config` | Session engine: idle gap, engaged thresholds, row cap. |
| `sn_analytics_session_funnels` | Named conversion funnels for the Visits view. |
| `sn_analytics_narrator` | Override the compact AI narrative. |
| `sn_analytics_digest` | Override the weekly executive digest. |
| `sn_analytics_recommender` | Override the recommendations payload. |
| `sn_analytics_refresh_secret` | Override the cron-refresh auth secret (default SN_SRV_TOKEN). |
| `sn_beacon_token` | Override the beacon/collector token (default SN_BEACON_TOKEN). |
| `sn_analytics_self_hosts` | Hosts folded as self-referrals in Sources. |
| `snt_ai_model_preference` | Route AI features to a specific model. |
| `snt_ai_economy_features` | Which AI features ride the economy tier. |
| `snt_ai_economy_model` | Which model the economy tier uses. |
| `snt_ai_cache_probe_enabled` | Kill switch for the read-only Anthropic prompt-cache probe (`inc/ai-cache-probe.php`). Return false to stop recording. |
| `snt_ai_cache_probe_min_prefix` | A model's minimum cacheable prefix in tokens, used by the probe's verdict. Return an int to teach it a model it doesn't know, or null to withhold a claim. |

`tests/analytics-filter-reference-parity.php` scans `inc/` for `apply_filters()`
calls in these namespaces and cross-checks this table both ways — a future
filter #13 fails that suite until it's documented here (or explicitly
allowlisted as intentionally undocumented, same as before).

## Seams outside this table

The table above is scoped to the analytics settings hub (prefixes
`sn_analytics_`, `snt_ai_`, `sn_beacon_`), and the parity suite asserts every
row is a real seam in those namespaces — so filters from other subsystems are
documented with their subsystem, not added here.

- `snt_rest_hardening_policy` — the routes removed, post types stripped,
  namespaces protected, and headers emitted on the unauthenticated REST
  surface. See [REST-HARDENING.md](REST-HARDENING.md).
