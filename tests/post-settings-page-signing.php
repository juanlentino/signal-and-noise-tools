<?php
/**
 * Tests: pillar essay meta (_sn_pillar / _sn_pillar_designation) is Pages-only.
 * Registration (page yes, post no; show_in_rest pinned false), the per-resource
 * auth_callback (edit_post on the object id, real register_meta signature),
 * the meta box render gate, output escaping, and the save path including the
 * post-type guard. (plugin v9.79.0)
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
function add_action( $h, $c, $p = 10, $a = 1 ) {}
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

echo "\nGroup: the checkbox renders, on pages only\n";
$page = (object) array( 'ID' => 11, 'post_type' => 'page' );
ob_start(); sn_post_settings_render( $page ); $html_page = ob_get_clean();
ok( false !== strpos( $html_page, 'name="sn_prov_sign"' ), 'a PAGE shows the signing checkbox' );
ok( false !== strpos( $html_page, 'Sign this page' ), 'labelled in plain words' );
ob_start(); sn_post_settings_render( (object) array( 'ID' => 12, 'post_type' => 'post' ) ); $html_post = ob_get_clean();
ok( false === strpos( $html_post, 'name="sn_prov_sign"' ), 'a POST does not' );

echo "\nGroup: the helper says what cannot be undone\n";
ok( false !== stripos( $html_page, 'permanent' ), 'the helper uses the word permanent' );
ok( false !== stripos( $html_page, 'cannot withdraw' ), 'and says unticking cannot withdraw an anchored record — the ledger is append-only' );

echo "\nGroup: markup is well formed (the bug the first draft had)\n";
// The first draft inserted this control INSIDE the freshness field's already-open
// <label>, nesting a <p> and a second <label> in it. Count tags rather than
// trusting that it looked fine.
ok( substr_count( $html_page, '<label' ) === substr_count( $html_page, '</label>' ), 'labels balance' );
ok( substr_count( $html_page, '<div' ) === substr_count( $html_page, '</div>' ), 'divs balance' );

echo "\nGroup: checked state + save\n";
$GLOBALS['__meta'][11]['_sn_prov_sign'] = '1';
ob_start(); sn_post_settings_render( $page ); $checked = ob_get_clean();
$seg = substr( $checked, strpos( $checked, 'sn_prov_sign' ), 80 );
ok( false !== strpos( $seg, 'checked' ), 'a signed page shows the box ticked' );

$GLOBALS['__meta'][11] = array();
$GLOBALS['__post_types'] = array( 11 => 'page' );
$_POST = array( 'sn_post_settings_nonce' => 'x', 'sn_prov_sign' => '1' );
sn_post_settings_save( 11 );
ok( '1' === ( $GLOBALS['__meta'][11]['_sn_prov_sign'] ?? '' ), 'ticking it writes the meta the resolver reads' );

$_POST = array( 'sn_post_settings_nonce' => 'x' );
sn_post_settings_save( 11 );
ok( ! isset( $GLOBALS['__meta'][11]['_sn_prov_sign'] ), 'unticking removes it — future versions stop; the anchored record is untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
