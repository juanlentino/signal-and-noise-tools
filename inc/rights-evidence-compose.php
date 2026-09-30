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
 * does both and calls these. Schema 2 (Unreleased): the reservation lists the
 * versions in force during the month, reads are split by purpose.
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

/** The record's schema. v1 records (17.0.0 to 19.9.0) carry no field and are implicitly 1. */
const SN_RIGHTS_EVIDENCE_SCHEMA = 2;

/** Purposes that are neither training nor retrieval: our own probes and dev traffic. */
const SN_RIGHTS_EVIDENCE_OWN_PURPOSES = array( 'ops', 'dev' );

/**
 * Purposes that are no purpose: the sensor wrote '' and the normalizer folds
 * it (and anything off the enum) into 'unknown'. Such a row is counted under
 * `unlabelled`, never under a purpose it did not record: it could be a
 * training crawler under a user agent the taxonomy missed.
 */
const SN_RIGHTS_EVIDENCE_UNLABELLED = array( '', 'unknown' );

/**
 * The key a purpose is counted under: itself, or `unlabelled`.
 *
 * @param string $purpose Row purpose.
 * @return string
 */
function sn_rights_evidence_purpose_key( $purpose ) {
	return in_array( (string) $purpose, SN_RIGHTS_EVIDENCE_UNLABELLED, true ) ? 'unlabelled' : (string) $purpose;
}

/**
 * The reservation in force during a month. PURE.
 *
 * A version is in force from its anchor (the time of the Bitcoin block the
 * ledger names) until the next anchored version's anchor. Anchor time is the
 * earliest moment the ledger can prove the bytes existed, so a version is
 * never claimed before it; a version not yet in a block is not claimed at all
 * and leaves the one before it open-ended. Every version in force at any
 * point of [start, end] is listed.
 *
 * @param array $history From sn_rights_evidence_signal_history().
 * @param array $month   From sn_rights_evidence_month().
 * @return array{window:array{start:string,end:string},signals:object}|null null when no signal was in force.
 */
function sn_rights_evidence_reservation( array $history, array $month ) {
	$start   = $month['start'] . 'T00:00:00Z';
	$end     = $month['end'] . 'T23:59:59Z';
	$signals = array();
	foreach ( $history as $slug => $versions ) {
		$anchored = array_values( array_filter( (array) $versions, static fn( $v ) => '' !== (string) ( $v['anchored_at'] ?? '' ) ) );
		usort( $anchored, static fn( $a, $b ) => (int) $a['version'] <=> (int) $b['version'] );
		foreach ( $anchored as $i => $v ) {
			$from = (string) $v['anchored_at'];
			$to   = isset( $anchored[ $i + 1 ] ) ? (string) $anchored[ $i + 1 ]['anchored_at'] : null;
			if ( $from > $end || ( null !== $to && $to <= $start ) ) {
				continue;
			}
			// Keys in sorted order: inside an object, sn_prov_canonical_json
			// does not sort for us, and the worker compares sorted bytes.
			$signals[ (string) $slug ][] = array(
				'block'        => (int) $v['block'],
				'content_hash' => (string) ( $v['content_hash'] ?? '' ),
				'valid_from'   => $from,
				'valid_to'     => $to,
				'version'      => (int) $v['version'],
			);
		}
	}
	if ( ! $signals ) {
		return null;
	}
	ksort( $signals );
	return array( 'window' => array( 'start' => $start, 'end' => $end ), 'signals' => (object) $signals );
}

/**
 * Rights-file reads of one family in one month, split by what they were for.
 * PURE. `train` is the training claim (rights_reads); a row with no recorded
 * purpose is unlabelled_reads and claimed by neither other block; every other
 * purpose but our own ops/dev traffic is retrieval (search, user, ...).
 *
 * @param string $family Sensor family slug.
 * @param array  $month  From sn_rights_evidence_month().
 * @param array  $rights snt_mr_fetch( $days, 'rights', filter ) result.
 * @return array{rights_reads:array,retrieval_reads:array,unlabelled_reads:array}
 */
function sn_rights_evidence_reads( $family, array $month, array $rights ) {
	$blocks   = array( 'rights_reads' => array(), 'retrieval_reads' => array(), 'unlabelled_reads' => array() );
	$min_seen = '';
	foreach ( (array) ( $rights['rows'] ?? array() ) as $row ) {
		$at = (string) ( $row['observed_at'] ?? '' );
		if ( '' === $min_seen || $at < $min_seen ) {
			$min_seen = $at;
		}
		$purpose = (string) ( $row['purpose'] ?? '' );
		if ( (string) ( $row['family'] ?? '' ) !== $family || substr( $at, 0, 10 ) < $month['start'] || substr( $at, 0, 10 ) > $month['end'] || in_array( $purpose, SN_RIGHTS_EVIDENCE_OWN_PURPOSES, true ) ) {
			continue;
		}
		$purpose = sn_rights_evidence_purpose_key( $purpose );
		$b       = &$blocks[ 'train' === $purpose ? 'rights_reads' : ( 'unlabelled' === $purpose ? 'unlabelled_reads' : 'retrieval_reads' ) ];
		$hits = max( 1, (int) ( $row['hits'] ?? 1 ) );
		$path = (string) ( $row['path'] ?? '' );
		$b['reads']                         = ( $b['reads'] ?? 0 ) + $hits;
		$b['by_purpose'][ $purpose ]        = ( $b['by_purpose'][ $purpose ] ?? 0 ) + $hits;
		$b['by_path'][ $purpose ][ $path ]  = ( $b['by_path'][ $purpose ][ $path ] ?? 0 ) + $hits;
		$b['first'] = '' === (string) ( $b['first'] ?? '' ) || $at < $b['first'] ? $at : $b['first'];
		$b['last']  = $at > (string) ( $b['last'] ?? '' ) ? $at : $b['last'];
		unset( $b );
	}
	$complete = empty( $rights['truncated'] ) || ( '' !== $min_seen && substr( $min_seen, 0, 10 ) <= $month['start'] );
	foreach ( $blocks as $k => $b ) {
		$by_path = array();
		foreach ( (array) ( $b['by_path'] ?? array() ) as $purpose => $paths ) {
			ksort( $paths );
			$by_path[ $purpose ] = (object) $paths;
		}
		ksort( $by_path );
		$by_purpose = (array) ( $b['by_purpose'] ?? array() );
		ksort( $by_purpose );
		$blocks[ $k ] = array(
			'reads'      => (int) ( $b['reads'] ?? 0 ),
			'by_purpose' => (object) $by_purpose,
			'by_path'    => (object) $by_path,
			'first'      => (string) ( $b['first'] ?? '' ),
			'last'       => (string) ( $b['last'] ?? '' ),
			'complete'   => $complete,
		);
	}
	return $blocks;
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
 * @param array  $rights      The family's filtered rights stream.
 * @param array  $reservation From sn_rights_evidence_reservation().
 * @param array  $sensor      {version, taxonomy}.
 * @param string $site        Home URL.
 * @param int    $now         Unix time (composed_at).
 * @return array|null The record payload (keys unsorted; sn_prov_canonical_json sorts); null when the sensor names no taxonomy.
 */
function sn_rights_evidence_compose( $family, array $month, array $aggregate, array $rights, array $reservation, array $sensor, $site, $now ) {
	if ( '' === (string) ( $sensor['taxonomy'] ?? '' ) ) {
		return null; // A record that cannot say which classification it counted under is not shipped blank.
	}
	$days       = array();
	$by_surface = array();
	$reads      = 0;
	$train      = 0;
	$max_day    = '';
	foreach ( (array) ( $aggregate['rows'] ?? array() ) as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( $day > $max_day ) {
			$max_day = $day;
		}
		if ( (string) ( $row['family'] ?? '' ) !== $family || $day < $month['start'] || $day > $month['end'] ) {
			continue;
		}
		$hits    = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$purpose = sn_rights_evidence_purpose_key( (string) ( $row['purpose'] ?? '' ) );
		$is_train = 'train' === $purpose ? $hits : 0;
		$reads  += $hits;
		$train  += $is_train;
		$days[ $day ]['reads'] = ( $days[ $day ]['reads'] ?? 0 ) + $hits;
		$days[ $day ]['train'] = ( $days[ $day ]['train'] ?? 0 ) + $is_train;
		$surface = (string) ( $row['surface'] ?? '' );
		if ( '' !== $surface ) {
			$by_surface[ $purpose ][ $surface ] = ( $by_surface[ $purpose ][ $surface ] ?? 0 ) + $hits;
		}
	}
	ksort( $days );
	ksort( $by_surface );
	foreach ( $by_surface as $p => $s ) {
		ksort( $s );
		$by_surface[ $p ] = (object) $s;
	}

	return array(
		'schema'      => SN_RIGHTS_EVIDENCE_SCHEMA,
		'kind'        => SN_RIGHTS_EVIDENCE_KIND,
		'family'      => $family,
		'month'       => $month['month'],
		'site'        => rtrim( (string) $site, '/' ),
		'composed_at' => gmdate( 'c', $now ),
		'reservation' => $reservation,
		'crawling'    => array(
			'reads'      => $reads,
			'train'      => $train,
			'by_day'     => (object) $days,
			'by_surface' => (object) $by_surface,
			'complete'   => empty( $aggregate['truncated'] ) || $max_day >= $month['end'],
		),
		'sensor'      => array(
			'version'  => (string) ( $sensor['version'] ?? '' ),
			'taxonomy' => (string) $sensor['taxonomy'],
		),
	) + sn_rights_evidence_reads( $family, $month, $rights );
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
