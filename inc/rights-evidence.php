<?php
/**
 * Signal & Noise Tools: rights evidence, the half that fetches and posts.
 *
 * Daily on cron and on demand: for the last complete month, one record per
 * AI-training family the sensor saw, composed by inc/rights-evidence-compose.php,
 * signed and anchored by the provenance worker as `kind: rights-evidence`
 * (worker 1.21.0). The composed bytes are stored BEFORE the POST and re-sent
 * verbatim on a retry, so a lost response can never produce a second record
 * with different bytes under the same path (the worker answers 409 to that,
 * 200-with-existing to the same bytes). No WordPress row stands behind a
 * record: anchoring is read off the public ledger, not confirmed back.
 *
 * @package SignalNoiseTools
 * @since 17.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RIGHTS_EVIDENCE_OPTION = 'sn_rights_evidence';
const SN_RIGHTS_EVIDENCE_HOOK   = 'sn_rights_evidence_daily';

/** Worker URL and secret set, the sensor configured. */
function sn_rights_evidence_is_ready() {
	return function_exists( 'sn_prov_worker_url' ) && '' !== sn_prov_worker_url()
		&& function_exists( 'sn_prov_hmac_secret' ) && '' !== sn_prov_hmac_secret()
		&& function_exists( 'snt_mr_config' ) && null !== snt_mr_config();
}

/** The stored ledger: month => family => {uuid, content_hash, canonical?, status, ledger_path, at, error}. */
function sn_rights_evidence_data() {
	$d = get_option( SN_RIGHTS_EVIDENCE_OPTION, array() );
	return is_array( $d ) ? $d : array();
}

/**
 * The public ledger's index.json, for the reservation block.
 *
 * @return array|null null when unreachable or not JSON.
 */
function sn_rights_evidence_ledger_index() {
	if ( ! function_exists( 'sn_prov_integrity_ledger_base' ) || ! function_exists( 'sn_prov_integrity_http_fetch' ) ) {
		return null;
	}
	$res = sn_prov_integrity_http_fetch( sn_prov_integrity_ledger_base() . 'index.json' );
	if ( 200 !== (int) ( $res['code'] ?? 0 ) ) {
		return null;
	}
	$json = json_decode( (string) ( $res['body'] ?? '' ), true );
	return is_array( $json ) ? $json : null;
}

/**
 * Sign and POST one record through the provenance webhook.
 *
 * @param string $uuid      The record id.
 * @param string $canonical The canonical bytes.
 * @return array{code:int,body:array} code 0 on a transport error.
 */
function sn_rights_evidence_post( $uuid, $canonical ) {
	$url    = sn_prov_worker_url();
	$secret = sn_prov_hmac_secret();
	if ( ! sn_prov_url_allowed( $url ) ) {
		return array( 'code' => 0, 'body' => array( 'error' => 'worker url refused by the outbound gate' ) );
	}
	$body = wp_json_encode( array(
		'canonical'    => $canonical,
		'content_hash' => hash( 'sha256', $canonical ),
		'note_uid'     => $uuid,
		'version'      => 1,
		'kind'         => SN_RIGHTS_EVIDENCE_KIND,
	) );
	$response = wp_remote_post( $url, array(
		'timeout'     => 20,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'        => $body,
	) );
	if ( is_wp_error( $response ) ) {
		return array( 'code' => 0, 'body' => array( 'error' => $response->get_error_message() ) );
	}
	$out = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return array( 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => is_array( $out ) ? $out : array() );
}

/**
 * The daily pass: compose what the last complete month still lacks, post
 * what is composed and not yet on the ledger. Idempotent by (month, family).
 *
 * @param int|null $now Unix time; null for time().
 * @return array{ok:bool,month:string,composed:int,posted:int,anchored:int,failed:int,error:string}
 */
function sn_rights_evidence_run( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$out = array( 'ok' => false, 'month' => '', 'composed' => 0, 'posted' => 0, 'anchored' => 0, 'failed' => 0, 'error' => '' );
	if ( ! sn_rights_evidence_is_ready() ) {
		$out['error'] = 'not-ready';
		return $out;
	}
	$month        = sn_rights_evidence_month( $now );
	$out['month'] = $month['month'];
	$data         = sn_rights_evidence_data();
	$days         = min( 90, (int) ceil( ( $now - strtotime( $month['start'] . 'T00:00:00Z' ) ) / DAY_IN_SECONDS ) + 1 );
	$aggregate    = snt_mr_fetch( $days );
	if ( empty( $aggregate['ok'] ) ) {
		$out['error'] = 'sensor: ' . (string) ( $aggregate['error'] ?? 'unknown' );
		return $out;
	}
	$site = home_url( '/' );
	$res  = null; // The reservation and the rights stream: read once per pass, only when something needs composing.
	$rights = null;
	foreach ( sn_rights_evidence_families( $aggregate, $month ) as $family ) {
		$entry = $data[ $month['month'] ][ $family ] ?? null;
		if ( is_array( $entry ) && '' !== (string) ( $entry['ledger_path'] ?? '' ) ) {
			$out['anchored']++;
			continue; // On the ledger; the bytes are immutable now.
		}
		if ( ! is_array( $entry ) || '' === (string) ( $entry['canonical'] ?? '' ) ) {
			if ( null === $res ) {
				$index = sn_rights_evidence_ledger_index();
				$res   = null === $index ? null : sn_rights_evidence_reservation( $index );
			}
			if ( null === $res ) {
				$out['error'] = 'ledger index unreadable; a record without the reservation is half an evidence';
				break;
			}
			if ( null === $rights ) {
				$rights = snt_mr_fetch( $days, 'rights' );
			}
			if ( empty( $rights['ok'] ) ) {
				$out['error'] = 'sensor rights stream: ' . (string) ( $rights['error'] ?? 'unknown' );
				break;
			}
			$sensor = function_exists( 'snt_mr_sensor_info' ) ? (array) snt_mr_sensor_info() : array();
			$sensor['taxonomy'] = (string) ( $aggregate['rows'][0]['taxonomy_version'] ?? '' );
			$payload   = sn_rights_evidence_compose( $family, $month, $aggregate, $rights, $res, $sensor, $site, $now );
			$canonical = sn_prov_canonical_json( $payload );
			$entry     = array(
				'uuid'         => sn_rights_evidence_uuid( $family, $month['month'], $site ),
				'content_hash' => hash( 'sha256', $canonical ),
				'canonical'    => $canonical,
				'status'       => 'composed',
				'ledger_path'  => '',
				'at'           => $now,
				'error'        => '',
			);
			$out['composed']++;
			$data[ $month['month'] ][ $family ] = $entry;
			update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false ); // Stored BEFORE the POST.
		}
		$r = sn_rights_evidence_post( $entry['uuid'], $entry['canonical'] );
		if ( $r['code'] >= 200 && $r['code'] < 300 ) {
			$entry['status']      = (string) ( $r['body']['ots_status'] ?? 'pending' ); // The ledger's word, on a fresh record and on a re-send alike.
			$entry['ledger_path'] = (string) ( $r['body']['ledger_path'] ?? '' );
			$entry['error']       = '';
			unset( $entry['canonical'] ); // The ledger holds the bytes now.
			$out['posted']++;
		} else {
			$entry['status'] = 'unanchored';
			$entry['error']  = $r['code'] . ' ' . (string) ( $r['body']['error'] ?? '' );
			$out['failed']++;
		}
		$entry['at'] = $now;
		$data[ $month['month'] ][ $family ] = $entry;
		update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false );
	}
	$out['ok'] = '' === $out['error'] && 0 === $out['failed'];
	return $out;
}

add_action( SN_RIGHTS_EVIDENCE_HOOK, 'sn_rights_evidence_run' );
add_action( 'init', static function () {
	if ( sn_rights_evidence_is_ready() && ! wp_next_scheduled( SN_RIGHTS_EVIDENCE_HOOK ) ) {
		wp_schedule_event( time() + 3600, 'daily', SN_RIGHTS_EVIDENCE_HOOK );
	}
} );
