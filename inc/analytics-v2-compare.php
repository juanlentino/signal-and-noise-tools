<?php
/**
 * Signal & Noise Tools: the dual-write check for the second-generation
 * analytics datasets (analytics worker 1.24.0 and later).
 *
 * The worker writes every accepted beacon twice: the legacy `sn_pageviews`
 * row, a row in `sn_pageviews_v2` for everything except a property row, and
 * a row in `sn_events_v2` for a custom event and each of its properties. Before any read moves to the new datasets, their counts have to
 * equal the legacy ones day by day. This reads all three and compares them.
 * It is the acceptance test for the cutover, and nothing else reads the new
 * datasets yet.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_DATASET_PV_V2     = 'sn_pageviews_v2';
const SN_ANALYTICS_DATASET_EVENTS_V2 = 'sn_events_v2';

/**
 * Rows per UTC day and event in one dataset. PURE. UTC days on purpose: the
 * question is whether two datasets hold the same rows, not whose day it is.
 *
 * @param string $dataset One of the three dataset names.
 * @param int    $days    Days back, today included (1..14).
 * @param bool   $pid     Also count the rows that carry a pageview ID (double11, v2 only).
 * `n` is the weighted count; `r` is the rows actually stored. They differ only
 * when Analytics Engine sampled, and then `n` is an estimate.
 *
 * @return string AE SQL.
 */
function sn_analytics_v2_count_sql( $dataset, $days, $pid = false ) {
	$dataset = in_array( $dataset, array( SN_ANALYTICS_DATASET, SN_ANALYTICS_DATASET_PV_V2, SN_ANALYTICS_DATASET_EVENTS_V2 ), true ) ? $dataset : SN_ANALYTICS_DATASET;
	$days    = max( 1, min( 14, (int) $days ) );
	return 'SELECT ' . "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, blob1 AS ev, sum(_sample_interval) AS n, count() AS r"
		. ( $pid ? ', sum(if(double11 > 0, _sample_interval, 0)) AS with_pid' : '' )
		. ' FROM ' . $dataset
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '" . ( $days - 1 ) . "' DAY)"
		. ' GROUP BY day, ev';
}

/**
 * Compare the three readings. PURE.
 *
 * A day before `$first_full_day` is `partial`: the worker started writing the
 * new datasets partway through it, so a shortfall there is expected and is
 * not a finding. From that day on, each side must hold exactly the legacy
 * count: every legacy row except `cp` against sn_pageviews_v2 (which keeps
 * one `ce` row per custom event since worker 1.25.0), and `ce` plus `cp`
 * against sn_events_v2. A day Analytics Engine sampled reads `sampled` when its
 * estimates differ: inconclusive, not a mismatch.
 *
 * @param array|null $legacy         Rows {day, ev, n} from the legacy dataset; null when the read failed.
 * @param array|null $pageviews      Rows {day, ev, n, with_pid} from sn_pageviews_v2.
 * @param array|null $events         Rows {day, ev, n, with_pid} from sn_events_v2.
 * @param string     $first_full_day YYYY-MM-DD, from the worker's /_sn/version.
 * @return array{ok:bool,read:bool,days:array<int,array<string,mixed>>,mismatched:int}
 */
function sn_analytics_v2_compare( $legacy, $pageviews, $events, $first_full_day ) {
	if ( ! is_array( $legacy ) || ! is_array( $pageviews ) || ! is_array( $events ) ) {
		return array( 'ok' => false, 'read' => false, 'days' => array(), 'mismatched' => 0 );
	}
	$days = array();
	$slot = static function ( $day ) use ( &$days ) {
		if ( ! isset( $days[ $day ] ) ) {
			$days[ $day ] = array( 'day' => $day, 'legacy_pageview_side' => 0, 'v2_pageviews' => 0, 'legacy_events' => 0, 'v2_events' => 0, 'with_pid' => 0, 'sampled' => false );
		}
	};
	// A count is exact only while every stored row stands for itself. Once
	// Analytics Engine samples (each dataset on its own), the weighted sums are
	// estimates and two identical writes can read unequal.
	$sampled = static fn( $r ) => isset( $r['r'] ) && (int) round( (float) ( $r['n'] ?? 0 ) ) !== (int) round( (float) $r['r'] );
	foreach ( $legacy as $r ) {
		$day = (string) ( $r['day'] ?? '' );
		$slot( $day );
		$ev = (string) ( $r['ev'] ?? '' );
		$n  = (int) round( (float) ( $r['n'] ?? 0 ) );
		// The pageviews dataset holds every legacy row except the property
		// rows; the events dataset holds the custom events and their property
		// rows. A `ce` row is therefore expected in BOTH (worker 1.25.0).
		if ( 'cp' !== $ev ) {
			$days[ $day ]['legacy_pageview_side'] += $n;
		}
		if ( 'ce' === $ev || 'cp' === $ev ) {
			$days[ $day ]['legacy_events'] += $n;
		}
		$days[ $day ]['sampled'] = $days[ $day ]['sampled'] || $sampled( $r );
	}
	foreach ( array( 'v2_pageviews' => $pageviews, 'v2_events' => $events ) as $key => $rows ) {
		foreach ( $rows as $r ) {
			$day = (string) ( $r['day'] ?? '' );
			$slot( $day );
			$days[ $day ][ $key ]      += (int) round( (float) ( $r['n'] ?? 0 ) );
			$days[ $day ]['with_pid'] += (int) round( (float) ( $r['with_pid'] ?? 0 ) );
			$days[ $day ]['sampled']   = $days[ $day ]['sampled'] || $sampled( $r );
		}
	}
	ksort( $days );
	$bad = 0;
	foreach ( $days as $day => $d ) {
		$equal = $d['legacy_pageview_side'] === $d['v2_pageviews'] && $d['legacy_events'] === $d['v2_events'];
		$state = $day < (string) $first_full_day ? 'partial' : ( $equal ? 'match' : ( $d['sampled'] ? 'sampled' : 'mismatch' ) ); // unequal estimates prove nothing either way.
		$bad  += 'mismatch' === $state ? 1 : 0;
		$days[ $day ]['state'] = $state;
	}
	return array( 'ok' => 0 === $bad, 'read' => true, 'days' => array_values( $days ), 'mismatched' => $bad );
}

/**
 * Read the three datasets and compare. Three Analytics Engine requests; call
 * it on demand, never on a page load.
 *
 * @param int    $days           Days back, today included.
 * @param string $first_full_day YYYY-MM-DD.
 * @return array<string,mixed>
 */
function sn_analytics_v2_check( $days = 4, $first_full_day = '2026-10-05' ) {
	$sets = array( SN_ANALYTICS_DATASET, SN_ANALYTICS_DATASET_PV_V2, SN_ANALYTICS_DATASET_EVENTS_V2 );
	$read = array();
	$fail = array();
	foreach ( $sets as $i => $ds ) {
		$rows = function_exists( 'sn_analytics_query' ) ? sn_analytics_query( sn_analytics_v2_count_sql( $ds, $days, $i > 0 ) ) : null;
		if ( ! is_array( $rows ) ) {
			// Stop at the first failure and keep its reason: the next successful
			// request would clear the stored error and leave "not read" unexplained.
			$err  = function_exists( 'sn_analytics_last_error' ) ? sn_analytics_last_error() : null;
			$fail = array( 'failed' => $ds, 'error' => is_array( $err ) ? 'HTTP ' . (int) ( $err['code'] ?? 0 ) . ' ' . substr( (string) ( $err['message'] ?? '' ), 0, 200 ) : '' );
			break;
		}
		$read[] = $rows;
	}
	return sn_analytics_v2_compare( $read[0] ?? null, $read[1] ?? null, $read[2] ?? null, $first_full_day )
		+ $fail + array( 'first_full_day' => (string) $first_full_day, 'datasets' => $sets );
}

function snt_ability_analytics_dual_write( $input = array() ) {
	$days = isset( $input['days'] ) ? (int) $input['days'] : 4;
	$stored = function_exists( 'get_option' ) && defined( 'SN_ANALYTICS_V2_VERIFIED_OPT' ) ? get_option( SN_ANALYTICS_V2_VERIFIED_OPT, null ) : null;
	// `verdict` is what the reads follow: the daily check's stored answer, not this live one.
	return sn_analytics_v2_check( max( 1, min( 14, $days ) ) ) + array( 'verdict' => is_array( $stored ) ? $stored : (object) array() );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/analytics-dual-write', array(
		'label'               => 'Analytics: do the new datasets hold what the old one holds?',
		'description'         => 'The analytics worker (1.24.0 and later) writes every beacon to the legacy dataset and to two second-generation datasets. This counts rows per UTC day in all three (three live Analytics Engine requests) and compares them: every legacy row except property rows (`cp`) against sn_pageviews_v2, and custom events with their property rows against sn_events_v2 (the base row of a custom event is in both, by design, since worker 1.25.0). `state` per day: `partial` before `first_full_day` (the dual write began mid-day; a shortfall there is expected), then `match` or `mismatch`. `with_pid` is how many new rows carry a pageview ID (theme 15.3.0 and later). `read: false` means a request failed and nothing was compared (`failed` names the dataset, `error` the reason); it is NOT a mismatch. `sampled` on a day means Analytics Engine sampled and the unequal counts are estimates: inconclusive. `verdict` is the daily check's stored answer ({ok, day, at, why}; empty until it has run): while `ok` is true, every read whose window starts on or after `first_full_day` uses the new datasets, and a mismatch sends them all back to the old one. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_analytics_dual_write',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 14, 'default' => 4, 'description' => 'UTC days back, today included.' ) ), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'read' => array( 'type' => 'boolean' ), 'days' => array( 'type' => 'array' ), 'mismatched' => array( 'type' => 'integer' ), 'first_full_day' => array( 'type' => 'string' ), 'datasets' => array( 'type' => 'array' ), 'failed' => array( 'type' => 'string' ), 'error' => array( 'type' => 'string' ), 'verdict' => array( 'type' => 'object' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => true ) ),
	) );
} );
