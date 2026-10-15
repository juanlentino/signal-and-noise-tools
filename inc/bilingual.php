<?php
/**
 * Signal & Noise Tools: the bilingual storefront (English / Spanish pairs).
 *
 * A Spanish page is an ordinary Page with its own Spanish slug (/musica/,
 * /servicios/, /sobre-mi/, /inicio/) and ONE meta row, `_sn_en_counterpart`,
 * naming the English Page it translates. That row is the whole model: it marks
 * the page Spanish and pairs it. Everything else (hreflang, <html lang>,
 * og:locale, the EN/ES toggle, the template, the English fallback) is read off
 * it by the helpers here. No rewrite rules, no cookies, no plugin.
 *
 * The parts files: bilingual-head.php (hreflang, lang, locale), bilingual-
 * render.php (template + English fallback), bilingual-seed.php (the four
 * draft pages, once). docs/BILINGUAL.md is the owner-facing write-up.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LANG_PAIR_META = '_sn_en_counterpart';

add_action( 'init', 'sn_lang_register_meta' );

/**
 * The pairing row, Pages only, editable in the block editor sidebar.
 */
function sn_lang_register_meta() {
	register_post_meta(
		'page',
		SN_LANG_PAIR_META,
		array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
				return current_user_can( 'edit_post', $object_id );
			},
		)
	);
}

/**
 * The English Page a Spanish Page translates, or 0 when $page_id is not a
 * Spanish page (no row, or the row names something that is not a page).
 *
 * @param int $page_id A Page id.
 * @return int English Page id, or 0.
 */
function sn_lang_en_counterpart( $page_id ) {
	$page_id = (int) $page_id;
	if ( $page_id < 1 ) {
		return 0;
	}
	$en = (int) get_post_meta( $page_id, SN_LANG_PAIR_META, true );
	if ( $en < 1 || $en === $page_id || 'page' !== get_post_type( $en ) ) {
		return 0;
	}
	return $en;
}

/**
 * The PUBLISHED Spanish Page that translates an English Page, or 0. A draft
 * translation is not a counterpart: the toggle and hreflang must never point
 * a reader at a page they cannot open.
 *
 * @param int $en_id English Page id.
 * @return int Spanish Page id, or 0.
 */
function sn_lang_es_counterpart( $en_id ) {
	static $memo = array();
	$en_id = (int) $en_id;
	if ( $en_id < 1 ) {
		return 0;
	}
	if ( ! array_key_exists( $en_id, $memo ) ) {
		$ids            = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => SN_LANG_PAIR_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- four rows site-wide, once per request
				'meta_value'       => $en_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$memo[ $en_id ] = $ids ? (int) $ids[0] : 0;
	}
	return $memo[ $en_id ];
}

/**
 * The language of the page being viewed: 'es' on a Spanish page, else 'en'.
 *
 * @return string
 */
function sn_lang_current() {
	if ( ! is_singular( 'page' ) ) {
		return 'en';
	}
	return sn_lang_en_counterpart( get_queried_object_id() ) ? 'es' : 'en';
}

/**
 * The public URL of a Page, with the front page as the bare home URL.
 *
 * @param int $page_id Page id.
 * @return string
 */
function sn_lang_page_url( $page_id ) {
	return (int) get_option( 'page_on_front' ) === (int) $page_id
		? home_url( '/' )
		: (string) get_permalink( $page_id );
}

/**
 * Both URLs of the pair the viewed page belongs to, or [] when it belongs to
 * none (unpaired, or the translation is still a draft). Both sides are
 * published by the time this returns a pair.
 *
 * @return array{en?:string,es?:string}
 */
function sn_lang_pair_urls() {
	if ( ! is_singular( 'page' ) ) {
		return array();
	}
	$id = (int) get_queried_object_id();
	$en = sn_lang_en_counterpart( $id );
	if ( $en ) {
		$es = $id;
		if ( 'publish' !== get_post_status( $en ) || 'publish' !== get_post_status( $es ) ) {
			return array();
		}
	} else {
		$en = $id;
		$es = sn_lang_es_counterpart( $id );
		if ( ! $es ) {
			return array();
		}
	}
	return array(
		'en' => sn_lang_page_url( $en ),
		'es' => sn_lang_page_url( $es ),
	);
}

require_once __DIR__ . '/bilingual-head.php';
require_once __DIR__ . '/bilingual-render.php';
require_once __DIR__ . '/bilingual-seed.php';
