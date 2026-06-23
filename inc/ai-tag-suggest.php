<?php
/**
 * AI tag suggestion: propose relevant EXISTING post_tag terms for a Note. Wraps the
 * shared snt_ai_generate_with_constraints helper; output is constrained to the
 * existing vocabulary twice (prompt instruction + post-parse filter via
 * sn_tag_normalize_key). Read-only; the caller applies. No output.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AI_TAG_INPUT_WORDS = 400;
const SN_AI_TAG_MAX_TOKENS  = 128;

/**
 * Suggest existing tags for a post.
 *
 * @param int $post_id Post id.
 * @return array|WP_Error { ok, post_id, suggested:[{term_id,name,slug}] } or WP_Error.
 */
function snt_ai_tag_suggest_impl( $post_id ) {
	$post_id = (int) $post_id;
	$gate    = function_exists( 'snt_ai_require_text_generation' ) ? snt_ai_require_text_generation() : null;
	if ( is_wp_error( $gate ) ) {
		return $gate;
	}

	$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	if ( ! is_array( $terms ) || ! $terms ) {
		return array( 'ok' => true, 'post_id' => $post_id, 'suggested' => array() );
	}

	$title = function_exists( 'get_the_title' ) ? wp_strip_all_tags( (string) get_the_title( $post_id ) ) : '';
	$body  = function_exists( 'snt_ai_extract_post_text' ) ? (string) snt_ai_extract_post_text( $post_id, SN_AI_TAG_INPUT_WORDS ) : '';
	$text  = trim( $title . "\n\n" . $body );
	if ( '' === $text ) {
		return array( 'ok' => true, 'post_id' => $post_id, 'suggested' => array() );
	}

	$names = array();
	foreach ( $terms as $t ) {
		$names[] = (string) $t->name;
	}
	$system = 'You tag blog posts. From ONLY the provided tag list, choose the tags that genuinely apply to this post and return them as a JSON array of exact strings copied from the list. Aim for the 3 to 4 most relevant tags (more for longer or wide-ranging posts; fewer only when the list genuinely has fewer that fit). Do not pad with tags that do not clearly apply. Return [] only if none fit. Never invent tags or return any string not in the list.';
	$prompt = "Available tags (choose only from these):\n- " . implode( "\n- ", $names ) . "\n\nPost title: " . $title . "\n\nPost content:\n" . $body;

	$raw = snt_ai_generate_with_constraints( $prompt, $system, SN_AI_TAG_MAX_TOKENS, 'tag_suggest' );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$wanted = sn_ai_tag_parse_names( (string) $raw );
	$rows   = snt_ai_tag_match_to_vocab( $wanted, $terms );
	// Exclude tags the post already has.
	$rows = array_values(
		array_filter(
			$rows,
			function ( $r ) use ( $post_id ) {
				return ! ( function_exists( 'has_term' ) && has_term( (int) $r['term_id'], 'post_tag', $post_id ) );
			}
		)
	);

	return array( 'ok' => true, 'post_id' => $post_id, 'suggested' => $rows );
}

/**
 * Extract a JSON string array from an AI response, tolerating code fences / prose.
 *
 * @param string $raw AI response.
 * @return array List of strings (possibly empty); never throws.
 */
function sn_ai_tag_parse_names( $raw ) {
	$raw     = trim( (string) $raw );
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) && preg_match( '/\[.*\]/s', $raw, $mm ) ) {
		$decoded = json_decode( $mm[0], true );
	}
	if ( ! is_array( $decoded ) ) {
		return array();
	}
	$out = array();
	foreach ( $decoded as $v ) {
		if ( is_string( $v ) && '' !== trim( $v ) ) {
			$out[] = trim( $v );
		}
	}
	return $out;
}

/**
 * Constrain returned names to the existing vocabulary via normalized-key match
 * (case/format-insensitive). Drops non-terms (hallucinations); dedupes.
 *
 * @param array $names Returned tag names.
 * @param array $terms post_tag term objects.
 * @return array [{term_id,name,slug}].
 */
function snt_ai_tag_match_to_vocab( array $names, array $terms ) {
	$by_key = array();
	foreach ( $terms as $t ) {
		$by_key[ sn_tag_normalize_key( $t->name ) ] = array( 'term_id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug );
	}
	$out  = array();
	$seen = array();
	foreach ( $names as $n ) {
		$k = sn_tag_normalize_key( $n );
		if ( isset( $by_key[ $k ] ) && ! isset( $seen[ $k ] ) ) {
			$out[]      = $by_key[ $k ];
			$seen[ $k ] = true;
		}
	}
	return $out;
}
