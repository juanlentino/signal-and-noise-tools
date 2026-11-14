<?php
/**
 * SN Reading's two groups that came from SN Traffic (owner 2026-11-14): what
 * people read is Reading's, how many came is Traffic's. "This week" (the north
 * star's engaged visitor-days, DOI downloads and inquiries) and "Top pages"
 * (the window's most-viewed pages). Same figures, same sources as on Traffic.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "This week": rows from the north star reading, or none when it is not set up. PURE given the reading.
 *
 * @param array|null $ns snt_nsm_reading().
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_reading_week_rows( $ns ) {
	if ( ! is_array( $ns ) || empty( $ns['configured'] ) ) {
		return array();
	}
	$v    = (int) $ns['value'];
	$d    = $v - (int) ( $ns['previous'] ?? 0 );
	$prev = (int) ( $ns['previous'] ?? 0 );
	$row  = array( 'label' => 'Engaged visitor-days · 7d', 'value' => (string) $v . ( $d ? ' ' . ( $d > 0 ? '▲' : '▼' ) . ' ' . abs( $d ) : '' ) );
	// Traffic's rule, kept: a change is colored only when it is meaningful (at least 5, and 20% of the prior week).
	if ( abs( $d ) >= 5 && ( $prev <= 0 || abs( $d ) * 100 >= 20 * $prev ) ) {
		$row['tone'] = $d > 0 ? 'up' : 'down';
	}
	$rows = array( $row );
	$doi  = $ns['layers']['return']['doi_downloads'] ?? null;
	if ( is_array( $doi ) && isset( $doi['value'] ) && is_numeric( $doi['value'] ) ) {
		$rows[] = array( 'label' => 'DOI downloads · ' . (string) ( $doi['window'] ?? '7d' ), 'value' => (string) (int) $doi['value'] );
	}
	$inq = $ns['layers']['return']['inquiries']['value'] ?? null;
	if ( is_numeric( $inq ) ) {
		$rows[] = array( 'label' => 'Inquiries · 7d', 'value' => (string) (int) $inq );
	}
	return $rows;
}

/**
 * "Top pages": the window's three most-viewed pages, human. PURE given the rows.
 *
 * @param array|null $top sn_analytics_top_paths() rows.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_reading_top_rows( $top ) {
	$rows = array();
	foreach ( (array) $top as $r ) {
		if ( is_array( $r ) && isset( $r['path'] ) && count( $rows ) < 3 ) {
			$rows[] = array( 'label' => (string) $r['path'], 'value' => number_format_i18n( (int) ( $r['views'] ?? 0 ) ) );
		}
	}
	return $rows;
}
