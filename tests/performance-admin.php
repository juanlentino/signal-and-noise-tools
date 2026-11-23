<?php
/**
 * Standalone test: Site > Performance, the classic leaf: open-and-wide shell
 * (Phase 4b, v6.46.0).
 *
 * The leaf is readings since 2026-11: the speculative loading switch is on
 * Settings > Front end. Per the open-wide design rule a short leaf earns full
 * width only by pairing two columns, never by being bare-stretched, so it
 * keeps the two-column sn_admin_shell: the state, what it means and where the
 * switch went in the MAIN column, the Profile reference (the mode in effect,
 * exclusions, browser support) in the narrower rail. This locks the shell
 * structure, balanced divs, that no form is left, and that neither state
 * reads as a warning.
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
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
$GLOBALS['__perf_on'] = true;
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $k, $d = '' ) { return 'perf.speculative_loading' === $k ? $GLOBALS['__perf_on'] : $d; } }

require_once __DIR__ . '/lib/admin-post-url-stub.php';
require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/admin-forms/performance.php';
require_once __DIR__ . '/../inc/admin-forms/settings-front-end.php';

ob_start();
sn_admin_render_performance_section();
$h = ob_get_clean();

echo "Group: render, full-width shell (state main + profile rail)\n";
pf_assert( false !== strpos( $h, 'class="sn-shell"' ), 'uses the full-width two-column shell' );
pf_assert( 1 === substr_count( $h, 'sn-shell__main' ), 'exactly one main column' );
pf_assert( 1 === substr_count( $h, 'sn-shell__rail' ), 'exactly one readout rail' );
pf_assert( false !== strpos( $h, '</aside></div>' ), 'shell is closed (balanced divs)' );
pf_assert( substr_count( $h, '<div' ) === substr_count( $h, '</div>' ), 'every div is closed' );

// The switch left: no form, no field, no way to post perf_save from here.
pf_assert( false === strpos( $h, '<form' ) && false === strpos( $h, 'name="speculative_loading"' ) && false === strpos( $h, 'perf_save' ), 'no form, no switch and no perf_save: the switch is on Settings > Front end' );

// The state and the pointer live in MAIN, before the rail opens.
$main_pos  = strpos( $h, 'sn-shell__main' );
$rail_pos  = strpos( $h, 'sn-shell__rail' );
$state_pos = strpos( $h, 'Prerender is on.' );
$moved_pos = strpos( $h, 'The prerender switch moved to <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=front-end">' );
pf_assert( false !== $state_pos && $state_pos > $main_pos && $state_pos < $rail_pos, 'the state sits in the main column, not the rail' );
pf_assert( false !== $moved_pos && $moved_pos > $state_pos && $moved_pos < $rail_pos && 1 === substr_count( $h, 'moved to' ), 'one line under it links to the pane the switch moved to' );

// The rail readout describes the profile + exclusions.
$rail = false !== $rail_pos ? substr( $h, $rail_pos ) : '';
pf_assert( false !== stripos( $rail, 'prerender' ), 'on: rail readout names the prerender mode' );
pf_assert( false !== stripos( $rail, 'contact' ), 'rail readout lists what is excluded' );

// Off is WordPress's own default, not nothing: no warning box, and the rail
// names what is served instead.
$GLOBALS['__perf_on'] = false;
ob_start();
sn_admin_render_performance_section();
$off = ob_get_clean();
pf_assert( false !== strpos( $off, 'Prerender is off; WordPress’s own default applies.' ) && false === strpos( $off, 'sn-status-box--warn' ) && false !== strpos( $off, '<span class="sn-pill">Off</span>' ), 'off: says WordPress\'s default applies, with a plain pill and no warn box' );
pf_assert( false !== strpos( $off, '<code>prefetch</code>' ) && false === strpos( $off, '<code>prerender</code>' ) && false !== strpos( $off, '<code>/contact/*</code>' ), 'off: the rail names prefetch, not prerender, and keeps the exclusion' );
pf_assert( false === stripos( $h . $off, 'disables speculative loading' ) && false === stripos( $h . $off, 'no speculation rules' ), 'neither state says off means no speculation rules' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
