<?php
/**
 * Tests: _sn_seo_title is registered on post + page and the accessor reads it.
 * (plugin v9.3.0)
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
function register_post_meta( $type, $key, $args ) { $GLOBALS['__registered'][ $type ][ $key ] = $args; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_meta_box() {}
function current_user_can( $c, $id = null ) { return true; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['__meta'][ $key ] ?? ''; }

require __DIR__ . '/../inc/post-settings.php';

echo "Group: registration\n";
sn_post_settings_register_meta();
foreach ( array( 'post', 'page' ) as $t ) {
	ok( isset( $GLOBALS['__registered'][ $t ]['_sn_seo_title'] ), "_sn_seo_title registered on '$t'" );
	$args = $GLOBALS['__registered'][ $t ]['_sn_seo_title'] ?? array();
	ok( ! empty( $args['show_in_rest'] ), "_sn_seo_title on '$t' is show_in_rest" );
	ok( 'sanitize_text_field' === ( $args['sanitize_callback'] ?? '' ), "_sn_seo_title on '$t' sanitizes as text" );
}

echo "\nGroup: accessor\n";
$GLOBALS['__meta']['_sn_seo_title'] = 'Custom Social Title';
ok( 'Custom Social Title' === sn_post_settings_get_seo_title( 7 ), 'accessor returns the stored override' );
$GLOBALS['__meta'] = array();
ok( '' === sn_post_settings_get_seo_title( 7 ), 'accessor returns empty string when unset' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
