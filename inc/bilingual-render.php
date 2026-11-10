<?php
/**
 * Signal & Noise Tools: how a Spanish page renders. It takes its English
 * counterpart's template, and while its own body is empty it shows the English
 * body (marked lang="en" so a screen reader switches voice).
 *
 * Loaded by inc/bilingual.php. See that file's header for the model.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Put the English page's template ahead of the generic page template, so
 * /musica/ renders through page-music.html and /inicio/ through front-page.html.
 * The Spanish slug's own template (page-musica.html) still wins if one exists.
 *
 * @param string[] $templates Candidate template file names, most specific first.
 * @return string[]
 */
function sn_lang_page_template_hierarchy( $templates ) {
	$id = (int) get_queried_object_id();
	$en = sn_lang_en_counterpart( $id );
	if ( ! $en ) {
		return $templates;
	}
	$extra = (int) get_option( 'page_on_front' ) === $en
		? 'front-page.php'
		: 'page-' . get_post_field( 'post_name', $en ) . '.php';
	return sn_lang_insert_template( (array) $templates, $extra );
}
add_filter( 'page_template_hierarchy', 'sn_lang_page_template_hierarchy' );

/**
 * Insert $extra right after the first candidate (the page's own slug), once.
 * Pure, for the test.
 *
 * @param string[] $templates Candidates.
 * @param string   $extra     The English page's template.
 * @return string[]
 */
function sn_lang_insert_template( $templates, $extra ) {
	if ( in_array( $extra, $templates, true ) ) {
		return $templates;
	}
	return array_merge( array_slice( $templates, 0, 1 ), array( $extra ), array_slice( $templates, 1 ) );
}

/**
 * The English page whose body the queried Spanish page borrows, or 0.
 *
 * Only an empty Spanish page borrows, and only a public English page lends:
 * never a draft, private or password-protected one, even if someone pairs to it.
 *
 * @return int English page id, or 0 when the queried page renders as itself.
 */
function sn_lang_fallback_source() {
	$id = (int) get_queried_object_id();
	if ( ! is_singular( 'page' ) ) {
		return 0;
	}
	$en = (int) sn_lang_en_counterpart( $id );
	if ( ! $en || '' !== trim( (string) get_post_field( 'post_content', $id ) ) || 'publish' !== get_post_status( $en ) || post_password_required( $en ) ) {
		return 0;
	}
	return $en;
}

/**
 * Whether the block being rendered took the English body. Reading it resets it.
 *
 * @param bool|null $set True when the English body went in; null to read.
 * @return bool The value before this call.
 */
function sn_lang_fell_back( $set = null ) {
	static $fell_back = false;
	$was       = $fell_back;
	$fell_back = (bool) $set;
	return $was;
}

/**
 * The English fallback, step one: just before core renders a post-content
 * block on an empty Spanish page, arm a one-shot on the_content. Core's own
 * render callback then wraps the English body the way the template asks
 * (tag name, layout, alignment, block supports), so no wrapper is written here.
 *
 * @param string|null $pre   A short-circuit value from an earlier filter.
 * @param array       $block Parsed block.
 * @return string|null $pre, untouched.
 */
function sn_lang_content_fallback_arm( $pre, $block ) {
	if ( null === $pre && 'core/post-content' === ( $block['blockName'] ?? '' ) && sn_lang_fallback_source() ) {
		add_filter( 'the_content', 'sn_lang_content_fallback_body', 1 );
	}
	return $pre;
}
add_filter( 'pre_render_block', 'sn_lang_content_fallback_arm', 10, 2 );

/**
 * Step two, once: hand core the English page's body in place of the empty
 * Spanish one. It removes itself first, so it never reaches an excerpt, a
 * nested render or another post in a query loop.
 *
 * @param string $content The content core is about to filter.
 * @return string
 */
function sn_lang_content_fallback_body( $content ) {
	remove_filter( 'the_content', 'sn_lang_content_fallback_body', 1 );
	$en = sn_lang_fallback_source();
	if ( ! $en || '' !== trim( (string) $content ) || (int) get_the_ID() !== (int) get_queried_object_id() ) {
		return $content;
	}
	sn_lang_fell_back( true );
	return str_replace( ']]>', ']]&gt;', (string) get_post_field( 'post_content', $en ) );
}

/**
 * Step three: mark the wrapper core rendered lang="en", so a screen reader
 * switches voice for the borrowed body.
 *
 * @param string $html Rendered core/post-content.
 * @return string
 */
function sn_lang_content_fallback( $html ) {
	remove_filter( 'the_content', 'sn_lang_content_fallback_body', 1 ); // Still armed when core never reached the_content.
	if ( ! sn_lang_fell_back() ) {
		return $html;
	}
	$wrapper = new WP_HTML_Tag_Processor( (string) $html );
	if ( $wrapper->next_tag() ) {
		$wrapper->set_attribute( 'lang', 'en' );
	}
	return $wrapper->get_updated_html();
}
add_filter( 'render_block_core/post-content', 'sn_lang_content_fallback', 9 );
