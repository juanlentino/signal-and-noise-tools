<?php
/**
 * Signal & Noise Tools — Bing Webmaster Tools: the site's Bing search figures.
 *
 * The Bing twin of the Search Console sync. One API key (Connections ›
 * Credentials, `bing_webmaster_key`, minted at Bing Webmaster Tools ›
 * Settings › API access) reads two documented JSON methods for the site:
 * GetRankAndTrafficStats (daily impressions and clicks, updated daily) and
 * GetQueryStats (top queries with positions, updated weekly). A daily sync
 * stores the shaped reading in one option; the S&N Analytics › Search view
 * and the `bing-search-performance` ability read that option, never Bing.
 *
 * The key travels as the documented `apikey` query parameter over HTTPS and
 * is never written to a log or an error string.
 *
 * @since 16.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_BING_API_BASE    = 'https://ssl.bing.com/webmaster/api.svc/json/';
const SN_BING_DATA_OPTION = 'sn_bing_webmaster_data';
const SN_BING_SYNC_HOOK   = 'sn_bing_sync_daily';
const SN_BING_WINDOW_DAYS = 28;
const SN_BING_TOP_QUERIES = 25;

/** The key, from the keyring; '' when none. */
function sn_bing_key() {
	return function_exists( 'sn_credential' ) ? (string) sn_credential( 'bing_webmaster_key' ) : '';
}

/** The site as Bing names it: the home URL with its trailing slash. */
function sn_bing_site_url() {
	return function_exists( 'home_url' ) ? trailingslashit( home_url( '/' ) ) : '';
}

/** Readiness: a key is stored. The sync keeps its schedule equal to this. */
function sn_bing_is_ready() {
	return '' !== sn_bing_key();
}

/**
 * One documented GET. Returns {ok, code, body (decoded `d` or raw), error}
 * and never throws. The key is stripped from every error string.
 *
 * @since 16.2.0
 */
function sn_bing_request( $method, array $params = array(), $key = null ) {
	$key = null === $key ? sn_bing_key() : (string) $key;
	if ( '' === $key ) {
		return array( 'ok' => false, 'code' => 0, 'body' => null, 'error' => 'no-key' );
	}
	$url  = SN_BING_API_BASE . rawurlencode( (string) $method ) . '?' . http_build_query( array_merge( $params, array( 'apikey' => $key ) ) );
	$resp = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'signal-and-noise-tools' ) ) );
	if ( is_wp_error( $resp ) ) {
		return array( 'ok' => false, 'code' => 0, 'body' => null, 'error' => str_replace( $key, '[key]', $resp->get_error_message() ) );
	}
	$code    = (int) wp_remote_retrieve_response_code( $resp );
	$raw     = (string) wp_remote_retrieve_body( $resp );
	$decoded = json_decode( $raw, true );
	$ok      = 200 === $code && is_array( $decoded ) && array_key_exists( 'd', $decoded );
	$error   = '';
	if ( ! $ok ) {
		$msg   = is_array( $decoded ) ? (string) ( $decoded['Message'] ?? $decoded['message'] ?? '' ) : '';
		$error = '' !== $msg ? $msg : 'http-' . $code;
	}
	return array( 'ok' => $ok, 'code' => $code, 'body' => $ok ? $decoded['d'] : $raw, 'error' => str_replace( $key, '[key]', $error ) );
}

/**
 * Bing's `/Date(1316156400000-0700)/` to `Y-m-d` (UTC). '' when it is not
 * that shape. PURE.
 *
 * @since 16.2.0
 */
function sn_bing_parse_date( $raw ) {
	if ( ! preg_match( '#/Date\((-?\d+)(?:[+-]\d{4})?\)/#', (string) $raw, $m ) ) {
		return '';
	}
	return gmdate( 'Y-m-d', (int) floor( (int) $m[1] / 1000 ) );
}

/**
 * Does the key's site list carry this site, verified? PURE.
 *
 * @since 16.2.0
 * @return array{listed:bool,verified:bool}
 */
function sn_bing_site_state( $sites, $home ) {
	$norm = static function ( $u ) {
		return strtolower( trim( (string) preg_replace( '#^https?://(www\.)?#', '', (string) $u ), '/' ) );
	};
	$want = $norm( $home );
	foreach ( (array) $sites as $s ) {
		if ( is_array( $s ) && $norm( $s['Url'] ?? '' ) === $want ) {
			return array( 'listed' => true, 'verified' => ! empty( $s['IsVerified'] ) );
		}
	}
	return array( 'listed' => false, 'verified' => false );
}

/**
 * Shape the two readings into the stored record. PURE given the rows and
 * the day the window ends: the last SN_BING_WINDOW_DAYS days of traffic are
 * summed; queries are ranked by impressions and cut to the top rows; every
 * figure is an integer or a rounded float, never a string.
 *
 * @since 16.2.0
 */
function sn_bing_shape( $traffic_rows, $query_rows, $end_ymd, $days = SN_BING_WINDOW_DAYS ) {
	$end   = strtotime( $end_ymd . ' 00:00:00 UTC' );
	$start = $end - ( ( (int) $days - 1 ) * DAY_IN_SECONDS );
	$daily = array();
	foreach ( (array) $traffic_rows as $r ) {
		$d = is_array( $r ) ? sn_bing_parse_date( $r['Date'] ?? '' ) : '';
		if ( '' === $d ) {
			continue;
		}
		$t = strtotime( $d . ' 00:00:00 UTC' );
		if ( $t < $start || $t > $end ) {
			continue;
		}
		$daily[ $d ] = array( 'clicks' => (int) ( $r['Clicks'] ?? 0 ), 'impressions' => (int) ( $r['Impressions'] ?? 0 ) );
	}
	ksort( $daily );
	$totals = array( 'clicks' => 0, 'impressions' => 0, 'days' => count( $daily ) );
	foreach ( $daily as $m ) {
		$totals['clicks']      += $m['clicks'];
		$totals['impressions'] += $m['impressions'];
	}
	$queries = array();
	foreach ( (array) $query_rows as $r ) {
		if ( ! is_array( $r ) || '' === trim( (string) ( $r['Query'] ?? '' ) ) ) {
			continue;
		}
		$imp = (int) ( $r['Impressions'] ?? 0 );
		$clk = (int) ( $r['Clicks'] ?? 0 );
		$queries[] = array(
			'key'         => trim( (string) $r['Query'] ),
			'clicks'      => $clk,
			'impressions' => $imp,
			'ctr'         => $imp > 0 ? round( $clk / $imp, 4 ) : 0.0,
			'position'    => round( (float) ( $r['AvgImpressionPosition'] ?? 0 ), 1 ),
		);
	}
	usort( $queries, static function ( $a, $b ) {
		return $b['impressions'] <=> $a['impressions'] ?: $b['clicks'] <=> $a['clicks'];
	} );
	return array(
		'window'  => array( 'start' => gmdate( 'Y-m-d', $start ), 'end' => $end_ymd, 'days' => (int) $days ),
		'totals'  => $totals,
		'daily'   => $daily,
		'queries' => array_slice( $queries, 0, SN_BING_TOP_QUERIES ),
	);
}

/** The stored reading, or null when nothing has synced. */
function sn_bing_data() {
	$d = get_option( SN_BING_DATA_OPTION, null );
	return is_array( $d ) && isset( $d['synced_at'] ) ? $d : null;
}

/**
 * Fetch both readings and store them. Keeps the last good record on a
 * failed fetch, with the failure beside it: a refused key must not read as
 * an empty search.
 *
 * @since 16.2.0
 * @return array{ok:bool,error:string}
 */
function sn_bing_sync() {
	if ( ! sn_bing_is_ready() ) {
		return array( 'ok' => false, 'error' => 'no-key' );
	}
	$site    = sn_bing_site_url();
	$traffic = sn_bing_request( 'GetRankAndTrafficStats', array( 'siteUrl' => $site ) );
	$queries = sn_bing_request( 'GetQueryStats', array( 'siteUrl' => $site ) );
	$prev    = sn_bing_data();
	if ( ! $traffic['ok'] || ! $queries['ok'] ) {
		$error = $traffic['ok'] ? 'GetQueryStats: ' . $queries['error'] : 'GetRankAndTrafficStats: ' . $traffic['error'];
		update_option( SN_BING_DATA_OPTION, array_merge( is_array( $prev ) ? $prev : array( 'synced_at' => 0 ), array( 'last_error' => gmdate( 'c' ) . ' ' . $error ) ), false );
		return array( 'ok' => false, 'error' => $error );
	}
	// Bing's traffic ends a day or two back; the window ends on the newest
	// day it reports, never on "today".
	$newest = '';
	foreach ( (array) $traffic['body'] as $r ) {
		$d = is_array( $r ) ? sn_bing_parse_date( $r['Date'] ?? '' ) : '';
		if ( $d > $newest ) {
			$newest = $d;
		}
	}
	$record = sn_bing_shape( $traffic['body'], $queries['body'], '' !== $newest ? $newest : gmdate( 'Y-m-d' ) );
	$record['site']       = $site;
	$record['synced_at']  = time();
	$record['last_error'] = '';
	update_option( SN_BING_DATA_OPTION, $record, false );
	return array( 'ok' => true, 'error' => '' );
}
add_action( SN_BING_SYNC_HOOK, 'sn_bing_sync' );
add_action( 'init', function () {
	if ( sn_bing_is_ready() && ! wp_next_scheduled( SN_BING_SYNC_HOOK ) ) {
		wp_schedule_event( time() + 600, 'daily', SN_BING_SYNC_HOOK );
	}
}, 20 );
