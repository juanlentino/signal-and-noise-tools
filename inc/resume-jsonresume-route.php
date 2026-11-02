<?php
/**
 * Signal & Noise Tools: /resume/json-resume.json and /cv/json-resume.json.
 *
 * The résumé as JSON Resume v1.0.0 (inc/resume-jsonresume.php), on a
 * flush-free virtual route (template_redirect priority 0, the did.json and
 * /notes/index.json mechanism). Its own path, not /resume.json: that is the
 * theme's page twin (url, content_text, provenance), which other readers and
 * the provenance-integrity sweep depend on, and JSON Resume has no registered
 * media type to negotiate on. Nested under the page it represents, ending in
 * .json, so any JSON Resume tool can fetch it as a plain file. Advertised from
 * the page's <head>. Served only while the page itself is published.
 *
 * Headers follow /notes/index.json (public, max-age=300, CORS open), not the
 * page twin, whose no-store comes from WordPress's 404 handling rather than a
 * decision.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-jsonresume.php';
require_once __DIR__ . '/resume-formats-parts.php';

const SN_JSONRESUME_FILE = 'json-resume.json';

/**
 * The résumé slug a request is for ('resume' or 'cv'), else ''. Path only.
 *
 * @param string $request_uri
 * @return string
 */
function sn_jsonresume_route_slug( $request_uri ) {
	$path = (string) parse_url( (string) $request_uri, PHP_URL_PATH );
	return preg_match( '~^/(resume|cv)/' . preg_quote( SN_JSONRESUME_FILE, '~' ) . '$~', $path, $m ) ? $m[1] : '';
}

/**
 * The published, unprotected résumé page for a slug, or null.
 *
 * @param string $slug
 * @return WP_Post|null
 */
function sn_jsonresume_page( $slug ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	return ( $page && 'publish' === $page->post_status && '' === (string) $page->post_password ) ? $page : null;
}

/**
 * The JSON Resume for a slug, built from the live document and page. On
 * /resume, `?lane=operations` is the operations résumé the page's second lane
 * shows (inc/resume-page-lanes.php): its headline, summary, order and
 * sections, with "What I've Built" as projects. Anything else, or no
 * operations résumé, is the music one.
 *
 * @param string $slug 'resume' or 'cv'.
 * @param string $lane 'music' or 'operations' (sn_resume_lane_param()).
 * @return array|null
 */
function sn_jsonresume_for( $slug, $lane = 'music' ) {
	$page = sn_jsonresume_page( $slug );
	if ( ! $page ) {
		return null;
	}
	$raw   = 'resume' === $slug && function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null;
	$lanes = null !== $raw && function_exists( 'sn_resume_lane_doc' ) && null !== sn_resume_variant_config( $raw, 'operations' );
	$lane  = $lanes ? $lane : '';
	$doc   = 'cv' === $slug
		? ( function_exists( 'sn_resume_es_doc' ) ? sn_resume_es_doc() : null )
		: ( 'operations' === $lane ? sn_resume_lane_doc( $raw, 'operations' ) : sn_resume_doc_get() );
	$extra = 'operations' === $lane ? (array) sn_resume_variant_config( $raw, 'operations' )['links'] : array();
	return sn_jsonresume_build(
		$doc,
		array(
			'name'          => function_exists( 'sn_setting' ) ? (string) sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ) : (string) get_bloginfo( 'name' ),
			'url'           => '' !== (string) ( $doc['pdf']['website'] ?? '' ) ? (string) $doc['pdf']['website'] : home_url( '/' ),
			'canonical'     => (string) get_permalink( $page ),
			'last_modified' => (string) get_post_modified_time( 'c', false, $page ),
			'version'       => defined( 'SNT_VERSION' ) ? SNT_VERSION : '',
			'profiles'      => array_merge( function_exists( 'sn_schema_person' ) ? (array) ( sn_schema_person()['sameAs'] ?? array() ) : array(), $extra ),
			'projects'      => (array) ( $doc['built']['items'] ?? array() ),
			'lane'          => $lane,
			'provenance'    => sn_resume_proof( $page ),
		)
	);
}

/** template_redirect: serve it, or let the 404 stand. */
function sn_jsonresume_maybe_serve() {
	$req  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$slug = sn_jsonresume_route_slug( $req );
	if ( '' === $slug ) {
		return;
	}
	$resume = sn_jsonresume_for( $slug, function_exists( 'sn_resume_active_lane' ) ? sn_resume_active_lane() : 'music' );
	if ( null === $resume ) {
		return;
	}
	sn_resume_format_send( 'application/json; charset=utf-8', $slug );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- application/json from wp_json_encode.
	echo wp_json_encode( $resume, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}

/** wp_head: advertise it on the résumé pages, the operations one on /resume?lane=operations. */
function sn_jsonresume_head_link() {
	$lane = function_exists( 'sn_resume_active_lane' ) ? sn_resume_active_lane() : 'music';
	foreach ( array( 'resume', 'cv' ) as $slug ) {
		if ( is_page( $slug ) && sn_jsonresume_page( $slug ) ) {
			printf( '<link rel="alternate" type="application/json" href="%s" title="JSON Resume">' . "\n", esc_url( home_url( '/' . $slug . '/' . SN_JSONRESUME_FILE . sn_resume_lane_query( $slug, $lane ) ) ) );
		}
	}
}

if ( ! defined( 'SN_RESUME_JSONRESUME_TEST' ) || ! SN_RESUME_JSONRESUME_TEST ) {
	add_action( 'template_redirect', 'sn_jsonresume_maybe_serve', 0 );
	add_action( 'wp_head', 'sn_jsonresume_head_link' );
}
