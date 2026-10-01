<?php
/**
 * Signal & Noise Tools: rights evidence, one signed retraction per click.
 *
 * The ledger is append-only: a posted record is never edited, only retracted
 * (owner ruling D1, 2026-09-30). The provenance worker's HMAC-gated
 * `POST /retract` signs, OpenTimestamps-stamps and commits
 * `retractions/<uid>/v<version>.{json,ots}` beside the record; the record's
 * bytes stay. The text is fixed in inc/rights-evidence-retractions.php, never
 * an input. Only a confirmed record with a ledger path and approved text is
 * eligible; a retracted record is final (the F5 refresh never re-reads it).
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The worker can be asked: its URL and secret are set. The gate the Retract
 * button paints behind and the handler re-checks (Lift's gate, minus the
 * sensor, which a retraction does not read).
 *
 * @return bool
 */
function sn_rights_evidence_can_retract() {
	return function_exists( 'sn_prov_worker_url' ) && '' !== (string) sn_prov_worker_url()
		&& function_exists( 'sn_prov_hmac_secret' ) && '' !== (string) sn_prov_hmac_secret();
}

/**
 * Records that can be retracted now: confirmed, on the ledger, with text.
 *
 * @return array<int,array{month:string,family:string,entry:array,text:array}>
 */
function sn_rights_evidence_retractable() {
	$out  = array();
	$data = sn_rights_evidence_data();
	ksort( $data );
	foreach ( $data as $month => $families ) {
		foreach ( (array) $families as $family => $e ) {
			$text = sn_rights_evidence_retraction_text( (string) $month, (string) $family );
			if ( null === $text || ! is_array( $e ) || '' === (string) ( $e['ledger_path'] ?? '' ) || '' === (string) ( $e['uuid'] ?? '' ) || 'confirmed' !== (string) ( $e['status'] ?? '' ) ) {
				continue;
			}
			$out[] = array( 'month' => (string) $month, 'family' => (string) $family, 'entry' => $e, 'text' => $text );
		}
	}
	return $out;
}

/**
 * The /retract body for one eligible record, in the worker's field order.
 *
 * @param array $entry The stored record {uuid, ledger_path, ...}.
 * @param array $text  Its approved text.
 * @param int   $now   Unix time.
 * @return array
 */
function sn_rights_evidence_retract_body( array $entry, array $text, $now ) {
	return array(
		'note_uid'       => (string) $entry['uuid'],
		'version'        => 1,
		'retracted_path' => (string) $entry['ledger_path'], // Byte for byte as stored.
		'what_was_wrong' => (string) $text['what_was_wrong'],
		'claimed'        => (string) $text['claimed'],
		'root_cause'     => (string) $text['root_cause'],
		'what_changed'   => (string) $text['what_changed'],
		'retracted_at'   => gmdate( 'c', (int) $now ),
	);
}

/**
 * Post the retraction of one record.
 *
 * 200 with ok: status `retracted`, retraction_path and retraction_hash stored,
 * the rest of the entry kept. 409 (absent subject, already retracted, bad
 * shape): `refused`, the entry untouched. Anything else: `failed`, untouched.
 * Takes the pass's lock, so a pass cannot write a stale copy over the result.
 *
 * @param string   $month  YYYY-MM.
 * @param string   $family Crawler family.
 * @param int|null $now    Unix time; null for time().
 * @return array{result:string,error:string} result: retracted|refused|failed|ineligible|busy.
 */
function sn_rights_evidence_retract( $month, $family, $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$match = array_values( array_filter( sn_rights_evidence_retractable(), static fn( $r ) => $r['month'] === (string) $month && $r['family'] === (string) $family ) );
	if ( ! $match ) {
		return array( 'result' => 'ineligible', 'error' => '' );
	}
	if ( get_transient( 'sn_rights_evidence_lock' ) ) {
		return array( 'result' => 'busy', 'error' => '' );
	}
	set_transient( 'sn_rights_evidence_lock', 1, 5 * MINUTE_IN_SECONDS );
	$r = sn_rights_evidence_signed_post(
		rtrim( (string) sn_prov_worker_url(), '/' ) . '/retract',
		sn_rights_evidence_retract_body( $match[0]['entry'], $match[0]['text'], $now )
	);
	$out = array( 'result' => 'failed', 'error' => $r['code'] . ' ' . (string) ( $r['body']['error'] ?? '' ) );
	if ( 200 === $r['code'] && true === ( $r['body']['ok'] ?? null ) && '' !== (string) ( $r['body']['path'] ?? '' ) ) {
		$data = sn_rights_evidence_data(); // Re-read: write over the latest copy only.
		$data[ $month ][ $family ]['status']          = 'retracted';
		$data[ $month ][ $family ]['retraction_path'] = (string) $r['body']['path'];
		$data[ $month ][ $family ]['retraction_hash'] = (string) ( $r['body']['content_hash'] ?? '' );
		update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false );
		$out = array( 'result' => 'retracted', 'error' => '' );
	} elseif ( 409 === $r['code'] ) {
		$out = array( 'result' => 'refused', 'error' => (string) ( $r['body']['error'] ?? '' ) );
		// A lost 200: the worker says it is already retracted. Believe the
		// ledger, not the word: the retraction file must name this record.
		if ( false !== stripos( $out['error'], 'already retracted' ) && sn_rights_evidence_retract_adopt( (string) $month, (string) $family, $match[0]['entry'] ) ) {
			$out = array( 'result' => 'retracted', 'error' => '' );
		}
	}
	delete_transient( 'sn_rights_evidence_lock' );
	return $out;
}

/**
 * Adopt a retraction already on the ledger (read-only): when
 * `retractions/<uid>/v1.json` exists and its payload.retracted_path is this
 * entry's ledger_path, mark the entry retracted with that path and hash.
 *
 * @param string $month  YYYY-MM.
 * @param string $family Crawler family.
 * @param array  $entry  The stored record.
 * @return bool Adopted.
 */
function sn_rights_evidence_retract_adopt( $month, $family, array $entry ) {
	$path = 'retractions/' . (string) $entry['uuid'] . '/v1.json';
	$doc  = function_exists( 'sn_rights_evidence_ledger_json' ) ? sn_rights_evidence_ledger_json( $path ) : null;
	if ( ! is_array( $doc ) || (string) $entry['ledger_path'] !== (string) ( $doc['payload']['retracted_path'] ?? '' ) ) {
		return false;
	}
	$data = sn_rights_evidence_data();
	$data[ $month ][ $family ]['status']          = 'retracted';
	$data[ $month ][ $family ]['retraction_path'] = $path;
	$data[ $month ][ $family ]['retraction_hash'] = (string) ( $doc['content_hash'] ?? '' );
	update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false );
	return true;
}
