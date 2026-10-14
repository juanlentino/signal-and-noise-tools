<?php
/**
 * Signal & Noise Tools — sn_apply gates 1 (fingerprint) and 2 (server-side
 * validation). MCP consolidation session 6b.
 *
 * Split out of inc/sn-apply/executors.php purely for the 450-line file
 * budget — its parts files still belong to the same per-change-type
 * executor layer (inc/sn-apply/executors.php holds target resolution, the
 * mode-support matrix, and the write dispatch; inc/abilities-sn-apply.php
 * orchestrates all four gates in order).
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v13.49.0. schedule_cron_event's delay ceiling, in seconds. Declared HERE
// rather than reused from WP because this module is deliberately free of
// WordPress constants — every gate in it loads under the standalone test
// harness, where HOUR_IN_SECONDS does not exist. snt_cron_schedule_event_impl()
// clamps to the same ceiling via HOUR_IN_SECONDS, and a test pins the two equal
// so they cannot drift apart silently.
const SNT_SN_APPLY_CRON_DELAY_MAX = 3600;

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran, after the delay ceiling above.
require_once __DIR__ . '/validation-fingerprint-parts.php';
require_once __DIR__ . '/validation-gate2-types-parts.php';
require_once __DIR__ . '/validation-gate2-parts.php';
