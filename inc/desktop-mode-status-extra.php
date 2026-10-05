<?php
/**
 * Signal & Noise Tools: the extra rows SN Systems and SN Provenance carry
 * (owner rule 2026-10-05: "lacking some information compared with the rest").
 *
 * Every figure is a local read the site already stores (a rollup table, the
 * cron history table, an option); none calls a remote service. The whole
 * payload is held for five minutes, because the localize that carries it runs
 * on every wp-admin screen. A source that cannot answer is null, and the card
 * leaves its row out: unknown is never painted as zero.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_DESKTOP_STATUS_EXTRA_KEY = 'snt_desktop_status_extra_v1';

/**
 * The extra rows for both cards, cached five minutes.
 *
 * @return array{systems:array,provenance:array}
 */
function snt_desktop_status_extra() {
	$cached = get_transient( SNT_DESKTOP_STATUS_EXTRA_KEY );
	if ( is_array( $cached ) && isset( $cached['systems'], $cached['provenance'] ) ) {
		return $cached;
	}
	$out = array(
		'systems'    => array(
			'edge'  => snt_desktop_edge_yesterday(),
			'cron'  => snt_desktop_cron_24h(),
			'cache' => snt_desktop_cache_state(),
		),
		'provenance' => array(
			'integrity' => snt_desktop_integrity(),
			'rights'    => snt_desktop_rights_state( function_exists( 'sn_rights_evidence_data' ) ? sn_rights_evidence_data() : null, function_exists( 'sn_rights_evidence_held' ) ? (array) sn_rights_evidence_held( false ) : array() ),
			'zenodo'    => snt_desktop_zenodo(),
		),
	);
	set_transient( SNT_DESKTOP_STATUS_EXTRA_KEY, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * Edge 5xx for the newest complete UTC day the rollup has covered (yesterday,
 * or the day before while the daily rollup has not run yet) and the day
 * before it. There is no rolling 24 hours: today is still filling. Null when
 * none of the last three days could be read.
 *
 * @return array{day:string,total:int,prior:int|null}|null `day` is the label ("Oct 4").
 */
function snt_desktop_edge_yesterday() {
	if ( ! function_exists( 'sn_edge_errors_range' ) ) {
		return null;
	}
	$ok = static fn( $r ) => is_array( $r ) && empty( $r['query']['error'] ) && array() !== array_filter( (array) ( $r['days'] ?? array() ), static fn( $d ) => 'pending' !== ( $d['read'] ?? '' ) );
	// Yesterday reads pending until the daily edge rollup runs: walk back to
	// the newest day it has covered, and label that day.
	for ( $back = 1; $back <= 3; $back++ ) {
		$t = time() - $back * DAY_IN_SECONDS;
		$d = gmdate( 'Y-m-d', $t );
		$a = sn_edge_errors_range( $d, $d );
		if ( ! $ok( $a ) ) {
			continue;
		}
		$pd = gmdate( 'Y-m-d', $t - DAY_IN_SECONDS );
		$b  = sn_edge_errors_range( $pd, $pd );
		return array( 'day' => gmdate( 'M j', $t ), 'total' => (int) ( $a['total'] ?? 0 ), 'prior' => $ok( $b ) ? (int) ( $b['total'] ?? 0 ) : null );
	}
	return null;
}

/**
 * Cron runs RECORDED in the last 24 hours from the history table, and the
 * recorded failures with their hooks (most first). A scheduled run that dies
 * fatally never reaches the post-hook that records it, so it leaves no row:
 * recorded failures come from Run-now and caught errors, and their absence is
 * not evidence that nothing failed. Null when the table is missing or the
 * read failed.
 *
 * @return array{fires:int,failed:int,failing:string[]}|null
 */
function snt_desktop_cron_24h() {
	global $wpdb;
	if ( ! defined( 'SNT_CRON_HISTORY_TABLE' ) || ! isset( $wpdb ) ) {
		return null;
	}
	$table = $wpdb->prefix . SNT_CRON_HISTORY_TABLE;
	$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own history table; the value binds via prepare().
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT hook, COUNT(*) AS fires, SUM(success = 0) AS failed FROM {$table} WHERE fired_at >= %s GROUP BY hook", $since ), ARRAY_A );
	if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
		return null;
	}
	return snt_desktop_cron_24h_shape( $rows );
}

/**
 * Shape the per-hook rows. PURE.
 *
 * @param array $rows {hook, fires, failed}.
 * @return array{fires:int,failed:int,failing:string[]}
 */
function snt_desktop_cron_24h_shape( array $rows ) {
	$fires   = 0;
	$failed  = 0;
	$failing = array();
	foreach ( $rows as $r ) {
		$fires += (int) ( $r['fires'] ?? 0 );
		$f      = (int) ( $r['failed'] ?? 0 );
		$failed += $f;
		if ( $f > 0 ) {
			$failing[ (string) ( $r['hook'] ?? '' ) ] = $f;
		}
	}
	arsort( $failing );
	return array( 'fires' => $fires, 'failed' => $failed, 'failing' => array_keys( $failing ) );
}

/**
 * The last full edge purge and the freshness of the last purge checked.
 *
 * @return array{last_purge:int,fresh:string,headline:string}|null
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
	);
}

/**
 * Signature integrity over the published corpus. The sweep checks a batch per
 * run; the notes that verify are the fleet minus the ones on the failing list.
 *
 * @return array{fleet:int,verified:int,failing:int,swept_at:int}|null
 */
function snt_desktop_integrity() {
	$s = function_exists( 'snt_ability_provenance_integrity_status' ) ? snt_ability_provenance_integrity_status() : null;
	if ( ! is_array( $s ) || ! isset( $s['fleet'] ) ) {
		return null;
	}
	$failing = count( (array) ( $s['failing'] ?? array() ) );
	$fleet   = (int) $s['fleet'];
	return array( 'fleet' => $fleet, 'verified' => max( 0, $fleet - $failing ), 'failing' => $failing, 'swept_at' => (int) ( $s['swept_at'] ?? 0 ) );
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
	if ( ! is_array( $data ) || array() === $data ) {
		return null;
	}
	ksort( $data );
	$month  = (string) array_key_last( $data );
	$counts = array();
	$posted = 0;
	foreach ( $data as $families ) {
		foreach ( (array) $families as $row ) {
			$st = (string) ( $row['status'] ?? '' );
			if ( in_array( $st, array( 'pending', 'confirmed' ), true ) ) {
				$posted = max( $posted, (int) ( $row['at'] ?? 0 ) );
			}
		}
	}
	foreach ( (array) $data[ $month ] as $row ) {
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

/**
 * Zenodo DOIs: production DOIs minted out of the subjects that should have one.
 *
 * @return array{minted:int,total:int}|null
 */
function snt_desktop_zenodo() {
	$z = function_exists( 'snt_ability_zenodo_status' ) ? snt_ability_zenodo_status() : null;
	if ( ! is_array( $z ) || empty( $z['enabled'] ) || ! isset( $z['total'] ) ) {
		return null;
	}
	return array( 'minted' => (int) ( $z['minted'] ?? 0 ), 'total' => (int) $z['total'] );
}
