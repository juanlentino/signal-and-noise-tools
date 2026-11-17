<?php
/**
 * Signal & Noise Tools: did Analytics Engine sample anything after the fix?
 *
 * Analytics worker 2.3.0 (live 2026-11-17 15:53:12 UTC) gave each event type
 * its own index, so a burst of one visitor's events is no longer sampled as
 * one. This counts, per event type, the page-view dataset's rows written since
 * then and how many carry a sample interval above 1, for every class and for
 * the counted-human class alone. The answer to "did it stop?" is
 * `human_sampled` = 0. One Analytics Engine read, cached an hour; never a
 * number changed anywhere else.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_PER_KIND_SINCE = '2026-11-17 15:53:12'; // UTC, analytics worker 2.3.0 deployed_at.
const SN_ANALYTICS_SAMPLING_SINCE_KEY = 'sn_analytics_sampling_since';

/**
 * The read. PURE.
 *
 * @param string $class Class SELECT expression (sn_analytics_rollup_class_parts()).
 * @return string
 */
function sn_analytics_sampling_since_sql( $class ) {
	return "SELECT blob1 AS ev, {$class} AS class, count() AS r, sum(if(_sample_interval > 1, 1, 0)) AS s"
		. " FROM sn_pageviews_v2 WHERE timestamp >= toDateTime('" . SN_ANALYTICS_PER_KIND_SINCE . "') GROUP BY ev, class";
}

/**
 * Fold rows into the answer. PURE.
 *
 * @param array|null $rows AE rows {ev, class, r, s}; null when the read failed.
 * @param int        $now  Unix time.
 * @return array{read:bool,since:string,hours:float,events:array,human_sampled:int|null,sampled:int|null}
 */
function sn_analytics_sampling_since_fold( $rows, $now ) {
	$out = array(
		'read'          => is_array( $rows ),
		'since'         => SN_ANALYTICS_PER_KIND_SINCE . ' UTC',
		'hours'         => round( max( 0, $now - (int) strtotime( SN_ANALYTICS_PER_KIND_SINCE . ' UTC' ) ) / 3600, 1 ),
		'events'        => array(),
		'human_sampled' => null,
		'sampled'       => null,
	);
	if ( ! is_array( $rows ) ) {
		return $out;
	}
	$out['human_sampled'] = 0;
	$out['sampled']       = 0;
	foreach ( $rows as $r ) {
		$r  = (array) $r;
		$ev = (string) ( $r['ev'] ?? '' );
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,12}$/', $ev ) ) {
			continue;
		}
		$e     = $out['events'][ $ev ] ?? array( 'rows' => 0, 'sampled' => 0, 'human_rows' => 0, 'human_sampled' => 0 );
		$rr    = (int) ( $r['r'] ?? 0 );
		$ss    = (int) ( $r['s'] ?? 0 );
		$human = 'human' === (string) ( $r['class'] ?? '' );
		$out['events'][ $ev ] = array(
			'rows'          => $e['rows'] + $rr,
			'sampled'       => $e['sampled'] + $ss,
			'human_rows'    => $e['human_rows'] + ( $human ? $rr : 0 ),
			'human_sampled' => $e['human_sampled'] + ( $human ? $ss : 0 ),
		);
		$out['sampled']       += $ss;
		$out['human_sampled'] += $human ? $ss : 0;
	}
	ksort( $out['events'] );
	return $out;
}

/**
 * The cached answer (one read an hour). Null when analytics is not configured
 * or the over-cap list did not read whole (bots would then count as human).
 *
 * @return array|null
 */
function sn_analytics_sampling_since() {
	$cached = get_transient( SN_ANALYTICS_SAMPLING_SINCE_KEY );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return null;
	}
	// A failed or cut list lets over-cap visitor-days read as human, so a bot's
	// sampled rows would read as a sampled human (the sampled-days run's rule).
	$cap = sn_analytics_overcap_vdays();
	if ( empty( $cap['ok'] ) || ! empty( $cap['truncated'] ) ) {
		return null;
	}
	list( $class ) = sn_analytics_rollup_class_parts( true );
	$out           = sn_analytics_sampling_since_fold( sn_analytics_query( sn_analytics_sampling_since_sql( $class ) ), time() );
	set_transient( SN_ANALYTICS_SAMPLING_SINCE_KEY, $out, $out['read'] ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	return $out;
}

/** Output schema, shared by the summary ability and its remote twin. */
function sn_analytics_sampling_since_schema() {
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Whether Analytics Engine still samples at write time since analytics worker 2.3.0 (`since`, UTC): per event type of the page-view dataset, rows written since then (`rows`) and those with a sample interval above 1 (`sampled`), for every class and for the counted-human class (`human_rows`, `human_sampled`). `human_sampled` 0 means no human row was sampled since the fix. `read` false: the read failed and nothing is known. `hours`: hours since the fix. Cached up to an hour. Null when not read.',
	);
}
