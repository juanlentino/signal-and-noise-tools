<?php
/**
 * Signal & Noise Tools — verifiable provenance (D2): embed a self-contained
 * provenance block into each OG-card PNG. The block is the Note's existing D1
 * Verifiable Credential (reused, no new signing) plus pointers, written into a
 * PNG `iTXt` chunk (keyword `provenance`). Machine-readability program, D2.
 *
 * Pure-PHP PNG chunk read/write — no library. A PNG is an 8-byte signature
 * followed by chunks `length(4,BE) | type(4) | data | crc32(4 over type+data)`.
 * We insert one `iTXt` chunk immediately before the terminal `IEND`.
 *
 * @package SignalNoiseTools
 * @since 9.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_OG_PROV_KEYWORD = 'provenance';

/**
 * Assemble a single PNG `iTXt` chunk (uncompressed, empty language/translated
 * keyword). Data layout: keyword \0 compFlag(0) compMethod(0) lang \0 trans \0 text.
 *
 * @param string $keyword ASCII, 1-79 bytes.
 * @param string $text    UTF-8 payload.
 * @return string The full chunk (length + type + data + CRC).
 */
function sn_og_png_itxt_chunk( $keyword, $text ) {
	$data = $keyword . "\x00" // keyword null separator
		. "\x00"              // compression flag: 0 = uncompressed
		. "\x00"              // compression method: 0
		. "\x00"              // empty language tag, null-terminated
		. "\x00"              // empty translated keyword, null-terminated
		. (string) $text;
	$type = 'iTXt';
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
}

/**
 * Return $png with an `iTXt` chunk ($keyword/$text) inserted just before IEND,
 * first removing any existing `iTXt` with the same keyword (idempotent). If
 * $png is not a well-formed PNG (no signature, or no IEND, or a truncated
 * chunk), the input is returned UNCHANGED — decorative provenance must never
 * corrupt the card.
 *
 * @param string $png
 * @param string $keyword
 * @param string $text
 * @return string
 */
function sn_og_png_set_itxt( $png, $keyword, $text ) {
	$sig = "\x89PNG\r\n\x1a\n";
	if ( ! is_string( $png ) || strncmp( $png, $sig, 8 ) !== 0 ) {
		return $png;
	}
	$len = strlen( $png );
	$off = 8;
	$out = $sig;
	$new = sn_og_png_itxt_chunk( $keyword, $text );
	while ( $off + 12 <= $len ) {
		$dlen = unpack( 'N', substr( $png, $off, 4 ) )[1];
		$type = substr( $png, $off + 4, 4 );
		$full = 8 + $dlen + 4;
		if ( $off + $full > $len ) {
			return $png; // truncated/corrupt tail — do not touch the file.
		}
		// Drop a prior provenance iTXt so re-injection never duplicates.
		if ( 'iTXt' === $type && strstr( substr( $png, $off + 8, $dlen ), "\x00", true ) === $keyword ) {
			$off += $full;
			continue;
		}
		if ( 'IEND' === $type ) {
			$out .= $new . substr( $png, $off, $full );
			$tail = $off + $full;
			if ( $tail < $len ) {
				$out .= substr( $png, $tail );
			}
			return $out;
		}
		$out .= substr( $png, $off, $full );
		$off += $full;
	}
	return $png; // no IEND — corrupt; leave unchanged.
}

/**
 * Read the text of the first `iTXt` chunk with $keyword, or null.
 *
 * @param string $png
 * @param string $keyword
 * @return string|null
 */
function sn_og_png_get_itxt( $png, $keyword ) {
	$sig = "\x89PNG\r\n\x1a\n";
	if ( ! is_string( $png ) || strncmp( $png, $sig, 8 ) !== 0 ) {
		return null;
	}
	$len = strlen( $png );
	$off = 8;
	while ( $off + 12 <= $len ) {
		$dlen = unpack( 'N', substr( $png, $off, 4 ) )[1];
		$type = substr( $png, $off + 4, 4 );
		$full = 8 + $dlen + 4;
		if ( $off + $full > $len ) {
			return null;
		}
		if ( 'iTXt' === $type ) {
			$data = substr( $png, $off + 8, $dlen );
			$kw   = strstr( $data, "\x00", true );
			if ( $kw === $keyword ) {
				// After keyword\0: compFlag(1) compMethod(1) lang\0 trans\0 text.
				$rest = substr( $data, strlen( (string) $kw ) + 1 + 2 );
				$p1   = strpos( $rest, "\x00" );
				if ( false === $p1 ) { return null; }
				$rest = substr( $rest, $p1 + 1 );
				$p2   = strpos( $rest, "\x00" );
				if ( false === $p2 ) { return null; }
				return substr( $rest, $p2 + 1 );
			}
		}
		$off += $full;
		if ( 'IEND' === $type ) { break; }
	}
	return null;
}
