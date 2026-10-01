<?php
/**
 * Signal & Noise Tools: rights evidence, sending stored bytes.
 *
 * sn_rights_evidence_send_stored() is the one place a stored entry is sent:
 * the daily pass calls it for a month past its review window, Post now for a
 * composed month the owner wants out before the window ends. A 422 from the
 * worker is a refusal: the entry keeps no bytes (the next pass recomposes)
 * and the month is held with the divergences as the reason, so refused bytes
 * are never sent twice. A transport failure stays `unanchored` and is retried.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write one entry into the stored ledger, read fresh so a concurrent write to
 * another entry is kept.
 *
 * @param string $ym     YYYY-MM.
 * @param string $family Sensor family slug.
 * @param array  $entry  The entry.
 */
function sn_rights_evidence_store_entry( $ym, $family, array $entry ) {
	$data                   = sn_rights_evidence_data();
	$data[ $ym ][ $family ] = $entry;
	update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false );
}

/**
 * Send one stored entry, store the answer, hold the month on a refusal.
 *
 * @param string $ym     YYYY-MM.
 * @param string $family Sensor family slug.
 * @param array  $entry  The entry, with its canonical bytes.
 * @param int    $now    Unix time.
 * @return string posted, refused or failed.
 */
function sn_rights_evidence_send_stored( $ym, $family, array $entry, $now ) {
	list( $entry, $kind ) = sn_rights_evidence_send( $entry, $now );
	sn_rights_evidence_store_entry( $ym, $family, $entry );
	if ( 'refused' === $kind ) {
		sn_rights_evidence_hold_month( $ym, sn_rights_evidence_refusal_reason( $family, $entry['divergences'] ?? array() ) );
	}
	return $kind;
}

/**
 * Post now: every composed, unposted entry of a month that is not held,
 * whatever its review window says. Owner-only (the dispatcher's capability
 * and a confirm); never composes.
 *
 * @param string   $ym  YYYY-MM.
 * @param int|null $now Unix time; null for time().
 * @return array{result:string,posted:int,refused:int,failed:int}
 *         result: posted, partial (something refused or failed), busy, or ineligible.
 */
function sn_rights_evidence_post_now( $ym, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$out = array( 'result' => 'ineligible', 'posted' => 0, 'refused' => 0, 'failed' => 0 );
	if ( ! array_key_exists( (string) $ym, sn_rights_evidence_pending() ) ) {
		return $out;
	}
	if ( get_transient( 'sn_rights_evidence_lock' ) ) {
		$out['result'] = 'busy';
		return $out;
	}
	set_transient( 'sn_rights_evidence_lock', 1, 5 * MINUTE_IN_SECONDS );
	foreach ( (array) ( sn_rights_evidence_data()[ $ym ] ?? array() ) as $family => $e ) {
		if ( in_array( (string) $ym, sn_rights_evidence_held( false ), true ) ) {
			break; // A refusal of an earlier family held the month: the rest wait.
		}
		if ( is_array( $e ) && '' !== (string) ( $e['canonical'] ?? '' ) && '' === (string) ( $e['ledger_path'] ?? '' ) ) {
			$out[ sn_rights_evidence_send_stored( (string) $ym, (string) $family, $e, $now ) ]++;
		}
	}
	delete_transient( 'sn_rights_evidence_lock' );
	$out['result'] = $out['refused'] || $out['failed'] ? 'partial' : 'posted';
	return $out;
}
