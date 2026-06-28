<?php
/**
 * Standalone test: Performance sub-tab — open-and-wide shell (Phase 4b, v6.46.0).
 *
 * Performance is a single-toggle form. Per the open-wide design rule, a lone
 * short form earns full width only by ADDING a second column — never by being
 * bare-stretched. So it goes into the full-width two-column sn_admin_shell: the
 * toggle (primary control) in the MAIN column, a status/reference readout
 * (current state, prerender/moderate profile, exclusions, browser support) in
 * the narrower rail. This locks the shell structure + balanced divs + the
 * toggle staying in main (a11y: primary actions never get buried in the rail).
 *
 * Run: php tests/performance-admin.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function pf_assert( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $k, $d = '' ) { return $d; } }

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/admin-forms/performance.php';

ob_start();
sn_admin_render_performance_section();
$h = ob_get_clean();

echo "Group: render — full-width shell (toggle main + status readout rail)\n";
pf_assert( false !== strpos( $h, 'class="sn-shell"' ), 'uses the full-width two-column shell' );
pf_assert( 1 === substr_count( $h, 'sn-shell__main' ), 'exactly one main column' );
pf_assert( 1 === substr_count( $h, 'sn-shell__rail' ), 'exactly one readout rail' );
pf_assert( false !== strpos( $h, '</aside></div>' ), 'shell is closed (balanced divs)' );

// The toggle (primary control) lives in MAIN, before the rail opens.
$main_pos   = strpos( $h, 'sn-shell__main' );
$rail_pos   = strpos( $h, 'sn-shell__rail' );
$toggle_pos = strpos( $h, 'name="speculative_loading"' );
pf_assert(
	false !== $toggle_pos && false !== $main_pos && false !== $rail_pos && $toggle_pos > $main_pos && $toggle_pos < $rail_pos,
	'the toggle (primary control) sits in the main column, not the rail'
);
pf_assert( false !== strpos( $h, 'value="perf_save"' ), 'save action intact (perf_save)' );

// The rail readout describes the profile + exclusions.
$rail = false !== $rail_pos ? substr( $h, $rail_pos ) : '';
pf_assert( false !== stripos( $rail, 'prerender' ), 'rail readout names the prerender mode' );
pf_assert( false !== stripos( $rail, 'contact' ) || false !== stripos( $rail, 'excluded' ) || false !== stripos( $rail, 'login' ), 'rail readout lists what is excluded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
