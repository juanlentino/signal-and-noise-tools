<?php
/**
 * Signal & Noise — Measurement → Analytics tab.
 *
 * Native wp-admin surface (no theme vocabulary) for the first-party edge
 * analytics. Reads only the durable rollup accessors (never AE) so it never
 * blocks; shows a config/empty state until the Cloudflare creds + worker land.
 * v5.5.0: a persistent header (controls → separation → delta cards → trend) over
 * a WP-native tab strip (Content · Technology · Geography · Engagement · Quality · Events);
 * each tab lazily fetches only its own panels' data.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-view-body.php';
require_once __DIR__ . '/analytics-admin-error.php';

const SN_ANALYTICS_RANGES = array( 7, 14, 30, 90, 365 );

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order the code always ran.
require_once __DIR__ . '/analytics-admin-resolve-parts.php';
require_once __DIR__ . '/analytics-admin-dashboard-parts.php';
require_once __DIR__ . '/analytics-admin-settings-parts.php';
