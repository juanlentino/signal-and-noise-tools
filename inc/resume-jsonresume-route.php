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
 * /resume it is the one résumé the page shows (inc/resume-page-lanes.php):
 * the operations view, "What I've Built" as projects, and /music's verified
 * credits among the profiles; any `?lane=` is ignored. With no operations
 * settings, and on /cv, it is that page's own document.
 *
 * @param string $slug 'resume' or 'cv'.
 * @return array|null
 */
function sn_jsonresume_for( $slug ) {
	$page = sn_jsonresume_page( $slug );
	if ( ! $page ) {
		return null;
	}
	$raw   = 'cv' === $slug
		? ( function_exists( 'sn_resume_es_doc' ) ? sn_resume_es_doc() : null )
		: ( function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null );
	$cfg   = null !== $raw && function_exists( 'sn_resume_lane_doc' ) ? sn_resume_variant_config( $raw, 'operations' ) : null;
	$doc   = null !== $cfg ? sn_resume_lane_doc( $raw, 'operations' ) : ( 'cv' === $slug ? $raw : sn_resume_doc_get() );
	$extra = null !== $cfg ? (array) $cfg['links'] : array();
	$built = sn_jsonresume_build(
		$doc,
		array(
			'name'          => function_exists( 'sn_setting' ) ? (string) sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ) : (string) get_bloginfo( 'name' ),
			'url'           => '' !== (string) ( $doc['pdf']['website'] ?? '' ) ? (string) $doc['pdf']['website'] : home_url( '/' ),
			'canonical'     => sn_resume_format_canonical( $slug ),
			'last_modified' => (string) get_post_modified_time( 'c', false, $page ),
			'version'       => defined( 'SNT_VERSION' ) ? SNT_VERSION : '',
			'profiles'      => array_merge( function_exists( 'sn_schema_person' ) ? (array) ( sn_schema_person()['sameAs'] ?? array() ) : array(), $extra ),
			'projects'      => (array) ( $doc['built']['items'] ?? array() ),
			'provenance'    => sn_resume_proof( $page ),
		)
	);
	$es      = 'cv' === $slug && function_exists( 'sn_resume_lang' );
	if ( $es ) {
		sn_resume_lang( 'es' );
	}
	$credits = null !== $cfg && function_exists( 'sn_resume_credits_url' ) ? sn_resume_credits_url() : '';
	if ( $es ) {
		sn_resume_lang( 'en' );
	}
	if ( '' !== $credits ) { // basics.profiles: the schema's place for a link with a name; "network" names it.
		$built['basics']['profiles'][] = array( 'network' => $es ? 'Créditos verificados' : 'Verified credits', 'username' => $es ? SN_RESUME_CREDITS_SLUG_ES : SN_RESUME_CREDITS_SLUG, 'url' => $credits );
	}
	return $built;
}

/** template_redirect: serve it, or let the 404 stand. */
function sn_jsonresume_maybe_serve() {
	$req  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$slug = sn_jsonresume_route_slug( $req );
	if ( '' === $slug ) {
		return;
	}
	$resume = sn_jsonresume_for( $slug );
	if ( null === $resume ) {
		return;
	}
	sn_resume_format_send( 'application/json; charset=utf-8', $slug );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- application/json from wp_json_encode.
	echo wp_json_encode( $resume, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}

/** wp_head: advertise it on the résumé pages. */
function sn_jsonresume_head_link() {
	foreach ( array( 'resume', 'cv' ) as $slug ) {
		if ( is_page( $slug ) && sn_jsonresume_page( $slug ) ) {
			printf( '<link rel="alternate" type="application/json" href="%s" title="JSON Resume">' . "\n", esc_url( home_url( '/' . $slug . '/' . SN_JSONRESUME_FILE ) ) );
		}
	}
}

if ( ! defined( 'SN_RESUME_JSONRESUME_TEST' ) || ! SN_RESUME_JSONRESUME_TEST ) {
	add_action( 'template_redirect', 'sn_jsonresume_maybe_serve', 0 );
	add_action( 'wp_head', 'sn_jsonresume_head_link' );
}
