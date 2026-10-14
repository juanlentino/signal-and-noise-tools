<?php
/**
 * Signal & Noise Tools — Analytics view: Overview (v9.68.0) — the WIRED
 * landing surface. The v9.67.0 flag-gated static mock, graduated: real data,
 * shared chrome, DEFAULT tab.
 *
 * Chrome: this view INHERITS the shared header (range/class/compare controls,
 * insights band, the shared Overview KPI card — which already speaks the
 * v9.63 honest vocabulary — and the movers rail). The mock's duplicate
 * "Headline" panel is gone: the shared card IS the headline. This body renders
 * only what the header does not: session quality, realtime, and the
 * acquisition/audience/page-role minis.
 *
 * LOAD-COST RULE (hard): the default landing renders on every Analytics
 * visit, so this body reads ONLY durable rollup tables (wp_sn_session_daily,
 * wp_sn_analytics_dims, wp_sn_analytics_utm, wp_sn_analytics_pageroles) and
 * the cron-warmed realtime transient. No live Analytics Engine call happens
 * on render — the session engine's capped live fetch stays on the Sessions
 * tab, where paying that cost is a deliberate click.
 *
 * NULL DISCIPLINE per panel — exactly what each one distinguishes:
 *  - Session quality (KPIs + trend): its accessor consults $wpdb->last_error
 *    itself and returns null on a FAILED read vs [] for an empty window, so
 *    failure renders "could not be read" (the v9.65.0 lesson — never served
 *    as an empty week) and [] folds honestly.
 *  - The six minis (sources, UTM, geography, devices, entry, exit): since
 *    v9.68.1 their accessors speak the SAME contract (null = failed wpdb
 *    read, [] = empty window — upgraded at the source, so the OLDER tabs
 *    inherit the honesty too), so this view simply resolves the accessor's
 *    own verdict: null renders that panel's "could not be read" fold, []
 *    folds as an honest empty window. (The v9.68.0 view-local last_error +
 *    num_queries bracket this view carried while the accessors conflated the
 *    two is retired — the accessor verdict is the one failure signal.)
 *  - Right now: a cold cron-warmed transient is "warming", never a fabricated
 *    0, and a warmed 0 is a real 0 — but a transient read has no failure
 *    channel, so "warming" honestly covers never-warmed and lost alike.
 *
 * ATTENTION LAYER (v9.69.0, owner-approved): every panel's headline movement
 * is judged vs the PREVIOUS period on EVERY render — compare Off included
 * (that is the feature's point; the header's compare control governs only the
 * visible delta chips). Prior windows come from the real
 * snt_analytics_compare_window(...,'prev') — zero new date math — and the
 * prior reads stay durable-table-only, bounded like the v9.68.0 compare reads.
 * Flagged panels wear the amber NOTABLE chip (header_meta seam, beside the
 * doorway) and are named in one needs-attention strip at the very top of the
 * body, with in-page anchor links. Reordering (the approved AFTER geometry,
 * generalized): Session quality ALWAYS first; flagged minis promote out of
 * the bento to full width directly beneath it, in canonical relative order;
 * entry/exit promote as a PAIR if either flags; Right now never promotes
 * (instantaneous — no prior period exists for it). Thresholds, sentiment
 * table and null discipline live in inc/analytics-overview-attention.php.
 * DOCUMENTED CHOICE — a FAILED prior read is attention UNKNOWN: no chip, no
 * strip mention, and no false "all calm" claim either; unknown renders as
 * silence, byte-identical to a quiet week, because claiming either state
 * would fabricate knowledge. TOTAL COLLAPSE (review r1 F1): an EMPTY current
 * window whose read SUCCEEDED is an ANSWER — 0 recorded — so a prior window
 * that cleared the views floor flags it ("views N → none recorded"). The
 * panel itself folds, so that flag is STRIP-ONLY: a plain flag, no anchor, no
 * chip, no promotion — the strip informs without a panel target. A FAILED
 * current read stays silent (unknown — no real 0 to claim). QUIET-WEEK
 * SHIELD: when nothing flags, the body is byte-identical to the v9.68.1
 * output (golden-pinned in tests).
 *
 * Composition: existing snt_an_* primitives + the existing dim/pageroles
 * table renderers. Light-only, no JS, no <wpd-*> — a wp-admin view, not a
 * desktop-mode window (widgets stay widgets).
 *
 * @package SignalNoiseTools
 * @since 9.68.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';        // panel chrome + KPI row + trend + empty-fold primitives
require_once __DIR__ . '/analytics-render-tables.php'; // snt_analytics_render_dim_table + snt_analytics_render_pageroles_table
require_once __DIR__ . '/analytics-overview-attention.php'; // v9.69.0 attention signals + chip + strip (pure)

// The session-quality bounce trend reads a fixed-LENGTH trailing window (8 ISO
// weeks) ANCHORED at the range control's end date ($to) — deliberate: a
// historical range shows the 8 weeks leading up to ITS end, so the trend stays
// coherent with the header window. Long enough to show drift, short enough to
// stay glanceable; the panel label renders the actual endpoint.
const SN_OVERVIEW_TREND_WEEKS = 8;

// Prior-window read depth for the mini-table compare (v9.68.0 part 4) — WIDER
// than any visible top-N so a row at prior rank 6 is matched by key instead of
// misread as "new". The movers idiom (sn_analytics_movers_uncached reads both
// windows at 50 and diffs in memory); durable-table reads, so depth is cheap.
const SN_OVERVIEW_PRIOR_LIMIT = 50;

// The rest of the view lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran.
require_once __DIR__ . '/analytics-view-overview-model-parts.php';
require_once __DIR__ . '/analytics-view-overview-panels-parts.php';
require_once __DIR__ . '/analytics-view-overview-render-parts.php';
