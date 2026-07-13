<?php
/**
 * Regression test: sn_settings_save() must wp_unslash() the $_POST payload
 * before sanitizing, or apostrophes gain one addslashes layer PER SAVE.
 *
 * WP core slashes all of $_POST (wp_magic_quotes()), and update_option() —
 * unlike update_post_meta() — does NOT unslash on write. sn_settings_save()
 * stored the slashed value verbatim, so "what's" became "what\'s"; the next
 * Identity-tab save round-tripped that through the textarea and re-slashed it
 * to "what\\\'s" (addslashes doubles every existing backslash: n → 2n+1),
 * growing exponentially until the /provenance og:description read
 * "what\\\\\\\\…\\'s human" in LinkedIn link previews.
 *
 * This fixture simulates wp_magic_quotes() on the way in (addslashes_deep)
 * and pins two behaviors:
 *   1. a single save stores the UNSLASHED text;
 *   2. repeated save round-trips (form re-echoes stored value, WP re-slashes
 *      it) are stable — no backslash accumulation.
 *
 * @since plugin v9.36.1
 */

// SECURITY: CLI / WP-CLI only (mirrors sibling fixtures).
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
    return $what === 'name' ? 'TestSite' : '';
}
// Sanitizers used by sn_settings_save() — identity transforms only.
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
// Real behavior (stripslashes_deep), not an identity stub — the whole point
// of this fixture is the slash round-trip.
function wp_unslash( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'wp_unslash', $value );
    }
    return is_string( $value ) ? stripslashes( $value ) : $value;
}
// Mirror of wp_magic_quotes(): what PHP/WP does to $_POST before any handler
// sees it. Applied to each simulated form submission below.
function test_slash_deep( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'test_slash_deep', $value );
    }
    return is_string( $value ) ? addslashes( $value ) : $value;
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
        echo "FAIL: $label — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
    }
}

$provenance = "A short read on why the industry needs to prove what's human, not chase what isn't.";
$tagline    = "Music Production & Creative Strategy — it's intentional";

// 1. First save: browser posts clean text, WP slashes it, handler receives
//    the slashed payload (sn_handle_save_identity passes $_POST verbatim).
sn_settings_save( test_slash_deep( array(
    'identity_site_name'           => 'Juan Lentino',
    'identity_site_description'    => $tagline,
    'seo_provenance_description'   => $provenance,
) ) );
sn_setting_reset_cache();

assertEq( $provenance, sn_setting( 'seo_copy.provenance_description', '' ), 'apostrophe stored unslashed after one save' );
assertEq( $tagline, sn_setting( 'identity.site_description', '' ), 'identity tagline stored unslashed after one save' );

// 2. Save the form 5 more times WITHOUT touching the fields: each submission
//    posts exactly what the textarea re-echoed (the stored value), and WP
//    slashes it again on the way in. Stored value must stay byte-identical.
for ( $i = 0; $i < 5; $i++ ) {
    $echoed = array(
        'identity_site_name'         => sn_setting( 'identity.site_name', '' ),
        'identity_site_description'  => sn_setting( 'identity.site_description', '' ),
        'seo_provenance_description' => sn_setting( 'seo_copy.provenance_description', '' ),
    );
    sn_settings_save( test_slash_deep( $echoed ) );
    sn_setting_reset_cache();
}

assertEq( $provenance, sn_setting( 'seo_copy.provenance_description', '' ), 'no backslash accumulation across 5 re-saves (2n+1 growth bug)' );
assertEq( $tagline, sn_setting( 'identity.site_description', '' ), 'identity tagline stable across 5 re-saves' );
assertEq( false, strpos( (string) sn_setting( 'seo_copy.provenance_description', '' ), '\\' ), 'stored provenance description contains no backslashes at all' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
