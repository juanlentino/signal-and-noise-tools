<?php
/**
 * Signal & Noise Tools: the extra status rows, the cron, cache, integrity and rights rows.
 *
 * Split from inc/desktop-mode-status-extra.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Cron runs RECORDED in the last 24 hours from the history table, and the
 * recorded failures with their hooks (most first). A scheduled run that dies
 * fatally never reaches the post-hook that records it, so it leaves no row:
 * recorded failures come from Run-now and caught errors, and their absence is
 * not evidence that nothing failed. Null when the table is missing or the
 * read failed.
 *
 * @return array{fires:int,failed:int,failing:string[],recovered:string[]}|null
 */
function snt_desktop_cron_24h() {
	global $wpdb;
	if ( ! defined( 'SNT_CRON_HISTORY_TABLE' ) || ! isset( $wpdb ) ) {
		return null;
	}
	$table = $wpdb->prefix . SNT_CRON_HISTORY_TABLE;
	$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	// last_ok_start: when the latest successful run STARTED (fired_at is
	// written when a run ends, elapsed_ms is how long it took).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own history table; the value binds via prepare().
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT hook, COUNT(*) AS fires, SUM(success = 0) AS failed, MAX(CASE WHEN success = 0 THEN fired_at END) AS last_failed, MAX(CASE WHEN success = 1 THEN fired_at - INTERVAL (COALESCE(elapsed_ms, 0) DIV 1000) SECOND END) AS last_ok_start FROM {$table} WHERE fired_at >= %s GROUP BY hook", $since ), ARRAY_A );
	if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}
	return snt_desktop_cron_24h_shape( $rows );
}

/**
 * A run that starts this many seconds after a hook's last failure is a later
 * run, not the same request: wp-cron.php records a reschedule error and then
 * runs the job within milliseconds, so that run's own success proves nothing.
 */
const SNT_CRON_RECOVERED_AFTER_S = 5;

/**
 * Shape the per-hook rows. PURE. 2026-10-25: a hook whose last failure was
 * followed by a later successful run is `recovered`, not `failing`, so one
 * blip does not keep the Systems card amber for a day of clean runs. The
 * failures still count in `failed`. A row without the two times is failing.
 *
 * @param array $rows {hook, fires, failed, last_failed?, last_ok_start?} (UTC datetimes).
 * @return array{fires:int,failed:int,failing:string[],recovered:string[]}
 */
function snt_desktop_cron_24h_shape( array $rows ) {
	$fires     = 0;
	$failed    = 0;
	$failing   = array();
	$recovered = array();
	foreach ( $rows as $r ) {
		$fires += (int) ( $r['fires'] ?? 0 );
		$f      = (int) ( $r['failed'] ?? 0 );
		$failed += $f;
		if ( $f < 1 ) {
			continue;
		}
		$bad  = strtotime( (string) ( $r['last_failed'] ?? '' ) . ' UTC' );
		$good = strtotime( (string) ( $r['last_ok_start'] ?? '' ) . ' UTC' );
		$hook = (string) ( $r['hook'] ?? '' );
		if ( empty( $r['last_failed'] ) || empty( $r['last_ok_start'] ) || false === $bad || false === $good || $good < $bad + SNT_CRON_RECOVERED_AFTER_S ) {
			$failing[ $hook ] = $f;
		} else {
			$recovered[ $hook ] = $f;
		}
	}
	arsort( $failing );
	arsort( $recovered );
	return array( 'fires' => $fires, 'failed' => $failed, 'failing' => array_keys( $failing ), 'recovered' => array_keys( $recovered ) );
}

/**
 * The last full edge purge and the freshness of the last purge checked.
 *
 * @return array{last_purge:int,fresh:string,fresh_time:int,headline:string}|null
 */
function snt_desktop_cache_state() {
	$last  = function_exists( 'snt_purge_ledger_last_edge' ) ? (int) snt_purge_ledger_last_edge() : 0;
	$fresh = function_exists( 'snt_cf_freshness_summary' ) ? snt_cf_freshness_summary() : null;
	if ( 0 === $last && ! is_array( $fresh ) ) {
		return null;
	}
	return array(
		'last_purge' => $last,
		'fresh'      => is_array( $fresh ) ? (string) ( $fresh['last'] ?? 'unknown' ) : 'unknown',
		'headline'   => is_array( $fresh ) ? (string) ( $fresh['headline'] ?? '' ) : '',
		// When the freshness report itself was written: a pending verdict ages
		// from this, never from the ledger's last purge (a manual purge moves
		// the ledger and leaves the report alone, Codex on #1925).
		'fresh_time' => is_array( $fresh ) ? (int) ( $fresh['last_time'] ?? 0 ) : 0,
	);
}

/**
 * The integrity sweep (payload hash, live twin, ledger record, published key;
 * it does not re-verify signature bytes) over the published corpus, counted
 * from the per-subject results the sweep stored: a subject it has not reached
 * yet is "not checked", never counted as passing.
 *
 * @return array{fleet:int,checked:int,clean:int,failing:int}|null
 */
function snt_desktop_integrity() {
	$state = function_exists( 'sn_prov_integrity_state' ) ? sn_prov_integrity_state() : null;
	return is_array( $state ) ? snt_desktop_integrity_shape( $state ) : null;
}

/**
 * Count the stored per-subject results. PURE.
 *
 * @param array $state sn_prov_integrity_state().
 * @return array{fleet:int,checked:int,clean:int,failing:int,keys:string}|null `keys` names a fleet-level key finding, '' when none.
 */
function snt_desktop_integrity_shape( array $state ) {
	$fleet = (int) ( $state['last_sweep']['fleet'] ?? 0 );
	if ( $fleet < 1 ) {
		return null;
	}
	$checked = 0;
	$clean   = 0;
	$unreach = 0;
	foreach ( (array) ( $state['notes'] ?? array() ) as $n ) {
		if ( ! is_array( $n ) || (int) ( $n['last_checked'] ?? 0 ) < 1 ) {
			continue;
		}
		++$checked;
		$codes = (array) ( $n['failures'] ?? array() );
		if ( array() === $codes ) {
			++$clean;
			continue;
		}
		// An outage (twin or ledger unreachable) is no evidence either way:
		// not a failure, and not a pass.
		$real = function_exists( 'sn_prov_integrity_is_outage' ) ? array_filter( $codes, static fn( $c ) => ! sn_prov_integrity_is_outage( is_array( $c ) ? ( $c['code'] ?? '' ) : $c ) ) : $codes;
		if ( array() === $real ) {
			++$unreach;
		}
	}
	// The published-key verdict is fleet-level: a missing, contradictory or
	// unreadable key file is a finding however clean the subjects are.
	$keys = (string) ( $state['last_sweep']['keys'] ?? '' );
	return array( 'fleet' => $fleet, 'checked' => min( $checked, $fleet ), 'clean' => min( $clean, $fleet ), 'failing' => $checked - $clean - $unreach, 'unreachable' => $unreach, 'keys' => in_array( $keys, array( 'key_mismatch', 'keys_missing', 'keys_unreachable' ), true ) ? $keys : '' );
}

/**
 * The newest month on the rights-evidence ledger and where it stands. PURE.
 * pending is posted and waiting for its Bitcoin block; confirmed is anchored;
 * composed is in review; refused, unanchored and conflict need the owner.
 *
 * @param array|null $data month => family => {status, at}.
 * @param string[]   $held Held months.
 * @return array{month:string,text:string,attention:bool,last_posted:int}|null
 */
function snt_desktop_rights_state( $data, array $held ) {
	$data = is_array( $data ) ? $data : array();
	// The newest month of the ledger AND the held list: a month held before
	// anything was composed has no ledger row and still needs the owner.
	$months = array_unique( array_merge( array_keys( $data ), array_map( 'strval', $held ) ) );
	if ( array() === $months ) {
		return null;
	}
	sort( $months );
	ksort( $data );
	$month  = (string) end( $months );
	$counts = array();
	$posted = 0;
	foreach ( $data as $families ) {
		foreach ( (array) $families as $row ) {
			$st = (string) ( $row['status'] ?? '' );
			// A retracted record was posted all the same: the ledger is append-only.
			if ( in_array( $st, array( 'pending', 'confirmed', 'retracted' ), true ) ) {
				$posted = max( $posted, (int) ( $row['at'] ?? 0 ) );
			}
		}
	}
	foreach ( (array) ( $data[ $month ] ?? array() ) as $row ) {
		$st            = (string) ( $row['status'] ?? '' );
		$counts[ $st ] = ( $counts[ $st ] ?? 0 ) + 1;
	}
	$words = array(
		'confirmed'  => 'anchored',
		'pending'    => 'waiting for a Bitcoin block',
		'composed'   => 'in review',
		'refused'    => 'refused',
		'unanchored' => 'not anchored',
		'conflict'   => 'in conflict',
		'retracted'  => 'retracted',
	);
	$parts = array();
	foreach ( $words as $st => $w ) {
		if ( ! empty( $counts[ $st ] ) ) {
			$parts[] = $counts[ $st ] . ' ' . $w;
		}
	}
	$is_held = in_array( $month, $held, true );
	if ( $is_held ) {
		array_unshift( $parts, 'held' );
	}
	$label = 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ? gmdate( 'F', (int) strtotime( $month . '-01 00:00:00 UTC' ) ) : $month;
	return array(
		'month'       => $label,
		'text'        => implode( ' · ', $parts ),
		'attention'   => $is_held || ! empty( $counts['refused'] ) || ! empty( $counts['unanchored'] ) || ! empty( $counts['conflict'] ),
		'last_posted' => $posted,
	);
}
