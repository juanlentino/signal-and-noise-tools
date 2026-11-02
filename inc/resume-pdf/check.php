<?php
/**
 * Signal & Noise Tools: the flags on a generated résumé PDF, read through
 * inc/resume-pdf/text-check.php. A part of the file the reader could not read
 * is a flag too, so an unread page never passes as clean.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is wrong with a generated résumé PDF: control characters or ligatures
 * in its text, a phone number in its text or its metadata, more pages than
 * allowed, a page or the metadata the reader could not read. An empty list is
 * a pass. `extractor` names the reader used.
 *
 * @param string   $bytes     PDF file.
 * @param int      $pages     Its page count (Dompdf's).
 * @param int|null $max_pages Page limit, or null for none.
 * @param string   $phone     The stored phone, if any: its digits anywhere in the text flag it too.
 * @return array{flags:array<int,string>,chars:int,extractor:string}
 */
function sn_resume_pdf_check( $bytes, $pages, $max_pages = null, $phone = '' ) {
	$objects = sn_resume_pdf_objects( (string) $bytes );
	$text    = sn_resume_pdf_text( $bytes, $objects );
	$raw     = preg_match( '~/Info (\d+) 0 R~', (string) $bytes, $info ) && preg_match( '~(?<![0-9])' . $info[1] . ' 0 obj(.*?)endobj~s', (string) $bytes, $im ) ? $im[1] : '';
	// Dompdf writes Info strings as UTF-16BE literals (\376\377...): decode them, or a phone there reads as octal noise.
	$meta = '';
	preg_match_all( '/\((?:\\\\.|[^\\\\)])*\)/s', $raw, $lits );
	foreach ( $lits[0] as $lit ) {
		$s     = stripcslashes( substr( $lit, 1, -1 ) );
		$meta .= ( "\xFE\xFF" === substr( $s, 0, 2 ) ? mb_convert_encoding( substr( $s, 2 ), 'UTF-8', 'UTF-16BE' ) : $s ) . "\n";
	}
	$flags  = sn_resume_pdf_text_flags( $text, $meta, $pages, $max_pages );
	$digits = (string) preg_replace( '/\D+/', '', (string) $phone );
	if ( strlen( $digits ) >= 7 && ! in_array( 'a phone number', $flags, true ) && false !== strpos( preg_replace( '/\D+/', '', $text . $meta ), $digits ) ) {
		$flags[] = 'a phone number';
	}
	list( $read, $total, $failed ) = sn_resume_pdf_pages_read( $objects );
	if ( $read < $total ) {
		$flags[] = sprintf( 'only %d of %d pages could be read', $read, $total );
	}
	if ( $failed ) {
		$flags[] = sprintf( '%d %s could not be inflated', $failed, 1 === $failed ? 'stream' : 'streams' );
	}
	if ( '' === $raw ) {
		$flags[] = 'the metadata could not be read'; // Dompdf always writes it: not found means not checked for a phone.
	}
	return array(
		'flags'     => $flags,
		'chars'     => mb_strlen( $text, 'UTF-8' ),
		'extractor' => 'built-in Dompdf/CPDF reader (inc/resume-pdf/text-check.php)',
	);
}

/**
 * How many pages have a content stream the reader read, of how many pages,
 * and how many streams did not inflate. PURE.
 *
 * @param array<int,array{dict:string,stream:string|false|null}> $objects sn_resume_pdf_objects().
 * @return array{0:int,1:int,2:int} Read, total, failed.
 */
function sn_resume_pdf_pages_read( $objects ) {
	$read  = 0;
	$total = 0;
	foreach ( $objects as $obj ) {
		if ( ! preg_match( '~/Type\s*/Page\b~', $obj['dict'] ) ) {
			continue;
		}
		++$total;
		preg_match_all( '~(\d+) 0 R~', preg_match( '~/Contents\s*(\[[^\]]*\]|\d+ 0 R)~', $obj['dict'], $c ) ? $c[1] : '', $refs );
		foreach ( $refs[1] as $ref ) {
			if ( '' !== (string) ( $objects[ (int) $ref ]['stream'] ?? '' ) ) {
				++$read;
				break;
			}
		}
	}
	return array( $read, $total, count( array_filter( $objects, static fn( $o ) => false === $o['stream'] ) ) );
}

/**
 * The flags for a PDF's text and metadata. PURE.
 *
 * @param string   $text      Extracted text.
 * @param string   $meta      The Info dictionary, raw.
 * @param int      $pages     Page count.
 * @param int|null $max_pages Page limit, or null for none.
 * @return array<int,string>
 */
function sn_resume_pdf_text_flags( $text, $meta, $pages, $max_pages = null ) {
	// Within one line: three numbers on three lines (a stats row) are not a phone. A no-break space is a space.
	$phone = '/(?<!\d)(?:\+?\d{1,3}[ \t.-]?)?\(?\d{3}\)?[ \t.-]\d{3}[ \t.-]\d{4}(?!\d)|(?<!\d)\d{10,11}(?!\d)/';
	$flags = array();
	if ( '' === trim( (string) $text ) ) {
		$flags[] = 'no text could be read';
	} elseif ( mb_strlen( (string) $text, 'UTF-8' ) < 1000 ) {
		$flags[] = 'only ' . mb_strlen( (string) $text, 'UTF-8' ) . ' characters could be read';
	}
	if ( preg_match( '/[\x00-\x08\x0B\x0D-\x1F]/', (string) $text ) ) {
		$flags[] = 'control characters in the text';
	}
	if ( preg_match( '/[\x{FB00}-\x{FB06}]/u', (string) $text ) ) {
		$flags[] = 'ligature characters in the text';
	}
	if ( preg_match( $phone, str_replace( "\xC2\xA0", ' ', $text . "\n" . $meta ) ) ) {
		$flags[] = 'a phone number';
	}
	if ( null !== $max_pages && (int) $pages > (int) $max_pages ) {
		$flags[] = sprintf( '%d pages, over %d', (int) $pages, (int) $max_pages );
	}
	return $flags;
}
