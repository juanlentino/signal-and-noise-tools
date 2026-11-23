<?php
/**
 * The schema author's name, sn_identity_person_name()
 * (inc/identity-person-name.php): identity.person_name when it is set, else
 * the site title. A saved-empty field printed an empty name while a site that
 * never saved it printed the site title; the two now agree. The name never
 * depends on the page in view: one Person node, one name. Real settings
 * store; the real provenance resolver is loaded to pin that it is untouched
 * and never consulted.
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

$post = (object) array( 'ID' => 1, 'post_author' => 7 );
/** The name with no post in view (an archive) and on a singular view of $post. */
function both_views() {
	$GLOBALS['__queried'] = null;
	$archive              = sn_identity_person_name();
	$GLOBALS['__queried'] = $GLOBALS['post'];
	$singular             = sn_identity_person_name();
	$GLOBALS['__queried'] = null;
	return array( $archive, $singular );
}

echo "Set: the field\n";
save_name( 'Juan Example' );
eq( array( 'Juan Example', 'Juan Example' ), both_views(), 'set: the field, on an archive and on a singular page' );
eq( sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ), sn_identity_person_name(), 'set: the very value every reader printed before (same expression)' );

echo "\nSaved empty\n";
save_name( '' );
eq( '', sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ), 'fixture: a saved-empty field reads back empty from sn_setting(), which is what the schema printed' );
eq( array( 'Test Site', 'Test Site' ), both_views(), 'saved empty: the site title, the same name on an archive and on a singular page whose author has a display name' );

echo "\nWhitespace only (the save trims; an option written another way may not be)\n";
$GLOBALS['__options'][ SN_SETTINGS_OPTION ] = array( 'identity' => array( 'person_name' => "  \t " ) );
sn_setting_reset_cache();
eq( array( 'Test Site', 'Test Site' ), both_views(), 'whitespace only: the site title' );

echo "\nNever saved\n";
$GLOBALS['__options'] = array();
sn_setting_reset_cache();
eq( array( 'Test Site', 'Test Site' ), both_views(), 'never saved: the site title, as before, and now the same as saved empty' );

echo "\nThe page in view never changes the name\n";
$GLOBALS['__filter'] = static function ( $author, $p ) { return 'FILTERED'; };
save_name( '' );
eq( array( 'Test Site', 'Test Site' ), both_views(), 'the provenance resolver is not consulted: its filter cannot reach the name' );
$code = (string) file_get_contents( __DIR__ . '/../inc/identity-person-name.php' );
eq( false, strpos( $code, 'sn_prov_author' ) || strpos( $code, 'get_queried_object' ) || strpos( $code, 'post_author' ), 'the function reads no post, no author and no provenance' );
$GLOBALS['__filter'] = null;

echo "\nProvenance is unchanged\n";
eq( 'Juan Display', sn_prov_author( $post ), 'sn_prov_author() is the post author\'s display name' );
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
