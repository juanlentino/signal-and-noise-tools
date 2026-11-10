<?php
/**
 * One line per reader for a fixture, for tests/html-readers-on-core.php.
 * $fn maps a reader's name to the function to call, so the same summary can be
 * taken from a former implementation when re-deriving the suite's $former table.
 */

/** Overlapping and touching [start,end) ranges merged: what the ranges cover, not how they are cut. */
function snt_test_cover( array $ranges ) {
	usort( $ranges, static function ( $a, $b ) { return $a[0] <=> $b[0]; } );
	$out = array();
	foreach ( $ranges as $r ) {
		$k = count( $out ) - 1;
		if ( $k >= 0 && $r[0] <= $out[ $k ][1] ) {
			$out[ $k ][1] = max( $out[ $k ][1], $r[1] );
		} else {
			$out[] = $r;
		}
	}
	return implode( ' ', array_map( static function ( $r ) { return $r[0] . '-' . $r[1]; }, $out ) );
}

function snt_test_reader_summary( $content, array $anchors, array $fn ) {
	$locate = array();
	foreach ( $anchors as $anchor ) {
		if ( false === strpos( $content, $anchor ) ) {
			continue;
		}
		$r        = $fn['locate']( $content, $anchor );
		$locate[] = $anchor . '=' . ( is_wp_error( $r ) ? str_replace( 'snt_sn_apply_anchor_', '', $r->get_error_code() ) : $r['offset'] . '+' . $r['length'] );
	}
	$thead = $fn['thead']( $content );
	return array(
		'locate'     => implode( '; ', $locate ),
		'violations' => implode( '; ', array_map( static function ( $v ) { return $v['rule'] . ':' . $v['anchor_text'] . ( null === $v['heading_level'] ? '' : ':h' . $v['heading_level'] ); }, $fn['violations']( $content ) ) ),
		'markup'     => snt_test_cover( $fn['ranges']( $content ) ),
		'emdash'     => implode( ' ', array_map( static function ( $r ) { return $r['position'] . ':' . $r['classification']; }, $fn['emdash']( $content ) ) ),
		'thead'      => ( $thead['thead'] ? 'yes' : 'no' ) . ' ' . $thead['th'] . '/' . $thead['th_without_scope'],
		'svg'        => implode( ', ', $fn['svg']( $content ) ),
	);
}
