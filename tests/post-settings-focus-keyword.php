<?php
/**
 * Tests: _sn_focus_keyword is registered on post + page with the 80-char cap
 * on its sanitizer (#1608: the panel saves through REST, so the cap rides the
 * registered callback, the only write path now that the classic handler is
 * gone), shared with the rw-door update-post-surfaces schema. (plugin v10.8.0)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Capture register_post_meta calls; stub the WP surface post-settings.php touches.
$GLOBALS['__registered'] = array();
$GLOBALS['__meta']       = array();
$GLOBALS['__deleted']    = array();
function register_post_meta( $type, $key, $args ) { $GLOBALS['__registered'][ $type ][ $key ] = $args; }
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $c; }
function add_meta_box() {}
function current_user_can( $c, $id = null ) { return true; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['__meta'][ $key ] ?? ''; }
function update_post_meta( $id, $key, $v ) { $GLOBALS['__meta'][ $key ] = $v; return true; }
function delete_post_meta( $id, $key ) { $GLOBALS['__deleted'][] = $key; unset( $GLOBALS['__meta'][ $key ] ); return true; }
function wp_verify_nonce( $n, $a ) { return true; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : ''; }
function wp_is_post_revision( $id ) { return false; }
function get_post_type( $id ) { return 'post'; }

require __DIR__ . '/../inc/post-settings.php';

echo "Group: registration\n";
sn_post_settings_register_meta();
foreach ( array( 'post', 'page' ) as $t ) {
	ok( isset( $GLOBALS['__registered'][ $t ]['_sn_focus_keyword'] ), "_sn_focus_keyword registered on '$t'" );
	$args = $GLOBALS['__registered'][ $t ]['_sn_focus_keyword'] ?? array();
	ok( 'sn_post_settings_sanitize_focus_keyword' === ( $args['sanitize_callback'] ?? '' ), "_sn_focus_keyword on '$t' sanitizes through the capping callback (#1608)" );
}
ok( function_exists( 'sn_post_settings_sanitize_focus_keyword' ) && 'music provenance' === sn_post_settings_sanitize_focus_keyword( '  music <b>provenance</b>  ' ), 'the callback strips tags and normalizes whitespace' );
ok( function_exists( 'sn_post_settings_sanitize_focus_keyword' ) && 80 === mb_strlen( sn_post_settings_sanitize_focus_keyword( str_repeat( 'k', 200 ) ) ), 'the callback hard-caps at 80 chars, so a REST write shares the POST limit' );

echo "\nGroup: the classic save handler is gone (#1608)\n";
ok( ! function_exists( 'sn_post_settings_save' ) && ! isset( $GLOBALS['__hooks']['save_post'] ), 'no save_post handler: nothing prints the form it read, so the registered sanitizer is the one write path' );
$js = (string) file_get_contents( __DIR__ . '/../assets/post-settings-panel.js' );
ok( false !== strpos( $js, "if ( '' === next[ k ] || false === next[ k ] ) {" ) && 2 === substr_count( $js, 'setMeta( normalize( next ) );' ), 'an emptied keyword goes back as null, so the key is deleted instead of stored as an empty row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
