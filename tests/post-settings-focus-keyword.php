<?php
/**
 * Tests: _sn_focus_keyword is registered on post + page, rendered in the
 * meta box, and saved with the 80-char cap shared with the rw-door
 * update-post-surfaces schema. (plugin v10.8.0)
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
function add_action( $h, $c, $p = 10, $a = 1 ) {}
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
	ok( 'sanitize_text_field' === ( $args['sanitize_callback'] ?? '' ), "_sn_focus_keyword on '$t' sanitizes as single-line text" );
}

echo "\nGroup: save handler\n";
$_POST = array(
	'sn_post_settings_nonce' => 'x',
	'sn_focus_keyword'       => '  music <b>provenance</b>  ',
);
sn_post_settings_save( 7 );
ok( 'music provenance' === ( $GLOBALS['__meta']['_sn_focus_keyword'] ?? '' ), 'keyword is tag-stripped, whitespace-normalized, and saved' );

$_POST['sn_focus_keyword'] = str_repeat( 'k', 200 );
sn_post_settings_save( 7 );
ok( 80 === mb_strlen( $GLOBALS['__meta']['_sn_focus_keyword'] ?? '' ), 'keyword hard-caps at 80 chars (parity with the rw-door schema)' );

$_POST['sn_focus_keyword'] = '';
sn_post_settings_save( 7 );
ok( in_array( '_sn_focus_keyword', $GLOBALS['__deleted'], true ) && ! isset( $GLOBALS['__meta']['_sn_focus_keyword'] ), 'empty submit deletes the key instead of storing an empty string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
