<?php
/**
 * Signal & Noise Tools — Cloudflare worker rows for Deploy Status.
 *
 * Extends the theme/plugin deploy-status surface (inc/admin-tab-dashboard.php
 * + signal-noise/get-deploy-status) with the five owned edge workers so an
 * undeployed or unreachable worker shows as an honest UNKNOWN row rather
 * than disappearing from the panel.
 *
 * Per worker:
 *   - latest = highest vX.Y.Z GitHub TAG (releases stay DRAFT forever here,
 *     so /releases/latest is empty — same tags approach as sn_gh_latest_plugin_tag).
 *   - live   = probed version from the worker's public status/version route,
 *     transient-cached. probe_url null → live "unprobeable" (no route), never
 *     fabricated. Probe failure → state "unknown", never "ok".
 *   - state  = ok | behind | unknown
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Live-probe success TTL — readout freshness, not monitoring. */
const SNT_DEPLOY_WORKER_LIVE_TTL_OK = 600; // 10 min: slack for a late WP-Cron run. Freshness comes from the warm, which probes every five minutes whatever the cache says.

/** Live-probe failure TTL — retry sooner after a miss. */
const SNT_DEPLOY_WORKER_LIVE_TTL_FAIL = 120; // 2 min; mirrors SN_WORKER_VERSION_TTL_FAIL.

/** At most one early tag re-fetch per worker in this window (see snt_deploy_worker_tag_is_stale()). */
const SNT_DEPLOY_WORKER_TAG_REFETCH_TTL = 5 * MINUTE_IN_SECONDS;

/** GitHub tags cache TTL — mirrors SN_GH_PLUGIN_CACHE_TTL. */
const SNT_DEPLOY_WORKER_TAG_TTL = HOUR_IN_SECONDS;

/** Short timeout so a cold dashboard never hangs on five edge calls. */
const SNT_DEPLOY_WORKER_TIMEOUT = 4;

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/deploy-workers-registry-parts.php';
require_once __DIR__ . '/deploy-workers-probe-parts.php';
require_once __DIR__ . '/deploy-workers-status-parts.php';
