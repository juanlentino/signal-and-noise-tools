<?php
/**
 * Standalone test: Front-End settings form — open-and-wide 2-up field layout (Phase 4b, v6.46.0).
 *
 * The Front-End leaf is a lone multi-field form. Like Identity (Phase 4a) it
 * earns full width by making its FIELDS the columns: the form gets a
 * .sn-front-end-form hook + a single real .sn-fieldset card (so it owns its
 * chrome once the leaf goes 'wide' and loses the wrapper card), and the CSS
 * grids the fields auto-fit. This locks (a) the render still emits the form +
 * all fields + the single save button, and (b) the new card/class structure.
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

require_once __DIR__ . '/../inc/admin-forms/front-end.php';

ob_start();
sn_admin_render_front_end_form();
$h = ob_get_clean();

echo "Group: render — full-width 2-up field form\n";
fe_assert( false !== strpos( $h, 'class="sn-front-end-form"' ), 'form carries the .sn-front-end-form class (the field-grid hook)' );
fe_assert( 1 === substr_count( $h, 'class="sn-fieldset"' ), 'form body wrapped in exactly one real .sn-fieldset card (owns chrome at full width)' );
fe_assert( false !== strpos( $h, 'name="sn_action" value="save_theme"' ), 'carries the save_theme action' );
fe_assert( false !== strpos( $h, 'name="theme_related_count"' ) && false !== strpos( $h, 'name="theme_ai_model"' ), 'first + last fields render (form body intact)' );

// Wide-leaf card-ownership: the save row is a card-owned .sn-fieldset-actions,
// NOT a bare .sn-savebar — whose negative card-bleed margin overflows the bare
// .sn-section (the v6.43.1 IndexNow regression). Matches Performance + IndexNow.
fe_assert( false === strpos( $h, 'class="sn-savebar"' ), 'no bare .sn-savebar (its negative bleed margin would overflow the wide .sn-section)' );
fe_assert( false !== strpos( $h, 'class="sn-fieldset-actions"' ), 'save row uses the card-owned .sn-fieldset-actions primitive' );

// Structure: the save row sits INSIDE the fieldset card (after it opens).
$fs_pos  = strpos( $h, 'class="sn-fieldset"' );
$act_pos = strpos( $h, 'class="sn-fieldset-actions"' );
fe_assert( false !== $fs_pos && false !== $act_pos && $act_pos > $fs_pos, 'the save row sits inside the fieldset card (after the card opens)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
