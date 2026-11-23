<?php
/**
 * The schema author's name, sn_identity_person_name()
 * (inc/identity-person-name.php): identity.person_name when it is set, else
 * the display name provenance resolves for the post in context
 * (sn_prov_author(), inc/provenance-core-subject-parts.php), else the site
 * title. Real settings store, real provenance resolver.
 *
 * Run: php tests/identity-person-name.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
$GLOBALS['__users']   = array( 7 => 'Juan Display', 8 => '' );
$GLOBALS['__filter']  = null;
$GLOBALS['__queried'] = null;
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['__options'][ $name ] = $value; return true; }
function get_bloginfo( $what ) { return 'name' === $what ? 'Test Site' : ''; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value, ...$args ) {
	return 'sn_prov_author' === $hook && is_callable( $GLOBALS['__filter'] ) ? call_user_func( $GLOBALS['__filter'], $value, ...$args ) : $value;
}
function get_the_author_meta( $field, $user_id ) { return 'display_name' === $field ? (string) ( $GLOBALS['__users'][ (int) $user_id ] ?? '' ) : ''; }
function is_singular() { return null !== $GLOBALS['__queried']; }
function get_queried_object() { return $GLOBALS['__queried']; }

require __DIR__ . '/../inc/settings.php';
require __DIR__ . '/../inc/provenance-core-subject-parts.php';
require __DIR__ . '/../inc/identity-person-name.php';

$pass = 0;
$fail = 0;
function eq( $want, $got, $label ) {
	global $pass, $fail;
	if ( $want === $got ) { $pass++; echo "PASS: $label\n"; return; }
	$fail++;
	echo "FAIL: $label: wanted " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . "\n";
}
function save_name( $name ) { sn_settings_save( array( 'identity_person_name' => $name ) ); sn_setting_reset_cache(); }

$post    = (object) array( 'ID' => 1, 'post_author' => 7 );
$orphan  = (object) array( 'ID' => 2, 'post_author' => 0 );
$unnamed = (object) array( 'ID' => 3, 'post_author' => 8 );

echo "Set: the field wins everywhere\n";
save_name( 'Juan Example' );
eq( 'Juan Example', sn_identity_person_name( $post ), 'set, on a post with an author: the field' );
eq( 'Juan Example', sn_identity_person_name(), 'set, with no post: the field' );
eq( sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ), sn_identity_person_name( $post ), 'set: the very value every reader printed before (same expression)' );

echo "\nSaved empty\n";
save_name( '' );
eq( '', sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ), 'fixture: a saved-empty field reads back empty from sn_setting(), which is what the schema printed' );
eq( 'Juan Display', sn_identity_person_name( $post ), 'empty, post with an author: the author\'s display name' );
eq( 'Test Site', sn_identity_person_name(), 'empty, no post in context: the site title' );
eq( 'Test Site', sn_identity_person_name( $orphan ), 'empty, a post with no author: the site title' );
eq( 'Test Site', sn_identity_person_name( $unnamed ), 'empty, an author with no display name: the site title' );
$GLOBALS['__queried'] = $post;
eq( 'Juan Display', sn_identity_person_name(), 'empty, no post passed on a singular view: the queried post\'s author' );
$GLOBALS['__queried'] = null;

echo "\nNever saved (the default merges the site title in)\n";
$GLOBALS['__options'] = array();
sn_setting_reset_cache();
eq( 'Test Site', sn_setting( 'identity.person_name', '' ), 'fixture: sn_setting() alone cannot tell never-saved from set to the site title' );
eq( 'Juan Display', sn_identity_person_name( $post ), 'never saved, post with an author: the display name, not the default' );
eq( 'Test Site', sn_identity_person_name(), 'never saved, no post: the site title' );

echo "\nOne resolution: provenance's own, its filter included\n";
$GLOBALS['__filter'] = static function ( $author, $p ) { return $author . ' (post ' . (int) $p->ID . ')'; };
eq( 'Juan Display (post 1)', sn_identity_person_name( $post ), 'the sn_prov_author filter reaches the schema name' );
eq( sn_prov_author( $post ), sn_identity_person_name( $post ), 'empty field: the two outputs name the author alike' );
$GLOBALS['__filter'] = null;

echo "\nProvenance is unchanged\n";
eq( 'Juan Display', sn_prov_author( $post ), 'sn_prov_author() is the post author\'s display name' );
eq( '', sn_prov_author( $orphan ), 'and empty for a post with no author, as before' );
save_name( 'Juan Example' );
eq( 'Juan Display', sn_prov_author( $post ), 'and never the identity field: a set field does not reach provenance' );
$src = (string) file_get_contents( __DIR__ . '/../inc/provenance-core-subject-parts.php' );
eq( 1, preg_match( '/function sn_prov_author\( \$post \) \{\s+\$author = get_the_author_meta\( \'display_name\', \(int\) \$post->post_author \);\s+return \(string\) apply_filters\( \'sn_prov_author\', \$author, \$post \);\s+\}/', $src ), 'sn_prov_author()\'s body is the two lines it was' );

echo "\nEvery reader goes through the one function\n";
foreach ( array( 'inc/seo-schema-identity-parts.php', 'inc/seo-schema-pages-parts.php', 'inc/resume-jsonresume-route.php' ) as $file ) {
	$code = (string) file_get_contents( __DIR__ . '/../' . $file );
	eq( true, false !== strpos( $code, 'sn_identity_person_name(' ) && false === strpos( $code, "sn_setting( 'identity.person_name'" ), $file . ' reads the name through sn_identity_person_name()' );
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
