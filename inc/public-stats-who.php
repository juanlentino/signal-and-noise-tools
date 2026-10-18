<?php
/**
 * Signal & Noise Tools: /stats, who the machines are, as rows (2026-10-18).
 *
 * The four parts of the machine reads (verified, named themselves and not
 * verified, not measured, this site's own tools), each with the rows that sit
 * inside it, nested one or two levels: verified by source, and the reads that
 * failed a check or presented a control token. Every share is of all machine
 * reads, so a nested row reads against the same whole as its parent. The
 * words are the shared vocabulary (inc/machine-readers-impostor-copy.php);
 * the long descriptions are said once, in a note under the rows. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/machine-readers-impostor-copy.php'; // the words, shared with every surface.

/**
 * A share as text: a real part under 1% reads "<1%", never "0%".
 *
 * @param int $n   The part.
 * @param int $all The whole.
 * @return string
 */
function sn_public_stats_share_text( $n, $all ) {
	return ( $n > 0 && 100 * $n / $all < 1 ? '<1' : (string) (int) round( 100 * $n / $all ) ) . '%';
}

/**
 * The rows for sn_public_stats_bars_html(). [] when no split was measured.
 *
 * Shown: Verified and Named themselves always; Not measured and this site's
 * own tools when they have reads; by Cloudflare always under Verified; by the
 * vendor's published ranges once the sensor measures it (0 is then a real
 * figure); failed a check, each reason and the control token only with reads.
 *
 * @param array $m The machines snapshot (split, impostors, impostor_reasons, tokens).
 * @return array
 */
function sn_public_stats_who_rows( array $m ) {
	$s   = (array) ( $m['split'] ?? array() );
	$top = array( 'verified', 'unverified', 'not_measured', 'first_party' );
	$all = array_sum( array_map( static fn( $k ) => max( 0, (int) ( $s[ $k ] ?? 0 ) ), $top ) );
	if ( $all < 1 ) {
		return array();
	}
	$l    = snt_mr_reader_labels();
	$row  = static fn( $label, $n, $depth ) => array( 'label' => $label, 'text' => sn_public_stats_share_text( (int) $n, $all ), 'pct' => 100 * (int) $n / $all, 'depth' => $depth );
	$vend = isset( $s['vendor_verified'] ) ? (int) $s['vendor_verified'] : null;
	$toks = array_values( array_filter( (array) ( $m['tokens'] ?? array() ), static fn( $i ) => is_array( $i ) && (int) ( $i['hits'] ?? 0 ) > 0 ) );
	$tokn = isset( $s['control_token'] ) ? (int) $s['control_token'] : array_sum( array_map( static fn( $i ) => (int) $i['hits'], $toks ) );
	$rows = array();
	foreach ( $top as $i => $k ) {
		$n = max( 0, (int) ( $s[ $k ] ?? 0 ) );
		if ( $i >= 2 && $n < 1 ) {
			continue;
		}
		$rows[] = $row( $l[ $k ]['label'], $n, 0 );
		if ( 'verified' === $k ) {
			$rows[] = $row( $l['by_cloudflare']['label'], $n - (int) $vend, 1 );
			if ( null !== $vend ) {
				$rows[] = $row( $l['by_vendor']['label'], $vend, 1 );
			}
		}
		if ( 'unverified' === $k ) {
			if ( (int) ( $m['impostors'] ?? 0 ) > 0 ) {
				$rows[] = $row( $l['failed_check']['label'], (int) $m['impostors'], 1 );
				foreach ( snt_mr_reason_rows( $m['impostor_reasons'] ?? null ) as $r ) {
					$rows[] = $row( $l[ $r['reason'] ]['label'], $r['hits'], 2 );
				}
			}
			if ( $tokn > 0 ) {
				$names  = implode( ', ', array_map( static fn( $i ) => (string) $i['token'], $toks ) );
				$rows[] = $row( $l['control_token']['label'] . ( '' === $names ? '' : ' (' . $names . ')' ), $tokn, 1 );
			}
		}
	}
	return $rows;
}

/**
 * The note under the rows: the long description of each nested row that
 * needs one, in plain words. '' when neither shows.
 *
 * @param array $m The machines snapshot.
 * @return string
 */
function sn_public_stats_who_note( array $m ) {
	$l    = snt_mr_reader_labels();
	$says = array();
	if ( (int) ( $m['impostors'] ?? 0 ) > 0 ) {
		$says[] = ucfirst( $l['failed_check']['label'] ) . ': ' . $l['failed_check']['title'];
	}
	if ( array() !== array_filter( (array) ( $m['tokens'] ?? array() ), static fn( $i ) => is_array( $i ) && (int) ( $i['hits'] ?? 0 ) > 0 ) ) {
		$says[] = ucfirst( $l['control_token']['label'] ) . ': ' . $l['control_token']['title'];
	}
	return array() === $says ? '' : '<p class="sn-public-stats__note">' . esc_html( implode( ' ', $says ) ) . '</p>';
}
