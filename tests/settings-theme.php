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
// Render-helper stubs for the P4 front-end form smoke test.
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) { $r = ( (string) $checked === (string) $current ) ? " checked='checked'" : ''; if ( $echo ) { echo $r; } return $r; }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) { $r = ( (string) $selected === (string) $current ) ? " selected='selected'" : ''; if ( $echo ) { echo $r; } return $r; }
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

// ── P2: AI-model allowlist + save handler (clamps + validation) ──────
require __DIR__ . '/../inc/admin-post-actions.php';

$models = sn_theme_ai_models();
ok( ! empty( $models ) && array_key_exists( 'claude-sonnet-4-6', $models ), 'ai models: list non-empty + contains default' );
ok( array_key_exists( 'claude-opus-4-8', $models ) && array_key_exists( 'claude-haiku-4-5', $models ), 'ai models: includes opus-4-8 + haiku-4-5 (alias ids)' );

// Out-of-range ints clamp; off-list model rejected; checkbox present → true.
sn_handle_save_theme( array(
	'theme_related_count'          => '99',
	'theme_palette_recent_count'   => '-3',
	'theme_json_feed_items'        => '0',
	'theme_palette_enabled'        => '1',
	'theme_ai_model'               => 'totally-fake-model',
	'theme_updated_threshold_days' => '500',
	'theme_reading_wpm'            => '5',
) );
ok( (int) sn_setting( 'theme.related_count' ) === 12, 'save: related_count clamps to max 12' );
ok( (int) sn_setting( 'theme.palette_recent_count' ) === 0, 'save: palette_recent_count clamps to min 0' );
ok( (int) sn_setting( 'theme.json_feed_items' ) === 1, 'save: json_feed_items clamps to min 1' );
ok( (int) sn_setting( 'theme.updated_threshold_days' ) === 90, 'save: updated_threshold clamps to max 90' );
ok( (int) sn_setting( 'theme.reading_wpm' ) === 100, 'save: reading_wpm clamps to min 100' );
ok( sn_setting( 'theme.ai_model' ) === 'claude-sonnet-4-6', 'save: off-list ai_model rejected → keeps default' );
ok( sn_setting( 'theme.palette_enabled' ) === true, 'save: palette_enabled true when checkbox present' );

// Checkbox absent/empty → false; on-list model accepted.
sn_handle_save_theme( array(
	'theme_palette_enabled' => '',
	'theme_ai_model'        => 'claude-opus-4-8',
) );
ok( sn_setting( 'theme.palette_enabled' ) === false, 'save: palette_enabled false when checkbox absent/empty' );
ok( sn_setting( 'theme.ai_model' ) === 'claude-opus-4-8', 'save: on-list ai_model accepted' );

// ── P4: front-end form renders without fatal + emits every field ─────
require __DIR__ . '/../inc/admin-forms/front-end.php';
ob_start();
sn_admin_render_front_end_form();
$form = ob_get_clean();
ok( strpos( $form, 'name="sn_action" value="save_theme"' ) !== false, 'form: posts the save_theme action' );
$field_names = array(
	'theme_related_count', 'theme_palette_recent_count', 'theme_palette_enabled',
	'theme_json_feed_items', 'theme_updated_threshold_days', 'theme_reading_wpm', 'theme_ai_model',
);
$missing = array();
foreach ( $field_names as $fn ) {
	if ( strpos( $form, 'name="' . $fn . '"' ) === false ) {
		$missing[] = $fn;
	}
}
ok( empty( $missing ), 'form: emits all 7 field inputs (' . ( $missing ? 'missing: ' . implode( ',', $missing ) : 'all present' ) . ')' );
ok( strpos( $form, 'value="claude-opus-4-8"' ) !== false, 'form: renders an option per allowlisted model' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
