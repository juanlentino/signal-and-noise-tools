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
 * Every object's dictionary and (inflated) stream, by object number. A
 * stream that does not inflate is false, so the check can count it.
 *
 * @param string $bytes PDF file.
 * @return array<int,array{dict:string,stream:string|false|null}>
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
			$len  = preg_match( '~/Length (\d++)(?! \d+ R)~', $dict, $lm ) ? (int) $lm[1] : max( 0, (int) strpos( $bytes, 'endstream', $data ) - $data );
			$raw  = substr( $bytes, $data, $len );
			$end  = (int) strpos( $bytes, 'endobj', $data + $len );
			$stream = false !== strpos( $dict, '/FlateDecode' ) ? @gzuncompress( $raw ) : $raw; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a stream that does not inflate is false, counted by the check.
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
 * @param string     $bytes   PDF file.
 * @param array|null $objects Its sn_resume_pdf_objects(), when already read.
 * @return string
 */
function sn_resume_pdf_text( $bytes, $objects = null ) {
	$objects = $objects ?? sn_resume_pdf_objects( (string) $bytes );
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

require_once __DIR__ . '/check.php'; // the flags read from it.
