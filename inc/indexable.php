<?php
/**
 * Signal & Noise Tools: is this singular indexable? The ONE predicate.
 *
 * Four readers asked the same question four ways and drifted: the sitemap
 * (a meta_query), the weekly URL Inspection targets (no filter at all), the
 * index_gap watch (noindex only) and IndexNow (nothing). /verify showed the
 * cost: the sitemap listed it while the page served noindex, because the
 * noindex came from a virtual route (inc/provenance-verify.php shadows the
 * seeded Verify page at template_redirect priority 0), not from `_sn_noindex`.
 *
 * Now each of them asks sn_post_is_indexable(). A singular is indexable when
 * it is a published post or page, has no password, is not `_sn_noindex`, has
 * no canonical pointing elsewhere (`_sn_canonical_url`), and its path is not
 * one the plugin itself serves as noindex.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/path-join-key.php';

/** The path the provenance verifier serves (inc/provenance-verify.php reads it too). */
const SN_PROV_VERIFY_PATH = '/verify';

/**
 * Join-key paths the plugin serves itself as noindex, whatever Page sits
 * underneath. A virtual route that prints its own robots meta belongs here.
 *
 * @return string[]
 */
function sn_noindex_route_paths() {
	return array( SN_PROV_VERIFY_PATH );
}

/**
 * The decision, over plain facts. PURE.
 *
 * @param array $f {type, status, password, noindex, canonical, path}.
 * @return bool
 */
function sn_indexable_decision( array $f ) {
	return in_array( (string) ( $f['type'] ?? '' ), array( 'post', 'page' ), true )
		&& 'publish' === (string) ( $f['status'] ?? '' )
		&& '' === (string) ( $f['password'] ?? '' )
		&& '1' !== (string) ( $f['noindex'] ?? '' )
		&& '' === trim( (string) ( $f['canonical'] ?? '' ) )
		&& ! in_array( sn_path_join_key( (string) ( $f['path'] ?? '' ) ), sn_noindex_route_paths(), true );
}

/**
 * Is this post or page indexable (in the sitemap, worth inspecting and pinging)?
 *
 * @param int|WP_Post $post Post object or id.
 * @return bool
 */
function sn_post_is_indexable( $post ) {
	$post = is_object( $post ) ? $post : ( function_exists( 'get_post' ) ? get_post( (int) $post ) : null );
	if ( ! is_object( $post ) || empty( $post->ID ) ) {
		return false;
	}
	$id = (int) $post->ID;
	return sn_indexable_decision( array(
		'type'      => (string) ( $post->post_type ?? '' ),
		'status'    => (string) ( $post->post_status ?? '' ),
		'password'  => (string) ( $post->post_password ?? '' ),
		'noindex'   => (string) get_post_meta( $id, '_sn_noindex', true ),
		'canonical' => (string) get_post_meta( $id, '_sn_canonical_url', true ),
		'path'      => function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : '',
	) );
}
