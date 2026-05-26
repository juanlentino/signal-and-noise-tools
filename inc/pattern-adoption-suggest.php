<?php
/**
 * Signal & Noise Tools — Pattern-adoption suggest impl.
 *
 * Given (post_id, block_fingerprint, pattern_type), locates the
 * candidate block in the post's parse_blocks() tree and synthesizes
 * replacement markup for the target v9.2.0 pattern via deterministic
 * template substitution.
 *
 * NO AI involved. The "suggest" in Suggest+Apply is the user
 * reviewing a proposed structural change in the before/after modal —
 * not an AI generating creative content.
 *
 * The two supported pattern types:
 *   - pull-quote        ← extracts <p> text + optional <cite> from core/quote
 *   - steps-enumerated  ← preserves <li> items from ordered core/list
 *
 * Surface convention follows ai-alt-text-suggest.php: pure impl +
 * REST endpoint. The Abilities API registration lives in
 * inc/abilities-ai-pattern-adoption.php.
 *
 * @package SignalNoiseTools
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PATTERN_ADOPTION_VALID_TYPES = array( 'pull-quote', 'steps-enumerated' );

/**
 * Pure impl: generate the replacement markup for a pattern-adoption
 * candidate.
 *
 * @param int    $post_id
 * @param string $block_fingerprint  md5 from the scan.
 * @param string $pattern_type       'pull-quote' or 'steps-enumerated'.
 * @return array{ok:bool,suggestion_markup:string,fingerprint:string,post_id:int,pattern_type:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_pattern_adoption_invalid_pattern_type   (422)
 *   snt_pattern_adoption_post_not_found         (404)
 *   snt_pattern_adoption_candidate_not_found    (404)
 *
 * @since 4.3.0
 */
function snt_ai_pattern_adoption_suggest_impl( $post_id, $block_fingerprint, $pattern_type ) {
	$post_id           = (int) $post_id;
	$block_fingerprint = (string) $block_fingerprint;
	$pattern_type      = (string) $pattern_type;

	if ( ! in_array( $pattern_type, SNT_PATTERN_ADOPTION_VALID_TYPES, true ) ) {
		return new WP_Error(
			'snt_pattern_adoption_invalid_pattern_type',
			__( 'pattern_type must be one of: pull-quote, steps-enumerated.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'snt_pattern_adoption_post_not_found',
			__( 'Post not found.', 'signal-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$blocks = parse_blocks( (string) $post->post_content );
	$match  = snt_pattern_adoption_find_block( $blocks, $block_fingerprint );

	if ( null === $match ) {
		return new WP_Error(
			'snt_pattern_adoption_candidate_not_found',
			__( 'Candidate block not found in current post content. Re-run scan.', 'signal-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	if ( 'pull-quote' === $pattern_type ) {
		$markup = snt_pattern_adoption_build_pull_quote_markup( $match );
	} else {
		$markup = snt_pattern_adoption_build_steps_enumerated_markup( $match );
	}

	return array(
		'ok'                 => true,
		'suggestion_markup'  => $markup,
		'fingerprint'        => $block_fingerprint,
		'post_id'            => $post_id,
		'pattern_type'       => $pattern_type,
	);
}

/**
 * Recursive search for a block matching $fingerprint.
 *
 * @param array  $tree
 * @param string $fingerprint
 * @return array|null  The matching block, or null.
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_find_block( $tree, $fingerprint ) {
	foreach ( $tree as $block ) {
		if ( md5( serialize_block( $block ) ) === $fingerprint ) {
			return $block;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = snt_pattern_adoption_find_block( $block['innerBlocks'], $fingerprint );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Build the pull-quote pattern's block markup from a source core/quote.
 *
 * Mirrors signal-and-noise/patterns/pull-quote.php (theme v9.2.0+).
 * If theme redesigns the pattern, update this template in lockstep.
 *
 * Source structure:
 *   core/quote
 *     ├── core/paragraph[] (innerHTML = "<p>text</p>")
 *     └── (optional) <cite> in the wrapper's innerHTML
 *
 * Target: a wp:group (sn-pattern-pull-quote, tagName=aside) containing
 * a wp:paragraph (sn-pull-quote__body) and an optional wp:paragraph
 * (sn-pull-quote__attribution). NOT wp:signal-noise/pull-quote — that
 * was a pattern slug, not a block type.
 *
 * Inline formatting (<strong>, <em>, <a>, <code>) is preserved via
 * wp_kses allowlist.
 *
 * @param array $quote_block
 * @return string
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_build_pull_quote_markup( $quote_block ) {
	$allowed_inline = array(
		'strong' => array(),
		'em'     => array(),
		'a'      => array( 'href' => true ),
		'code'   => array(),
	);

	// Extract body text from core/quote's core/paragraph innerBlocks,
	// preserving inline formatting.
	$body_html = '';
	foreach ( ( $quote_block['innerBlocks'] ?? array() ) as $inner ) {
		if ( 'core/paragraph' === ( $inner['blockName'] ?? '' ) ) {
			$inner_html = (string) ( $inner['innerHTML'] ?? '' );
			// Strip <p> wrapper, preserve inline content.
			$inner_html = preg_replace( '~^\s*<p[^>]*>~i', '', (string) $inner_html );
			$inner_html = preg_replace( '~</p>\s*$~i', '', (string) $inner_html );
			$body_html .= ' ' . wp_kses( (string) $inner_html, $allowed_inline );
		}
	}
	$body_html = trim( $body_html );

	// Extract optional <cite> from the wrapper innerHTML. First-cite wins;
	// nested or multiple cites collapse to the first match's text.
	$cite_html = '';
	if ( preg_match( '~<cite[^>]*>(.*?)</cite>~is', (string) ( $quote_block['innerHTML'] ?? '' ), $m ) ) {
		$cite_html = trim( wp_kses( $m[1], $allowed_inline ) );
	}

	// Build target markup. Attribution paragraph OMITTED if no <cite>.
	$markup  = "<!-- wp:group {\"className\":\"sn-pattern-pull-quote\",\"tagName\":\"aside\",\"layout\":{\"type\":\"constrained\"}} -->\n";
	$markup .= "<aside class=\"wp-block-group sn-pattern-pull-quote\">\n";
	$markup .= "<!-- wp:paragraph {\"className\":\"sn-pull-quote__body\"} -->\n";
	$markup .= "<p class=\"sn-pull-quote__body\">{$body_html}</p>\n";
	$markup .= "<!-- /wp:paragraph -->\n";
	if ( '' !== $cite_html ) {
		$markup .= "<!-- wp:paragraph {\"className\":\"sn-pull-quote__attribution\"} -->\n";
		$markup .= "<p class=\"sn-pull-quote__attribution\">{$cite_html}</p>\n";
		$markup .= "<!-- /wp:paragraph -->\n";
	}
	$markup .= "</aside>\n";
	$markup .= "<!-- /wp:group -->";

	return $markup;
}

/**
 * Build the steps-enumerated pattern's block markup from a source
 * ordered core/list.
 *
 * Mirrors signal-and-noise/patterns/steps-enumerated.php (theme v9.2.0+).
 * The pattern's label paragraph (<p class="sn-steps__label">) is
 * intentionally OMITTED — source core/list has no concept of a label,
 * and inventing one would inject editorial content. User adds a label
 * manually after apply if desired.
 *
 * Target: wp:group (sn-pattern-steps-enumerated) containing wp:list
 * (ordered, sn-steps__list) with wp:list-item children. NOT
 * wp:signal-noise/steps-enumerated — that was a pattern slug.
 *
 * Inline formatting (<strong>, <em>, <a>, <code>) in list items is
 * preserved via wp_kses allowlist.
 *
 * @param array $list_block
 * @return string
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_build_steps_enumerated_markup( $list_block ) {
	$allowed_inline = array(
		'strong' => array(),
		'em'     => array(),
		'a'      => array( 'href' => true ),
		'code'   => array(),
	);

	$items_html = '';
	foreach ( ( $list_block['innerBlocks'] ?? array() ) as $inner ) {
		if ( 'core/list-item' === ( $inner['blockName'] ?? '' ) ) {
			$inner_html = (string) ( $inner['innerHTML'] ?? '' );
			// Strip <li> wrapper, preserve inline content.
			$inner_html = preg_replace( '~^\s*<li[^>]*>~i', '', (string) $inner_html );
			$inner_html = preg_replace( '~</li>\s*$~i', '', (string) $inner_html );
			$cleaned    = trim( wp_kses( (string) $inner_html, $allowed_inline ) );

			$items_html .= "<!-- wp:list-item -->\n";
			$items_html .= "<li>{$cleaned}</li>\n";
			$items_html .= "<!-- /wp:list-item -->\n";
		}
	}

	$markup  = "<!-- wp:group {\"className\":\"sn-pattern-steps-enumerated\",\"layout\":{\"type\":\"constrained\"}} -->\n";
	$markup .= "<div class=\"wp-block-group sn-pattern-steps-enumerated\">\n";
	$markup .= "<!-- wp:list {\"ordered\":true,\"className\":\"sn-steps__list\"} -->\n";
	$markup .= "<ol class=\"wp-block-list sn-steps__list\">\n";
	$markup .= $items_html;
	$markup .= "</ol>\n";
	$markup .= "<!-- /wp:list -->\n";
	$markup .= "</div>\n";
	$markup .= "<!-- /wp:group -->";

	return $markup;
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — suggest dispatch (back-compat surface for CI/wp-cli).
 * JS clients use the Abilities API surface via wp.apiFetch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/pattern-adoption-suggest', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$result = snt_ai_pattern_adoption_suggest_impl(
				(int)    $request->get_param( 'post_id' ),
				(string) $request->get_param( 'block_fingerprint' ),
				(string) $request->get_param( 'pattern_type' )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( $result );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id'           => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'block_fingerprint' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'pattern_type'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		),
	) );
} );
