<?php
/**
 * Tests: pillar essay meta (_sn_pillar / _sn_pillar_designation) is Pages-only.
 * Registration (page yes, post no; show_in_rest pinned false), the per-resource
 * auth_callback (edit_post on the object id, real register_meta signature),
 * the panel's Pages-only gate (#1608), and the REST write path (the classic
 * save handler is gone). (plugin v9.79.0)
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
$GLOBALS['__post_types'] = array();
$GLOBALS['__cap_calls']  = array();
$GLOBALS['__cap_result'] = true;

function register_post_meta( $type, $key, $args ) { $GLOBALS['__registered'][ $type ][ $key ] = $args; }
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $c; }
function add_meta_box() {}
function current_user_can( $cap, $id = null ) {
	$GLOBALS['__cap_calls'][] = array( $cap, $id );
	return $GLOBALS['__cap_result'];
}
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['__meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['__meta'][ $id ][ $key ] ); return true; }
function get_post_type( $post = null ) {
	if ( is_object( $post ) ) { return $post->post_type; }
	return $GLOBALS['__post_types'][ $post ] ?? 'post';
}

// Save-path surface.
function wp_verify_nonce( $nonce, $action ) { return true; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function wp_is_post_revision( $id ) { return false; }
function sanitize_text_field( $str ) {
	$str = preg_replace( '/<[^>]*>/', '', (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ) { return trim( (string) $str ); }
function esc_url_raw( $url ) { return filter_var( (string) $url, FILTER_VALIDATE_URL ) ? (string) $url : ''; }

// Render-path surface.
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . $name . '">'; }
function checked( $checked, $current = true, $display = true ) {
	$result = ( $checked == $current ) ? " checked='checked'" : '';
	if ( $display ) { echo $result; }
	return $result;
}
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function get_the_title( $post ) { return 'A Page Title'; }
function get_permalink( $post ) { return 'https://example.test/page/'; }

require __DIR__ . '/../inc/post-settings.php';

// The harness above only loads the file; registration runs on init in WP.
sn_post_settings_register_meta();

echo "\nGroup: the opt-in EXISTS at all\n";
// v10.84.0 shipped the per-page signing gate and NO way to set it: the meta key
// lived in exactly two places, its own const and one get_post_meta. Ticking
// nothing and saving produced nothing, because there was nothing to tick. These
// are the assertions that would have caught it.
ok( isset( $GLOBALS['__registered']['page']['_sn_prov_sign'] ), 'the sign meta is REGISTERED for pages' );
ok( ! isset( $GLOBALS['__registered']['post']['_sn_prov_sign'] ), 'and NOT for posts — a post is a subject by category, so the control would decide nothing there' );

echo "\nGroup: the checkbox is painted, on pages only (#1608: the document panel)\n";
$js = (string) file_get_contents( __DIR__ . '/../assets/post-settings-panel.js' );
ok( ! function_exists( 'sn_post_settings_render' ), 'no classic meta box render remains' );
ok( false !== strpos( $js, "key: '_sn_prov_sign', kind: 'flag', page: true" ), 'the panel declares the signing flag as Pages-only' );
ok( false !== strpos( $js, "return ! f.page || isPage;" ), 'and drops page-only rows for a post: a post is a subject by category, so the control would decide nothing there' );
ok( false !== strpos( $js, 'Sign this page (provenance)' ), 'labelled in plain words' );

echo "\nGroup: the helper says what cannot be undone\n";
$help_at = strpos( $js, "key: '_sn_prov_sign'" );
$help    = substr( $js, $help_at, 600 );
ok( false !== stripos( $help, 'permanent' ), 'the helper uses the word permanent' );
ok( false !== stripos( $help, 'cannot withdraw' ), 'and says unticking cannot withdraw an anchored record: the ledger is append-only' );

echo "\nGroup: checked state + the REST write\n";
ok( false !== strpos( $js, 'checked: !! value' ), 'a stored flag paints the box ticked' );
ok( false !== strpos( $js, "props.set( f.key, v ? true : null )" ), 'ticking writes true (stored 1), unticking writes null (deleted)' );
ok( false !== strpos( $js, "if ( '' === next[ k ] || false === next[ k ] ) {" ) && 2 === substr_count( $js, 'setMeta( normalize( next ) );' ), 'a never-ticked flag goes back as null too, so no empty row is stored for a page that is not signed' );
ok( 'rest_sanitize_boolean' === ( $GLOBALS['__registered']['page']['_sn_prov_sign']['sanitize_callback'] ?? '' ), 'the flag sanitizes as a REST boolean' );
ok( ! function_exists( 'sn_post_settings_save' ) && ! isset( $GLOBALS['__hooks']['save_post'] ), 'no classic save handler remains: the resolver reads what the entity save stored' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
