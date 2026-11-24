<?php
/**
 * Standalone test: the front-end settings form, open and wide (Phase 4b,
 * v6.46.0; three headed boxes since 2026-11, on Settings > Front end).
 *
 * The form earns full width with a .sn-front-end-form hook and real
 * .sn-fieldset cards of its own (the pane is 'wide' and has no wrapper card):
 * one row of three boxes by subject, each gridding its fields auto-fit, then
 * the one save row. This locks (a) the render still emits the ONE form + all
 * fields + the single save button, and (b) the card/class structure.
 *
 * Run: php tests/front-end-form.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function fe_assert( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $k, $d = '' ) { return $d; } }
if ( ! function_exists( 'sn_theme_ai_models' ) ) { function sn_theme_ai_models() { return array( 'claude-sonnet-5' => 'Claude Sonnet 5' ); } }
if ( ! function_exists( 'sn_theme_ai_vision_models' ) ) { function sn_theme_ai_vision_models() { return array( 'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite' ); } }

require_once __DIR__ . '/lib/admin-post-url-stub.php';
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); } }
require_once __DIR__ . '/../inc/admin-forms/front-end.php';

ob_start();
sn_admin_render_front_end_form();
$h = ob_get_clean();

echo "Group: render, full-width form of three boxes\n";
fe_assert( false !== strpos( $h, 'class="sn-front-end-form"' ), 'form carries the .sn-front-end-form class (the field-grid hook)' );
fe_assert( 3 === substr_count( $h, 'class="sn-fieldset"' ) && 1 === substr_count( $h, '<div class="sn-2up">' ), 'the form body is one row of exactly three real .sn-fieldset cards (it owns its chrome at full width)' );
fe_assert( 1 === substr_count( $h, '<form' ) && 1 === substr_count( $h, 'type="submit"' ) && '</form>' === substr( $h, -7 ), 'ONE form and ONE save button: the handler writes all eight fields on every save, so the boxes are headings inside it' );
fe_assert( substr_count( $h, '<div' ) === substr_count( $h, '</div>' ), 'every div is closed' );
fe_assert( false !== strpos( $h, 'name="action" value="sn_save_theme"' ), 'carries the save_theme action' );
fe_assert( false !== strpos( $h, 'name="theme_related_count"' ) && false !== strpos( $h, 'name="theme_note_reply_alias"' ), 'first + last fields render (form body intact)' );
fe_assert( false === strpos( $h, 'name="theme_notes_per_page"' ) && false === strpos( $h, 'name="theme_json_feed_items"' ) && false !== strpos( $h, 'options-reading.php' ), 'Notes per page and JSON feed items left the form; a link to Settings › Reading took their place' );
// v10.46.0: the three AI settings left this form for the AI tab. Asserting their
// ABSENCE matters as much as the old presence checks did — a stray input left
// behind here would post ai_* keys at save_theme, which no longer reads them, so
// the user's edit would vanish with a success notice.
fe_assert( false === strpos( $h, 'name="theme_ai_model"' ), 'the prose-model select is GONE from the render-knobs form' );
fe_assert( false === strpos( $h, 'name="theme_ai_alt_model"' ), 'the vision-model select is gone too' );
fe_assert( false === strpos( $h, 'name="theme_ai_monthly_budget"' ), 'the monthly budget is gone (it was never a render knob)' );

// Wide pane: the save row is a .sn-fieldset-actions, NOT a bare .sn-savebar,
// whose negative card-bleed margin overflows the bare .sn-section (the
// v6.43.1 IndexNow regression).
fe_assert( false === strpos( $h, 'class="sn-savebar"' ), 'no bare .sn-savebar (its negative bleed margin would overflow the wide .sn-section)' );
fe_assert( false !== strpos( $h, 'class="sn-fieldset-actions"' ), 'save row uses the .sn-fieldset-actions primitive' );

// Structure: the save row follows the row of boxes, inside the form, so it
// reads as the Save for all three and not for the last one.
$row_end = strpos( $h, '</div></div><div class="sn-fieldset-actions">' );
fe_assert( false !== $row_end && $row_end > strrpos( $h, 'class="sn-fieldset"' ), 'the save row sits after the three boxes close, inside the form' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
