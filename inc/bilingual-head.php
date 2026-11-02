<?php
/**
 * Signal & Noise Tools: the bilingual head (hreflang, <html lang>, og:locale,
 * schema inLanguage) and the slug question the page gates ask.
 *
 * Loaded by inc/bilingual.php. See that file's header for the model.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Spanish pages are written in Argentine Spanish (voseo; owner, 2026-10-15),
 * so og:locale, <html lang> and inLanguage say es-AR. hreflang stays the
 * broad `es` so search offers them to every Spanish reader.
 */
const SN_LANG_ES_OG_LOCALE = 'es_AR';
const SN_LANG_ES_TAG       = 'es-AR';

/**
 * The slug a page gate should see: the English counterpart's slug on a Spanish
 * page, so /musica/ answers "music" to every check that keys on the slug (the
 * music schema, the services schema, the ProfilePage list, the template).
 *
 * @param WP_Post|object $post A Page.
 * @return string
 */
function sn_lang_effective_slug( $post ) {
	$id = (int) ( $post->ID ?? 0 );
	$en = function_exists( 'sn_lang_en_counterpart' ) ? sn_lang_en_counterpart( $id ) : 0;
	return $en ? (string) get_post_field( 'post_name', $en ) : (string) ( $post->post_name ?? '' );
}

/**
 * is_page( $slug ), also true on that page's Spanish counterpart.
 *
 * @param string $slug English page slug.
 * @return bool
 */
function sn_lang_is_page( $slug ) {
	if ( is_page( $slug ) ) {
		return true;
	}
	$post = is_singular( 'page' ) ? get_queried_object() : null;
	return $post && sn_lang_effective_slug( $post ) === $slug;
}

/**
 * og:locale for the viewed page.
 *
 * @return string
 */
function sn_lang_og_locale() {
	return 'es' === sn_lang_current() ? SN_LANG_ES_OG_LOCALE : (string) sn_setting( 'identity.locale', 'en_US' );
}

/**
 * schema.org inLanguage (BCP 47) for the viewed page.
 *
 * @return string
 */
function sn_lang_schema_language() {
	return 'es' === sn_lang_current() ? SN_LANG_ES_TAG : str_replace( '_', '-', (string) sn_setting( 'identity.locale', 'en_US' ) );
}

/**
 * The three alternate links for a pair: en, es, and x-default (English).
 * Pure, so the shape is testable without a request.
 *
 * @param array{en?:string,es?:string} $pair sn_lang_pair_urls().
 * @return string HTML, or '' when there is no pair.
 */
function sn_lang_hreflang_html( $pair ) {
	if ( empty( $pair['en'] ) || empty( $pair['es'] ) ) {
		return '';
	}
	$out = '';
	foreach ( array( 'en' => $pair['en'], 'es' => $pair['es'], 'x-default' => $pair['en'] ) as $lang => $url ) {
		$out .= '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '">' . "\n";
	}
	return $out;
}

add_action(
	'wp_head',
	function () {
		echo sn_lang_hreflang_html( (array) apply_filters( 'sn_lang_hreflang_pair', sn_lang_pair_urls() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per attribute in the builder
	},
	2
);

/**
 * <html lang="es-AR"> on a Spanish page. language_attributes() is what core's
 * block template canvas prints; this rewrites only its lang value.
 *
 * @param string $output The attribute string, e.g. 'lang="en-US"'.
 * @return string
 */
function sn_lang_language_attributes( $output ) {
	if ( 'es' !== sn_lang_current() ) {
		return $output;
	}
	return preg_match( '/\blang="[^"]*"/', $output )
		? (string) preg_replace( '/\blang="[^"]*"/', 'lang="' . SN_LANG_ES_TAG . '"', $output )
		: trim( $output . ' lang="' . SN_LANG_ES_TAG . '"' );
}
add_filter( 'language_attributes', 'sn_lang_language_attributes' );
