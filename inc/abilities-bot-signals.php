<?php
/**
 * The beacon bot-signals readout's nightly store (inc/analytics-bot-signals.php
 * computes it) and its reader, signal-noise/bot-signals, read through
 * sn-status{bot_signals}. Remote twin signal-noise/remote-bot-signals
 * (inc/abilities-remote-set.php) reads the same schema function below.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read AE and store the readout. Stores only on a real read: a failed query
 * keeps the last good reading. Runs with the nightly rollup.
 *
 * @return bool
 */
function sn_bot_signals_refresh() {
	if ( ! function_exists( 'sn_analytics_query' ) ) {
		return false;
	}
	// Analytics 2.0: across the clean day each generation is read for its side.
	// A visitor-day lives on one side, so the rows join; the split day's
	// signal count comes from both and is merged into one day.
	$from = sn_analytics_trailing_from( SNT_BOT_SIGNAL_DAYS );
	$now  = gmdate( 'Y-m-d H:i:s', sn_analytics_clock() );
	$sets = sn_analytics_stitched_rows( static fn( $s, $r ) => sn_bot_signals_sql( SNT_BOT_SIGNAL_DAYS, $s, $r ), $from, $now );
	$dset = null === $sets ? null : sn_analytics_stitched_rows( static fn( $s, $r ) => sn_bot_signals_days_sql( SNT_BOT_SIGNAL_DAYS, $s, $r ), $from, $now );
	if ( null === $sets || null === $dset ) {
		return false;
	}
	$rows = array_merge( ...$sets );
	$days = sn_bot_signals_days_merge( $dset );
	$readout = sn_bot_signals_readout( $rows, $days );
	// Truncated means a half reached its LIMIT, not that two halves add up past it.
	$readout['truncated'] = array() !== array_filter( $sets, static fn( $set ) => count( $set ) >= SNT_BOT_SIGNAL_LIMIT );
	update_option( SNT_BOT_SIGNAL_OPTION, $readout + array( 'measured_at' => time() ), false );
	return true;
}
if ( defined( 'SN_ANALYTICS_ROLLUP_DAILY_HOOK' ) ) {
	add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_bot_signals_refresh', 20 );
}

/**
 * The stored readout, or null when nothing has been measured.
 *
 * @return array|null
 */
function sn_bot_signals_stored() {
	$v = function_exists( 'get_option' ) ? get_option( SNT_BOT_SIGNAL_OPTION, null ) : null;
	return is_array( $v ) && isset( $v['cohorts'] ) ? $v : null;
}

/**
 * Execute callback. Never queries AE: the nightly rollup stores the reading.
 *
 * @return array
 */
function snt_ability_bot_signals() {
	$r = function_exists( 'sn_bot_signals_stored' ) ? sn_bot_signals_stored() : null;
	return is_array( $r ) ? array( 'measured' => true ) + $r : array( 'measured' => false );
}

/**
 * The payload's keys and types, shared by the admin registration and its
 * remote twin so the pair is byte-identical by construction. It hashes into
 * SN_REMOTE_CONTRACT_VERSION: change it and the contract moves.
 *
 * @return array
 */
function snt_bot_signals_output_schema() {
	$int = array( 'type' => 'integer' );
	return array(
		'type'       => 'object',
		'properties' => array(
			'measured'     => array( 'type' => 'boolean' ),
			'window_days'  => $int,
			'visitor_days' => $int,
			'truncated'    => array( 'type' => 'boolean' ),
			'days_present' => $int,
			'human'        => array( 'type' => 'object', 'properties' => array( 'visitor_days' => $int, 'likely_automated' => $int ) ),
			'cohorts'      => array( 'type' => 'object' ),
			'weights'      => array( 'type' => 'object' ),
			'threshold'    => $int,
			'subtracted'   => array( 'type' => 'boolean' ),
			'measured_at'  => $int,
		),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/bot-signals', array(
		'label'               => 'Beacon Bot Signals',
		'description'         => 'Observe-only readout of the beacon bot signals over the last 14 days, per visitor-day that carried them: human.visitor_days and human.likely_automated (score = sum of weights of fired bits >= threshold; NOT subtracted from any count), and per cohort (relay, intent, stored_bot, over_cap, hosting) n, fired and rate (percent of that cohort\'s visitor-days) for each signal: webdriver, no_input, ua_mismatch, headless, tz_mismatch. days_present counts UTC days carrying any signal row; truncated means the visitor-day list hit its row limit. measured:false means nothing has been stored yet. Read-only; stored nightly. Read it through sn-status{bot_signals}.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_bot_signals',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => snt_bot_signals_output_schema(),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ), // absorbed: read via sn-status{bot_signals}.
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
