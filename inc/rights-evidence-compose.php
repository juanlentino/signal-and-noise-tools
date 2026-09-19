<?php
/**
 * Signal & Noise Tools: rights evidence, the pure half.
 *
 * One record per crawler family per calendar month, composed from what the
 * edge sensor already holds for ninety days: the reservation in force (the
 * ledger's own rights-signal versions and hashes), who fetched the rights
 * files and when, and how much was crawled, per day, for training. The
 * record is canonical JSON the provenance worker signs and anchors under
 * `rights-evidence/<uuid>/v1` (worker 1.21.0), so the triple outlives the
 * sensor's retention. Nothing here fetches or writes; inc/rights-evidence.php
 * does both and calls these.
 *
 * @package SignalNoiseTools
 * @since 17.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The subject kind the worker files the record under. */
const SN_RIGHTS_EVIDENCE_KIND = 'rights-evidence';

/** RFC 4122's URL namespace: the record's id is the UUIDv5 of its own URL. */
const SN_RIGHTS_EVIDENCE_NS = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

/**
 * The record's id: UUIDv5 of the record's URL, so the same family and month
 * always name the same ledger path and a re-send is the worker's
 * 200-with-existing, never a second record.
 *
 * @param string $family Sensor family slug.
 * @param string $month  YYYY-MM.
 * @param string $site   The site's home URL.
 * @return string 8-4-4-4-12 lowercase hex.
 */
function sn_rights_evidence_uuid( $family, $month, $site ) {
	$ns   = hex2bin( str_replace( '-', '', SN_RIGHTS_EVIDENCE_NS ) );
	$hash = sha1( $ns . rtrim( (string) $site, '/' ) . '/rights-evidence/' . $family . '/' . $month );
	$hex  = substr( $hash, 0, 32 );
	$hex  = substr_replace( $hex, '5', 12, 1 ); // version 5
	$hex  = substr_replace( $hex, dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 ), 16, 1 ); // RFC variant
	return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
}

/**
 * The last complete calendar month before $now, UTC.
 *
 * @param int $now Unix time.
 * @return array{month:string,start:string,end:string} YYYY-MM and its first and last day.
 */
function sn_rights_evidence_month( $now ) {
	$first_of_this = gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ), 1, (int) gmdate( 'Y', $now ) );
	$last          = $first_of_this - DAY_IN_SECONDS;
	return array(
		'month' => gmdate( 'Y-m', $last ),
		'start' => gmdate( 'Y-m-01', $last ),
		'end'   => gmdate( 'Y-m-t', $last ),
	);
}

/**
 * The reservation block from the public ledger's index: every rights-signal
 * file's current version, hash and anchor.
 *
 * @param array $index The decoded index.json.
 * @return array<int,array{slug:string,version:int,content_hash:string,ots_status:string,bitcoin_block:?int}>|null null when the index carries no signals.
 */
function sn_rights_evidence_reservation( $index ) {
	$rows = array();
	foreach ( (array) ( $index['rights_signals'] ?? array() ) as $r ) {
		if ( ! is_array( $r ) || '' === (string) ( $r['slug'] ?? '' ) ) {
			continue;
		}
		$rows[] = array(
			'slug'          => (string) $r['slug'],
			'version'       => (int) ( $r['version'] ?? 0 ),
			'content_hash'  => (string) ( $r['content_hash'] ?? '' ),
			'ots_status'    => (string) ( $r['ots_status'] ?? '' ),
			'bitcoin_block' => isset( $r['bitcoin_block'] ) ? (int) $r['bitcoin_block'] : null,
		);
	}
	usort( $rows, static fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );
	return $rows ? $rows : null;
}

/**
 * The record for one family and month. PURE.
 *
 * `complete` on each block says whether the sensor read covered the whole
 * month: the aggregate truncates its newest rows at the edge's cap, the
 * rights stream its oldest, so each is judged against its own edge.
 *
 * @param string $family      Sensor family slug.
 * @param array  $month       From sn_rights_evidence_month().
 * @param array  $aggregate   snt_mr_fetch( $days ) result (rows + truncated).
 * @param array  $rights      snt_mr_fetch( $days, 'rights' ) result.
 * @param array  $reservation From sn_rights_evidence_reservation().
 * @param array  $sensor      {version, taxonomy} or empty.
 * @param string $site        Home URL.
 * @param int    $now         Unix time (composed_at).
 * @return array The record payload, keys unsorted (sn_prov_canonical_json sorts).
 */
function sn_rights_evidence_compose( $family, array $month, array $aggregate, array $rights, array $reservation, array $sensor, $site, $now ) {
	$days = array();
	$by_surface = array();
	$reads = 0;
	$train = 0;
	$max_day = '';
	foreach ( (array) ( $aggregate['rows'] ?? array() ) as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( $day > $max_day ) {
			$max_day = $day;
		}
		if ( (string) ( $row['family'] ?? '' ) !== $family || $day < $month['start'] || $day > $month['end'] ) {
			continue;
		}
		$hits   = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$reads += $hits;
		$days[ $day ]['reads'] = ( $days[ $day ]['reads'] ?? 0 ) + $hits;
		$days[ $day ]['train'] = ( $days[ $day ]['train'] ?? 0 ) + ( 'train' === (string) ( $row['purpose'] ?? '' ) ? $hits : 0 );
		$train += 'train' === (string) ( $row['purpose'] ?? '' ) ? $hits : 0;
		$surface = (string) ( $row['surface'] ?? '' );
		if ( '' !== $surface ) {
			$by_surface[ $surface ] = ( $by_surface[ $surface ] ?? 0 ) + $hits;
		}
	}
	ksort( $days );
	ksort( $by_surface );

	$fetches  = 0;
	$by_path  = array();
	$first    = '';
	$last     = '';
	$min_seen = '';
	foreach ( (array) ( $rights['rows'] ?? array() ) as $row ) {
		$at = (string) ( $row['observed_at'] ?? '' );
		if ( '' === $min_seen || $at < $min_seen ) {
			$min_seen = $at;
		}
		if ( (string) ( $row['family'] ?? '' ) !== $family || substr( $at, 0, 10 ) < $month['start'] || substr( $at, 0, 10 ) > $month['end'] ) {
			continue;
		}
		$hits     = max( 1, (int) ( $row['hits'] ?? 1 ) );
		$fetches += $hits;
		$path     = (string) ( $row['path'] ?? '' );
		$by_path[ $path ] = ( $by_path[ $path ] ?? 0 ) + $hits;
		$first = '' === $first || $at < $first ? $at : $first;
		$last  = $at > $last ? $at : $last;
	}
	ksort( $by_path );

	return array(
		'kind'        => SN_RIGHTS_EVIDENCE_KIND,
		'family'      => $family,
		'month'       => $month['month'],
		'site'        => rtrim( (string) $site, '/' ),
		'composed_at' => gmdate( 'c', $now ),
		'reservation' => array( 'as_of' => gmdate( 'c', $now ), 'signals' => $reservation ),
		'rights_reads' => array(
			'reads'    => $fetches,
			'by_path'  => (object) $by_path,
			'first'    => $first,
			'last'     => $last,
			'complete' => empty( $rights['truncated'] ) || ( '' !== $min_seen && substr( $min_seen, 0, 10 ) <= $month['start'] ),
		),
		'crawling'    => array(
			'reads'      => $reads,
			'train'      => $train,
			'by_day'     => (object) $days,
			'by_surface' => (object) $by_surface,
			'complete'   => empty( $aggregate['truncated'] ) || $max_day >= $month['end'],
		),
		'sensor'      => array(
			'version'  => (string) ( $sensor['version'] ?? '' ),
			'taxonomy' => (string) ( $sensor['taxonomy'] ?? '' ),
		),
	);
}

/**
 * The families a month's record is composed for: the declared AI-training
 * set, restricted to those the aggregate saw in the month. A family with no
 * reads has nothing to evidence.
 *
 * @param array $aggregate snt_mr_fetch( $days ) result.
 * @param array $month     From sn_rights_evidence_month().
 * @return string[] Sorted.
 */
function sn_rights_evidence_families( array $aggregate, array $month ) {
	$ai   = function_exists( 'snt_mr_ai_training_families' ) ? snt_mr_ai_training_families() : array();
	$seen = array();
	foreach ( (array) ( $aggregate['rows'] ?? array() ) as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		$fam = (string) ( $row['family'] ?? '' );
		if ( in_array( $fam, $ai, true ) && $day >= $month['start'] && $day <= $month['end'] && (int) ( $row['hits'] ?? 0 ) > 0 ) {
			$seen[ $fam ] = true;
		}
	}
	$out = array_keys( $seen );
	sort( $out );
	return $out;
}
