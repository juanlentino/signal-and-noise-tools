<?php
/**
 * Analytics export formatters — turn durable-table read rows into a downloadable
 * CSV or JSON payload. Pure (no I/O); the admin-post handler streams the result.
 *
 * @package SignalAndNoiseTools
 * @since   6.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RFC-4180 CSV from a list of associative rows (header = first row's keys).
 *
 * @param array<int, array<string, scalar>> $rows
 * @return string
 */
function sn_analytics_export_csv( $rows ) {
	if ( empty( $rows ) ) {
		return '';
	}
	$fh   = fopen( 'php://temp', 'r+' );
	$keys = array_keys( $rows[0] );
	// PHP 8.5: $escape parameter must be explicit. Empty string disables the
	// non-standard backslash escape mechanism — RFC-4180 double-quoting suffices.
	fputcsv( $fh, $keys, ',', '"', '' );
	foreach ( $rows as $r ) {
		$line = array();
		foreach ( $keys as $k ) {
			$line[] = $r[ $k ] ?? '';
		}
		fputcsv( $fh, $line, ',', '"', '' );
	}
	rewind( $fh );
	$out = stream_get_contents( $fh );
	fclose( $fh );
	return (string) $out;
}

/**
 * Pretty JSON from a list of rows.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return string
 */
function sn_analytics_export_json( $rows ) {
	return (string) wp_json_encode( $rows );
}
