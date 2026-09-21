<?php
/**
 * Tests: pillar essay meta (_sn_pillar / _sn_pillar_designation) is Pages-only.
 * Registration (page yes, post no; show_in_rest pinned TRUE since #1608, the
 * panel is the React sidebar the flag waited for), the per-resource
 * auth_callback (edit_post on the object id, real register_meta signature),
 * the panel's Pages-only gate on the pair, and the REST write path (the
 * classic save handler is gone). (plugin v9.79.0)
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

echo "Group: registration (Pages only)\n";
sn_post_settings_register_meta();
$flag  = $GLOBALS['__registered']['page']['_sn_pillar'] ?? array();
$desig = $GLOBALS['__registered']['page']['_sn_pillar_designation'] ?? array();
ok( array() !== $flag, "_sn_pillar registered on 'page'" );
ok( array() !== $desig, "_sn_pillar_designation registered on 'page'" );
ok( ! isset( $GLOBALS['__registered']['post']['_sn_pillar'] ), "_sn_pillar NOT registered on 'post' (pillars are Pages)" );
ok( ! isset( $GLOBALS['__registered']['post']['_sn_pillar_designation'] ), "_sn_pillar_designation NOT registered on 'post'" );
ok( 'boolean' === ( $flag['type'] ?? '' ), '_sn_pillar is a boolean' );
ok( 'string' === ( $desig['type'] ?? '' ), '_sn_pillar_designation is a string' );
ok( 'rest_sanitize_boolean' === ( $flag['sanitize_callback'] ?? '' ), '_sn_pillar sanitizes as boolean' );
ok( 'sanitize_text_field' === ( $desig['sanitize_callback'] ?? '' ), '_sn_pillar_designation sanitizes as single-line text (free text, never format-validated)' );
ok( true === ( $flag['show_in_rest'] ?? null ), '_sn_pillar show_in_rest is true: the document panel (#1608) reads and writes it through the post entity' );
ok( true === ( $desig['show_in_rest'] ?? null ), '_sn_pillar_designation show_in_rest is true for the same panel' );

echo "\nGroup: auth_callback (per-resource edit_post, real register_meta signature)\n";
foreach ( array( '_sn_pillar' => $flag, '_sn_pillar_designation' => $desig ) as $key => $args ) {
	$auth = $args['auth_callback'] ?? null;
	if ( ! is_callable( $auth ) ) {
		ok( false, "$key auth_callback is callable" );
		ok( false, "$key auth asks for edit_post on the object id" );
		ok( false, "$key auth denies when the cap check denies" );
		continue;
	}
	ok( true, "$key auth_callback is callable" );
	$GLOBALS['__cap_calls']  = array();
	$GLOBALS['__cap_result'] = true;
	$allowed = call_user_func( $auth, false, $key, 42, 7, 'edit_post_meta', array() );
	ok( true === $allowed && array( array( 'edit_post', 42 ) ) === $GLOBALS['__cap_calls'], "$key auth asks for edit_post on the object id" );
	$GLOBALS['__cap_result'] = false;
	ok( false === call_user_func( $auth, false, $key, 42, 7, 'edit_post_meta', array() ), "$key auth denies when the cap check denies" );
}
$GLOBALS['__cap_result'] = true;

echo "\nGroup: the panel paints the pair on Pages only (#1608)\n";
// The classic meta box is gone; the pair now rides assets/post-settings-panel.js,
// where each field row carries `page: true` and the render filters on it.
$js = (string) file_get_contents( __DIR__ . '/../assets/post-settings-panel.js' );
ok( ! function_exists( 'sn_post_settings_render' ), 'sn_post_settings_render is gone: no classic meta box paints the pair' );
ok( false !== strpos( $js, "key: '_sn_pillar', kind: 'flag', page: true" ), 'the panel declares _sn_pillar as a Pages-only flag' );
ok( false !== strpos( $js, "key: '_sn_pillar_designation', kind: 'text', page: true" ), 'the panel declares _sn_pillar_designation as Pages-only text' );
ok( false !== strpos( $js, "return ! f.page || isPage;" ), 'the panel drops page-only rows unless the edited type is a page' );
ok( false !== strpos( $js, "Feature as a pillar essay" ) && false !== strpos( $js, "Pillar designation" ), 'the labels the meta box carried survive verbatim' );

echo "\nGroup: the write path is REST, the classic handler is gone (#1608)\n";
ok( ! function_exists( 'sn_post_settings_save' ) && ! isset( $GLOBALS['__hooks']['save_post'] ), 'no save_post handler remains: nothing printed the form it read' );
$desig = $GLOBALS['__registered']['page']['_sn_pillar_designation'] ?? array();
ok( 'sanitize_text_field' === ( $desig['sanitize_callback'] ?? '' ), 'the designation sanitizes through sanitize_text_field on the REST path' );
ok( '1.01' === call_user_func( $desig['sanitize_callback'], '  1.01  ' ), 'designation is trimmed' );
ok( '2.00' === call_user_func( $desig['sanitize_callback'], '<b>2.00</b>' ), 'designation is sanitized (tags stripped)' );
ok( 'as-substrate 2.00' === call_user_func( $desig['sanitize_callback'], 'as-substrate 2.00' ), 'designation stays free text (owner numbering, no format validation)' );
ok( 'rest_sanitize_boolean' === ( $GLOBALS['__registered']['page']['_sn_pillar']['sanitize_callback'] ?? '' ), 'the flag sanitizes as a REST boolean' );
ok( ! isset( $GLOBALS['__registered']['post']['_sn_pillar'] ) && ! isset( $GLOBALS['__registered']['post']['_sn_pillar_designation'] ), "a crafted REST write against a 'post' never sets the pair: unregistered meta is refused by the controller" );
ok( false !== strpos( $js, "if ( '' === next[ k ] || false === next[ k ] ) {" ) && 2 === substr_count( $js, 'setMeta( normalize( next ) );' ), 'an unticked flag or an emptied designation goes back as null, so the key is deleted instead of stored as an empty row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
