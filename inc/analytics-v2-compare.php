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
const SN_ANALYTICS_V2_SAMPLED_MAX    = 200; // visitor-days listed per dataset in the sampling diagnostic.

/**
 * Rows per UTC day and event in one dataset. PURE. UTC days on purpose: the
 * question is whether two datasets hold the same rows, not whose day it is.
 *
 * @param string $dataset One of the three dataset names.
 * @param int    $days    Days back, today included (1..14).
 * @param bool   $pid     Also count the rows that carry a pageview ID (double11, v2 only).
 * `v` is the distinct visitor-day hashes (index1): the rollups count and group
 * by it, so equal row counts over different hashes are not the same data.
 * `n` is the weighted count; `r` is the rows actually stored. They differ only
 * when Analytics Engine sampled, and then `n` is an estimate.
 *
 * @return string AE SQL.
 */
function sn_analytics_v2_count_sql( $dataset, $days, $pid = false ) {
	$dataset = in_array( $dataset, array( SN_ANALYTICS_DATASET, SN_ANALYTICS_DATASET_PV_V2, SN_ANALYTICS_DATASET_EVENTS_V2 ), true ) ? $dataset : SN_ANALYTICS_DATASET;
	$days    = max( 1, min( 14, (int) $days ) );
	return 'SELECT ' . "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, blob1 AS ev, sum(_sample_interval) AS n, count() AS r, count(DISTINCT index1) AS v"
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
 * estimates differ: inconclusive, not a mismatch. The comparison is event by
 * event (pv, sc, tm, the vitals, ce, cp), and a `match` needs every one of
 * them counted exactly on both sides.
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
	// counts[ day ][ side ][ event ] = { n: weighted count, exact: every stored row stands for itself }.
	$counts = array();
	$pid    = array();
	foreach ( array( 'legacy' => $legacy, 'pageviews' => $pageviews, 'events' => $events ) as $side => $rows ) {
		foreach ( $rows as $r ) {
			$day = (string) ( $r['day'] ?? '' );
			$ev  = (string) ( $r['ev'] ?? '' );
			$n   = (int) round( (float) ( $r['n'] ?? 0 ) );
			$had = $counts[ $day ][ $side ][ $ev ] ?? array( 'n' => 0, 'exact' => true, 'v' => 0, 'r' => 0 );
			// Analytics Engine samples each dataset on its own; once a stored row
			// stands for several, the weighted sum is an estimate.
			$stored = isset( $r['r'] ) ? (int) round( (float) $r['r'] ) : $n;
			$counts[ $day ][ $side ][ $ev ] = array( 'n' => $had['n'] + $n, 'exact' => $had['exact'] && $stored === $n, 'v' => $had['v'] + (int) round( (float) ( $r['v'] ?? 0 ) ), 'r' => $had['r'] + $stored );
			$pid[ $day ] = ( $pid[ $day ] ?? 0 ) + (int) round( (float) ( $r['with_pid'] ?? 0 ) );
		}
	}
	ksort( $counts );
	$days = array();
	$bad  = 0;
	foreach ( $counts as $day => $c ) {
		$l   = $c['legacy'] ?? array();
		$p   = $c['pageviews'] ?? array();
		$e   = $c['events'] ?? array();
		$sum = static fn( $side, $skip ) => array_sum( array_map( static fn( $ev ) => in_array( $ev, $skip, true ) ? 0 : $side[ $ev ]['n'], array_keys( $side ) ) );
		// Event by event, so two errors that cancel in a total cannot pass: the
		// pageviews dataset holds every legacy event except `cp`; the events
		// dataset holds `ce` and `cp` (a `ce` row is in both, worker 1.25.0).
		$differs = array();
		$sampled = array();
		$exact   = 0;
		$same    = array();
		$pairs   = array();
		// Owner rule 2026-10-05: Analytics Engine samples a visitor-day at write
		// time, and the two pageview datasets were measured to hold the SAME
		// sample (same stored rows, same weights). Equal stored rows, equal
		// weighted count and equal distinct visitors on both sides is exact
		// equality of what is stored, sampled or not: no tolerance.
		$identical = static fn( $a, $b ) => $a['n'] === $b['n'] && $a['r'] === $b['r'] && $a['v'] === $b['v'];
		foreach ( array_unique( array_merge( array_keys( $l ), array_keys( $p ) ) ) as $ev ) {
			if ( 'cp' !== $ev ) {
				$pairs[] = array( $ev, $l[ $ev ] ?? null, $p[ $ev ] ?? null, 'pageviews' );
			}
		}
		foreach ( array_unique( array_merge( array_intersect( array_keys( $l ), array( 'ce', 'cp' ) ), array_keys( $e ) ) ) as $ev ) {
			$pairs[] = array( $ev, $l[ $ev ] ?? null, $e[ $ev ] ?? null, 'events' );
		}
		if ( ( $p['cp']['n'] ?? 0 ) > 0 ) {
			// The contract: property rows live in the events dataset only. The
			// rollups read the pageviews dataset without an event filter.
			$differs[] = 'cp (pageviews: must hold none, has ' . $p['cp']['n'] . ')';
		}
		foreach ( $pairs as list( $ev, $a, $b, $side ) ) {
			$a = $a ?? array( 'n' => 0, 'exact' => true, 'v' => 0, 'r' => 0 );
			$b = $b ?? array( 'n' => 0, 'exact' => true, 'v' => 0, 'r' => 0 );
			if ( ( 0 === $a['n'] ) !== ( 0 === $b['n'] ) ) {
				// Rows on one side and none on the other is a finding whatever the
				// sampling: an estimate can be off, it cannot be of nothing.
				$differs[] = $ev . ' (' . $side . ': ' . $a['n'] . ' vs ' . $b['n'] . ')';
			} elseif ( ( ! $a['exact'] || ! $b['exact'] ) && $identical( $a, $b ) ) {
				++$exact; // the same sample on both sides.
				$same[] = $ev;
			} elseif ( ! $a['exact'] || ! $b['exact'] ) {
				$sampled[] = $ev; // different samples: estimates prove nothing either way.
			} elseif ( $a['n'] !== $b['n'] ) {
				$differs[] = $ev . ' (' . $side . ': ' . $a['n'] . ' vs ' . $b['n'] . ')';
			} elseif ( $a['v'] !== $b['v'] ) {
				// Same rows, different visitor hashes: visits and sessions would differ.
				$differs[] = $ev . ' (' . $side . ' visitors: ' . $a['v'] . ' vs ' . $b['v'] . ')';
			} else {
				++$exact;
			}
		}
		// A match needs exact evidence where it matters: the pageviews
		// themselves counted exactly and equal. A day with nothing exact to
		// compare is `sampled`, never `match`.
		$pv_exact = isset( $l['pv'], $p['pv'] ) && ( ( $l['pv']['exact'] && $p['pv']['exact'] ) || $identical( $l['pv'], $p['pv'] ) );
		// The events dataset is proven only by a custom event counted exactly in
		// both: a day with no custom events says nothing about it.
		$ev_exact = isset( $l['ce'], $e['ce'] ) && $l['ce']['exact'] && $e['ce']['exact'] && $l['ce']['n'] === $e['ce']['n'] && $l['ce']['n'] > 0; // a match already means cp, where present, was exact and equal too.
		if ( $day < (string) $first_full_day ) {
			$state = 'partial';
		} elseif ( $differs ) {
			$state = 'mismatch';
		} elseif ( $pv_exact && $exact > 0 && array() === $sampled ) {
			// Every event type read from these datasets was counted exactly and
			// agrees. One sampled type is enough to withhold the match: its
			// counts are estimates, and the rollups read that type too.
			$state = 'match';
		} else {
			$state = 'sampled';
		}
		$bad   += 'mismatch' === $state ? 1 : 0;
		$days[] = array(
			'day'                  => $day,
			'legacy_pageview_side' => $sum( $l, array( 'cp' ) ),
			'v2_pageviews'         => $sum( $p, array() ),
			'legacy_events'        => ( $l['ce']['n'] ?? 0 ) + ( $l['cp']['n'] ?? 0 ),
			'v2_events'            => $sum( $e, array() ),
			'with_pid'             => (int) ( $pid[ $day ] ?? 0 ),
			'sampled'              => array() !== $sampled,
			'sampled_events'       => array_values( array_unique( $sampled ) ),
			'identical_sample'     => array_values( array_unique( $same ) ),
			'differs'              => $differs,
			'events_proven'        => 'match' === $state && $ev_exact,
			'state'                => $state,
		);
	}
	return array( 'ok' => 0 === $bad, 'read' => true, 'days' => $days, 'mismatched' => $bad );
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

/**
 * AE SQL: the visitor-days whose rows Analytics Engine sampled, per UTC day,
 * in one dataset. PURE. Analytics Engine samples per index value (index1, the
 * visitor-day hash) and only at high volume, so a sampled day should trace to
 * a few heavy visitor-days. `stored_bot` says whether the worker classed any
 * of the visitor's rows as a bot.
 *
 * @param string $dataset One of the three dataset names.
 * @param int    $days    Days back, today included (1..14).
 * @return string
 */
function sn_analytics_v2_sampled_sql( $dataset, $days ) {
	$dataset = in_array( $dataset, array( SN_ANALYTICS_DATASET, SN_ANALYTICS_DATASET_PV_V2, SN_ANALYTICS_DATASET_EVENTS_V2 ), true ) ? $dataset : SN_ANALYTICS_DATASET;
	$days    = max( 1, min( 14, (int) $days ) );
	// `human` uses the read-time rule every human figure uses (not a stored bot,
	// and the network rule); the over-cap list is applied in PHP. One row past
	// the cap says the list was cut short.
	$net = function_exists( 'sn_analytics_network_human_sql' ) ? sn_analytics_network_human_sql() : '1';
	return 'SELECT ' . "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, index1 AS vid, sum(_sample_interval) AS n, count() AS r, max(if(blob7 = 'bot', 1, 0)) AS stored_bot, max(if(blob7 != 'bot' AND ({$net}), 1, 0)) AS human"
		. ' FROM ' . $dataset
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '" . ( $days - 1 ) . "' DAY) AND _sample_interval > 1"
		. ' GROUP BY day, vid LIMIT ' . ( SN_ANALYTICS_V2_SAMPLED_MAX + 1 );
}

/**
 * Who was sampled: one row per dataset, day and visitor-day, with whether the
 * visitor-day holds rows the human reads count (the read-time rule) and
 * whether it is over the page-view cap. PURE given its inputs.
 *
 * `conclusive` is false when a read failed, a list was cut at its cap, or the
 * over-cap list could not be read: `counted_human` is then null (a cut list
 * could miss humans, a missing cap list could count over-cap visitors as
 * human, so it is neither a floor nor a ceiling). Even when conclusive, this is a separate query: Analytics Engine
 * picks a read resolution per query, so it shows the rows as THIS read saw
 * them, which is the stored (write-time) sampling when it reads at full
 * resolution.
 *
 * @param array<string,array|null> $by_dataset Rows of sn_analytics_v2_sampled_sql() keyed by dataset; null when that read failed.
 * @param array                    $over_cap   sn_analytics_overcap_vdays() result {hashes, ok, truncated}.
 * @return array{read:bool,conclusive:bool,rows:array<int,array<string,mixed>>,counted_human:int|null,truncated:bool,over_cap_ok:bool}
 */
function sn_analytics_v2_sampled_visitors( array $by_dataset, array $over_cap ) {
	$cap   = array_flip( array_map( 'strtolower', (array) ( $over_cap['hashes'] ?? array() ) ) );
	$cap_ok = ! empty( $over_cap['ok'] ) && empty( $over_cap['truncated'] );
	$out   = array();
	$read  = true;
	$trunc = false;
	foreach ( $by_dataset as $ds => $rows ) {
		if ( ! is_array( $rows ) ) {
			$read = false;
			continue;
		}
		$trunc = $trunc || count( $rows ) > SN_ANALYTICS_V2_SAMPLED_MAX;
		foreach ( array_slice( $rows, 0, SN_ANALYTICS_V2_SAMPLED_MAX ) as $r ) {
			$vid   = strtolower( (string) ( $r['vid'] ?? '' ) );
			$out[] = array(
				'dataset'    => (string) $ds,
				'day'        => (string) ( $r['day'] ?? '' ),
				'vid'        => $vid,
				'rows'       => (int) round( (float) ( $r['r'] ?? 0 ) ),
				'stands_for' => (int) round( (float) ( $r['n'] ?? 0 ) ),
				'stored_bot' => (int) ( $r['stored_bot'] ?? 0 ) > 0,
				'human'      => (int) ( $r['human'] ?? 0 ) > 0,
				'over_cap'   => isset( $cap[ $vid ] ),
			);
		}
	}
	// The question the check needs answered: did traffic the human reads COUNT get sampled?
	$human = count( array_filter( $out, static fn( $x ) => $x['human'] && ! $x['over_cap'] ) );
	$conclusive = $read && ! $trunc && $cap_ok;
	return array(
		'read'          => $read,
		'conclusive'    => $conclusive,
		'truncated'     => $trunc,
		'over_cap_ok'   => $cap_ok,
		'rows'          => $out,
		// A cut list can miss humans (too low); a missing cap list can count
		// over-cap visitors as human (too high). Neither is a bound: unknown.
		'counted_human' => $conclusive ? $human : null,
	);
}

function snt_ability_analytics_dual_write( $input = array() ) {
	$days = isset( $input['days'] ) ? (int) $input['days'] : 4;
	$stored = function_exists( 'get_option' ) && defined( 'SN_ANALYTICS_V2_VERIFIED_OPT' ) ? get_option( SN_ANALYTICS_V2_VERIFIED_OPT, null ) : null;
	// `verdict` is what the reads follow: the daily check's stored answer, not this live one.
	$days   = max( 1, min( 14, $days ) );
	$out    = sn_analytics_v2_check( $days ) + array( 'verdict' => is_array( $stored ) ? $stored : (object) array() );
	// Diagnostic (2026-10-05): every day so far reads `sampled`, at a volume
	// Analytics Engine documents as never sampled. Name the sampled
	// visitor-days, three more reads, on demand only (never the daily check).
	if ( array() !== array_filter( (array) ( $out['days'] ?? array() ), static fn( $d ) => ! empty( $d['sampled'] ) ) ) {
		// The over-cap list first (it may query too), then the three reads, stopping
		// at the first failure so its dataset and reason survive, as the check does.
		$list = function_exists( 'sn_analytics_overcap_vdays' ) ? (array) sn_analytics_overcap_vdays() : array();
		$by   = array();
		$fail = array();
		foreach ( array( SN_ANALYTICS_DATASET, SN_ANALYTICS_DATASET_PV_V2, SN_ANALYTICS_DATASET_EVENTS_V2 ) as $ds ) {
			$rows = function_exists( 'sn_analytics_query' ) ? sn_analytics_query( sn_analytics_v2_sampled_sql( $ds, $days ) ) : null;
			$by[ $ds ] = $rows;
			if ( ! is_array( $rows ) ) {
				$err  = function_exists( 'sn_analytics_last_error' ) ? sn_analytics_last_error() : null;
				$fail = array( 'failed' => $ds, 'error' => is_array( $err ) ? 'HTTP ' . (int) ( $err['code'] ?? 0 ) . ' ' . substr( (string) ( $err['message'] ?? '' ), 0, 200 ) : '' );
				break;
			}
		}
		$out['sampled_visitors'] = sn_analytics_v2_sampled_visitors( $by, $list ) + $fail;
	}
	return $out;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/analytics-dual-write', array(
		'label'               => 'Analytics: do the new datasets hold what the old one holds?',
		'description'         => 'The analytics worker (1.24.0 and later) writes every beacon to the legacy dataset and to two second-generation datasets. This counts rows per UTC day in all three (three live Analytics Engine requests) and compares them: every legacy row except property rows (`cp`) against sn_pageviews_v2, and custom events with their property rows against sn_events_v2 (the base row of a custom event is in both, by design, since worker 1.25.0). `state` per day: `partial` before `first_full_day` (the dual write began mid-day; a shortfall there is expected), then `match` or `mismatch`. `with_pid` is how many new rows carry a pageview ID (theme 15.3.0 and later). `read: false` means a request failed and nothing was compared (`failed` names the dataset, `error` the reason); it is NOT a mismatch. The comparison is event by event; `differs` names the events whose exact counts disagree. `sampled` means Analytics Engine sampled some events that day (`sampled_events`): those are estimates, and an event type both sides sampled identically (same stored rows, weighted count and distinct visitors) counts as exact and is listed in `identical_sample`; a day with any event sampled DIFFERENTLY is `sampled`, never `match`; on such a day `sampled_visitors` names the sampled visitor-days per dataset (rows stored vs rows they stand for), whether each holds rows the human reads count (the read-time rule) and whether it is over the page-view cap, and `counted_human`. `conclusive: false` (a failed or cut-short read, or no over-cap list) makes `counted_human` null: unknown, neither a floor nor a ceiling. It is a separate query: Analytics Engine picks a read resolution per query, so it shows the rows as this read saw them. `verdict` is the stored answer of the daily check ({ok, day, at, why}; empty until it has run): while `ok` is true, every read whose window starts on or after `first_full_day` uses the new datasets, and a mismatch sends them all back to the old one. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_analytics_dual_write',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 14, 'default' => 4, 'description' => 'UTC days back, today included.' ) ), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'read' => array( 'type' => 'boolean' ), 'days' => array( 'type' => 'array' ), 'mismatched' => array( 'type' => 'integer' ), 'first_full_day' => array( 'type' => 'string' ), 'datasets' => array( 'type' => 'array' ), 'failed' => array( 'type' => 'string' ), 'error' => array( 'type' => 'string' ), 'verdict' => array( 'type' => 'object' ), 'sampled_visitors' => array( 'type' => 'object' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => true ) ),
	) );
} );
