<?php
/**
 * Signal & Noise Tools: the purge ledger, and the rules that keep purges rare.
 *
 * Owner, 2026-10-03: "Cache purges shouldn't happen as often as are happening
 * here. Ever." One install fired three full purges (Breeze's own update hook,
 * the theme's update purge, this plugin's version-change rollover), each
 * emptying all of Redis, and there was no record of any of it. This module:
 *
 *   - keeps a ring of the last SNT_PURGE_LEDGER_CAP purges: when, what asked,
 *     what was cleared, and what Cloudways answered;
 *   - removes Breeze's own update purge (owner's call; the theme's update
 *     purge replaces it without a Redis flush, theme 14.10.0);
 *   - tells the Cloudways leg when to stand down (snt_purge_wants_app_purge()).
 *
 * @package SignalNoiseTools
 * @since   20.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PURGE_LEDGER_OPTION = 'snt_purge_ledger';
const SNT_PURGE_LEDGER_CAP    = 50;

/**
 * Append one row, newest first, capped. Rows are small arrays of scalars.
 *
 * @param array $row The row.
 * @return void
 */
function snt_purge_ledger_add( array $row ) {
	$rows = snt_purge_ledger_rows();
	array_unshift( $rows, array_merge( array( 'time' => time() ), $row ) );
	update_option( SNT_PURGE_LEDGER_OPTION, array_slice( $rows, 0, SNT_PURGE_LEDGER_CAP ), false );
}

/**
 * The stored rows, newest first.
 *
 * @return array<int,array>
 */
function snt_purge_ledger_rows() {
	$rows = get_option( SNT_PURGE_LEDGER_OPTION, array() );
	return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
}

/**
 * What asked for a purge when the caller did not say: the theme passes
 * `trigger` since 14.10.0; an older theme, Breeze, or a cron leaves it out.
 *
 * @param array $args The chain's args.
 * @return string
 */
function snt_purge_trigger( array $args = array() ) {
	if ( ! empty( $args['trigger'] ) ) {
		return (string) $args['trigger'];
	}
	if ( function_exists( 'doing_action' ) && doing_action( 'upgrader_process_complete' ) ) {
		return 'update';
	}
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return 'cron:' . (string) current_action();
	}
	return 'manual';
}

/**
 * Open the chain's row (theme sn_before_cache_flush). Stashed in a global,
 * closed by snt_purge_ledger_close().
 *
 * @param array $args The chain's args.
 * @return void
 */
function snt_purge_ledger_open( $args = array() ) {
	$args                          = is_array( $args ) ? $args : array();
	$GLOBALS['snt_purge_current'] = array(
		'trigger' => snt_purge_trigger( $args ),
		'redis'   => ! array_key_exists( 'object_cache', $args ) || ! empty( $args['object_cache'] ),
		'pages'   => ! array_key_exists( 'origin_html', $args ) || ! empty( $args['origin_html'] ),
		'edge'    => ! array_key_exists( 'cloudflare', $args ) || ! empty( $args['cloudflare'] ),
		'started' => time(),
	);
}
add_action( 'sn_before_cache_flush', 'snt_purge_ledger_open', 1, 1 );

/**
 * Close it (theme sn_after_full_cache_flush): one row, with the Cloudways
 * leg's own answer when it ran in this request.
 *
 * @return void
 */
function snt_purge_ledger_close() {
	$row = $GLOBALS['snt_purge_current'] ?? null;
	unset( $GLOBALS['snt_purge_current'] );
	if ( ! is_array( $row ) ) {
		return;
	}
	$row['cloudways'] = $GLOBALS['snt_purge_cloudways'] ?? 'not run';
	unset( $row['started'], $GLOBALS['snt_purge_cloudways'] );
	snt_purge_ledger_add( $row );
}
add_action( 'sn_after_full_cache_flush', 'snt_purge_ledger_close', 99, 0 );

/**
 * Whether the Cloudways app purge should run now. It clears Varnish AND all
 * of Redis, so only an explicit purge-everything asks for it: the manual
 * button, or a chain that flushes the object cache anyway. An update or a
 * styles save does not (Varnish never served this site's pages, measured
 * 2026-10-03: every repeat request read x-cache MISS). Breeze's nightly
 * purge never does: the owner turned it off, and if the setting comes back
 * this keeps it from emptying Redis every night.
 *
 * @return bool
 */
function snt_purge_wants_app_purge() {
	if ( function_exists( 'doing_action' ) && doing_action( 'breeze_purge_cache' ) ) {
		return false;
	}
	$current = $GLOBALS['snt_purge_current'] ?? null;
	return ! is_array( $current ) || ! empty( $current['redis'] );
}

/**
 * 5a (owner, 2026-10-03): remove Breeze's own update purge. Breeze 2.6.0
 * hooks Breeze_Bulk_Update::breeze_after_plugin_bulk_upgrade on
 * upgrader_process_complete, and it fires breeze_clear_all_cache, which ends
 * in wp_cache_flush(). The instance is anonymous, so find it by class.
 *
 * @return bool Whether it was found and removed.
 */
function snt_purge_remove_breeze_update_purge() {
	global $wp_filter;
	$hook = $wp_filter['upgrader_process_complete'] ?? null;
	if ( ! $hook || ! isset( $hook->callbacks ) ) {
		return false;
	}
	foreach ( $hook->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'] ?? null;
			if ( is_array( $fn ) && is_object( $fn[0] ) && is_a( $fn[0], 'Breeze_Bulk_Update' ) && 'breeze_after_plugin_bulk_upgrade' === ( $fn[1] ?? '' ) ) {
				return remove_action( 'upgrader_process_complete', $fn, $priority );
			}
		}
	}
	return false;
}
add_action( 'plugins_loaded', 'snt_purge_remove_breeze_update_purge', 20 );

/**
 * Whether Breeze's update purge is still hooked (the ledger's status line).
 *
 * @return bool
 */
function snt_purge_breeze_update_purge_hooked() {
	global $wp_filter;
	foreach ( (array) ( $wp_filter['upgrader_process_complete']->callbacks ?? array() ) as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'] ?? null;
			if ( is_array( $fn ) && is_object( $fn[0] ) && is_a( $fn[0], 'Breeze_Bulk_Update' ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * The ledger's reading: counts for the last 7 days by trigger, the rows,
 * and the two settings that keep purges rare.
 *
 * @param int|null $now Unix time (test seam).
 * @return array
 */
function snt_purge_ledger_summary( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$rows  = snt_purge_ledger_rows();
	$week  = array_filter( $rows, static fn( $r ) => (int) ( $r['time'] ?? 0 ) >= $now - 7 * DAY_IN_SECONDS );
	$count = array();
	foreach ( $week as $r ) {
		$t           = (string) ( $r['trigger'] ?? '?' );
		$count[ $t ] = ( $count[ $t ] ?? 0 ) + 1;
	}
	return array(
		'last_7_days'         => count( $week ),
		'by_trigger'          => $count,
		'redis_flushes_7d'    => count( array_filter( $week, static fn( $r ) => ! empty( $r['redis'] ) ) ),
		'breeze_update_purge' => snt_purge_breeze_update_purge_hooked() ? 'hooked' : 'removed',
		'breeze_nightly'      => function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'breeze_purge_cache' ) ? 'scheduled' : 'off',
		'rows'                => array_slice( $rows, 0, 20 ),
	);
}

/**
 * Whether a purge with this trigger ran in the last $secs (the rollover's
 * skip test).
 *
 * @param string   $trigger The trigger.
 * @param int      $secs    Window.
 * @param int|null $now     Unix time (test seam).
 * @return bool
 */
function snt_purge_ran_recently( $trigger, $secs, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	foreach ( snt_purge_ledger_rows() as $r ) {
		if ( (int) ( $r['time'] ?? 0 ) < $now - $secs ) {
			return false; // Newest first: nothing older can match.
		}
		if ( $trigger === ( $r['trigger'] ?? '' ) ) {
			return true;
		}
	}
	return false;
}
