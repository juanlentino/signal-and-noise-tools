<?php
/**
 * Signal & Noise Tools: the words for machine-reader evidence, shared by every
 * surface that prints it (the desktop card through its payload, Reader
 * evidence on both twins, the Machine readers hero, /stats). Short labels for
 * rows, long descriptions for a row's title or a note, and the clauses the
 * AI-training finding uses. One place, so every surface says the same words.
 * PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every evidence row, in display order: a short label and a long description.
 * Plain words: /stats prints them too.
 *
 * @return array<string,array{label:string,title:string}>
 */
function snt_mr_reader_labels() {
	$w = static fn( $label, $title ) => array( 'label' => $label, 'title' => $title );
	return array(
		'verified'         => $w( __( 'Verified', 'signal-and-noise-tools' ), __( 'A check vouched for the client: Cloudflare’s verified-bot check, or an address inside the IP ranges the vendor publishes.', 'signal-and-noise-tools' ) ),
		'by_cloudflare'    => $w( __( 'by Cloudflare', 'signal-and-noise-tools' ), __( 'Cloudflare’s verified-bot check vouched for the client.', 'signal-and-noise-tools' ) ),
		'by_vendor'        => $w( __( 'by the vendor’s published ranges', 'signal-and-noise-tools' ), __( 'The request came from inside the IP ranges the vendor publishes for its crawler.', 'signal-and-noise-tools' ) ),
		'unverified'       => $w( __( 'Named themselves, not verified', 'signal-and-noise-tools' ), __( 'The user agent named a crawler, and no check vouched for it.', 'signal-and-noise-tools' ) ),
		'failed_check'     => $w( __( 'failed a check', 'signal-and-noise-tools' ), __( 'Named a known crawler, and a check showed the claim false. Counted in the total, credited to no one.', 'signal-and-noise-tools' ) ),
		'cf_directory'     => $w( __( 'Cloudflare’s check', 'signal-and-noise-tools' ), __( 'Named a crawler Cloudflare verifies when it is genuine, and Cloudflare did not verify the request.', 'signal-and-noise-tools' ) ),
		'vendor_ranges'    => $w( __( 'outside the vendor’s published ranges', 'signal-and-noise-tools' ), __( 'Came from outside the IP ranges the vendor publishes, and from a network that is not the vendor’s.', 'signal-and-noise-tools' ) ),
		'off_home_network' => $w( __( 'outside the vendor’s network', 'signal-and-noise-tools' ), __( 'Came from a network that is not the vendor’s own.', 'signal-and-noise-tools' ) ),
		'rotator_network'  => $w( __( 'network posing as many crawlers', 'signal-and-noise-tools' ), __( 'Came from a network that presented many vendors’ crawlers, none of them verified.', 'signal-and-noise-tools' ) ),
		'control_token'    => $w( __( 'control token', 'signal-and-noise-tools' ), __( 'A robots.txt token, not a crawler: reads that present it as their name are counted and credited to no one.', 'signal-and-noise-tools' ) ),
		'not_measured'     => $w( __( 'Not measured', 'signal-and-noise-tools' ), __( 'Reads from before the edge recorded verification.', 'signal-and-noise-tools' ) ),
		'first_party'      => $w( __( 'This site’s own tools', 'signal-and-noise-tools' ), __( 'This site’s own monitors and checks: counted, not readership.', 'signal-and-noise-tools' ) ),
		'ai_training'      => $w( __( 'AI-training reads', 'signal-and-noise-tools' ), __( 'Reads from crawlers that say they gather AI training data, credited to their vendor.', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The impostor reasons with reads, in ladder order; a zero reason is left out.
 *
 * @param array|null $reasons Reason => hits (snt_mr_impostor_reasons()).
 * @return array<int,array{reason:string,hits:int}>
 */
function snt_mr_reason_rows( $reasons ) {
	$out = array();
	foreach ( array( 'cf_directory', 'vendor_ranges', 'off_home_network', 'rotator_network' ) as $r ) {
		if ( (int) ( ( (array) $reasons )[ $r ] ?? 0 ) > 0 ) {
			$out[] = array( 'reason' => $r, 'hits' => (int) $reasons[ $r ] );
		}
	}
	return $out;
}

/**
 * Verified, by source: "83 by Cloudflare, 140 by the vendor’s published
 * ranges". The vendor part only once the sensor measures it.
 *
 * @param int  $verified Every verified read (the vendor part included).
 * @param int  $vendor   The part verified by the vendor's published ranges.
 * @param bool $measured Whether any read carried the vendor-ranges field.
 * @return string
 */
function snt_mr_verified_by( $verified, $vendor, $measured ) {
	$l   = snt_mr_reader_labels();
	$out = number_format_i18n( (int) $verified - ( $measured ? (int) $vendor : 0 ) ) . ' ' . $l['by_cloudflare']['label'];
	return $out . ( $measured ? ', ' . number_format_i18n( (int) $vendor ) . ' ' . $l['by_vendor']['label'] : '' );
}

/**
 * The AI-training figure in full, for the title of a row that shows the
 * verified part: claimed, then each part.
 *
 * @param array $t        A verification split (claimed, verified, vendor_verified, unverified, unverifiable).
 * @param bool  $measured Whether vendor ranges were measured.
 * @return string
 */
function snt_mr_ai_training_title( array $t, $measured ) {
	return sprintf(
		/* translators: 1: claimed, 2: verified, 3: verified by source, 4: not verified, 5: before verification. */
		__( '%1$s claimed, credited to their vendor: %2$s verified (%3$s), %4$s named themselves, not verified, %5$s before verification was recorded.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) ( $t['claimed'] ?? 0 ) ),
		number_format_i18n( (int) ( $t['verified'] ?? 0 ) ),
		snt_mr_verified_by( (int) ( $t['verified'] ?? 0 ), (int) ( $t['vendor_verified'] ?? 0 ), $measured ),
		number_format_i18n( (int) ( $t['unverified'] ?? 0 ) ),
		number_format_i18n( (int) ( $t['unverifiable'] ?? 0 ) )
	);
}

/**
 * The words a payload carries for a surface that cannot call PHP (the desktop
 * card): the labels, the reason rows and the AI-training description.
 *
 * @param array $summary  snt_mr_summary_payload() (impostors, verification).
 * @param bool  $measured Whether vendor ranges were measured.
 * @return array{labels:array,impostor_reasons:array,ai_training_title:string}
 */
function snt_mr_reader_words( array $summary, $measured ) {
	$ai = $summary['verification']['ai_training_by_purpose'] ?? null;
	return array(
		'labels'            => snt_mr_reader_labels(),
		'impostor_reasons'  => function_exists( 'snt_mr_impostor_reasons' ) ? snt_mr_reason_rows( snt_mr_impostor_reasons( $summary['impostors'] ?? null ) ) : array(),
		'ai_training_title' => is_array( $ai ) ? snt_mr_ai_training_title( $ai, $measured ) : '',
	);
}

/**
 * What one impostor reason means, as a clause after a count (the AI-training
 * finding's breakdown).
 *
 * @param string $reason cf_directory|vendor_ranges|off_home_network|rotator_network.
 * @return string
 */
function snt_mr_impostor_reason_label( $reason ) {
	switch ( (string) $reason ) {
		case 'cf_directory':
			return __( 'failed Cloudflare’s check', 'signal-and-noise-tools' );
		case 'vendor_ranges':
			return __( 'came from outside the vendor’s published IP ranges and network', 'signal-and-noise-tools' );
		case 'off_home_network':
			return __( 'came from outside the vendor’s own network', 'signal-and-noise-tools' );
		case 'rotator_network':
			return __( 'came from a network posing as many crawlers', 'signal-and-noise-tools' );
	}
	return __( 'failed a check', 'signal-and-noise-tools' );
}

/**
 * The per-reason breakdown as one clause: "1,378 failed Cloudflare’s check;
 * 461 came from outside the vendor’s own network". '' when there is none.
 *
 * @param array|null $reasons Reason => hits (snt_mr_impostor_reasons()).
 * @return string
 */
function snt_mr_impostor_breakdown( $reasons ) {
	$parts = array();
	foreach ( (array) $reasons as $reason => $hits ) {
		if ( (int) $hits > 0 ) {
			$parts[] = number_format_i18n( (int) $hits ) . ' ' . snt_mr_impostor_reason_label( (string) $reason );
		}
	}
	return implode( '; ', $parts );
}
