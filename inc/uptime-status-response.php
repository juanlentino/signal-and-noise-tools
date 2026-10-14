<?php
/**
 * Signal & Noise Tools: the 24h response-time read for the Better Stack
 * monitors (split out of inc/uptime-status.php, 2026-10-13).
 *
 * Owner-approved 2026-10-13 ("Yes, show the median"): the figure was a plain
 * mean of every sample, so seven checks near the 30 s timeout out of ~510
 * turned a 21 ms median into a 282 ms "average". It is now the MEDIAN, plus
 * the slowest single check (its time in ms, the region it came from, and
 * when), so the outliers are named instead of smeared into the typical figure.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Renamed from sn_uptime_response_times (2026-10-13): that key held an int
// mean per monitor; a cache written by the old code must never be read as
// the new per-monitor stats array.
define( 'SN_UPTIME_STATUS_RESPONSE_TRANSIENT', 'sn_uptime_response_stats' );

/**
 * A readable name for a Better Stack check region. Only the codes the API
 * documents are named; anything else is returned as given, never guessed.
 *
 * @param string $code Region code from the response-times payload.
 * @return string|null Null when the payload carried no region.
 */
function sn_uptime_status_region_label( $code ) {
	$code = strtolower( trim( (string) $code ) );
	if ( '' === $code ) {
		return null;
	}
	$names = array(
		'us' => 'the US',
		'eu' => 'Europe',
		'as' => 'Asia',
		'au' => 'Australia',
	);
	return $names[ $code ] ?? $code;
}

/**
 * Median and slowest check over one monitor's regions (samples in SECONDS).
 * A sample without a numeric response_time is left out, never counted as 0.
 *
 * @param array $regions data.attributes.regions from the response-times endpoint.
 * @return array|null {median_ms:int, max_ms:int, max_region:string|null, max_at:string|null, samples:int}, or null with no samples.
 */
function sn_uptime_status_response_stats( $regions ) {
	$times = array();
	$max   = null;
	foreach ( (array) $regions as $region ) {
		foreach ( (array) ( $region['response_times'] ?? array() ) as $sample ) {
			if ( ! isset( $sample['response_time'] ) || ! is_numeric( $sample['response_time'] ) ) {
				continue;
			}
			$t       = (float) $sample['response_time'];
			$times[] = $t;
			if ( null === $max || $t > $max['t'] ) {
				$max = array(
					't'      => $t,
					'region' => $region['region'] ?? '',
					'at'     => isset( $sample['at'] ) && '' !== (string) $sample['at'] ? (string) $sample['at'] : null,
				);
			}
		}
	}
	$n = count( $times );
	if ( 0 === $n ) {
		return null;
	}
	sort( $times );
	$mid    = intdiv( $n, 2 );
	$median = 1 === $n % 2 ? $times[ $mid ] : ( $times[ $mid - 1 ] + $times[ $mid ] ) / 2;
	return array(
		'median_ms'  => (int) round( 1000 * $median ),
		'max_ms'     => (int) round( 1000 * $max['t'] ),
		'max_region' => sn_uptime_status_region_label( $max['region'] ),
		'max_at'     => $max['at'],
		'samples'    => $n,
	);
}

/**
 * Response stats per MONITOR (heartbeats are inbound; they have no response
 * times) from the 24h response-times endpoint. 15 min TTL; the same
 * circuit-break discipline as the availability maps.
 *
 * @param array $rows Normalized snapshot rows.
 * @return array Map of "monitor:{id}" to the stats array or null.
 */
function sn_uptime_status_response_map( $rows ) {
	$cached = get_transient( SN_UPTIME_STATUS_RESPONSE_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$map    = array();
	$broken = false;

	foreach ( $rows as $row ) {
		if ( 'monitor' !== $row['kind'] || '' === $row['id'] ) {
			continue;
		}
		$key = 'monitor:' . $row['id'];
		if ( $broken ) {
			$map[ $key ] = null;
			continue;
		}
		$resp = sn_uptime_status_api_get( 'v2/monitors/' . rawurlencode( $row['id'] ) . '/response-times' );
		if ( is_wp_error( $resp ) || ! isset( $resp['data']['attributes']['regions'] ) || ! is_array( $resp['data']['attributes']['regions'] ) ) {
			$broken      = true;
			$map[ $key ] = null;
			continue;
		}
		$map[ $key ] = sn_uptime_status_response_stats( $resp['data']['attributes']['regions'] );
	}

	set_transient( SN_UPTIME_STATUS_RESPONSE_TRANSIENT, $map, $broken ? 600 : 900 );
	return $map;
}

/**
 * The response keys every row carries, null unless the detail tier read them.
 * response_ms is the median (it was the mean before 2026-10-13; the key stays
 * for existing readers).
 *
 * @param array|null $stats One monitor's stats, or null.
 * @return array
 */
function sn_uptime_status_response_fields( $stats ) {
	$s = is_array( $stats ) ? $stats : array();
	return array(
		'response_ms' => $s['median_ms'] ?? null,
		'median_ms'   => $s['median_ms'] ?? null,
		'max_ms'      => $s['max_ms'] ?? null,
		'max_region'  => $s['max_region'] ?? null,
		'max_at'      => $s['max_at'] ?? null,
	);
}
