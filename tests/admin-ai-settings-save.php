<?php
/**
 * Handler tests for the v10.46.0 AI-settings split.
 *
 * When one settings form becomes two, the failure to fear is a clobber: form A
 * saves and silently blanks the keys only form B posts. This suite pins the
 * opposite — sn_handle_save_theme() and sn_handle_ai_settings_save() are
 * DISJOINT writers over the same `theme.` namespace, so a front-end save can
 * never zero the AI budget and an AI save can never reset the render knobs.
 *
 * Also pins the allow-list behaviour that moved with the handler: an off-list
 * model id KEEPS the stored value rather than parking an unknown id.
 *
 * Stub-and-require harness copied from tests/analytics-tuning-save.php.
 *
 * Run: php tests/admin-ai-settings-save.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['__options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['__options'][ $n ] ); return true; }
function get_bloginfo( $w ) { return ''; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }

// sn_setting store backed by a plain map.
//
// STUB FIDELITY (the repo's #1 trap): the real sn_setting_update()
// (inc/settings.php) returns whether the write PERSISTED — it re-reads the
// option and returns `$cursor === $value` — NOT whether the value changed. The
// handlers &= those returns, so their `*_saved` flash means "every write landed"
// and `*_unchanged` means "one did not". A stub returning a changed-flag would
// invert that and make every assertion below assert fiction. Matches the stub in
// tests/analytics-tuning-save.php.
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}
function sn_setting_update( $path, $value ) { $GLOBALS['__settings'][ $path ] = $value; return true; }
// sn_theme_ai_models() / sn_theme_ai_vision_models() are NOT stubbed: they live
// in the module under test, so the allow-list assertions below run against the
// real shipped model ids rather than a fixture that could drift from them.

require __DIR__ . '/../inc/admin-post-actions.php';
require __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: dispatch map\n";
$map = sn_admin_post_handlers();
ok( isset( $map['ai_settings_save'] ) && 'sn_handle_ai_settings_save' === $map['ai_settings_save'],
	'ai_settings_save routed to its handler' );
ok( isset( $map['save_theme'] ) && 'sn_handle_save_theme' === $map['save_theme'],
	'save_theme still routed (the split did not replace it)' );

echo "Group: the clobber that this split could have caused\n";
$GLOBALS['__settings'] = array(
	'theme.related_count'     => 7,
	'theme.reading_wpm'       => 300,
	'theme.notes_per_page'    => 40,
	'theme.ai_model'          => 'claude-opus-4-8',
	'theme.ai_alt_model'      => 'claude-sonnet-5',
	'theme.ai_monthly_budget' => 25.00,
);
// A front-end save posts NO ai_* fields — exactly what the split form sends.
sn_handle_save_theme( array(
	'theme_related_count'  => 7,
	'theme_reading_wpm'    => 300,
	'theme_notes_per_page' => 40,
) );
ok( 'claude-opus-4-8' === sn_setting( 'theme.ai_model' ), 'front-end save leaves theme.ai_model intact' );
ok( 'claude-sonnet-5' === sn_setting( 'theme.ai_alt_model' ), 'front-end save leaves theme.ai_alt_model intact' );
ok( 25.00 === sn_setting( 'theme.ai_monthly_budget' ),
	'front-end save leaves theme.ai_monthly_budget intact (a leftover read here would zero the cap on every save)' );

// An AI save posts NO render knobs.
sn_handle_ai_settings_save( array(
	'theme_ai_model'          => 'claude-sonnet-5',
	'theme_ai_alt_model'      => 'gemini-2.5-flash-lite',
	'theme_ai_monthly_budget' => 40,
) );
ok( 7 === sn_setting( 'theme.related_count' ), 'AI save leaves theme.related_count intact' );
ok( 300 === sn_setting( 'theme.reading_wpm' ), 'AI save leaves theme.reading_wpm intact' );
ok( 40 === sn_setting( 'theme.notes_per_page' ), 'AI save leaves theme.notes_per_page intact' );
ok( 'claude-sonnet-5' === sn_setting( 'theme.ai_model' ), 'AI save writes the posted prose model' );
ok( 40.0 === sn_setting( 'theme.ai_monthly_budget' ), 'AI save writes the posted budget' );

echo "Group: allow-list (validation, not sanitization)\n";
$GLOBALS['__settings']['theme.ai_model']     = 'claude-opus-4-8';
$GLOBALS['__settings']['theme.ai_alt_model'] = 'gemini-2.5-flash-lite';
sn_handle_ai_settings_save( array(
	'theme_ai_model'          => 'gpt-fictional-9',
	'theme_ai_alt_model'      => '../../etc/passwd',
	'theme_ai_monthly_budget' => 10,
) );
ok( 'claude-opus-4-8' === sn_setting( 'theme.ai_model' ), 'off-list prose model id keeps the stored value' );
ok( 'gemini-2.5-flash-lite' === sn_setting( 'theme.ai_alt_model' ), 'off-list vision model id keeps the stored value' );

echo "Group: budget clamping\n";
sn_handle_ai_settings_save( array( 'theme_ai_monthly_budget' => -50 ) );
ok( 0.0 === sn_setting( 'theme.ai_monthly_budget' ), 'a negative budget clamps to 0 (no cap), never a negative ceiling' );
sn_handle_ai_settings_save( array( 'theme_ai_monthly_budget' => '12.345' ) );
ok( 12.35 === sn_setting( 'theme.ai_monthly_budget' ), 'budget rounds to cents' );
sn_handle_ai_settings_save( array( 'theme_ai_model' => 'claude-sonnet-5' ) );
ok( 0.0 === sn_setting( 'theme.ai_monthly_budget' ),
	'THIS handler reads an absent budget as 0 — which is precisely why sn_handle_save_theme must no longer read it' );

echo "Group: flash codes are the AI form's own\n";
// The point of a separate code pair: after saving on the AI tab the user must
// not read "Front-end settings saved." Both codes are registered in
// inc/admin-flash-messages.php.
$GLOBALS['__settings'] = array();
$code = sn_handle_ai_settings_save( array( 'theme_ai_model' => 'claude-opus-4-8', 'theme_ai_alt_model' => 'gemini-2.5-flash-lite', 'theme_ai_monthly_budget' => 5 ) );
ok( 'ai_settings_saved' === $code, 'a successful AI save reports ai_settings_saved, not theme_saved' );
ok( in_array( $code, array( 'ai_settings_saved', 'ai_settings_unchanged' ), true ),
	'the AI handler only ever emits its own two flash codes' );
$code = sn_handle_save_theme( array( 'theme_related_count' => 3 ) );
ok( 'theme_saved' === $code, 'the front-end handler still reports theme_saved (unchanged contract)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
