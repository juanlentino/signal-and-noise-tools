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
	// Overlapping purges are what this ledger exists to see, so two writers
	// must not drop each other's row. add_option() fails when the name exists
	// (one INSERT on a unique key), which makes it a lock; a lock older than
	// 10 seconds is a crashed writer and is taken over.
	$lock = SNT_PURGE_LEDGER_OPTION . '_lock';
	$have = false;
	// Up to ~12 s: a lock older than 10 s is a crashed writer and is taken
	// over, so a live wait always ends holding the lock.
	for ( $i = 0; $i < 120; $i++ ) {
		if ( add_option( $lock, time(), '', false ) ) {
			$have = true;
			break;
		}
		if ( (int) get_option( $lock, 0 ) < time() - 10 ) {
			delete_option( $lock );
			continue;
		}
		usleep( 100000 );
	}
	if ( ! $have ) {
		return; // Never write, or release, without holding the lock.
	}
	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( SNT_PURGE_LEDGER_OPTION, 'options' ); // Read the row the other writer just stored.
	}
	$rows = snt_purge_ledger_rows();
	array_unshift( $rows, array_merge( array( 'time' => time() ), $row ) );
	update_option( SNT_PURGE_LEDGER_OPTION, array_slice( $rows, 0, SNT_PURGE_LEDGER_CAP ), false );
	delete_option( $lock );
}

/**
 * When the edge was last purged (0 when never): the card's "refreshing"
 * window counts from a purge that cleared Cloudflare, not any purge.
 *
 * @return int
 */
function snt_purge_ledger_last_edge() {
	foreach ( snt_purge_ledger_rows() as $r ) {
		if ( ! empty( $r['edge'] ) ) {
			return (int) ( $r['time'] ?? 0 );
		}
	}
	return 0;
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
	// A post save or first publish (Codex: these were counted as manual).
	foreach ( array( 'wp_after_insert_post', 'transition_post_status', 'save_post' ) as $hook ) {
		if ( function_exists( 'doing_action' ) && doing_action( $hook ) ) {
			return 'publish';
		}
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
		'edge'    => false, // Set by the Cloudflare leg when it actually dispatches (sn_cf_purge_everything).
		// How the theme can clear transients here: by group in Redis (theme
		// 14.10.0), or only the database rows, which hold nothing with Object
		// Cache Pro. The first row after install answers which.
		'transients' => function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ? 'group' : 'db',
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
	$row['edge']      = ! empty( $GLOBALS['snt_purge_edge'] );
	unset( $GLOBALS['snt_purge_edge'] );
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
	// Only with the replacement in place: the Signal & Noise theme 15.0.0+
	// purges the page caches after ANY plugin or theme update. Without it,
	// Breeze's hook is the only update purge, so it stays (Codex).
	if ( ! snt_purge_theme_replaces_breeze() ) {
		return false;
	}
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
 * Whether the active theme's update purge covers every package (theme 15.0.0+).
 *
 * @param string|null $template Test seam: the active template slug.
 * @param string|null $version  Test seam: its version.
 * @return bool
 */
function snt_purge_theme_replaces_breeze( $template = null, $version = null ) {
	if ( null === $template || null === $version ) {
		if ( ! function_exists( 'wp_get_theme' ) || ! function_exists( 'get_template' ) ) {
			return false;
		}
		$template = get_template();
		$version  = (string) wp_get_theme( $template )->get( 'Version' );
	}
	return 'signal-and-noise' === $template && version_compare( $version, '15.0.0', '>=' );
}

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
	// The ring holds SNT_PURGE_LEDGER_CAP rows. When it is full and its oldest
	// row is inside the week, more purges happened than it kept: the counts
	// are a floor, and a purge storm is exactly when that happens.
	$oldest = $rows ? (int) ( end( $rows )['time'] ?? 0 ) : 0;
	return array(
		'last_7_days'         => count( $week ),
		'last_7_days_is_floor' => count( $rows ) >= SNT_PURGE_LEDGER_CAP && $oldest >= $now - 7 * DAY_IN_SECONDS,
		'by_trigger'          => $count,
		// 'unknown' (a Cloudways timeout) counts: undercounting is the worse error.
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
