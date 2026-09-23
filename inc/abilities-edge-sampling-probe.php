<?php
/**
 * Signal & Noise Tools, `signal-noise/edge-sampling-probe`: two live GraphQL
 * reads that answer a question the stored edge figures cannot (17.9.2, #1002).
 *
 * The week's sampled 5xx rows (errors_5xx, ~38,600, all `edge=504 origin=-`)
 * disagree with the zone's exact daily totals (~6,100 5xx, mostly 503). 17.9.1
 * assumed a double count and stopped multiplying a grouped `count` by its
 * `sampleInterval`; the repaired totals did not move, which refuted that
 * reading at this volume. This probe measures instead of inferring:
 *
 *   A  count and avg{sampleInterval} per 5xx group, so whether sampling is
 *      active here at all is a reading, not an assumption;
 *   B  the same grouped by requestSource, so a 504 from a Worker's subrequest
 *      and one from a visitor's request are told apart. Its own document: an
 *      unknown field fails only B, and B's error is reported rather than lost.
 *
 * Live (it spends two GraphQL calls), read-only, on demand. Never writes.
 *
 * @package SignalNoiseTools
 * @since 17.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Summarise GraphQL groups: rows, total count, count × sampleInterval, and the
 * sampleInterval range. PURE.
 *
 * @param array $groups Rows with count, avg.sampleInterval, dimensions.
 * @return array{rows:int,count:int,count_times_interval:int,interval_min:float|null,interval_max:float|null,sampled_rows:int}
 */
function snt_edge_sampling_summary( array $groups ) {
	$out = array( 'rows' => 0, 'count' => 0, 'count_times_interval' => 0, 'interval_min' => null, 'interval_max' => null, 'sampled_rows' => 0 );
	foreach ( $groups as $g ) {
		if ( ! is_array( $g ) ) {
			continue;
		}
		$c  = (int) ( $g['count'] ?? 0 );
		$si = (float) ( $g['avg']['sampleInterval'] ?? 1 );
		++$out['rows'];
		$out['count']                += $c;
		$out['count_times_interval'] += (int) round( $c * max( 1.0, $si ) );
		$out['interval_min']          = null === $out['interval_min'] ? $si : min( $out['interval_min'], $si );
		$out['interval_max']          = null === $out['interval_max'] ? $si : max( $out['interval_max'], $si );
		if ( $si > 1.0 ) {
			++$out['sampled_rows'];
		}
	}
	return $out;
}

/**
 * Read C's verdict: does a sampled group `count` already carry Cloudflare's
 * estimate, or must it be multiplied by sampleInterval? PURE. The adaptive
 * visitor total is computed both ways and each set against the exact total;
 * the closer one wins, and a verdict is only given when that one is within
 * 10% and the other is not, since a near-tie at low sampling settles nothing.
 *
 * @param string $day    YYYY-MM-DD read.
 * @param array  $groups Adaptive groups (count, avg.sampleInterval).
 * @param int    $exact  httpRequests1dGroups requests for the day.
 * @return array{day:string,exact:int,as_count:int,as_count_times_interval:int,verdict:string}
 */
function snt_edge_count_meaning( $day, array $groups, $exact ) {
	$s   = snt_edge_sampling_summary( $groups );
	$off = static function ( $v ) use ( $exact ) {
		return $exact > 0 ? abs( $v - $exact ) / $exact : INF;
	};
	$a       = $off( $s['count'] );
	$b       = $off( $s['count_times_interval'] );
	$verdict = 'unsettled';
	if ( $a <= 0.10 && $b > 0.10 ) {
		$verdict = 'count_is_the_estimate';
	} elseif ( $b <= 0.10 && $a > 0.10 ) {
		$verdict = 'multiply_by_interval';
	}
	return array(
		'day'                     => (string) $day,
		'exact'                   => (int) $exact,
		'as_count'                => $s['count'],
		'as_count_times_interval' => $s['count_times_interval'],
		'verdict'                 => $verdict,
	);
}

/**
 * @param mixed $input Unused.
 * @return array<string,mixed>
 */
function snt_ability_edge_sampling_probe( $input = null ) {
	unset( $input );
	if ( ! function_exists( 'sn_edge_config' ) || ! sn_edge_config() ) {
		return array( 'state' => 'unconfigured' );
	}
	$until = gmdate( 'Y-m-d\TH:i:s\Z' );
	$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS );
	$vars  = array( 'from' => $since, 'to' => $until );

	$doc_a = 'query($zone:string!,$from:Time!,$to:Time!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'a:httpRequestsAdaptiveGroups(limit:100,filter:{datetime_geq:$from,datetime_lt:$to,edgeResponseStatus_geq:500},orderBy:[count_DESC]){'
		. 'count avg{sampleInterval} dimensions{edgeResponseStatus originResponseStatus clientRequestPath}}}}}';
	$doc_b = 'query($zone:string!,$from:Time!,$to:Time!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'b:httpRequestsAdaptiveGroups(limit:50,filter:{datetime_geq:$from,datetime_lt:$to,edgeResponseStatus_geq:500},orderBy:[count_DESC]){'
		. 'count avg{sampleInterval} dimensions{requestSource edgeResponseStatus}}}}}';

	$err_a = '';
	$err_b = '';
	$a     = sn_edge_query( $doc_a, $vars, $err_a );
	$b     = sn_edge_query( $doc_b, $vars, $err_b );

	// 17.9.3, read C: which reading of a sampled `count` is right, settled by
	// a total both datasets carry. Yesterday (UTC), visitor requests only,
	// from the adaptive groups, beside the exact httpRequests1dGroups total
	// for the same day. Whichever of `count` and `count x sampleInterval`
	// lands on the exact figure is how Cloudflare means `count`.
	$day   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	$doc_c = 'query($zone:string!,$from:Time!,$to:Time!,$day:Date!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'c:httpRequestsAdaptiveGroups(limit:10000,filter:{datetime_geq:$from,datetime_lt:$to,requestSource:"eyeball"}){count avg{sampleInterval}}'
		. 'x:httpRequests1dGroups(limit:1,filter:{date_geq:$day,date_leq:$day}){sum{requests}}}}}';
	$err_c = '';
	$c     = sn_edge_query( $doc_c, array( 'from' => $day . 'T00:00:00Z', 'to' => gmdate( 'Y-m-d', strtotime( $day . ' UTC' ) + DAY_IN_SECONDS ) . 'T00:00:00Z', 'day' => $day ), $err_c );

	$rows_a = is_array( $a ) ? (array) ( $a['a'] ?? array() ) : array();
	$rows_b = is_array( $b ) ? (array) ( $b['b'] ?? array() ) : array();

	$by_source = array();
	foreach ( $rows_b as $g ) {
		$src                = (string) ( $g['dimensions']['requestSource'] ?? '' );
		$key                = ( '' === $src ? '(none)' : $src ) . ' ' . (int) ( $g['dimensions']['edgeResponseStatus'] ?? 0 );
		$by_source[ $key ] = ( $by_source[ $key ] ?? 0 ) + (int) ( $g['count'] ?? 0 );
	}
	arsort( $by_source );

	return array(
		'state'           => 'measured',
		'window'          => array( 'from' => $since, 'to' => $until ),
		'sampling'        => is_array( $a ) ? snt_edge_sampling_summary( $rows_a ) : null,
		'sampling_error'  => $err_a,
		'top_groups'      => array_slice( array_map( static function ( $g ) {
			return array(
				'count'           => (int) ( $g['count'] ?? 0 ),
				'sample_interval' => (float) ( $g['avg']['sampleInterval'] ?? 1 ),
				'edge'            => (int) ( $g['dimensions']['edgeResponseStatus'] ?? 0 ),
				'origin'          => (int) ( $g['dimensions']['originResponseStatus'] ?? 0 ),
				'path'            => (string) ( $g['dimensions']['clientRequestPath'] ?? '' ),
			);
		}, $rows_a ), 0, 15 ),
		'by_request_source' => is_array( $b ) ? $by_source : null,
		'source_error'      => $err_b,
		'count_meaning'     => is_array( $c ) ? snt_edge_count_meaning( $day, (array) ( $c['c'] ?? array() ), (int) ( $c['x'][0]['sum']['requests'] ?? 0 ) ) : null,
		'count_error'       => $err_c,
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/edge-sampling-probe', array(
		'label'               => 'Edge Sampling Probe',
		'description'         => 'Two LIVE Cloudflare GraphQL reads over the last 24 hours of 5xx (each run spends two API calls; read-only, writes nothing). `sampling`: count, count × sampleInterval and the sampleInterval range across the sampled 5xx groups, so whether adaptive sampling is active at this volume is measured (`sampled_rows` > 0 means some rows stand for more than one event). `by_request_source`: the same 5xx grouped by requestSource and status, which separates a Worker subrequest (edgeWorkerFetch) from a visitor request (eyeball). Each read reports its own error (`sampling_error`, `source_error`) instead of failing the other. Built for #1002: the sampled 5xx and the zone\'s exact totals disagree on count and on code.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_edge_sampling_probe',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'             => array( 'type' => 'string', 'enum' => array( 'unconfigured', 'measured' ) ),
				'window'            => array( 'type' => 'object' ),
				'sampling'          => array( 'type' => array( 'object', 'null' ) ),
				'sampling_error'    => array( 'type' => 'string' ),
				'top_groups'        => array( 'type' => 'array' ),
				'by_request_source' => array( 'type' => array( 'object', 'null' ) ),
				'source_error'      => array( 'type' => 'string' ),
				'count_meaning'     => array( 'type' => array( 'object', 'null' ), 'description' => '17.9.3: yesterday\'s visitor requests from the sampled groups, as `count` and as `count x sampleInterval`, beside the exact daily total; verdict count_is_the_estimate, multiply_by_interval, or unsettled (neither or both within 10%).' ),
				'count_error'       => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => true ),
		),
	) );
} );
