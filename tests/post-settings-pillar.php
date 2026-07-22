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
ok( false === ( $flag['show_in_rest'] ?? null ), '_sn_pillar show_in_rest pinned false (POST bridge saves it; flip only for a real REST sidebar)' );
ok( false === ( $desig['show_in_rest'] ?? null ), '_sn_pillar_designation show_in_rest pinned false' );

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

echo "\nGroup: meta box render\n";
$page            = new stdClass();
$page->ID        = 11;
$page->post_type = 'page';
$GLOBALS['__meta'][11] = array(
	'_sn_pillar'             => '1',
	'_sn_pillar_designation' => '1.01" onmouseover="x',
);
ob_start();
sn_post_settings_render( $page );
$html = ob_get_clean();
ok( false !== strpos( $html, 'Feature as a pillar essay' ), 'page render includes the pillar checkbox label' );
ok( false === strpos( $html, 'sn-fieldset' ), 'no .sn-fieldset/heading markup: flat .sn-field sections like every sibling (admin.css never loads on edit screens)' );
ok( false !== strpos( $html, 'name="sn_pillar"' ), 'page render includes the feature checkbox' );
ok( false !== strpos( $html, 'name="sn_pillar_designation"' ), 'page render includes the designation input' );
ok( false !== strpos( $html, 'checked' ), 'stored flag renders the checkbox checked' );
ok( false === strpos( $html, '1.01" onmouseover=' ), 'designation value cannot break out of the attribute (raw quote absent)' );
ok( false !== strpos( $html, esc_attr( '1.01" onmouseover="x' ) ), 'designation value renders in escaped form' );

$note            = new stdClass();
$note->ID        = 12;
$note->post_type = 'post';
ob_start();
sn_post_settings_render( $note );
$html2 = ob_get_clean();
ok( false === strpos( $html2, 'sn_pillar' ), 'post render has no pillar fields' );
ok( false !== strpos( $html2, 'sn_seo_title' ), 'post render still shows the shared fields' );

echo "\nGroup: save path\n";
$GLOBALS['__post_types'] = array( 21 => 'page', 22 => 'post' );

function save_with( $post_id, $fields ) {
	$_POST = array_merge( array( 'sn_post_settings_nonce' => 'n' ), $fields );
	sn_post_settings_save( $post_id );
}

save_with( 21, array( 'sn_pillar' => '1', 'sn_pillar_designation' => '  1.01  ' ) );
ok( '1' === ( $GLOBALS['__meta'][21]['_sn_pillar'] ?? null ), "checkbox on stores '1'" );
ok( '1.01' === ( $GLOBALS['__meta'][21]['_sn_pillar_designation'] ?? null ), 'designation is trimmed' );

save_with( 21, array( 'sn_pillar_designation' => '<b>2.00</b>' ) );
ok( ! isset( $GLOBALS['__meta'][21]['_sn_pillar'] ), 'checkbox absent deletes the flag' );
ok( '2.00' === ( $GLOBALS['__meta'][21]['_sn_pillar_designation'] ?? null ), 'designation is sanitized (tags stripped)' );

save_with( 21, array( 'sn_pillar_designation' => 'as-substrate 2.00' ) );
ok( 'as-substrate 2.00' === ( $GLOBALS['__meta'][21]['_sn_pillar_designation'] ?? null ), 'designation stays free text (owner numbering, no format validation)' );

save_with( 21, array( 'sn_pillar' => '1', 'sn_pillar_designation' => '   ' ) );
ok( ! isset( $GLOBALS['__meta'][21]['_sn_pillar_designation'] ), 'empty designation deletes the key' );
ok( '1' === ( $GLOBALS['__meta'][21]['_sn_pillar'] ?? null ), 'flag persists independently of the designation' );

save_with( 22, array( 'sn_pillar' => '1', 'sn_pillar_designation' => '1.00' ) );
ok( ! isset( $GLOBALS['__meta'][22]['_sn_pillar'] ), "a crafted POST against a 'post' never sets the flag" );
ok( ! isset( $GLOBALS['__meta'][22]['_sn_pillar_designation'] ), "a crafted POST against a 'post' never sets the designation" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
