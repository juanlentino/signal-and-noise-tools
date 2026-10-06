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
 * @return array{day:string,total?:int,visitor?:int,dashboard?:int|null,prior?:int|null,failed?:bool}|null `failed` when the newest non-pending day's read failed. `day` is the label ("Oct 4"); `visitor` the 5xx a visitor received; `dashboard` how many hit the owner's own admin and its polls (null when the day carries no paths).
 */
function snt_desktop_edge_yesterday() {
	if ( ! function_exists( 'sn_edge_errors_range' ) ) {
		return null;
	}
	// Only the requested day's own state counts: 'read' (not failed, pending
	// or untracked). query.error is global to the last query, not this day.
	$state = static function ( $r, $day ) {
		foreach ( (array) ( is_array( $r ) ? ( $r['days'] ?? array() ) : array() ) as $d ) {
			if ( $day === (string) ( $d['day'] ?? '' ) ) {
				return (string) ( $d['read'] ?? '' );
			}
		}
		return '';
	};
	$ok = static fn( $r, $day ) => 'read' === $state( $r, $day );
	// Yesterday reads pending until the daily edge rollup runs: walk back to
	// the newest day it has covered, and label that day.
	for ( $back = 1; $back <= 3; $back++ ) {
		$t = time() - $back * DAY_IN_SECONDS;
		$d = gmdate( 'Y-m-d', $t );
		$a = sn_edge_errors_range( $d, $d );
		if ( ! $ok( $a, $d ) ) {
			// A pending (or unrecorded) day walks back; a FAILED read of the
			// newest day is said, never replaced by an older day's count.
			if ( 'failed' === $state( $a, $d ) ) {
				return array( 'day' => gmdate( 'M j', $t ), 'failed' => true );
			}
			continue;
		}
		$pd = gmdate( 'Y-m-d', $t - DAY_IN_SECONDS );
		$b  = sn_edge_errors_range( $pd, $pd );
		// Who asked (18.2.0): only a visitor's 5xx is an outage someone saw;
		// a Worker subrequest's is the Worker's own business.
		$visitor = 0;
		foreach ( (array) ( $a['days'] ?? array() ) as $row ) {
			$visitor += $d === (string) ( $row['day'] ?? '' ) ? (int) ( $row['visitor'] ?? 0 ) : 0;
		}
		return array( 'day' => gmdate( 'M j', $t ), 'total' => (int) ( $a['total'] ?? 0 ), 'visitor' => $visitor, 'dashboard' => snt_desktop_edge_dashboard_count( $a['paths'] ?? null ), 'prior' => $ok( $b, $pd ) ? (int) ( $b['total'] ?? 0 ) : null );
	}
	return null;
}

/**
 * How many of a day's 5xx hit the owner's own dashboard: wp-admin, the
 * OpenStation shell and its session ping, and the abilities and desktop
 * reads its cards poll (2026-10-05: about 25 of the week's 30). Counted from
 * the day's top failing paths, so a dashboard path outside that list counts
 * as "everything else"; the split never overstates the dashboard. PURE.
 *
 * @param mixed $paths The day's `paths`: list of { value, requests }.
 * @return int|null Null when the day carries no paths to split by.
 */
function snt_desktop_edge_dashboard_count( $paths ) {
	if ( ! is_array( $paths ) ) {
		return null;
	}
	$n = 0;
	foreach ( $paths as $p ) {
		$path = (string) ( is_array( $p ) ? ( $p['value'] ?? '' ) : '' );
		if ( preg_match( '#^/(wp-admin/|openstation/|wp-json/(desktop-mode|openstation|wp-abilities)/|wp-json/signal-noise/v1/desktop/)#', $path ) ) {
			$n += (int) ( $p['requests'] ?? 0 );
		}
	}
	return $n;
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

/**
 * Zenodo DOIs: production DOIs minted out of the subjects that should have one.
 *
 * @return array{minted:int,total:int}|null
 */
function snt_desktop_zenodo() {
	$z = function_exists( 'snt_ability_zenodo_status' ) ? snt_ability_zenodo_status() : null;
	// The counts come from stored post meta: they stand without a token (a
	// rotation must not hide minted DOIs or the subjects still waiting).
	if ( ! is_array( $z ) || ! isset( $z['total'] ) ) {
		return null;
	}
	return array( 'minted' => (int) ( $z['minted'] ?? 0 ), 'total' => (int) $z['total'] );
}
