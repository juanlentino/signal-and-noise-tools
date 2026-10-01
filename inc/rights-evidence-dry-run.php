<?php
/**
 * Signal & Noise Tools: rights evidence, composed from live reads, posted
 * nowhere.
 *
 * sn_rights_evidence_compose_month() is the one composition the daily pass
 * and the dry run share: the aggregate once, the ledger's rights-signal
 * history once, the rights stream once per family (filtered at the sensor to
 * that family, our own ops and dev traffic left out). This file never sends:
 * no POST, no option of record written. The daily pass in
 * inc/rights-evidence.php is the only caller that stores and posts.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sensor's taxonomy for a read: the envelope's, else the first row that
 * names one. '' when nothing does.
 *
 * @param array $aggregate snt_mr_fetch() result.
 * @return string
 */
function sn_rights_evidence_taxonomy( array $aggregate ) {
	$t = (string) ( $aggregate['taxonomy_version'] ?? '' );
	foreach ( (array) ( $aggregate['rows'] ?? array() ) as $row ) {
		if ( '' !== $t ) {
			break;
		}
		$t = (string) ( $row['taxonomy_version'] ?? '' );
	}
	return $t;
}

/**
 * Payloads for one month: every AI-training family the aggregate saw in it,
 * minus $skip.
 *
 * @param array    $month From sn_rights_evidence_month() (or a backlog target).
 * @param int      $now   Unix time (composed_at, and the window's far edge).
 * @param string[] $skip  Families not to compose (already stored).
 * @return array{error:string,payloads:array<string,array>} payloads empty whenever error is set.
 */
function sn_rights_evidence_compose_month( array $month, $now, array $skip = array() ) {
	$none      = array( 'error' => '', 'payloads' => array() );
	$days      = min( 90, (int) ceil( ( $now - strtotime( $month['start'] . 'T00:00:00Z' ) ) / DAY_IN_SECONDS ) + 1 );
	$aggregate = snt_mr_fetch( $days );
	if ( empty( $aggregate['ok'] ) ) {
		return array( 'error' => 'sensor: ' . (string) ( $aggregate['error'] ?? 'unknown' ) ) + $none;
	}
	$families = array_values( array_diff( sn_rights_evidence_families( $aggregate, $month ), $skip ) );
	if ( ! $families ) {
		return $none;
	}
	$index   = sn_rights_evidence_ledger_index();
	$history = null === $index ? null : sn_rights_evidence_signal_history( $index );
	$res     = null === $history ? null : sn_rights_evidence_reservation( $history, $month );
	if ( null === $res ) {
		return array( 'error' => 'ledger index or rights-signal history unreadable, or no signal in force; a record without the reservation is half an evidence' ) + $none;
	}
	$sensor             = function_exists( 'snt_mr_sensor_info' ) ? (array) snt_mr_sensor_info() : array();
	$sensor['taxonomy'] = sn_rights_evidence_taxonomy( $aggregate );
	$site               = home_url( '/' );
	$payloads           = array();
	foreach ( $families as $family ) {
		$rights = snt_mr_fetch( $days, 'rights', array( 'family' => $family, 'exclude_purpose' => SNT_MR_RIGHTS_EXCLUDE ) );
		if ( empty( $rights['ok'] ) ) {
			return array( 'error' => 'sensor rights stream: ' . (string) ( $rights['error'] ?? 'unknown' ) ) + $none;
		}
		$payload = sn_rights_evidence_compose( $family, $month, $aggregate, $rights, $res, $sensor, $site, $now );
		if ( null === $payload ) {
			return array( 'error' => 'the sensor names no taxonomy; a record must say which classification it counted under' ) + $none;
		}
		$payloads[ $family ] = $payload;
	}
	return array( 'error' => '', 'payloads' => $payloads );
}

/**
 * The payloads a month WOULD carry today, per family, as canonical bytes.
 * Live sensor and ledger reads; nothing stored, nothing posted.
 *
 * @param string   $ym  YYYY-MM.
 * @param int|null $now Unix time; null for time().
 * @return array{ok:bool,month:string,error:string,payloads:array<string,string>}
 */
function sn_rights_evidence_dry_run( $ym, $now = null ) {
	$composed = sn_rights_evidence_compose_ym( $ym, $now );
	$out      = array();
	foreach ( $composed['payloads'] as $family => $payload ) {
		$out[ $family ] = sn_prov_canonical_json( $payload );
	}
	return array( 'ok' => '' === $composed['error'], 'month' => (string) $ym, 'error' => $composed['error'], 'payloads' => $out );
}

/**
 * A YYYY-MM as a month array, refused unless it is a complete month.
 *
 * @param string $ym  YYYY-MM.
 * @param int    $now Unix time.
 * @return array{0:?array,1:string} The month (with its start as Unix time) and '', or null and why.
 */
function sn_rights_evidence_ym_month( $ym, $now ) {
	$start = 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $ym ) ? strtotime( $ym . '-01T00:00:00Z' ) : false;
	if ( false === $start ) {
		return array( null, 'month must be YYYY-MM' );
	}
	if ( strcmp( (string) $ym, gmdate( 'Y-m', $now ) ) >= 0 ) {
		return array( null, 'month must be complete: the current month and future months cannot be composed' );
	}
	return array( array( 'month' => (string) $ym, 'start' => gmdate( 'Y-m-01', $start ), 'end' => gmdate( 'Y-m-t', $start ), 'ts' => $start ), '' );
}

/**
 * compose_month for a YYYY-MM, every family. Only a complete month whose
 * start is still inside the sensor's 90-day window can be composed.
 *
 * @param string   $ym  YYYY-MM.
 * @param int|null $now Unix time; null for time().
 * @return array{error:string,payloads:array<string,array>}
 */
function sn_rights_evidence_compose_ym( $ym, $now = null ) {
	$now             = null === $now ? time() : (int) $now;
	list( $m, $why ) = sn_rights_evidence_ym_month( $ym, $now );
	if ( null === $m ) {
		return array( 'error' => $why, 'payloads' => array() );
	}
	if ( ! sn_rights_evidence_in_window( $m['ts'], $now ) ) {
		return array( 'error' => 'month is past the sensor\'s 90-day window', 'payloads' => array() );
	}
	unset( $m['ts'] );
	return sn_rights_evidence_compose_month( $m, $now );
}

/** Why a month's v1 record is corrected by erratum (owner ruling D1, 2026-09-30). */
const SN_RIGHTS_EVIDENCE_ERRATUM_REASON = 'v1 cited rights-signal versions anchored after the month as in force; the versions in force during the month, by anchor time, are listed here.';

/**
 * D1: the erratum data for a month's posted v1 records. The ledger's rule is
 * that a month's record is minted once and a correction is a retraction,
 * never a v2 (signal-and-noise-provenance rights-evidence-checks.mjs), so the
 * owner chose retraction plus an erratum. Driven by the stored records, not by
 * today's aggregate: every v1 of ours with a ledger path (retracted ones too,
 * the erratum ships with the retraction) gets the record corrected, the reason
 * and the reservation that was in force, composed once from the ledger. A 409
 * conflict kept the ledger's own bytes, not ours: nothing to correct. No
 * sensor read, so a month past the 90-day window still gets its erratum.
 * For the erratum document only: never a ledger record, never posted.
 *
 * @param string   $ym  YYYY-MM.
 * @param int|null $now Unix time; null for time().
 * @return array{ok:bool,error:string,erratum:array<string,string>}
 */
function sn_rights_evidence_erratum( $ym, $now = null ) {
	list( $month, $why ) = sn_rights_evidence_ym_month( $ym, null === $now ? time() : (int) $now );
	if ( null === $month ) {
		return array( 'ok' => false, 'error' => $why, 'erratum' => array() );
	}
	unset( $month['ts'] );
	$v1s = array_filter(
		(array) ( sn_rights_evidence_data()[ $ym ] ?? array() ),
		static fn( $e ) => is_array( $e ) && '' !== (string) ( $e['ledger_path'] ?? '' ) && 'conflict' !== (string) ( $e['status'] ?? '' )
	);
	if ( ! $v1s ) {
		return array( 'ok' => true, 'error' => '', 'erratum' => array() );
	}
	$index   = sn_rights_evidence_ledger_index();
	$history = null === $index ? null : sn_rights_evidence_signal_history( $index );
	$res     = null === $history ? null : sn_rights_evidence_reservation( $history, $month );
	if ( null === $res ) {
		return array( 'ok' => false, 'error' => 'ledger index or rights-signal history unreadable, or no signal in force for the month', 'erratum' => array() );
	}
	ksort( $v1s );
	$erratum = array();
	foreach ( $v1s as $family => $v1 ) {
		$erratum[ $family ] = sn_prov_canonical_json(
			array(
				'corrects'    => array(
					'ledger_path'  => (string) $v1['ledger_path'],
					'content_hash' => (string) ( $v1['content_hash'] ?? '' ),
					'version'      => 1,
				),
				'family'      => (string) $family,
				'month'       => (string) $ym,
				'reason'      => SN_RIGHTS_EVIDENCE_ERRATUM_REASON,
				'reservation' => $res,
			)
		);
	}
	return array( 'ok' => true, 'error' => '', 'erratum' => $erratum );
}
