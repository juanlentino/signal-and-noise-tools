<?php
/**
 * Recompute analytics history: re-roll every stored rollup and the session
 * daily rollup (engaged included) for the trailing 90 days through the SAME
 * run functions the nightly uses, so old days read under the current human
 * rule. Owner-run, background (chained WP-Cron single events), oldest first.
 *
 * 19.4.3: one tick is ONE unit (one rollup family for one 7-day batch, or one
 * session day), so no cron request is heavy. The cursor (done + step) lives in
 * the option, the unit is named in it before it runs, and a shutdown handler
 * records a fatal or an unfinished tick as partial. A run whose last_tick is
 * older than the stale window reads "stalled" and can be resumed from the
 * cursor. Strict mode (sn_analytics_strict) still stops a unit at the first
 * failed or truncated AE result before it writes; the cursor does not move.
 * Every unit's write replaces its own days (19.4.2), so re-running is safe.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ANALYTICS_RECOMPUTE_OPT   = 'sn_analytics_recompute';
const SNT_ANALYTICS_RECOMPUTE_HOOK  = 'sn_analytics_recompute_tick';
const SNT_ANALYTICS_RECOMPUTE_SPAN  = 90; // AE keeps ~92 days.
const SNT_ANALYTICS_RECOMPUTE_BATCH = 7;  // the nightly's proven window size.
const SNT_ANALYTICS_RECOMPUTE_STALE = 900; // a running state older than this is dead.
const SNT_ANALYTICS_RECOMPUTE_FAMILIES = array(
	'pageviews' => 'sn_analytics_pageviews_run_rollup',
	'dims'      => 'sn_analytics_dims_run_rollup',
	'utm'       => 'sn_analytics_utm_run_rollup',
	'buckets'   => 'sn_analytics_buckets_run_rollup',
	'pageroles' => 'sn_analytics_pageroles_run_rollup',
	'events'    => 'sn_analytics_events_run_rollup',
);

/**
 * The progress option, always in full shape. `step` is the unit index inside
 * the current batch; `unit` names the unit last started.
 *
 * @return array
 */
function sn_analytics_recompute_status() {
	$o = get_option( SNT_ANALYTICS_RECOMPUTE_OPT );
	return array_merge(
		array( 'state' => 'idle', 'done' => 0, 'total' => SNT_ANALYTICS_RECOMPUTE_SPAN, 'through' => '', 'error' => '', 'started' => 0, 'last_tick' => 0, 'step' => 0, 'unit' => '' ),
		is_array( $o ) ? $o : array()
	);
}

/**
 * Batch window for a given progress: days-ago [lower, until). PURE.
 *
 * @param int $done  Days already recomputed.
 * @param int $total Span in days.
 * @return array{days:int,until:int}
 */
function sn_analytics_recompute_batch( $done, $total ) {
	$lower = max( 1, (int) $total - (int) $done );
	return array( 'days' => $lower, 'until' => max( 0, $lower - SNT_ANALYTICS_RECOMPUTE_BATCH ) );
}

/**
 * The units of one batch, in order: the six rollup families, then one session
 * day each (days-ago; UTC days, today never complete). PURE.
 *
 * @param array{days:int,until:int} $b Batch window.
 * @return string[]
 */
function sn_analytics_recompute_units( array $b ) {
	$units = array_keys( SNT_ANALYTICS_RECOMPUTE_FAMILIES );
	for ( $ago = (int) $b['days']; $ago > (int) $b['until'] && $ago >= 1; $ago-- ) {
		$units[] = 'session:' . $ago;
	}
	return $units;
}

/** Stalled: running, but no tick has moved it within the stale window. */
function sn_analytics_recompute_stalled( array $st ) {
	return 'running' === $st['state'] && time() - (int) $st['last_tick'] >= SNT_ANALYTICS_RECOMPUTE_STALE;
}

/**
 * Start a fresh run, or resume a partial/stalled one from its cursor.
 *
 * @param bool $resume Continue from the cursor instead of day 0.
 * @return string Flash code.
 */
function sn_analytics_recompute_start( $resume = false ) {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return 'analytics_test_unconfigured';
	}
	$st = sn_analytics_recompute_status();
	if ( 'running' === $st['state'] && ! sn_analytics_recompute_stalled( $st ) ) {
		return 'analytics_recompute_busy';
	}
	$can_resume = $resume && ( 'partial' === $st['state'] || 'running' === $st['state'] );
	$next       = $can_resume
		? array_merge( $st, array( 'state' => 'running', 'error' => '', 'last_tick' => time() ) )
		: array( 'state' => 'running', 'done' => 0, 'total' => SNT_ANALYTICS_RECOMPUTE_SPAN, 'through' => '', 'error' => '', 'started' => time(), 'last_tick' => time(), 'step' => 0, 'unit' => '' );
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, $next, false );
	wp_clear_scheduled_hook( SNT_ANALYTICS_RECOMPUTE_HOOK );
	wp_schedule_single_event( time(), SNT_ANALYTICS_RECOMPUTE_HOOK );
	return $can_resume ? 'analytics_recompute_resumed' : 'analytics_recompute_started';
}

/** Put strict mode and the window override back to the nightly's defaults. */
function sn_analytics_recompute_reset_mode() {
	sn_analytics_rollup_window( false );
	sn_analytics_strict( 'off' );
}

/** Record the run as partial with a reason. */
function sn_analytics_recompute_mark_partial( $why ) {
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array_merge( sn_analytics_recompute_status(), array( 'state' => 'partial', 'error' => (string) $why, 'last_tick' => time() ) ), false );
}

/**
 * The unit the current request is inside ('' when none). The tick sets it
 * before a unit and clears it at its end marker; the shutdown handler reads it.
 *
 * @param string|null $set New value, or null to read.
 * @return string
 */
function sn_analytics_recompute_in_unit( $set = null ) {
	static $unit = '';
	if ( null !== $set ) {
		$unit = (string) $set;
	}
	return $unit;
}

/**
 * Shutdown handler: a tick that dies inside a unit (fatal, timeout) is written
 * down as partial, naming the unit and the error, instead of staying "running".
 *
 * @param array|null $err error_get_last() shape; tests pass one in.
 * @return void
 */
function sn_analytics_recompute_on_shutdown( $err = null ) {
	$unit = sn_analytics_recompute_in_unit();
	if ( '' === $unit ) {
		return; // the tick reached its end marker.
	}
	sn_analytics_recompute_in_unit( '' );
	$err   = func_num_args() ? $err : error_get_last();
	$fatal = is_array( $err ) && in_array( (int) ( $err['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
	$why   = $fatal
		? 'died in ' . $unit . ': ' . trim( (string) $err['message'] ) . ' (' . basename( (string) ( $err['file'] ?? '' ) ) . ':' . (int) ( $err['line'] ?? 0 ) . ')'
		: 'died in ' . $unit . ': the request ended before the unit finished (timeout or kill)';
	sn_analytics_recompute_reset_mode();
	sn_analytics_recompute_mark_partial( $why );
	error_log( '[sn-analytics] history recompute ' . $why );
}

/**
 * One unit: re-roll one family for the current batch, or one session day.
 *
 * @return void
 */
function sn_analytics_recompute_tick() {
	$st = sn_analytics_recompute_status();
	if ( 'running' !== $st['state'] ) {
		return;
	}
	$b     = sn_analytics_recompute_batch( $st['done'], $st['total'] );
	$units = sn_analytics_recompute_units( $b );
	$step  = min( max( 0, (int) $st['step'] ), count( $units ) - 1 );
	$unit  = $units[ $step ];

	// Name the unit BEFORE it runs, so a death names where it died.
	$st = array_merge( $st, array( 'unit' => $unit, 'step' => $step, 'last_tick' => time() ) );
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, $st, false );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore -- generous per unit; the shutdown handler is the real net.
	}
	sn_analytics_recompute_in_unit( $unit );
	register_shutdown_function( 'sn_analytics_recompute_on_shutdown' );

	// The cap list must be read and complete, or the unit would write uncapped
	// numbers over the old ones. Fresh once per batch; cached between its units.
	if ( 0 === $step ) {
		delete_transient( SNT_ANALYTICS_VDAY_CACHE_KEY );
	}
	$list    = sn_analytics_overcap_vdays();
	$tripped = empty( $list['ok'] ) ? 'the over-cap visitor-day list could not be read'
		: ( ! empty( $list['truncated'] ) ? 'more than ' . SNT_ANALYTICS_VDAY_LIST_MAX . ' over-cap visitor-days; the list is incomplete' : '' );
	if ( '' === $tripped ) {
		sn_analytics_strict( 'on' );
		sn_analytics_rollup_window( $b );
		if ( 0 === strpos( $unit, 'session:' ) ) {
			sn_session_rollup_run( gmdate( 'Y-m-d', time() - (int) substr( $unit, 8 ) * DAY_IN_SECONDS ) );
		} elseif ( function_exists( SNT_ANALYTICS_RECOMPUTE_FAMILIES[ $unit ] ) ) {
			call_user_func( SNT_ANALYTICS_RECOMPUTE_FAMILIES[ $unit ] );
		}
		$tripped = sn_analytics_strict()['tripped'];
		sn_analytics_recompute_reset_mode();
	}
	sn_analytics_recompute_in_unit( '' ); // end marker.

	if ( '' !== $tripped ) {
		sn_analytics_recompute_mark_partial( $unit . ': ' . $tripped . '; days from ' . gmdate( 'Y-m-d', time() - $b['days'] * DAY_IN_SECONDS ) . ' were not rewritten' );
		return;
	}
	$next = array_merge( $st, array( 'step' => $step + 1, 'last_tick' => time() ) );
	if ( $step + 1 >= count( $units ) ) {
		$done = min( (int) $st['total'], (int) $st['done'] + ( $b['days'] - $b['until'] ) );
		$next = array_merge( $next, array(
			'done'    => $done,
			'step'    => 0,
			'through' => gmdate( 'Y-m-d', time() - ( $b['until'] > 0 ? $b['until'] + 1 : 0 ) * DAY_IN_SECONDS ),
			'state'   => $done >= (int) $st['total'] ? 'done' : 'running',
		) );
	}
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, $next, false );
	if ( 'running' === $next['state'] ) {
		wp_schedule_single_event( time() + 5, SNT_ANALYTICS_RECOMPUTE_HOOK );
	}
}
add_action( SNT_ANALYTICS_RECOMPUTE_HOOK, 'sn_analytics_recompute_tick' );

/**
 * Admin-post handler (nonce + manage_options are enforced by the dispatcher).
 *
 * @param array $post Raw $_POST; mode=resume continues from the cursor.
 * @return string Flash code.
 */
function sn_handle_analytics_recompute( $post ) {
	return sn_analytics_recompute_start( 'resume' === ( is_array( $post ) ? (string) ( $post['mode'] ?? '' ) : '' ) );
}
