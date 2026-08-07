<?php
/**
 * Signal & Noise Tools — sn_scan scan_type "anchor_violations".
 *
 * Two rules, both BINARY — no thresholds, no ratios (the owner explicitly
 * measured and rejected an anchor-length/sentence-length ratio: it punishes
 * short sentences, which are deliberate in the house voice):
 *
 *   1. anchor_equals_sentence — the anchor text of an <a> is identical to
 *      the FULL sentence containing it (terminal punctuation ignored on
 *      both sides). A link should name a claim inside a sentence, not
 *      swallow the sentence whole.
 *   2. heading_contains_link — any <a> inside an h1–h6 block. Headings are
 *      structure, never navigation.
 *
 * Both measured against the published corpus 2026-08-08: zero false
 * positives, and together they catch all 13 known violations. Pure
 * structural detection over raw post_content — zero AI, zero writes, the
 * sn_scan family contract (inc/sn-scan-adapters.php's envelope shape).
 *
 * No apply path exists yet (a "link_reshape" change type is designed but
 * gated on an owner decision) — apply_hint is null; violations are resolved
 * in the editor by hand.
 *
 * @package SignalNoiseTools
 * @since 10.58.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Clear rule match, not a byte-exact duplicate — same tier as
// SNT_SN_SCAN_CONF_BLOCK_MIGRATIONS (0.9), per the documented-constant
// convention in inc/sn-scan-adapters.php.
const SNT_SN_SCAN_CONF_ANCHOR_VIOLATIONS = 0.9;

/**
 * Normalize a text fragment for comparison: strip tags, decode entities
 * once, collapse whitespace runs, trim, then drop terminal punctuation so
 * an anchor without its sentence's closing period still matches ("The DAW
 * signs the assembly" vs "The DAW signs the assembly.").
 *
 * @param string $s
 * @return string
 */
function snt_anchor_violations_normalize( $s ) {
	$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $s ) : strip_tags( (string) $s );
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', $s );
	$s = trim( (string) $s );
	return rtrim( $s, ".!?\u{2026}" );
}

/**
 * Flatten markup to plain text while PRESERVING the \x01/\x02 sentinel pair
 * a caller spliced around one anchor. Closing block-level tags become
 * newlines first so a heading never runs into the following paragraph as
 * one "sentence".
 *
 * @param string $marked Content with exactly one \x01…\x02 sentinel span.
 * @return string
 */
function snt_anchor_violations_flatten( $marked ) {
	$s = preg_replace( '/<!--.*?-->/s', '', (string) $marked );
	$s = preg_replace( '#</(p|h[1-6]|li|blockquote|figcaption|pre|td|th)>#i', "\n", $s );
	$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return preg_replace( '/[ \t\x{00A0}]+/u', ' ', $s );
}

/**
 * The sentence (as a substring span of $text) containing byte offset $pos.
 * Boundaries: newlines, and a terminator run [.!?…] followed by whitespace.
 * Deliberately the same simple tokenizer posture as
 * snt_sn_validate_sentence_count() — no abbreviation awareness; a false
 * split SHORTENS a sentence, which can only ever suppress a match for a
 * binary equality rule, never invent one… unless the anchor equals the
 * fragment, which the corpus measurement showed does not occur.
 *
 * @param string $text
 * @param int    $pos  Byte offset the sentence must contain.
 * @return string
 */
function snt_anchor_violations_sentence_at( $text, $pos ) {
	$text = (string) $text;
	$len  = strlen( $text );
	$pos  = max( 0, min( $pos, $len ) );

	$start = 0;
	if ( preg_match_all( '/[.!?\x{2026}](?=\s)|\n/u', substr( $text, 0, $pos ), $m, PREG_OFFSET_CAPTURE ) ) {
		$last  = end( $m[0] );
		$start = $last[1] + strlen( $last[0] );
	}

	$end = $len;
	if ( preg_match( '/[.!?\x{2026}](?=\s|$)|\n/u', $text, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
		$end = $m[0][1] + ( "\n" === $m[0][0] ? 0 : strlen( $m[0][0] ) );
	}

	return substr( $text, $start, $end - $start );
}

/**
 * Scan one post's raw content for both anchor rules.
 *
 * @param string $content Raw post_content (block markup).
 * @return array<int,array{rule:string,anchor_text:string,sentence:string,heading_level:int|null}>
 */
function snt_anchor_violations_scan_content( $content ) {
	$content    = (string) $content;
	$violations = array();

	// Heading spans first — rule 2, and an exclusion map for rule 1 (a
	// heading wrapped entirely in a link would otherwise fire BOTH rules for
	// one editorial defect; the heading rule owns it).
	$heading_spans = array();
	if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $content, $headings, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		foreach ( $headings as $h ) {
			$span_start      = $h[0][1];
			$heading_spans[] = array( $span_start, $span_start + strlen( $h[0][0] ) );
			if ( preg_match( '/<a\b[^>]*>(.*?)<\/a>/is', $h[2][0], $a ) ) {
				$violations[] = array(
					'rule'          => 'heading_contains_link',
					'anchor_text'   => snt_anchor_violations_normalize( $a[1] ),
					'sentence'      => snt_anchor_violations_normalize( $h[2][0] ),
					'heading_level' => (int) $h[1][0],
				);
			}
		}
	}

	if ( ! preg_match_all( '/<a\b[^>]*>.*?<\/a>/is', $content, $anchors, PREG_OFFSET_CAPTURE ) ) {
		return $violations;
	}

	foreach ( $anchors[0] as $match ) {
		$anchor_html = $match[0];
		$offset      = $match[1];

		$in_heading = false;
		foreach ( $heading_spans as $span ) {
			if ( $offset >= $span[0] && $offset < $span[1] ) {
				$in_heading = true;
				break;
			}
		}
		if ( $in_heading ) {
			continue; // Rule 2 already owns every anchor inside a heading.
		}

		$anchor_text = snt_anchor_violations_normalize( $anchor_html );
		if ( '' === $anchor_text ) {
			continue;
		}

		// Splice sentinels around THIS occurrence only, flatten, then read
		// the sentence containing the anchor's start.
		$marked   = substr_replace( $content, "\x01" . $anchor_html . "\x02", $offset, strlen( $anchor_html ) );
		$flat     = snt_anchor_violations_flatten( $marked );
		$pos1     = strpos( $flat, "\x01" );
		if ( false === $pos1 ) {
			continue; // Anchor vanished in flattening (e.g. inside a stripped comment) — nothing to judge.
		}
		$flat     = str_replace( array( "\x01", "\x02" ), '', $flat );
		$sentence = snt_anchor_violations_sentence_at( $flat, $pos1 );
		$sentence_norm = snt_anchor_violations_normalize( $sentence );

		if ( '' !== $sentence_norm && $sentence_norm === $anchor_text ) {
			$violations[] = array(
				'rule'          => 'anchor_equals_sentence',
				'anchor_text'   => $anchor_text,
				'sentence'      => $sentence_norm,
				'heading_level' => null,
			);
		}
	}

	return $violations;
}

/**
 * sn_scan adapter: anchor_violations. Walks the same corpus convention as
 * the emdash adapter (snt_corpus_fetch_posts 'any'/'post' — scheduled Notes
 * included, so a violation is caught BEFORE it publishes), reshaping each
 * violation into the standard envelope row.
 *
 * @param int[]|null $allowed_ids Restrict to these post ids, or null for the corpus.
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_anchor_violations( $allowed_ids ) {
	if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
	}

	if ( null !== $allowed_ids ) {
		$source_ids = $allowed_ids;
	} else {
		$source_ids = array_map( static function ( $p ) { return (int) $p->ID; }, snt_corpus_fetch_posts( 'any', 'post' ) );
	}

	$candidates = array();
	$examined   = 0;
	foreach ( $source_ids as $pid ) {
		$post = get_post( (int) $pid );
		if ( ! $post ) {
			continue;
		}
		$examined++;

		// Per-post ordinal disambiguates two byte-identical violations in one
		// post; being content-derived (not position-derived), the fingerprint
		// survives unrelated edits elsewhere in the post.
		$ordinals = array();
		foreach ( snt_anchor_violations_scan_content( (string) $post->post_content ) as $v ) {
			$key              = $v['rule'] . '|' . $v['anchor_text'];
			$ordinals[ $key ] = ( $ordinals[ $key ] ?? -1 ) + 1;

			$candidates[] = array(
				'target_identity'     => (string) $post->ID,
				'content_fingerprint' => md5( $key . '|' . $ordinals[ $key ] ),
				'targets'             => array( array(
					'post_id' => (int) $post->ID,
					'slug'    => (string) ( $post->post_name ?? '' ),
					'title'   => (string) ( $post->post_title ?? '' ),
				) ),
				'confidence'          => SNT_SN_SCAN_CONF_ANCHOR_VIOLATIONS,
				'evidence'            => array(
					'rule'          => $v['rule'],
					'anchor_text'   => $v['anchor_text'],
					'sentence'      => $v['sentence'],
					'heading_level' => $v['heading_level'],
				),
				// No apply path: link_reshape is designed but owner-gated; a
				// violation is fixed in the editor by hand today.
				'apply_hint'          => null,
			);
		}
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $examined,
		'posts_skipped'  => 0,
		'truncated'      => false,
	);
}
