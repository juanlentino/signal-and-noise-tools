<?php
/**
 * SN Traffic Lanes, Phase 1: the lanes table and its nightly rollup.
 *
 * Every beacon page view and every machine read lands in exactly one lane per
 * day (docs/plans/traffic-lanes-2026-10-15.md, the mapping table and advisor
 * checkpoint 3). The lane is derived at READ time from stored fields under
 * `lane_rule_version`; a stored day whose rule version differs from the
 * current one is never re-rolled, so a rule change never relabels history.
 *
 *   beacon sensor   public pageviews (stitched, the house dataset choice) plus
 *                   `sn_owner_hits`; site-local days; `hits` = page views, plus
 *                   visits (visitor-days with a page view) and visitor_days
 *                   (every visitor-day in the row).
 *   machine sensor  the rights-signals aggregate view; `hits` = reads. Its day
 *                   is the sensor's UTC date (toDate(timestamp)): the view
 *                   carries no time of day, so no conversion can recover a
 *                   site-local one. Visits and visitor_days are NULL there.
 *
 * Key columns are NOT NULL DEFAULT '' (a NULL in a unique key never collides);
 * figures are NULL where a sensor does not measure them. Nothing here changes
 * any existing table, figure or default.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LANES_TABLE          = 'sn_analytics_lanes';
const SN_LANES_DB_VERSION     = '1';
const SN_LANES_DB_VERSION_OPT = 'sn_analytics_lanes_db_version';
const SN_LANE_RULE_VERSION    = 1;
const SN_LANES_SINCE_OPT      = 'sn_analytics_lanes_since'; // { beacon: Y-m-d, machine: Y-m-d }, set from evidence only.
const SN_LANES_LAST_RUN_OPT   = 'sn_analytics_lanes_last_run'; // { sensor: { at, outcome } }: the report tells "never ran" from "nothing stored".
const SN_LANES_OWNER_DATASET  = 'sn_owner_hits';            // the analytics Worker's DATASETS.owner.
const SN_LANES_WINDOW_DAYS    = 7;                          // trailing complete days each run writes.
const SN_LANES                = array( 'owner', 'owner_agent', 'unverified_owner', 'own_infra', 'verified_bot', 'claimed_bot', 'unknown_machine', 'suspected_automation', 'human' );
// The contract's identity codes 1 to 5 (code 0 is "none"): lane and reason.
const SN_LANES_IDENTITY = array(
	1 => array( 'owner', 'owner_device' ),
	2 => array( 'owner_agent', 'owner_token' ),
	3 => array( 'unverified_owner', 'owner_token_invalid' ),
	4 => array( 'unverified_owner', 'logged_in' ),
	5 => array( 'unverified_owner', 'owner_legacy' ),
	6 => array( 'own_infra', 'first_party' ), // x-sn-first-party header (analytics worker, 2026-10-19).
);

require_once __DIR__ . '/analytics-lanes-beacon.php';
require_once __DIR__ . '/analytics-lanes-machine.php';
require_once __DIR__ . '/analytics-lanes-store.php';
require_once __DIR__ . '/analytics-lanes-read.php';
require_once __DIR__ . '/analytics-lanes-view.php';

/**
 * The rule version rows are written under. `$set` is the test seam that
 * plants a bump; 0 releases it.
 *
 * @param int|null $set Version to hold.
 * @return int
 */
function sn_lanes_rule_version( $set = null ) {
	static $v = 0;
	if ( null !== $set ) {
		$v = max( 0, (int) $set );
	}
	return $v > 0 ? $v : SN_LANE_RULE_VERSION;
}

/**
 * An agent code as its allowlist label ('' for 0 or an unknown code). The
 * list is #2101's sn_owner_agents(), in code order. PURE.
 *
 * @param mixed $code Agent code.
 * @return string
 */
function sn_lanes_agent_label( $code ) {
	$agents = function_exists( 'sn_owner_agents' ) ? sn_owner_agents() : array();
	return (string) ( $agents[ (int) $code - 1 ] ?? '' );
}

/**
 * dbDelta CREATE TABLE. PURE.
 *
 * @return string
 */
function sn_lanes_schema_sql() {
	global $wpdb;
	return "CREATE TABLE {$wpdb->prefix}" . SN_LANES_TABLE . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		sensor VARCHAR(8) NOT NULL DEFAULT '',
		lane VARCHAR(24) NOT NULL DEFAULT '',
		reason VARCHAR(24) NOT NULL DEFAULT '',
		agent VARCHAR(20) NOT NULL DEFAULT '',
		family VARCHAR(32) NOT NULL DEFAULT '',
		purpose VARCHAR(16) NOT NULL DEFAULT '',
		hits INT UNSIGNED NULL DEFAULT NULL,
		visits INT UNSIGNED NULL DEFAULT NULL,
		visitor_days INT UNSIGNED NULL DEFAULT NULL,
		rule_version INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY lane_key (day, sensor, lane, reason, agent, family, purpose)
	) {$wpdb->get_charset_collate()};";
}

/** Create or upgrade the table (the house install idiom). */
function sn_lanes_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_lanes_schema_sql() );
	update_option( SN_LANES_DB_VERSION_OPT, SN_LANES_DB_VERSION );
}

/** One option compare per request; install only on the delta. */
function sn_lanes_maybe_install() {
	if ( get_option( SN_LANES_DB_VERSION_OPT ) !== SN_LANES_DB_VERSION ) {
		sn_lanes_install();
	}
}
add_action( 'init', 'sn_lanes_maybe_install' );

/**
 * Per-sensor `lanes_since`, or one sensor's day. Set only from evidence, and
 * only ever moved earlier.
 *
 * @param string      $sensor 'beacon' | 'machine' | '' for both.
 * @param string|null $day    Evidence day to record.
 * @return array<string,string>|string
 */
function sn_lanes_since( $sensor = '', $day = null ) {
	$s = get_option( SN_LANES_SINCE_OPT, array() );
	$s = is_array( $s ) ? $s : array();
	if ( null !== $day && in_array( $sensor, array( 'beacon', 'machine' ), true ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
		$have = (string) ( $s[ $sensor ] ?? '' );
		if ( '' === $have || (string) $day < $have ) {
			$s[ $sensor ] = (string) $day;
			update_option( SN_LANES_SINCE_OPT, $s, false );
		}
	}
	$out = array( 'beacon' => (string) ( $s['beacon'] ?? '' ), 'machine' => (string) ( $s['machine'] ?? '' ) );
	return '' === $sensor ? $out : ( $out[ $sensor ] ?? '' );
}

/**
 * Record a sensor's last run outcome, or read them all.
 *
 * @param string      $sensor  'beacon' | 'machine' | '' to read.
 * @param string|null $outcome wrote | not_written | read_failed | truncated | empty | overcap_failed.
 * @return bool|array<string,array{at:int,outcome:string}> True for 'wrote' when recording.
 */
function sn_lanes_last_run( $sensor = '', $outcome = null ) {
	$all = get_option( SN_LANES_LAST_RUN_OPT, array() );
	$all = is_array( $all ) ? $all : array();
	if ( null === $outcome ) {
		return $all;
	}
	$all[ (string) $sensor ] = array( 'at' => time(), 'outcome' => (string) $outcome );
	update_option( SN_LANES_LAST_RUN_OPT, $all, false );
	return 'wrote' === $outcome;
}

/**
 * The nightly pass, after the analytics rollup (10) and bot signals (20).
 * Each sensor writes on its own: one failing never blocks the other.
 */
function sn_lanes_run_rollup() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return;
	}
	// The human lane is read under the over-cap list, as every human reader is.
	// A list that did not read would store over-cap visitor-days as human, so
	// the beacon pass is skipped (and says so); the machine pass needs no list.
	if ( empty( sn_analytics_overcap_vdays()['ok'] ) ) {
		error_log( '[sn-lanes] beacon skipped: the over-cap visitor-day list did not read; the stored days are kept' );
		sn_lanes_last_run( 'beacon', 'overcap_failed' );
	} else {
		sn_lanes_beacon_run();
	}
	sn_lanes_machine_run();
}
if ( defined( 'SN_ANALYTICS_ROLLUP_DAILY_HOOK' ) ) {
	add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_lanes_run_rollup', 30 );
}
