<?php
/**
 * Signal & Noise Tools: where current readers arrived from. ADMIN ONLY.
 *
 * One grouped query on each realtime refresh: pageviews in the last 5
 * minutes, distinct human readers per referrer host (blob3), folded into the
 * dashboard's own source names (sn_analytics_canonical_source()). A click
 * inside the site is not a source and is dropped; no referrer reads Direct.
 * Only the gated live/admin route carries it, never the public one.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_LIVE_SOURCES_MAX = 5;

/**
 * @return string AE SQL.
 */
function sn_analytics_live_sources_sql() {
	return implode( ' ', array(
		'SELECT blob3 AS host, count(DISTINCT index1) AS readers',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '5' MINUTE AND blob1 = 'pv' AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'GROUP BY host ORDER BY readers DESC LIMIT 40',
	) );
}

/**
 * Rows to the top sources. PURE given the self-host list.
 *
 * @param array      $rows       AE rows { host, readers }.
 * @param array|null $self_hosts The site's own hosts (null reads the site's).
 * @return array<int,array{label:string,readers:int}>
 */
function sn_analytics_live_sources_from_rows( array $rows, $self_hosts = null ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['readers'] ) ) {
			continue;
		}
		$label = sn_analytics_canonical_source( (string) ( $row['host'] ?? '' ), $self_hosts );
		if ( SN_ANALYTICS_INTERNAL_REFERRER === $label ) {
			continue;
		}
		$label        = '(direct)' === $label ? __( 'Direct', 'signal-and-noise-tools' ) : $label;
		$by[ $label ] = ( $by[ $label ] ?? 0 ) + max( 0, (int) $row['readers'] );
	}
	$out = array();
	foreach ( $by as $label => $readers ) {
		if ( $readers > 0 ) {
			$out[] = array( 'label' => (string) $label, 'readers' => (int) $readers );
		}
	}
	usort( $out, static fn( $a, $b ) => $b['readers'] <=> $a['readers'] ?: strcmp( $a['label'], $b['label'] ) );
	return array_slice( $out, 0, SN_ANALYTICS_LIVE_SOURCES_MAX );
}

/**
 * The read, for the realtime refresh. Null on a failed query.
 *
 * @return array|null
 */
function sn_analytics_live_sources_read() {
	$rows = sn_analytics_query( sn_analytics_live_sources_sql() );
	return is_array( $rows ) ? sn_analytics_live_sources_from_rows( $rows ) : null;
}
