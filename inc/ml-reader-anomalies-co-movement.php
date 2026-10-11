<?php
/**
 * reader-anomalies: the co-movement rule.
 *
 * On 2026-10-10 seven families ran above their norm on the same day, every one
 * of them claimed agents reading from Google LLC addresses with no Cloudflare
 * verification: one operator presenting seven user agents, which the
 * per-family detector reported as seven findings. When three or more families
 * deviate (an anomaly or a silence) on the same day, mostly on unverified
 * reads, from the same network, that is ONE signal naming the network; the
 * per-family signals move inside it as `members`. Loaded by
 * inc/ml-reader-anomalies-split.php.
 *
 * @package Signal_And_Noise_Tools
 */

defined( 'ABSPATH' ) || exit;

/** Families that must share a network for a co-movement. */
const SN_MR_CO_MOVEMENT_MIN = 3;

/**
 * The network a family's deviation on $day rode on: its top network among
 * unverified hits that day, when unverified hits outnumber verified ones. A
 * silent day has no hits, so the family's unverified reads over the whole
 * verifiable window stand in. '' when the day is not mostly unverified or no
 * network was recorded.
 *
 * @param array  $rows   Aggregate rows, first-party already removed.
 * @param string $family Family.
 * @param string $day    YYYY-MM-DD.
 * @param string $vfrom  First verifiable day.
 * @param string $to     Window end.
 * @return string
 */
function snt_ml_reader_unverified_network( array $rows, $family, $day, $vfrom, $to ) {
	$fold = static function ( $lo, $hi ) use ( $rows, $family ) {
		$t = array( 'verified' => 0, 'unverified' => 0, 'nets' => array() );
		foreach ( $rows as $r ) {
			$d = (string) ( $r['day'] ?? '' );
			if ( (string) ( $r['family'] ?? '' ) !== (string) $family || $d < $lo || $d > $hi ) {
				continue;
			}
			$class = snt_mr_row_verification( $r );
			$hits  = max( 0, (int) ( $r['hits'] ?? 0 ) );
			if ( 'unverifiable' === $class ) {
				continue;
			}
			$t[ $class ] += $hits;
			$net          = (string) ( $r['network'] ?? '' );
			if ( 'unverified' === $class && '' !== $net ) {
				$t['nets'][ $net ] = ( $t['nets'][ $net ] ?? 0 ) + $hits;
			}
		}
		return $t;
	};
	$t = $fold( $day, $day );
	if ( 0 === $t['verified'] + $t['unverified'] ) {
		$t = $fold( $vfrom, $to );
	}
	if ( $t['unverified'] <= $t['verified'] || array() === $t['nets'] ) {
		return '';
	}
	arsort( $t['nets'] );
	return (string) array_key_first( $t['nets'] );
}

/**
 * Fold same-day deviations of three or more families on one unverified
 * network into one signal each.
 *
 * @param array  $families Family rows, each with `family` and `signals`.
 * @param array  $rows     Aggregate rows, first-party already removed.
 * @param string $from     Window start.
 * @param string $to       Window end.
 * @return array{0:array,1:array} The family rows less the folded signals, and the co-movement signals.
 */
function snt_ml_reader_co_movement( array $families, array $rows, $from, $to ) {
	$vfrom  = snt_ml_reader_verifiable_from( $from );
	$groups = array();
	foreach ( $families as $f ) {
		foreach ( (array) $f['signals'] as $sig ) {
			if ( ! in_array( $sig['kind'] ?? '', array( 'anomaly', 'reader_silent' ), true ) ) {
				continue;
			}
			$day = substr( (string) $sig['id'], strrpos( (string) $sig['id'], ':' ) + 1 );
			$net = $day >= $vfrom ? snt_ml_reader_unverified_network( $rows, $f['family'], $day, $vfrom, $to ) : '';
			if ( '' !== $net ) {
				$groups[ $day ][ $net ][ (string) $f['family'] ] = $sig;
			}
		}
	}
	$co     = array();
	$folded = array();
	ksort( $groups );
	foreach ( $groups as $day => $nets ) {
		foreach ( $nets as $net => $members ) {
			if ( count( $members ) < SN_MR_CO_MOVEMENT_MIN ) {
				continue;
			}
			ksort( $members );
			$dirs = array_unique( array_column( $members, 'direction' ) );
			$co[] = array(
				'id'            => 'co-movement:' . $day . ':' . $net,
				'tier'          => 'predictive',
				'kind'          => 'reader_co_movement',
				'subject'       => (string) $net,
				'subject_label' => 'Network: ' . $net,
				'stat'          => 'co_movement_rule',
				'value'         => count( $members ),
				'direction'     => 1 === count( $dirs ) ? (string) reset( $dirs ) : 'mixed',
				'confidence'    => 'high',
				'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( snt_mr_day_range( $from, $to ) ) ),
				'network'       => (string) $net,
				'day'           => (string) $day,
				'families'      => array_keys( $members ),
				'members'       => array_values( $members ),
				'basis'         => 'claimed',
				'plain_label'   => sprintf( '%d reader families moved together on %s, on unverified reads from %s (%s): one operator under several user agents, more likely than %d separate crawlers', count( $members ), $day, $net, implode( ', ', array_keys( $members ) ), count( $members ) ),
				'severity'      => max( array_map( static fn( $s ) => (int) ( $s['severity'] ?? 0 ), $members ) ),
			);
			foreach ( $members as $sig ) {
				$folded[ (string) $sig['id'] ] = true;
			}
		}
	}
	foreach ( $families as $i => $f ) {
		$families[ $i ]['signals'] = array_values( array_filter( (array) $f['signals'], static fn( $s ) => ! isset( $folded[ (string) ( $s['id'] ?? '' ) ] ) ) );
	}
	return array( $families, $co );
}
