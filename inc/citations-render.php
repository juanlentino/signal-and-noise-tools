<?php
/**
 * Signal & Noise — the verified citation graph: the public surface.
 *
 * Places a "Cited by" aside after a single note's content (outside the
 * post-content element the public ledger checks), listing ONLY the tiers the site
 * can actually vouch for (`verified` and `unattributed`). An `asserted` claim —
 * one whose link has since gone — is recorded and visible in the admin and shown
 * to nobody else, because publishing it would be exactly the conflation this
 * module exists to avoid.
 *
 * TWO RULES SHAPE THE MARKUP.
 *
 * 1. SILENT ABSENCE. No citations means no aside — not an empty heading, not
 *    "Cited by (0)". A note nobody has cited should look like a note nobody has
 *    cited. This mirrors inc/ml-related-render.php, where an unbuilt artifact and
 *    an empty one are alike silent.
 *
 * 2. THE TIER GOVERNS HOW MUCH OF THE SOURCE WE REPEAT. A `verified` source
 *    publishes a discoverable identity, so quoting the page's own title is fair —
 *    there is someone to hold to it. An `unattributed` source has nobody
 *    discoverably behind it, and its <title> is attacker-controlled text we would
 *    be reprinting on the author's own note. Those entries show the DOMAIN ONLY.
 *    The safety property falls out of the epistemics rather than being bolted on.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The label shown against a public tier. Only the two publishable tiers have one;
 * anything else returns '' so an unexpected tier cannot render an unlabelled row.
 *
 * @param string $tier
 * @return string
 */
function sn_cit_public_label( $tier ) {
	switch ( $tier ) {
		case 'verified':
			return __( 'verified', 'signal-and-noise-tools' );
		case 'unattributed':
			return __( 'unattributed', 'signal-and-noise-tools' );
		default:
			return '';
	}
}

/**
 * What to show as the link text for one citation row.
 *
 * verified     → the source page's own title, falling back to its domain.
 * unattributed → the domain, ALWAYS. See rule 2 in the file docblock.
 *
 * Pure.
 *
 * @param string $tier
 * @param string $title Remote page title, may be ''.
 * @param string $url
 * @return string '' when nothing safe can be shown.
 */
function sn_cit_public_link_text( $tier, $title, $url ) {
	$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
	$host = preg_replace( '#^www\.#i', '', strtolower( $host ) );
	if ( 'unattributed' === $tier ) {
		return (string) $host;
	}
	if ( 'verified' === $tier ) {
		$title = trim( (string) $title );
		return '' !== $title ? $title : (string) $host;
	}
	return '';
}

/**
 * Build the aside, or '' when there is nothing publishable.
 *
 * @param int $post_id
 * @return string
 */
function sn_cit_public_html( $post_id ) {
	if ( ! function_exists( 'sn_cit_for_post' ) ) {
		return '';
	}
	$rows = sn_cit_for_post( (int) $post_id, true );
	if ( ! is_array( $rows ) || array() === $rows ) {
		return '';
	}

	$items = '';
	foreach ( $rows as $row ) {
		$tier = isset( $row->tier ) ? (string) $row->tier : '';
		// Belt and braces: the query already filters to the public tiers, but a
		// render path must never be the only thing standing between an
		// `asserted` claim and the page.
		if ( ! function_exists( 'sn_cit_tier_is_public' ) || ! sn_cit_tier_is_public( $tier ) ) {
			continue;
		}
		$url = isset( $row->source_url ) ? (string) $row->source_url : '';
		if ( '' === $url ) {
			continue;
		}
		$text  = sn_cit_public_link_text( $tier, isset( $row->source_title ) ? $row->source_title : '', $url );
		$label = sn_cit_public_label( $tier );
		if ( '' === $text || '' === $label ) {
			continue;
		}
		$items .= '<li class="snt-cit-item snt-cit-item--' . esc_attr( $tier ) . '">'
			// ugc + nofollow: these are third-party links this site did not choose.
			. '<a href="' . esc_url( $url ) . '" rel="noopener nofollow ugc">' . esc_html( $text ) . '</a>'
			. ' <span class="snt-cit-tier">' . esc_html( $label ) . '</span>'
			. '</li>';
	}
	if ( '' === $items ) {
		return '';
	}

	return '<aside class="snt-cit" aria-labelledby="snt-cit-title">'
		. '<h2 class="snt-cit-title" id="snt-cit-title">'
		. esc_html__( 'Cited by', 'signal-and-noise-tools' )
		. '</h2>'
		. '<p class="snt-cit-note">'
		. esc_html__( 'Each of these was re-fetched and still links here. "Verified" means the source also publishes a discoverable identity; "unattributed" means it does not, so only its domain is shown.', 'signal-and-noise-tools' )
		. '</p>'
		. '<ul class="snt-cit-list">' . $items . '</ul>'
		. '</aside>';
}

/**
 * Append the aside to main-query singular note content.
 *
 * Guards mirror inc/ml-related-render.php, including the get_the_excerpt one:
 * core's wp_trim_excerpt() runs the_content, so without it an auto-excerpt
 * generated inside the singular loop would leak the aside's text into the
 * excerpt.
 *
 * @param string $content
 * @return string
 */
function sn_cit_render_append( $content ) {
	// On a block theme the aside is placed AFTER the post-content element
	// (sn_cit_render_after_content): that element is the region the public
	// ledger's checker reads against the signed text, and nothing of ours
	// may sit inside it.
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		return $content;
	}
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( function_exists( 'doing_filter' ) && doing_filter( 'get_the_excerpt' ) ) {
		return $content;
	}
	$post_id = (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return $content;
	}
	$html = sn_cit_public_html( $post_id );
	return '' === $html ? $content : $content . $html;
}

/**
 * Place the aside after the note's post-content element, never inside it.
 *
 * The public ledger's checker reads the served page from the post-content
 * element's opening tag to its end (or the first provenance, share or footer
 * element) and compares that text with what was signed. An aside appended
 * through `the_content` sits inside that element, so the first public citation
 * a note received would have failed its public check, and anyone can cause a
 * citation by linking to a note and sending a webmention. Here the aside is a
 * sibling that follows the element's closing tag.
 *
 * @param string $html     The rendered core/post-content block.
 * @param array  $block    The parsed block (unused).
 * @param object $instance The WP_Block, for the post it renders.
 * @return string
 */
function sn_cit_render_after_content( $html, $block = array(), $instance = null ) {
	if ( ! is_singular( 'post' ) ) {
		return $html;
	}
	// Only the note the page is for: a query loop on the same page renders
	// other posts through this block too.
	$post_id = is_object( $instance ) && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
	if ( $post_id <= 0 || (int) get_queried_object_id() !== $post_id ) {
		return $html;
	}
	return $html . sn_cit_public_html( $post_id );
}

if ( ! defined( 'SN_CIT_TEST' ) || ! SN_CIT_TEST ) {
	add_filter( 'render_block_core/post-content', 'sn_cit_render_after_content', 21, 3 );
	// Priority 21: after the provenance panel and the related-notes aside (both
	// at 20), so inbound citations read last — the note, then what it says, then
	// who says they took it.
	add_filter( 'the_content', 'sn_cit_render_append', 21 );
}
