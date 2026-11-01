<?php
/**
 * Signal & Noise — first-party analytics daily-rollup data layer (P2).
 *
 * Turns the raw, sampled, ~3-month-retained Cloudflare Analytics Engine
 * stream (written by the edge worker, read via inc/analytics-api.php) into a
 * durable per-day-per-path aggregate in `wp_sn_analytics_daily`. Downstream
 * widgets / insights / the front-end `[sn_popular]` block (P3/P4) read this
 * table, never AE directly, so the render path never blocks on a network call
 * and history survives AE's retention window.
 *
 * ── Why a rollup table ───────────────────────────────────────────────────────
 *
 *   - AE retains raw events ~90 days; this table keeps the daily aggregate
 *     forever (one row per day per path — slow-growing, no prune needed).
 *   - AE SQL is a network call; the table is a local indexed read.
 *   - AE samples; the rollup applies sum(_sample_interval) once at write time.
 *
 * ── Event-type correctness (the load-bearing detail) ─────────────────────────
 *
 * The worker writes ONE AE row per beacon event, and the doubles are sparse by
 * event type (see signal-and-noise-analytics-worker/src/index.js):
 *
 *   blob1='pv'  → a pageview;    double1=0,            double2=0
 *   blob1='sc'  → scroll depth;  double1=scroll_pct,   double2=0
 *   blob1='tm'  → time on page;  double1=0,            double2=time_ms
 *
 * blob2 (path) + index1 (visitor-day hash) are present on ALL three. So a naive
 * avg(double1) over every row for a path would be dragged toward 0 by the pv/tm
 * rows. Each metric therefore gets its own event-type filter via conditional
 * aggregation — sumIf/avgIf (Cloudflare AE SQL, GA since 2025-11):
 *
 *   views      = sumIf(_sample_interval, blob1 = 'pv')   true pageview count
 *   visits     = count(DISTINCT index1)                  approx unique visitor-days
 *   scroll_avg = avgIf(double1, blob1 = 'sc')            mean scroll milestone
 *   time_avg   = avgIf(double2, blob1 = 'tm')            mean visible ms per exit
 *
 * Phase A (schema v5) adds the exact weighted engagement sums to the same
 * SELECT — scroll_sum/scroll_events/time_sum/time_events, every one
 * _sample_interval-weighted — and a SECOND query for pageview_visits (distinct
 * visitor-days with ≥1 pv), merged per (day, path, class) in PHP, because the
 * single-query gated distinct 422s on live AE (P0.1, docs/analytics-integrity-plan.md).
 * The stored scroll_avg/time_avg now come from the weighted ratio sum/events
 * (identical to avgIf at sample interval 1; correct under sampling).
 *
 * v9.66.0 (schema v6): the STORED scroll_sum column is re-based in PHP to TRUE
 * depth units — 25 × scroll_events, the exact sum of per-view max scroll
 * depths (the beacon's cumulative milestones are evenly spaced and fire at
 * most once per view; see inc/analytics-derive.php). The SELECT's raw
 * sumIf(double1 * _sample_interval, 'sc') alias stays UNCHANGED (the AE query
 * gains no new arithmetic forms — dialect-guard / live-AE zero-risk path) but
 * now feeds ONLY the weighted legacy scroll_avg; it is never stored. time_sum
 * is genuinely event-summed ms — untouched.
 *
 * Note: `views` is sample-corrected (×_sample_interval) but `visits` is a raw
 * distinct count of the hashes that survived sampling — under AE sampling the
 * two diverge (views/visit inflates). This site's volume rarely trips sampling
 * (interval=1 → both exact), and this matches the locked spec, so consumers
 * should treat views/visits as an estimate, not a precise ratio.
 *
 * ── Refresh architecture (the shared outbound-client SWR pattern) ──────────────────
 *
 *   - sn_analytics_run_rollup() (the cron callback) queries AE for the trailing
 *     SN_ANALYTICS_ROLLUP_WINDOW_DAYS and UPSERTs each (day, path). Re-rolling a
 *     window is idempotent: each run recomputes the full-day aggregate, so a
 *     partial "today" self-corrects and late-arriving samples are absorbed.
 *   - sn_analytics_rollup_warm() (admin_init) schedules a non-blocking single
 *     event when the freshness stamp is older than SN_ANALYTICS_ROLLUP_TTL, so
 *     an active admin sees ~15-min-fresh data without ever blocking a render.
 *   - sn_analytics_rollup_schedule() (init) registers a daily recurring backstop
 *     so the rollup still happens on days nobody opens wp-admin.
 *
 * Dormant until AE is configured (via the wp-config constants OR the admin settings
 * options) — sn_analytics_config() returns null and run_rollup() no-ops. The empty
 * table is created but never written, exactly like the Plausible widgets stay blank
 * until their token lands.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

require_once __DIR__ . '/analytics-human-rule.php'; // the ONE counted-human rule

const SN_ANALYTICS_DAILY_TABLE          = 'sn_analytics_daily';
// v3: one-time purge of admin/login rows that leaked in before the ingestion
// guard (sn_analytics_is_excluded_path) existed. Data-only — no schema change.
// v5: additive engagement-sum columns (scroll_sum/scroll_events/time_sum/
// time_events) for exact per-view / per-visit denominators (Phase A spec §6).
// NULL DEFAULT NULL is load-bearing: legacy rows must read NULL ("never
// measured"), never a fabricated 0 — the derive layer distinguishes the two.
// v6 (v9.66.0): scroll_sum re-based to TRUE depth units via the one-time
// repair UPDATE scroll_sum = 25 * scroll_events (exact identity — both
// columns come from the same 'sc' events; NULL rows stay NULL). Data-only.
const SN_ANALYTICS_DAILY_DB_VERSION     = '6';
const SN_ANALYTICS_DAILY_DB_VERSION_OPT = 'sn_analytics_daily_db_version';

// SN_ANALYTICS_DATASET (the AE "sn_pageviews" dataset) is defined by the
// read-client inc/analytics-api.php, which the loader requires before this file.

// Two distinct hooks, same callback. The warmer schedules SINGLE events on the
// on-demand hook (which clears after firing, so wp_next_scheduled() reads false
// between runs and the warmer can re-fire). The daily backstop uses its OWN
// recurring hook — sharing one hook would leave wp_next_scheduled() permanently
// truthy and silently neuter the 15-min SWR warmer.
const SN_ANALYTICS_ROLLUP_HOOK         = 'sn_analytics_rollup';        // on-demand (warmer single events)
const SN_ANALYTICS_ROLLUP_DAILY_HOOK   = 'sn_analytics_rollup_daily';  // recurring daily backstop
const SN_ANALYTICS_ROLLUP_WINDOW_DAYS  = 7;                            // trailing window each run re-aggregates
const SN_ANALYTICS_ROLLUP_FRESH_KEY    = 'sn_analytics_rollup_fresh';
// The shape the last daily read ran with: {shape: folded|unfolded, at}. 'unfolded' means live AE refused the over-cap fold and the run fell back.
const SN_ANALYTICS_ROLLUP_SHAPE_OPT    = 'sn_analytics_rollup_shape';
const SN_ANALYTICS_ROLLUP_TTL          = 15 * MINUTE_IN_SECONDS;  // freshness target for the admin warmer
const SN_ANALYTICS_ROLLUP_RETENTION    = DAY_IN_SECONDS;          // freshness stamp outlives the TTL
const SN_ANALYTICS_CLASSES             = array( 'human', 'suspect', 'bot' );
// Never-invert integrity alarm (Phase A spec §5): set by the upsert guard when
// a human row arrives with views < pageview_visits (arithmetically impossible —
// a genuine rollup/sampling bug), read by the Content-Health scan's
// analytics_integrity check (inc/health-analytics-integrity.php, v9.65.0).
// The row is still written un-clamped; the alarm is the feature.
const SN_ANALYTICS_INTEGRITY_ALERT_OPT = 'sn_analytics_integrity_alert';



// The rest of the data layer lives in four parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/analytics-rollup-schema-parts.php';
require_once __DIR__ . '/analytics-rollup-gated-parts.php';
require_once __DIR__ . '/analytics-rollup-pid-scroll.php';
require_once __DIR__ . '/analytics-rollup-upsert-parts.php';
require_once __DIR__ . '/analytics-rollup-run-parts.php';
