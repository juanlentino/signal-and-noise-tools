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
ok( $d['theme']['ai_model'] === 'claude-sonnet-5', 'defaults: ai_model claude-sonnet-5' );
ok( $d['theme']['notes_per_page'] === 20, 'defaults: notes_per_page 20' );
ok( $d['theme']['ai_monthly_budget'] === 0, 'defaults: ai_monthly_budget 0 (cap off)' );

// ── P2: AI-model allowlist + save handler (clamps + validation) ──────
require __DIR__ . '/../inc/admin-post-actions.php';

$models = sn_theme_ai_models();
ok( ! empty( $models ) && array_key_exists( 'claude-sonnet-5', $models ) && ! array_key_exists( 'claude-sonnet-4-6', $models ), 'ai models: non-empty + contains default (sonnet-5), drops the dominated sonnet-4-6' );
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
	'theme_notes_per_page'         => '500',
	'theme_ai_monthly_budget'      => '-5',
) );
ok( (int) sn_setting( 'theme.related_count' ) === 12, 'save: related_count clamps to max 12' );
ok( (int) sn_setting( 'theme.palette_recent_count' ) === 0, 'save: palette_recent_count clamps to min 0' );
ok( (int) sn_setting( 'theme.json_feed_items' ) === 1, 'save: json_feed_items clamps to min 1' );
ok( (int) sn_setting( 'theme.updated_threshold_days' ) === 90, 'save: updated_threshold clamps to max 90' );
ok( (int) sn_setting( 'theme.reading_wpm' ) === 100, 'save: reading_wpm clamps to min 100' );
// v10.46.0: the ai_* keys are saved by sn_handle_ai_settings_save() now — a
// save_theme POST carrying them must be INERT, not merely harmless. That is the
// whole point of the split; tests/admin-ai-settings-save.php covers the new
// handler's own clamping and allow-listing.
ok( sn_setting( 'theme.ai_model' ) === 'claude-sonnet-5', 'save_theme ignores a posted ai_model (handled by the AI form now)' );
ok( sn_setting( 'theme.palette_enabled' ) === true, 'save: palette_enabled true when checkbox present' );
ok( (int) sn_setting( 'theme.notes_per_page' ) === 100, 'save: notes_per_page clamps to max 100' );
ok( (float) sn_setting( 'theme.ai_monthly_budget' ) === 0.0, 'save_theme leaves ai_monthly_budget alone (still the seeded 0)' );

// Checkbox absent/empty → false. A posted ai_model stays ignored here.
sn_setting_update( 'theme.ai_model', 'claude-sonnet-5' );
sn_handle_save_theme( array(
	'theme_palette_enabled'   => '',
	'theme_ai_model'          => 'claude-opus-4-8',
	'theme_ai_monthly_budget' => '12.5',
) );
ok( sn_setting( 'theme.palette_enabled' ) === false, 'save: palette_enabled false when checkbox absent/empty' );
ok( sn_setting( 'theme.ai_model' ) === 'claude-sonnet-5', 'save_theme does NOT accept an on-list ai_model either — the key is no longer its business' );
ok( (float) sn_setting( 'theme.ai_monthly_budget' ) === 0.0, 'save_theme does not write ai_monthly_budget' );

// ── v7.3.0: vision (alt-text) model — curated allowlist. The list itself still
// lives here (it is theme settings data); the SAVE moved to the AI handler. ──
$vmodels = sn_theme_ai_vision_models();
ok( array_key_exists( 'gemini-2.5-flash-lite', $vmodels ), 'vision models: curated list carries the default pin' );
sn_setting_update( 'theme.ai_alt_model', 'gemini-2.5-flash' );
sn_handle_save_theme( array( 'theme_ai_alt_model' => 'evil-model' ) );
ok( sn_setting( 'theme.ai_alt_model' ) === 'gemini-2.5-flash', 'save_theme cannot park an off-list vision model (it does not touch the key at all)' );

// ── P4: front-end form renders without fatal + emits every field ─────
require __DIR__ . '/../inc/admin-forms/front-end.php';
ob_start();
sn_admin_render_front_end_form();
$form = ob_get_clean();
ok( strpos( $form, 'name="sn_action" value="save_theme"' ) !== false, 'form: posts the save_theme action' );
$field_names = array(
	'theme_related_count', 'theme_palette_recent_count', 'theme_palette_enabled',
	'theme_json_feed_items', 'theme_updated_threshold_days', 'theme_reading_wpm',
	'theme_notes_per_page',
);
$missing = array();
foreach ( $field_names as $fn ) {
	if ( strpos( $form, 'name="' . $fn . '"' ) === false ) {
		$missing[] = $fn;
	}
}
ok( empty( $missing ), 'form: emits all 7 render-knob inputs (' . ( $missing ? 'missing: ' . implode( ',', $missing ) : 'all present' ) . ')' );
// v10.46.0: the model selects moved to inc/admin-forms/ai-settings.php.
ok( strpos( $form, 'value="claude-opus-4-8"' ) === false, 'form: no model options remain in the render-knobs form' );

// ── P5: theme-filter callbacks (the cross-package contract) ──────────
require __DIR__ . '/../inc/theme-filters.php';

// Configured value flows through (sn_setting_update busts the static cache).
sn_setting_update( 'theme.related_count', 7 );
ok( (int) sn_tf_related_count( 3 ) === 7, 'filter: related_count returns configured 7' );

// Fallback to the theme-supplied default when unset (direct unset + cache reset).
unset( $GLOBALS['__options']['sn_settings']['theme']['related_count'] );
sn_setting_reset_cache();
ok( (int) sn_tf_related_count( 3 ) === 3, 'filter: related_count falls back to supplied default' );

// Defense-in-depth: a hand-edited out-of-range option is clamped on the way out.
$GLOBALS['__options']['sn_settings']['theme']['related_count'] = 99;
sn_setting_reset_cache();
ok( (int) sn_tf_related_count( 3 ) === 12, 'filter: related_count clamps a tampered value to max 12' );

// Each remaining numeric/bool callback honors its configured value.
sn_setting_update( 'theme.palette_recent_count', 4 );
ok( (int) sn_tf_palette_recent_count( 8 ) === 4, 'filter: palette_recent_count returns configured 4' );
sn_setting_update( 'theme.json_feed_items', 5 );
ok( (int) sn_tf_json_feed_items( 20 ) === 5, 'filter: json_feed_items returns configured 5' );
sn_setting_update( 'theme.updated_threshold_days', 30 );
ok( (int) sn_tf_updated_threshold( 14 ) === 30, 'filter: updated_threshold returns configured 30' );
sn_setting_update( 'theme.reading_wpm', 250 );
ok( (int) sn_tf_reading_wpm( 225 ) === 250, 'filter: reading_wpm returns configured 250' );

sn_setting_update( 'theme.notes_per_page', 12 );
ok( (int) sn_tf_notes_per_page( 20 ) === 12, 'filter: notes_per_page returns configured 12' );
unset( $GLOBALS['__options']['sn_settings']['theme']['notes_per_page'] );
sn_setting_reset_cache();
ok( (int) sn_tf_notes_per_page( 20 ) === 20, 'filter: notes_per_page falls back to supplied default' );
$GLOBALS['__options']['sn_settings']['theme']['notes_per_page'] = 999;
sn_setting_reset_cache();
ok( (int) sn_tf_notes_per_page( 20 ) === 100, 'filter: notes_per_page clamps a tampered value to max 100' );

sn_setting_update( 'theme.palette_enabled', false );
ok( sn_tf_palette_enabled( true ) === false, 'filter: palette_enabled returns configured false' );
unset( $GLOBALS['__options']['sn_settings']['theme']['palette_enabled'] );
sn_setting_reset_cache();
ok( sn_tf_palette_enabled( true ) === true, 'filter: palette_enabled falls back to supplied default true' );

// ai_model: configured allowlisted id flows; an off-list stored id falls back to $d.
sn_setting_update( 'theme.ai_model', 'claude-opus-4-8' );
ok( sn_tf_ai_model( 'claude-sonnet-4-6' ) === 'claude-opus-4-8', 'filter: ai_model returns configured id' );
$GLOBALS['__options']['sn_settings']['theme']['ai_model'] = 'off-list-tampered';
sn_setting_reset_cache();
ok( sn_tf_ai_model( 'claude-sonnet-4-6' ) === 'claude-sonnet-4-6', 'filter: ai_model rejects a tampered off-list id → supplied default' );

// v6.52.0: sn_tf_ai_model is now feature-aware (4-arg). The owner's text-model
// dropdown choice must NOT clobber the alt-text vision route (both hook
// snt_ai_model_preference @ pri 10, so order alone can't guarantee it). For the
// 'alt-text' feature the filter returns the incoming value unchanged regardless
// of theme.ai_model; every other feature still applies the configured id.
sn_setting_update( 'theme.ai_model', 'claude-opus-4-8' );
ok( sn_tf_ai_model( 'gemini-2.5-flash-lite', '', '', 'alt-text' ) === 'gemini-2.5-flash-lite', 'filter: alt-text feature passes the incoming model through unchanged (vision route not clobbered)' );
ok( sn_tf_ai_model( 'claude-sonnet-5', '', '', 'generic' ) === 'claude-opus-4-8', 'filter: non-alt-text feature still applies the configured model' );
ok( sn_tf_ai_model( 'claude-sonnet-5' ) === 'claude-opus-4-8', 'filter: legacy 1-arg call still applies the configured model (backward compatible)' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
