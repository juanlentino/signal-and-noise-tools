<?php
/**
 * Signal & Noise Tools: Notes provenance commit-chain core, the normalization (the active check, the note UID, normalize v1 and v2, the block-text expansion, the canonical JSON and its sort helpers).
 *
 * Split from inc/provenance-core.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the provenance subsystem operable? Requires ext-intl for reproducible
 * Unicode NFC normalization. If absent, the whole feature no-ops (an admin
 * notice is surfaced by Plan 5's admin module).
 */
function sn_prov_active() {
	return function_exists( 'normalizer_normalize' );
}

/**
 * Return the Note's stable provenance UUID, minting + persisting it on first
 * call. This — not the WP post ID or slug — is the ledger key, so migrations
 * and slug changes never disturb the ledger.
 *
 * @param int $post_id
 * @return string RFC 4122 v4 UUID.
 */
function sn_prov_note_uid( $post_id ) {
	$uid = get_post_meta( (int) $post_id, SN_PROV_UID_META, true );
	if ( is_string( $uid ) && '' !== $uid ) {
		return $uid;
	}
	$uid = wp_generate_uuid4();
	update_post_meta( (int) $post_id, SN_PROV_UID_META, $uid );
	return $uid;
}

/**
 * sn-normalize-v1: block/HTML markup -> deterministic plain prose.
 *
 * Ordered pipeline (mirrored byte-for-byte by the JS reference impl in the
 * ledger repo — DO NOT reorder without bumping the algo version):
 *   1. remove Gutenberg block-delimiter comments
 *   2. strip all HTML tags
 *   3. decode HTML entities exactly once (HTML5 table, UTF-8)
 *   4. Unicode NFC (ext-intl)
 *   5. line endings -> LF, strip leading BOM
 *   6. per line: collapse [space|tab|NBSP] runs to one space, trim
 *   7. collapse 2+ blank lines to one; trim overall
 *
 * Callers outside sn_prov_record() must check sn_prov_active() first — NFC
 * (step 4) is only reproducible when ext-intl is loaded.
 *
 * @param string $post_content
 * @return string
 */
function sn_prov_normalize_v1( $post_content ) {
	$s = (string) $post_content;
	$s = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $s );            // 1
	$s = wp_strip_all_tags( $s );                                     // 2
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );   // 3
	if ( function_exists( 'normalizer_normalize' ) ) {               // 4
		$n = normalizer_normalize( $s, Normalizer::FORM_C );
		if ( is_string( $n ) ) {
			$s = $n;
		}
	}
	$s = preg_replace( '/^\x{FEFF}/u', '', $s );                      // 5
	$s = preg_replace( '/\r\n?/', "\n", $s );
	$lines = array_map(                                              // 6
		static function ( $ln ) {
			return trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $ln ) );
		},
		explode( "\n", $s )
	);
	$s = implode( "\n", $lines );
	$s = preg_replace( '/\n{3,}/', "\n\n", $s );                     // 7
	return trim( $s );
}

/**
 * sn-normalize-v2's step 0: expand the theme's dynamic-block TEXT into the
 * prose stream, so the record can finally see it.
 *
 * WHY THIS EXISTS (v13.4.0): signal-noise/sidenote and signal-noise/
 * pull-quote are dynamic blocks whose text lives entirely in attributes —
 * serialized into the block delimiter as JSON, which step 1 of v1 removed
 * wholesale. Their words were outside the signed record AND broke public
 * verification outright: render.php emits that text into the served page,
 * so the ledger's byte-equality check (verify.mjs: normalize(page) ===
 * payload.content) could never pass for a signed subject using either
 * block. Expansion closes both at once — the payload gains exactly the
 * text the page shows.
 *
 * THE RULE, chosen so an OFFLINE verifier can apply it with nothing but
 * the post_content bytes (no registry, no block.json, no WordPress):
 * for every VOID `signal-noise/*` block delimiter, every TOP-LEVEL
 * string-typed attribute value in its serialized JSON, in the JSON's own
 * order, empty strings skipped, joined as paragraphs ("\n\n") in place of
 * the delimiter. Deliberately NOT driven by block.json roles: the ledger
 * repo's reference implementation must reproduce this from the delimiter
 * alone. What block.json buys instead is enforcement — the THEME's
 * render-parity test pins that each block's render.php emits exactly its
 * string attributes' text in this order, so a future block that would
 * break the rule fails at authoring time, and a future block that obeys
 * it is signed on registration with no edit here.
 *
 * Boundaries, deliberate: core/* void blocks are NOT expanded (their
 * string attrs are settings like "desc", not prose — and core dynamic
 * blocks that render text remain unusable on signed subjects, exactly as
 * before). Non-void signal-noise blocks are NOT expanded (inner content
 * vs attribute ordering would be ambiguous; none exist). Nested values
 * are NOT walked. Malformed attrs JSON expands to nothing — v1's step-1
 * removal, unchanged. The `--`/`<`/`>`/`&`/`"` characters inside real
 * serialized attrs arrive \u-escaped (core's serialize_block_attributes),
 * so the non-greedy JSON grab below can never be truncated by a literal
 * `-->` and the trailing `/-->` anchor forces the correct extent past any
 * `}` inside string values.
 *
 * Mirrored byte-for-byte by the JS reference impl in the ledger repo
 * (normalize/sn-normalize-v2.mjs) — DO NOT change without bumping the
 * algo version again.
 *
 * @since 13.4.0
 * @param string $s Raw post_content.
 * @return string
 */
function sn_prov_expand_block_text( $s ) {
	return (string) preg_replace_callback(
		'#<!--\s+wp:signal-noise/[a-z][a-z0-9-]*(\s+(\{.*?\}))?\s+/-->#s',
		static function ( $m ) {
			$attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
			if ( ! is_array( $attrs ) ) {
				return '';
			}
			$parts = array();
			foreach ( $attrs as $key => $value ) {
				// Identifier-shaped keys ONLY (review MEDIUM): PHP iterates
				// JSON keys in insertion order for ALL key types, but
				// ECMA-262 hoists integer-like keys ("0", "10") FIRST — so a
				// numeric-string key would make the two reference
				// implementations sign DIFFERENT prose from the same bytes.
				// Real block attributes are identifiers (block.json names);
				// restricting to the identifier grammar makes cross-language
				// iteration order identical by construction.
				if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/', (string) $key ) ) {
					continue;
				}
				if ( is_string( $value ) && '' !== $value ) {
					$parts[] = $value;
				}
			}
			return implode( "\n\n", $parts );
		},
		(string) $s
	);
}

/**
 * sn-normalize-v2: sn_prov_expand_block_text() (step 0), then the v1
 * pipeline unchanged. BYTE-IDENTICAL to v1 for any content containing no
 * void signal-noise/* delimiter — verified over the whole live corpus
 * (2026-08-25: zero posts/pages carry one), so the bump re-signs nothing.
 * The algo NAME is a bearing field; sn_prov_record()'s transition shim
 * keeps a bearing-identical save coalescing across the generation change.
 *
 * @since 13.4.0
 * @param string $post_content
 * @return string
 */
function sn_prov_normalize_v2( $post_content ) {
	return sn_prov_normalize_v1( sn_prov_expand_block_text( (string) $post_content ) );
}

/**
 * Deterministic JSON: recursively sort object keys (byte order), compact,
 * UTF-8, slashes + non-ASCII emitted raw. Byte-identical to RFC 8785 for our
 * strings-and-integers payload. Object keys MUST stay ASCII and non-numeric.
 *
 * @param array $data
 * @return string
 */
function sn_prov_canonical_json( array $data ) {
	sn_prov_ksort_recursive( $data );
	// JSON_UNESCAPED_LINE_TERMINATORS (19.3.3): without it PHP still escapes
	// U+2028/U+2029 even under UNESCAPED_UNICODE, while the provenance
	// worker's canonicalize (JSON.stringify) emits them raw, so a note carrying
	// one (pasted from Word or Docs) hashed differently on each side and was
	// refused on every reconcile. No ledger record can hold one, so no stored
	// hash moves.
	return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
}

/**
 * Recursively ksort associative arrays; leave list arrays in order.
 *
 * @param array $data
 */
function sn_prov_ksort_recursive( array &$data ) {
	if ( ! sn_prov_is_list( $data ) ) {
		ksort( $data, SORT_STRING );
	}
	foreach ( $data as &$value ) {
		if ( is_array( $value ) ) {
			sn_prov_ksort_recursive( $value );
		}
	}
	unset( $value );
}

/**
 * array_is_list() polyfill (native since PHP 8.1; target is 8.0).
 *
 * @param array $arr
 * @return bool
 */
function sn_prov_is_list( array $arr ) {
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $arr );
	}
	$i = 0;
	foreach ( $arr as $k => $unused ) {
		if ( $k !== $i++ ) {
			return false;
		}
	}
	return true;
}

