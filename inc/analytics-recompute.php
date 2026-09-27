<?php
/**
 * Recompute analytics history: re-roll every stored rollup and the session
 * daily rollup (engaged included) for the trailing 90 days through the SAME
 * run functions the nightly uses, so old days read under the current human
 * rule. Owner-run, background (chained WP-Cron single events), one 7-day
 * batch per tick, oldest first. Strict mode (sn_analytics_strict) stops a
 * batch at the first failed or truncated AE result before it writes; the run
 * is then "partial" and says why. Every write is an idempotent per-day UPSERT,
 * so re-running is safe.
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

/**
 * The progress option, always in full shape.
 *
 * @return array{state:string,done:int,total:int,through:string,error:string,started:int,last_tick:int}
 */
function sn_analytics_recompute_status() {
	$o = get_option( SNT_ANALYTICS_RECOMPUTE_OPT );
	return array_merge(
		array( 'state' => 'idle', 'done' => 0, 'total' => SNT_ANALYTICS_RECOMPUTE_SPAN, 'through' => '', 'error' => '', 'started' => 0, 'last_tick' => 0 ),
		is_array( $o ) ? $o : array()
	);
}

/**
 * The batch for a given progress: days-ago window [lower, until). Oldest first;
 * the last batch has until 0 (no upper bound, reaches today). PURE.
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
 * Start a run (the admin-post handler). Refuses while one is live.
 *
 * @return string Flash code.
 */
function sn_analytics_recompute_start() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return 'analytics_test_unconfigured';
	}
	$st = sn_analytics_recompute_status();
	if ( 'running' === $st['state'] && time() - (int) $st['last_tick'] < SNT_ANALYTICS_RECOMPUTE_STALE ) {
		return 'analytics_recompute_busy';
	}
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array( 'state' => 'running', 'done' => 0, 'total' => SNT_ANALYTICS_RECOMPUTE_SPAN, 'through' => '', 'error' => '', 'started' => time(), 'last_tick' => time() ), false );
	wp_clear_scheduled_hook( SNT_ANALYTICS_RECOMPUTE_HOOK );
	wp_schedule_single_event( time(), SNT_ANALYTICS_RECOMPUTE_HOOK );
	return 'analytics_recompute_started';
}

/**
 * One batch: re-roll its days through the nightly run functions, in strict mode.
 *
 * @return void
 */
function sn_analytics_recompute_tick() {
	$st = sn_analytics_recompute_status();
	if ( 'running' !== $st['state'] ) {
		return;
	}
	$fail = static function ( $why ) use ( $st ) {
		update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array_merge( $st, array( 'state' => 'partial', 'error' => (string) $why, 'last_tick' => time() ) ), false );
	};
	// The cap list must be read and complete, or the batch would write
	// uncapped numbers over the old ones. Read it fresh for every batch.
	delete_transient( SNT_ANALYTICS_VDAY_CACHE_KEY );
	$list = sn_analytics_overcap_vdays();
	if ( empty( $list['ok'] ) ) {
		$fail( 'the over-cap visitor-day list could not be read' );
		return;
	}
	if ( ! empty( $list['truncated'] ) ) {
		$fail( 'more than ' . SNT_ANALYTICS_VDAY_LIST_MAX . ' over-cap visitor-days; the list is incomplete' );
		return;
	}

	$b = sn_analytics_recompute_batch( $st['done'], $st['total'] );
	sn_analytics_strict( 'on' );
	sn_analytics_rollup_window( $b );
	sn_analytics_run_rollup();
	// Session days are UTC days; today is never complete, so stop at yesterday.
	for ( $ago = $b['days']; $ago > $b['until'] && $ago >= 1 && '' === sn_analytics_strict()['tripped']; $ago-- ) {
		sn_session_rollup_run( gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS ) );
	}
	$tripped = sn_analytics_strict()['tripped'];
	sn_analytics_rollup_window( false );
	sn_analytics_strict( 'off' );

	if ( '' !== $tripped ) {
		$fail( $tripped . '; days from ' . gmdate( 'Y-m-d', time() - $b['days'] * DAY_IN_SECONDS ) . ' were not rewritten' );
		return;
	}
	$done = min( (int) $st['total'], (int) $st['done'] + ( $b['days'] - $b['until'] ) );
	$next = array_merge( $st, array(
		'done'      => $done,
		'through'   => gmdate( 'Y-m-d', time() - ( $b['until'] > 0 ? $b['until'] + 1 : 0 ) * DAY_IN_SECONDS ),
		'state'     => $done >= (int) $st['total'] ? 'done' : 'running',
		'last_tick' => time(),
	) );
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, $next, false );
	if ( 'running' === $next['state'] ) {
		wp_schedule_single_event( time() + 5, SNT_ANALYTICS_RECOMPUTE_HOOK );
	}
}
add_action( SNT_ANALYTICS_RECOMPUTE_HOOK, 'sn_analytics_recompute_tick' );

/**
 * Admin-post handler (nonce + manage_options are enforced by the dispatcher).
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_analytics_recompute( $post ) {
	unset( $post );
	return sn_analytics_recompute_start();
}

/**
 * The status line beside the button. PURE over the status array.
 *
 * @param array $st sn_analytics_recompute_status() shape.
 * @return string
 */
function sn_analytics_recompute_line( array $st ) {
	$n = sprintf( '%d of %d days', (int) $st['done'], (int) $st['total'] );
	switch ( $st['state'] ) {
		case 'running':
			return 'Recomputing history: ' . $n . ' done.';
		case 'partial':
			return 'Recompute stopped at ' . $n . ': ' . $st['error'] . '. Run it again to retry.';
		case 'done':
			return 'Recomputed through ' . $st['through'] . ', ' . $n . '.';
	}
	return 'History before the current rule was set has not been recomputed.';
}

/**
 * One button + status line, printed under the human-rule note.
 *
 * @return void
 */
function snt_analytics_render_recompute() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$st = sn_analytics_recompute_status();
	echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'analytics_recompute' ) ) . '">';
	wp_nonce_field( 'sn_analytics_recompute' );
	echo '<p class="sn-an-visitor-note">' . esc_html( sn_analytics_recompute_line( $st ) ) . ' ';
	echo '<button type="submit" name="action" value="sn_analytics_recompute" class="button button-small"' . ( 'running' === $st['state'] ? ' disabled' : '' ) . '>' . esc_html__( 'Recompute analytics history', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}
