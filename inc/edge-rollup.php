<?php
/**
 * Signal & Noise — edge-analytics durable storage + daily GraphQL rollup.
 *
 * The companion to inc/edge-analytics.php (the GraphQL client). Two tables mirror
 * the AE dims/daily split:
 *   - sn_edge_daily — one EXACT row per UTC day (httpRequests1dGroups): requests,
 *     cache, bandwidth, threats, pageViews, and the 2xx/3xx/4xx/5xx status buckets.
 *   - sn_edge_dims  — (day, dim, value) breakdowns: country (from the daily map),
 *     plus today's sampled colo + threat detail.
 *
 * A daily WP-Cron poll re-pulls the trailing ~13 months of 1dGroups (exact,
 * idempotent overwrite — the first run back-fills) and a trailing adaptive snapshot
 * (24h by default, clamped to the node's discovered retention) of the two adaptive
 * datasets (Cloudflare's own sampled estimates, taken as reported since 17.9.1). Dormant until the GraphQL
 * client is configured; a failed query is skipped, never fatal.
 *
 * @package SignalNoiseTools
 * @since 6.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/edge-error-labels.php'; // The 5xx responder rows in words (short labels and their legend).

const SN_EDGE_DAILY_TABLE      = 'sn_edge_daily';
const SN_EDGE_DIMS_TABLE       = 'sn_edge_dims';
const SN_EDGE_DB_VERSION       = '1';
const SN_EDGE_DB_VERSION_OPT   = 'sn_edge_db_version';
const SN_EDGE_BACKFILL_DAYS    = 395; // ~13 months — inside httpRequests1dGroups retention.
const SN_EDGE_ROLLUP_HOOK      = 'sn_edge_rollup_cron';
const SN_EDGE_RESAMPLE_HOOK    = 'sn_edge_resample_repair'; // 17.9.1: one-shot.
const SN_EDGE_HONEST_FROM_OPT  = 'sn_edge_honest_from';      // First day the sampled dims count honestly.
const SN_EDGE_RESAMPLE_LOCK    = 'sn_edge_resample_running'; // 17.9.2: one repair in flight.
const SN_EDGE_ERRORS_QUERY_OPT = 'sn_edge_errors_query_last'; // 17.9.3: the errors query's last outcome.
const SN_EDGE_ERRORS_READ_OPT  = 'sn_edge_errors_read_days';  // 18.3.0: day => '' (read) or the refusal, per rollup run.

// The rest of the module lives in four parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/edge-rollup-schema-parts.php';
require_once __DIR__ . '/edge-rollup-upsert-parts.php';
require_once __DIR__ . '/edge-rollup-run-parts.php';
require_once __DIR__ . '/edge-rollup-errors-parts.php';
