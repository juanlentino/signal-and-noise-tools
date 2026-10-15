<?php
/**
 * Signal & Noise Tools: seed the four Spanish storefront pages, once, as
 * DRAFTS with empty bodies. A draft shows no toggle and no hreflang; the owner
 * writes the Spanish copy and publishes each page when it is ready. Until its
 * body is written, a published Spanish page shows the English body.
 *
 * A slug already taken (by any page, or by an entry in the redirect map) is
 * skipped, never overwritten; the skips are kept in the flag option.
 *
 * Loaded by inc/bilingual.php. See that file's header for the model.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LANG_SEED_OPT = 'sn_spanish_pages_seeded_v1';

/**
 * Spanish slug => [ Spanish title, English page path ('' = the front page) ].
 *
 * @return array<string,array{0:string,1:string}>
 */
function sn_lang_seed_map() {
	return array(
		'inicio'    => array( 'Inicio', '' ),
		'sobre-mi'  => array( 'Sobre mí', 'about' ),
		'musica'    => array( 'Música', 'music' ),
		'servicios' => array( 'Servicios', 'services' ),
	);
}

add_action( 'admin_init', 'sn_lang_seed_pages' );

/**
 * Create the missing Spanish drafts. Idempotent behind SN_LANG_SEED_OPT.
 */
function sn_lang_seed_pages() {
	if ( get_option( SN_LANG_SEED_OPT ) || ! current_user_can( 'publish_pages' ) ) {
		return;
	}
	// A lock first: two admin requests at once (heartbeat beside a page load)
	// must not both insert. add_option() is false when the row already exists.
	if ( ! add_option( SN_LANG_SEED_OPT, array( 'running' => time() ), '', false ) ) {
		return;
	}
	$result = array(
		'created' => array(),
		'skipped' => array(),
	);
	foreach ( sn_lang_seed_map() as $slug => $spec ) {
		$en = '' === $spec[1] ? (int) get_option( 'page_on_front' ) : (int) ( get_page_by_path( $spec[1] )->ID ?? 0 );
		if ( $en < 1 ) {
			$result['skipped'][ $slug ] = 'no English page';
			continue;
		}
		$taken = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );
		if ( $taken ) {
			$result['skipped'][ $slug ] = (int) get_post_meta( $taken->ID, SN_LANG_PAIR_META, true ) === $en ? 'already paired' : 'slug taken';
			continue;
		}
		if ( function_exists( 'sn_redirect_target' ) && sn_redirect_target( '/' . $slug ) ) {
			$result['skipped'][ $slug ] = 'redirect exists';
			continue;
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $spec[0],
					'post_name'    => $slug,
					'post_content' => '',
					'meta_input'   => array( SN_LANG_PAIR_META => $en ),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$result['skipped'][ $slug ] = $id->get_error_message();
			continue;
		}
		$result['created'][ $slug ] = (int) $id;
	}
	update_option( SN_LANG_SEED_OPT, $result + array( 'at' => time() ), false );
}
