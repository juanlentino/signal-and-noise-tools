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
 * The English fallback: an empty Spanish page renders its English page's body.
 * Only the page's own post-content block, never one inside a query loop.
 *
 * @param string        $html     Rendered core/post-content.
 * @param array         $block    Parsed block.
 * @param WP_Block|null $instance Block instance (context carries postId).
 * @return string
 */
function sn_lang_content_fallback( $html, $block, $instance = null ) {
	$id = (int) get_queried_object_id();
	if ( ! is_singular( 'page' ) || ( $instance && isset( $instance->context['postId'] ) && (int) $instance->context['postId'] !== $id ) ) {
		return $html;
	}
	$en = sn_lang_en_counterpart( $id );
	// Only a public English page lends its body: never a draft, private or
	// password-protected one, even if someone pairs to it.
	if ( ! $en || '' !== trim( (string) get_post_field( 'post_content', $id ) ) || 'publish' !== get_post_status( $en ) || post_password_required( $en ) ) {
		return $html;
	}
	$body = (string) apply_filters( 'the_content', str_replace( ']]>', ']]&gt;', (string) get_post_field( 'post_content', $en ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
	return '' === trim( $body ) ? $html : '<div lang="en">' . $body . '</div>';
}
add_filter( 'render_block_core/post-content', 'sn_lang_content_fallback', 9, 3 );
