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

/**
 * Build the provenance block for a Note's OG card, or null when D1 declines a
 * credential (non-public, password-protected, unsigned, or payload/hash drift)
 * — inheriting D1's visibility gate exactly, so a card never embeds provenance
 * a public verifier could not confirm, and never leaks protected content.
 *
 * @param int $post_id
 * @return array<string,mixed>|null
 */
function sn_og_card_provenance_block( $post_id ) {
	if ( ! function_exists( 'sn_prov_credential' ) ) {
		return null;
	}
	$vc = sn_prov_credential( (int) $post_id, null );
	if ( null === $vc ) {
		return null; // D1 gate.
	}
	$uid = function_exists( 'sn_prov_note_uid' ) ? (string) sn_prov_note_uid( $post_id ) : '';
	if ( '' === $uid ) {
		return null;
	}
	$ns   = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	$home = function_exists( 'home_url' ) ? home_url() : '';
	return array(
		'@context'       => 'https://juanlentino.com/ns/card-provenance/v1',
		'type'           => 'OGCardProvenance',
		'note_uid'       => $uid,
		'credential_url' => function_exists( 'rest_url' ) ? rest_url( $ns . '/credential/' . rawurlencode( $uid ) ) : ( $home . '/wp-json/' . $ns . '/credential/' . rawurlencode( $uid ) ),
		'did_document'   => function_exists( 'home_url' ) ? home_url( '/.well-known/did.json' ) : '/.well-known/did.json',
		'credential'     => $vc, // the D1 Verifiable Credential, verbatim (single source of truth).
	);
}

/**
 * Inject the provenance block into the card PNG at $path (in place). No-op
 * (returns false) when there is no block, the file is unreadable, or it is not
 * a PNG. Never throws into the non-blocking card-generation path.
 *
 * @param string $path Absolute path to a generated card PNG.
 * @param int    $post_id
 * @return bool True only when the file was rewritten with a provenance chunk.
 */
function sn_og_card_inject_provenance( $path, $post_id ) {
	$block = sn_og_card_provenance_block( $post_id );
	if ( null === $block ) {
		return false;
	}
	$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $block, JSON_UNESCAPED_SLASHES ) : json_encode( $block );
	if ( ! is_string( $json ) || '' === $json ) {
		return false;
	}
	if ( ! is_readable( $path ) ) {
		return false;
	}
	$png = file_get_contents( $path );
	if ( false === $png ) {
		return false;
	}
	$out = sn_og_png_set_itxt( $png, SN_OG_PROV_KEYWORD, $json );
	if ( $out === $png ) {
		return false; // not a PNG, or nothing changed.
	}
	return false !== file_put_contents( $path, $out );
}

/**
 * Advertise the OG-card provenance convention in sub-project A's discovery
 * manifest (the theme owns the filter; the plugin appends one callback).
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_og_card_advertise_surface( $surfaces ) {
	$surfaces[] = array(
		'type'        => 'og-card-provenance',
		'url'         => 'https://juanlentino.com/ns/card-provenance/v1',
		'format'      => 'image/png (embedded application/json in an iTXt "provenance" chunk)',
		'title'       => 'OG card provenance',
		'description' => "Each Note's social-share card embeds a self-contained provenance block (the Note's Verifiable Credential) in its PNG metadata.",
	);
	return $surfaces;
}

if ( ! defined( 'SN_OG_CARD_PROV_TEST' ) || ! SN_OG_CARD_PROV_TEST ) {
	add_filter( 'sn_agents_surfaces', 'sn_og_card_advertise_surface' );
}
