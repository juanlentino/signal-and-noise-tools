<?php
/**
 * Standalone test: the site name, tagline and locale on Site > Identity are an
 * override of core's Settings > General, not a second copy (2026-11-10).
 *
 * A field left empty, or holding what core already says, stores nothing, so
 * the value is read from core and follows it when core changes. A value that
 * differs from core is stored and wins, exactly as before.
 *
 * Run: php tests/settings-identity-core-override.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$GLOBALS['__options'] = array();
$GLOBALS['__core']    = array( 'name' => 'Core Name', 'description' => 'Core tagline', 'locale' => 'es_AR' );
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default; }
// As core does: the registered sanitize callback runs on every write.
function update_option( $name, $value ) { $GLOBALS['__options'][ $name ] = 'sn_settings' === $name ? sn_settings_sanitize_option( $value ) : $value; return true; }
function get_bloginfo( $what ) { return $GLOBALS['__core'][ $what ] ?? ''; }
function get_locale() { return $GLOBALS['__core']['locale']; }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, $q ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_field() {}
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function add_action() {}
require __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/lib/admin-post-url-stub.php';
require_once __DIR__ . '/../inc/admin-tabs.php';
require_once __DIR__ . '/../inc/admin-forms/identity-and-seo.php';

function stored_identity() { return (array) ( $GLOBALS['__options']['sn_settings']['identity'] ?? array() ); }
function read( $key ) { sn_setting_reset_cache(); return sn_setting( 'identity.' . $key, 'CALLER DEFAULT' ); }
function form() { sn_setting_reset_cache(); ob_start(); sn_admin_render_identity_and_seo_form(); return (string) ob_get_clean(); }

echo "Nothing stored: core's values are read\n";
ok( 'Core Name' === read( 'site_name' ) && 'Core tagline' === read( 'site_description' ), "site name and tagline are core's" );
ok( 'es_AR' === read( 'locale' ), "the locale is core's Site Language, not a literal en_US" );

echo "A save that only repeats core, or leaves a field empty, stores nothing\n";
sn_settings_save( array( 'identity_site_name' => 'Core Name', 'identity_site_description' => '', 'identity_locale' => 'es_AR', 'identity_person_name' => 'A Person' ) );
$id = stored_identity();
ok( ! array_key_exists( 'site_name', $id ), 'a site name equal to core is not stored' );
ok( ! array_key_exists( 'site_description', $id ), 'an empty tagline is not stored (it used to beat every fallback as an empty string)' );
ok( ! array_key_exists( 'locale', $id ), 'a locale equal to core is not stored' );
ok( 'A Person' === ( $id['person_name'] ?? null ), 'the rest of the identity fields are stored as before' );
$GLOBALS['__core'] = array( 'name' => 'Renamed In General', 'description' => 'New tagline', 'locale' => 'en_GB' );
ok( 'Renamed In General' === read( 'site_name' ) && 'New tagline' === read( 'site_description' ) && 'en_GB' === read( 'locale' ), 'so a change in Settings > General is what every reader sees' );

echo "A value that differs from core is an override and wins\n";
sn_settings_save( array( 'identity_site_name' => 'Juan Lentino', 'identity_site_description' => 'Music Production & Creative Strategy', 'identity_locale' => 'en_US' ) );
ok( 'Juan Lentino' === read( 'site_name' ) && 'Music Production & Creative Strategy' === read( 'site_description' ) && 'en_US' === read( 'locale' ), 'the three overrides are stored and read back' );
$GLOBALS['__core']['name'] = 'Something Else';
ok( 'Juan Lentino' === read( 'site_name' ), 'and core changing does not move them' );

echo "Values saved before this change keep working\n";
$GLOBALS['__options']['sn_settings'] = array( 'identity' => array( 'site_name' => 'Legacy Name', 'site_description' => 'Legacy tagline', 'locale' => 'pt_BR' ) );
ok( 'Legacy Name' === read( 'site_name' ) && 'Legacy tagline' === read( 'site_description' ) && 'pt_BR' === read( 'locale' ), 'a stored value that differs from core reads back unchanged' );

echo "The form shows core's value as the placeholder\n";
$html = form();
ok( 1 === preg_match( '/name="identity_site_name" value="Legacy Name" placeholder="Something Else"/', $html ), 'an override is the field value, with core as the placeholder' );
$GLOBALS['__options']['sn_settings'] = array( 'identity' => array( 'site_name' => 'Something Else', 'locale' => 'en_GB' ) );
$html                                = form();
ok( 1 === preg_match( '/name="identity_site_name" value="" placeholder="Something Else"/', $html ), 'a stored copy of core shows as an empty field over the placeholder, not as an override' );
ok( 1 === preg_match( '/name="identity_site_description"[^>]*placeholder="New tagline"[^>]*><\/textarea>/', $html ), 'the tagline too' );
ok( 1 === preg_match( '/name="identity_locale" value="" placeholder="en_GB"/', $html ), 'and the locale' );
ok( false !== strpos( $html, 'href="https://example.test/wp-admin/options-general.php"' ), 'the form links to Settings > General' );

echo "Core stores the name and tagline entity-encoded (sanitize_option runs esc_html)\n";
$GLOBALS['__options'] = array();
$GLOBALS['__core']    = array( 'name' => 'Rock &amp; Roll', 'description' => 'Tom&#039;s &quot;notes&quot;', 'locale' => 'en_US' );
ok( 'Rock & Roll' === read( 'site_name' ) && 'Tom\'s "notes"' === read( 'site_description' ), 'readers get the text, not the entities (the value goes into JSON-LD and og tags)' );
sn_settings_save( array( 'identity_site_name' => 'Rock & Roll', 'identity_site_description' => 'Tom\'s "notes"' ) );
ok( array() === array_intersect_key( stored_identity(), array( 'site_name' => 1, 'site_description' => 1 ) ), 'and the same text typed in the form is recognized as core\'s, so nothing is stored' );
ok( 1 === preg_match( '/name="identity_site_name" value="" placeholder="Rock &amp; Roll"/', form() ), 'the placeholder is escaped once' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
