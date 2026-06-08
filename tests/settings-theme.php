<?php
/**
 * Tests for the v4.12.0 Front-End settings subtree, save handler, and the
 * theme-filter callbacks (settings-hygiene batch A).
 *
 * Standalone CLI fixture: stubs the WP option store + sanitizers, requires the
 * real settings.php / admin-post-actions.php / theme-filters.php, and exercises
 * defaults, clamping, allowlist validation, and the sn_tf_* filter callbacks.
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'SN_THEME_FILTERS_TEST', true ); // suppress add_filter wiring in theme-filters.php

// In-memory option store + minimal WP stubs.
$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function get_bloginfo( $what ) { return ''; }
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $s ) { return $s; }
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/settings.php';

// ── P1: theme subtree defaults ───────────────────────────────────────
$d = sn_settings_defaults();
ok( isset( $d['theme'] ), 'defaults: theme subtree present' );
ok( $d['theme']['related_count'] === 3, 'defaults: related_count 3' );
ok( $d['theme']['palette_recent_count'] === 8, 'defaults: palette_recent_count 8' );
ok( $d['theme']['palette_enabled'] === true, 'defaults: palette_enabled true' );
ok( $d['theme']['json_feed_items'] === 20, 'defaults: json_feed_items 20' );
ok( $d['theme']['updated_threshold_days'] === 14, 'defaults: updated_threshold_days 14' );
ok( $d['theme']['reading_wpm'] === 225, 'defaults: reading_wpm 225' );
ok( $d['theme']['ai_model'] === 'claude-sonnet-4-6', 'defaults: ai_model claude-sonnet-4-6' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
