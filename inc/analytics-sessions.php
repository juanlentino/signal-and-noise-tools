<?php
/**
 * Signal & Noise — cookieless within-day session engine (v8.8.0).
 *
 * Reads the existing sn_pageviews Analytics Engine data as WITHIN-DAY visits.
 * index1 (the daily-rotating visitor hash) may be grouped ONLY inside a single
 * UTC day — never across days (the salt rotates at UTC midnight, so cross-day
 * stitching is impossible by construction). No cookie, no device storage, no
 * consent trigger. This is the deliberate, documented revision of the prior
 * "index1 is count-only" principle.
 *
 * Pure transforms only — NO top-level add_action/add_filter, so the module loads
 * standalone under the CLI test harness.
 *
 * @package SignalNoiseTools
 * @since 8.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

require_once __DIR__ . '/analytics-human-rule.php'; // the ONE counted-human rule

const SN_ANALYTICS_SESSION_GAP_SEC     = 1800;  // >30 min idle starts a new visit.
const SN_ANALYTICS_SESSION_ENGAGED_PCT = 50;    // engaged read: scroll depth % floor.
const SN_ANALYTICS_SESSION_ENGAGED_MS  = 15000; // engaged read: dwell ms floor.
const SN_ANALYTICS_SESSION_ROW_CAP     = 50000; // max raw rows pulled per window.

// S2 §3 (settings-defined funnels): textarea-parser caps. Kept small — a
// funnel list is a curated set of named conversion paths, not an open log.
const SN_ANALYTICS_FUNNELS_MAX       = 10; // max funnels the parser will accept.
const SN_ANALYTICS_FUNNELS_MAX_STEPS = 8;  // max steps per funnel.

// Reason-surfacing task: the closed six-kind enum for sn_analytics_parse_funnels()
// errors. Order is the STABLE encoding sn_handle_analytics_funnels_save()
// (inc/admin-post-actions/analytics.php) packs into the flash code's <line>k<kindIndex>
// pairs, and inc/admin-flash-messages.php decodes back into a reason line —
// append new kinds at the END only; never reorder or remove an entry, or an
// already-redirected flash code would decode to the WRONG reason.
const SN_ANALYTICS_FUNNELS_ERR_KINDS = array( 'colon', 'name', 'long', 'step', 'few', 'many' );

// The rest of the engine lives in four parts files (house line cap, 2026-10-14),
// loaded in the order it always ran.
require_once __DIR__ . '/analytics-sessions-config-parts.php';
require_once __DIR__ . '/analytics-sessions-funnel-parse-parts.php';
require_once __DIR__ . '/analytics-sessions-visits-parts.php';
require_once __DIR__ . '/analytics-sessions-reports-parts.php';
