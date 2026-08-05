<?php
/**
 * Tests: every REST-exposed SEO-era post-meta key gates its auth_callback on the
 * PER-RESOURCE capability (edit_post on the object id), not the blanket edit_posts.
 *
 * Why this suite exists: the CMA post-ship audit of v10.48.0 (2026-08-05) filed LOW-1 —
 * the ten SEO-era keys shared a closure that ignored $object_id, while the sibling pillar
 * pair (v9.79.0, same file) already used the real six-arg register_meta signature. The
 * drift was not exploitable (WP_REST_Posts_Controller clears edit_post($id) before any
 * meta is applied, and the classic save path re-checks it), so nothing could reach the
 * blanket callback with an id the caller could not already edit — but a meta key should
 * be self-defending rather than relying on its parent controller, and the three existing
 * post-settings suites all stub current_user_can() always-true, so the gate had no
 * assertion anywhere. This suite is that assertion.
 *
 * (plugin v10.48.1)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Capture register_post_meta calls + every capability check, mirroring the shape
// tests/post-settings-pillar.php uses for the pillar pair.
$GLOBALS['__registered'] = array();
$GLOBALS['__cap_calls']  = array();
$GLOBALS['__cap_result'] = true;

function register_post_meta( $type, $key, $args ) { $GLOBALS['__registered'][ $type ][ $key ] = $args; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_meta_box() {}
function current_user_can( $cap, $id = null ) {
	$GLOBALS['__cap_calls'][] = array( $cap, $id );
	return $GLOBALS['__cap_result'];
}
function get_post_meta( $id, $key, $single = true ) { return ''; }

require __DIR__ . '/../inc/post-settings.php';

sn_post_settings_register_meta();

/** The ten REST-exposed SEO-era keys (the LOW-1 set). */
$seo_keys = array(
	'_sn_noindex',
	'_sn_noarchive',
	'_sn_noimageindex',
	'_sn_evergreen',
	'_sn_meta_description',
	'_sn_canonical_url',
	'_sn_og_image_url',
	'_sn_og_card_title',
	'_sn_seo_title',
	'_sn_focus_keyword',
);

echo "Group: the SEO-era keys are REST-exposed on both post types (the precondition that makes the gate matter)\n";
foreach ( SN_POST_SETTINGS_POST_TYPES as $type ) {
	foreach ( $seo_keys as $key ) {
		$args = $GLOBALS['__registered'][ $type ][ $key ] ?? null;
		ok( is_array( $args ) && true === ( $args['show_in_rest'] ?? null ), "$key registered show_in_rest on '$type'" );
	}
}

echo "\nGroup: auth_callback asks edit_post on the OBJECT ID (not blanket edit_posts)\n";
foreach ( SN_POST_SETTINGS_POST_TYPES as $type ) {
	foreach ( $seo_keys as $key ) {
		$auth = $GLOBALS['__registered'][ $type ][ $key ]['auth_callback'] ?? null;
		if ( ! is_callable( $auth ) ) {
			ok( false, "$key on '$type': auth_callback is callable" );
			ok( false, "$key on '$type': auth asks for edit_post on the object id" );
			continue;
		}
		ok( true, "$key on '$type': auth_callback is callable" );

		$GLOBALS['__cap_calls']  = array();
		$GLOBALS['__cap_result'] = true;
		// The real signature WP passes a registered meta auth_callback.
		$allowed = call_user_func( $auth, false, $key, 42, 7, 'edit_post_meta', array() );
		ok(
			true === $allowed && array( array( 'edit_post', 42 ) ) === $GLOBALS['__cap_calls'],
			"$key on '$type': auth asks for edit_post on the object id"
		);
	}
}

echo "\nGroup: the gate actually denies (a blanket-true stub would hide this)\n";
foreach ( $seo_keys as $key ) {
	$auth = $GLOBALS['__registered']['post'][ $key ]['auth_callback'] ?? null;
	$GLOBALS['__cap_result'] = false;
	ok( false === call_user_func( $auth, false, $key, 42, 7, 'edit_post_meta', array() ), "$key denies when the per-resource cap check denies" );
}
$GLOBALS['__cap_result'] = true;

echo "\nGroup: a DIFFERENT object id is what gets checked (the IDOR-class assertion)\n";
$auth = $GLOBALS['__registered']['post']['_sn_canonical_url']['auth_callback'];
$GLOBALS['__cap_calls'] = array();
call_user_func( $auth, false, '_sn_canonical_url', 99, 7, 'edit_post_meta', array() );
ok( array( array( 'edit_post', 99 ) ) === $GLOBALS['__cap_calls'], 'the checked id follows the operated id (99, not a constant)' );

echo "\nGroup: the pillar pair (already correct) has not regressed\n";
foreach ( array( '_sn_pillar', '_sn_pillar_designation' ) as $key ) {
	$auth = $GLOBALS['__registered']['page'][ $key ]['auth_callback'] ?? null;
	$GLOBALS['__cap_calls'] = array();
	call_user_func( $auth, false, $key, 42, 7, 'edit_post_meta', array() );
	ok( array( array( 'edit_post', 42 ) ) === $GLOBALS['__cap_calls'], "$key still gates per-resource" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
