<?php
/**
 * Signal & Noise Tools: the résumé's contact card, a vCard 3.0 (RFC 2426).
 *
 * Version 3.0, not 4.0, because it is the one every address book imports.
 * Built from the JSON Resume basics (inc/resume-jsonresume.php), so it holds
 * only what the résumé already publishes: no phone unless the owner made it
 * public, and the email the public PDF prints. Served at /resume/contact.vcf
 * by inc/resume-formats-route.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Escape a vCard text value (RFC 2426 §4). @param string $v @return string */
function sn_resume_vcard_escape( $v ) {
	return str_replace( array( '\\', ',', ';', "\r\n", "\n" ), array( '\\\\', '\\,', '\\;', '\\n', '\\n' ), (string) $v );
}

/**
 * One content line, folded at 75 octets on character boundaries.
 *
 * @param string $name  Name with parameters, e.g. "EMAIL;TYPE=INTERNET".
 * @param string $value Already escaped where it is text.
 * @return string Line(s) ending in CRLF.
 */
function sn_resume_vcard_line( $name, $value ) {
	$rest  = $name . ':' . $value;
	$lines = array();
	$max   = 75;
	while ( strlen( $rest ) > $max ) {
		$cut = $max;
		while ( $cut > 0 && 0x80 === ( ord( $rest[ $cut ] ) & 0xC0 ) ) {
			--$cut; // Never split a UTF-8 sequence.
		}
		$lines[] = substr( $rest, 0, $cut );
		$rest    = substr( $rest, $cut );
		$max     = 74; // Continuation lines start with a space.
	}
	$lines[] = $rest;
	return implode( "\r\n ", $lines ) . "\r\n";
}

/**
 * The vCard.
 *
 * @param array $resume sn_jsonresume_build() output.
 * @return string
 */
function sn_resume_vcard( array $resume ) {
	$b    = $resume['basics'] ?? array();
	$meta = $resume['meta'] ?? array();
	$e    = 'sn_resume_vcard_escape';
	$name = (string) ( $b['name'] ?? '' );
	$at   = strrpos( $name, ' ' );
	$out  = "BEGIN:VCARD\r\nVERSION:3.0\r\n";
	$out .= sn_resume_vcard_line( 'N', false === $at ? $e( $name ) . ';;;;' : $e( substr( $name, $at + 1 ) ) . ';' . $e( substr( $name, 0, $at ) ) . ';;;' );
	$out .= sn_resume_vcard_line( 'FN', $e( $name ) );
	if ( isset( $b['label'] ) ) {
		$out .= sn_resume_vcard_line( 'TITLE', $e( $b['label'] ) );
	}
	if ( isset( $b['email'] ) ) {
		$out .= sn_resume_vcard_line( 'EMAIL;TYPE=INTERNET', $e( $b['email'] ) );
	}
	if ( isset( $b['phone'] ) ) {
		$out .= sn_resume_vcard_line( 'TEL;TYPE=WORK,VOICE', $e( $b['phone'] ) );
	}
	if ( isset( $b['location']['city'] ) ) {
		$out .= sn_resume_vcard_line( 'ADR;TYPE=WORK', ';;;' . $e( $b['location']['city'] ) . ';' . $e( $b['location']['region'] ?? '' ) . ';;' );
	}
	if ( isset( $b['url'] ) ) {
		$out .= sn_resume_vcard_line( 'URL', $b['url'] );
	}
	foreach ( $b['profiles'] ?? array() as $p ) {
		$out .= sn_resume_vcard_line( 'X-SOCIALPROFILE;TYPE=' . strtolower( preg_replace( '~[^A-Za-z0-9-]~', '', $p['network'] ) ), $p['url'] );
	}
	$note = array_filter( array( (string) ( $meta['canonical'] ?? '' ), (string) ( $meta['x-provenance']['verify_url'] ?? '' ) ) );
	if ( $note ) {
		$out .= sn_resume_vcard_line( 'NOTE', $e( implode( "\n", $note ) ) );
	}
	if ( isset( $meta['lastModified'] ) ) {
		$out .= sn_resume_vcard_line( 'REV', $meta['lastModified'] );
	}
	return $out . "END:VCARD\r\n";
}
