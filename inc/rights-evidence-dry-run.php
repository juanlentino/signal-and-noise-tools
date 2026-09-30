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
 * @since 19.10.0
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
 * compose_month for a YYYY-MM, every family.
 *
 * @param string   $ym  YYYY-MM.
 * @param int|null $now Unix time; null for time().
 * @return array{error:string,payloads:array<string,array>}
 */
function sn_rights_evidence_compose_ym( $ym, $now = null ) {
	$start = 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $ym ) ? strtotime( $ym . '-01T00:00:00Z' ) : false;
	if ( false === $start ) {
		return array( 'error' => 'month must be YYYY-MM', 'payloads' => array() );
	}
	$month = array( 'month' => (string) $ym, 'start' => gmdate( 'Y-m-01', $start ), 'end' => gmdate( 'Y-m-t', $start ) );
	return sn_rights_evidence_compose_month( $month, null === $now ? time() : (int) $now );
}

/** The one line a v2 carries on why it supersedes its v1. */
const SN_RIGHTS_EVIDENCE_V2_REASON = 'v1 cited rights-signal versions anchored after the month as in force and did not separate training reads of the rights files from retrieval reads; v2 lists the versions in force during the month by anchor time and splits reads by purpose.';

/**
 * D1: schema-2 v2 candidates for a month's posted v1 records, each naming the
 * record it supersedes. A DRAFT: printed for the owner, never posted (the
 * pass posts version 1 only, and the worker files v2 as a new path).
 *
 * @param string   $ym  YYYY-MM.
 * @param int|null $now Unix time; null for time().
 * @return array{ok:bool,error:string,drafts:array<string,string>}
 */
function sn_rights_evidence_v2_drafts( $ym, $now = null ) {
	$composed = sn_rights_evidence_compose_ym( $ym, $now );
	$stored   = (array) ( sn_rights_evidence_data()[ $ym ] ?? array() );
	$drafts   = array();
	foreach ( $composed['payloads'] as $family => $payload ) {
		$v1 = $stored[ $family ] ?? null;
		if ( ! is_array( $v1 ) || '' === (string) ( $v1['ledger_path'] ?? '' ) ) {
			continue; // No v1 on the ledger for this family: nothing to supersede.
		}
		$payload['supersedes'] = array(
			'version'      => 1,
			'ledger_path'  => (string) $v1['ledger_path'],
			'content_hash' => (string) ( $v1['content_hash'] ?? '' ),
		);
		$payload['reason']     = SN_RIGHTS_EVIDENCE_V2_REASON;
		$drafts[ $family ]     = sn_prov_canonical_json( $payload );
	}
	return array( 'ok' => '' === $composed['error'], 'error' => $composed['error'], 'drafts' => $drafts );
}
