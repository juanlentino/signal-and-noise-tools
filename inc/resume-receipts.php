<?php
/**
 * Signal & Noise Tools: receipts on the résumé (owner brief 2026-11-01 #2).
 *
 * A receipt is the page that backs a claim: the issuer's verification page,
 * the paper, the code. Education and affiliation rows already carry theirs as
 * `credential_url`, publications as `url` (the title's link is the receipt; no
 * separate mark), "What I've Built" items as `url`.
 * Experience and earlier entries may carry `receipt_url` + `receipt_type`, and
 * a role lists receipts for single bullets in `bullet_receipts`, keyed by
 * sn_resume_bullet_key() as `bullet_variants` is. Only real, https URLs are
 * kept; a bullet whose text changes loses its receipt, as it loses its flag.
 * The English page shows each as a quiet mark (sn_resume_receipt()); the PDFs
 * show none.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Receipt types, and the mark each shows. */
const SN_RESUME_RECEIPT_TYPES = array(
	'verification' => 'verify',
	'publication'  => 'paper',
	'repository'   => 'code',
	'membership'   => 'verify',
);

/**
 * A row's receipt as keys to append, or nothing when it has no https URL.
 *
 * @param mixed $in Raw row.
 * @return array{receipt_url?:string,receipt_type?:string}
 */
function sn_resume_receipt_keep( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$url = sn_resume_https_url( $in['receipt_url'] ?? '' );
	if ( '' === $url ) {
		return array();
	}
	$type = (string) ( $in['receipt_type'] ?? '' );
	return array(
		'receipt_url'  => $url,
		'receipt_type' => isset( SN_RESUME_RECEIPT_TYPES[ $type ] ) ? $type : 'verification',
	);
}

/**
 * A role's per-bullet receipts, kept only for bullets the role still has.
 *
 * @param mixed             $in      Raw {key: {receipt_url, receipt_type}} map.
 * @param array<int,string> $bullets The role's normalized bullets.
 * @return array<string,array{receipt_url:string,receipt_type:string}>
 */
function sn_resume_bullet_receipts( $in, $bullets ) {
	$keys = array_flip( array_map( 'sn_resume_bullet_key', $bullets ) );
	$out  = array();
	foreach ( (array) $in as $key => $row ) {
		$keep = isset( $keys[ (string) $key ] ) ? sn_resume_receipt_keep( $row ) : array();
		if ( $keep ) {
			$out[ (string) $key ] = $keep;
		}
	}
	return $out;
}

/**
 * The mark a row's receipt shows, '' when it has none. English page only.
 *
 * @param array  $row     Row with receipt_url / receipt_type.
 * @param string $context What it backs, for the link's accessible name.
 * @return string
 */
function sn_resume_row_receipt( $row, $context ) {
	$type = (string) ( $row['receipt_type'] ?? '' );
	return function_exists( 'sn_resume_receipt' ) ? sn_resume_receipt( (string) ( $row['receipt_url'] ?? '' ), SN_RESUME_RECEIPT_TYPES[ $type ] ?? 'verify', $context ) : '';
}
