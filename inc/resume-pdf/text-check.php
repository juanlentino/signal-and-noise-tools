<?php
/**
 * Signal & Noise Tools: what a generated résumé PDF actually says (owner brief
 * 2026-11-01, "a check on every generation").
 *
 * The server has no PDF text tool, so this reads the one kind of file it needs
 * to: Dompdf's CPDF output. It inflates each stream, reads every font's
 * ToUnicode map, and decodes the strings of each text operator through the map
 * of the font in use. tests/resume-pdf-text-check.php holds it to pdftotext's
 * reading of the same file. It is a check, not a general PDF reader.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every object's dictionary and (inflated) stream, by object number.
 *
 * @param string $bytes PDF file.
 * @return array<int,array{dict:string,stream:string|null}>
 */
function sn_resume_pdf_objects( $bytes ) {
	$out = array();
	if ( ! preg_match_all( '/(?<![0-9])(\d+) 0 obj\b/', $bytes, $m, PREG_OFFSET_CAPTURE ) ) {
		return $out;
	}
	foreach ( $m[1] as $i => $hit ) {
		$start = $m[0][ $i ][1] + strlen( $m[0][ $i ][0] );
		$end   = strpos( $bytes, 'endobj', $start );
		$sp    = strpos( $bytes, 'stream', $start );
		if ( false === $end ) {
			continue;
		}
		$stream = null;
		$dict   = substr( $bytes, $start, ( false !== $sp && $sp < $end ? $sp : $end ) - $start );
		if ( false !== $sp && $sp < $end ) {
			$data = $sp + 6;
			$data += ( "\r" === ( $bytes[ $data ] ?? '' ) ) ? 2 : 1;
			$len  = preg_match( '~/Length (\d+)(?! \d+ R)~', $dict, $lm ) ? (int) $lm[1] : max( 0, (int) strpos( $bytes, 'endstream', $data ) - $data );
			$raw  = substr( $bytes, $data, $len );
			$end  = (int) strpos( $bytes, 'endobj', $data + $len );
			$stream = false !== strpos( $dict, '/FlateDecode' ) ? (string) @gzuncompress( $raw ) : $raw; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a stream that does not inflate reads as empty.
		}
		$out[ (int) $hit[0] ] = array( 'dict' => $dict, 'stream' => $stream );
	}
	return $out;
}

/**
 * A ToUnicode map: code (as hex) to UTF-8.
 *
 * @param string $cmap CMap stream.
 * @return array<string,string>
 */
function sn_resume_pdf_cmap( $cmap ) {
	$u   = static fn( $hex ) => mb_convert_encoding( (string) hex2bin( strlen( $hex ) % 4 ? str_pad( $hex, 4, '0', STR_PAD_LEFT ) : $hex ), 'UTF-8', 'UTF-16BE' );
	$map = array();
	preg_match_all( '/beginbfchar(.*?)endbfchar/s', $cmap, $blocks );
	foreach ( $blocks[1] as $block ) {
		preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER );
		foreach ( $pairs as $p ) {
			$map[ strtoupper( $p[1] ) ] = $u( $p[2] );
		}
	}
	preg_match_all( '/beginbfrange(.*?)endbfrange/s', $cmap, $blocks );
	foreach ( $blocks[1] as $block ) {
		preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER );
		foreach ( $ranges as $r ) {
			$width = strlen( $r[1] );
			for ( $c = hexdec( $r[1] ), $to = hexdec( $r[2] ), $dst = hexdec( $r[3] ); $c <= $to && $c - hexdec( $r[1] ) < 65536; $c++, $dst++ ) {
				$map[ strtoupper( str_pad( dechex( $c ), $width, '0', STR_PAD_LEFT ) ) ] = $u( dechex( $dst ) );
			}
		}
	}
	return $map;
}

/**
 * The text of a Dompdf PDF, one line per text object.
 *
 * @param string $bytes PDF file.
 * @return string
 */
function sn_resume_pdf_text( $bytes ) {
	$objects = sn_resume_pdf_objects( (string) $bytes );
	$maps    = array(); // font resource name => ToUnicode map.
	foreach ( $objects as $obj ) {
		if ( ! preg_match( '~/Font\s*<<(.*?)>>~s', $obj['dict'], $fonts ) ) {
			continue;
		}
		preg_match_all( '~/([A-Za-z0-9_.+-]+)\s+(\d+) 0 R~', $fonts[1], $names, PREG_SET_ORDER );
		foreach ( $names as $n ) {
			$font = $objects[ (int) $n[2] ]['dict'] ?? '';
			if ( preg_match( '~/ToUnicode (\d+) 0 R~', $font, $tu ) && isset( $objects[ (int) $tu[1] ] ) ) {
				$maps[ $n[1] ] = sn_resume_pdf_cmap( (string) $objects[ (int) $tu[1] ]['stream'] );
			}
		}
	}
	$text = '';
	foreach ( $objects as $obj ) {
		$s = (string) $obj['stream'];
		if ( null === $obj['stream'] || false === strpos( $s, 'BT' ) || false !== strpos( $s, 'begincmap' ) ) {
			continue;
		}
		$map = array();
		preg_match_all( '~/([A-Za-z0-9_.+-]+)\s+[\d.]+\s+Tf|<([0-9A-Fa-f\s]*)>|\((?:\\\\.|[^\\\\)])*\)|\bET\b~s', $s, $tokens, PREG_SET_ORDER );
		foreach ( $tokens as $t ) {
			if ( ! empty( $t[1] ) ) {
				$map = $maps[ $t[1] ] ?? array();
			} elseif ( 'ET' === $t[0] ) {
				$text .= "\n";
			} else {
				// A hex string, or a literal one (Dompdf writes Unicode text as
				// UTF-16BE literals): either way, codes through the font's map.
				$hex = '<' === $t[0][0] ? strtoupper( (string) preg_replace( '/\s+/', '', $t[2] ) ) : strtoupper( bin2hex( stripcslashes( substr( $t[0], 1, -1 ) ) ) );
				$w   = $map && 4 === strlen( (string) array_key_first( $map ) ) ? 4 : 2;
				foreach ( str_split( $hex, $w ) as $code ) {
					$text .= $map[ $code ] ?? ( 4 === $w ? mb_convert_encoding( (string) hex2bin( $code ), 'UTF-8', 'UTF-16BE' ) : chr( (int) hexdec( $code ) ) );
				}
			}
		}
	}
	return $text;
}

/**
 * What is wrong with a generated résumé PDF: control characters or ligatures
 * in its text, a phone number in its text or its metadata, more pages than
 * allowed. An empty list is a pass. `extractor` names the reader used.
 *
 * @param string   $bytes     PDF file.
 * @param int      $pages     Its page count (Dompdf's).
 * @param int|null $max_pages Page limit, or null for none.
 * @param string   $phone     The stored phone, if any: its digits anywhere in the text flag it too.
 * @return array{flags:array<int,string>,chars:int,extractor:string}
 */
function sn_resume_pdf_check( $bytes, $pages, $max_pages = null, $phone = '' ) {
	$text = sn_resume_pdf_text( $bytes );
	$raw  = preg_match( '~/Info (\d+) 0 R~', (string) $bytes, $info ) && preg_match( '~(?<![0-9])' . $info[1] . ' 0 obj(.*?)endobj~s', (string) $bytes, $im ) ? $im[1] : '';
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
	return array(
		'flags'     => $flags,
		'chars'     => mb_strlen( $text, 'UTF-8' ),
		'extractor' => 'built-in Dompdf/CPDF reader (inc/resume-pdf/text-check.php)',
	);
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
	$phone = '/(?<!\d)(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}(?!\d)|(?<!\d)\d{10,11}(?!\d)/';
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
	if ( preg_match( $phone, (string) $text ) || preg_match( $phone, (string) $meta ) ) {
		$flags[] = 'a phone number';
	}
	if ( null !== $max_pages && (int) $pages > (int) $max_pages ) {
		$flags[] = sprintf( '%d pages, over %d', (int) $pages, (int) $max_pages );
	}
	return $flags;
}
