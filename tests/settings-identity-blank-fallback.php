<?php
/**
 * Regression test: a blank Identity job_title / knows_about save must not
 * permanently poison the sn_setting() fallback default.
 *
 * sn_settings_save() always wrote the 'job_title' and 'knows_about' keys,
 * even when blank/empty. sn_setting() merges stored options over defaults
 * with array_replace_recursive(), which keeps a present-but-empty key
 * rather than falling through to the caller's default — so
 * sn_setting('identity.job_title', 'Music Producer') returned '' forever
 * after one accidental blank save (inc/seo-schema.php:74-83 then emits
 * "jobTitle": "" / "knowsAbout": [] into the Person schema).
 *
 * Fix: sn_settings_save() omits these two keys from the identity subtree
 * when blank, so sn_setting()'s array_key_exists check falls through.
 *
 * @since plugin (fix for #1224)
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function get_bloginfo( $what ) {
	return 'name' === $what ? 'TestSite' : '';
}
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

require __DIR__ . '/../inc/settings.php';

$pass = 0;
$fail = 0;
function assertEq( $expected, $actual, $label ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

// Save a real job_title once — establishes it is honored when present.
sn_settings_save( array( 'identity_job_title' => 'Sound Designer' ) );
sn_setting_reset_cache();
assertEq( 'Sound Designer', sn_setting( 'identity.job_title', 'Music Producer' ), 'a real job_title is stored and read back' );

// A subsequent blank save (e.g. an empty form field) must NOT permanently
// blank the field out from under the caller's fallback default.
sn_settings_save( array( 'identity_job_title' => '' ) );
sn_setting_reset_cache();
assertEq( 'Music Producer', sn_setting( 'identity.job_title', 'Music Producer' ), 'a blank job_title save falls through to the caller default, not stored empty' );

// Same for knows_about (textarea → array); an empty textarea must not lock
// in an empty array over the caller's default topic list.
sn_settings_save( array( 'identity_knows_about' => "Topic One\nTopic Two" ) );
sn_setting_reset_cache();
assertEq( array( 'Topic One', 'Topic Two' ), sn_setting( 'identity.knows_about', array( 'Default' ) ), 'a real knows_about list is stored and read back' );

sn_settings_save( array( 'identity_knows_about' => '' ) );
sn_setting_reset_cache();
assertEq( array( 'Default' ), sn_setting( 'identity.knows_about', array( 'Default' ) ), 'a blank knows_about save falls through to the caller default, not stored empty' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
