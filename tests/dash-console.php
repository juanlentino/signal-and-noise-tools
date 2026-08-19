<?php
/**
 * Tests: the Dashboard console (direction B with C's band).
 *
 * The property that matters is DENSITY AT REST. v11.28.0 collapsed to three
 * grey lines when the site was healthy; a console shows every row at all times
 * and lets alarms assert themselves over that density. Nothing here collapses,
 * and the tests say so.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a, $n = '_wpnonce', $r = true ) { echo '<input type="hidden" name="' . $n . '" value="x">'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u . '&' . $n . '=x'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-briefing.php';
require __DIR__ . '/../inc/dash-zone-measurement.php';
require __DIR__ . '/../inc/dash-console.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function render( $checks, $components, $meas, $brief ) {
	ob_start(); sn_dash_render_console( $checks, $components, $meas, $brief ); return ob_get_clean();
}
echo "dashboard console\n\n";

$checks = array(
	array( 'label' => 'Health', 'value' => '0 findings', 'href' => '/wp-admin/h', 'pill' => array( 'kind' => 'ok', 'text' => 'clear' ) ),
	array( 'label' => 'Cron',   'value' => '62 events',  'pill' => array( 'kind' => 'ok', 'text' => 'healthy' ) ),
	array( 'label' => 'Caches', 'value' => '1/3',        'pill' => array( 'kind' => 'warn', 'text' => 'stale' ) ),
);
$components = array(
	array( 'label' => 'Theme',      'value' => '11.12.0', 'measured' => true, 'attention' => false ),
	array( 'label' => 'Remote MCP', 'value' => 'warming…', 'measured' => true, 'attention' => false ),
);
$meas  = array( 'views_7d' => 128, 'views_delta' => 25, 'anchored' => 33, 'citations' => 0 );
$brief = array( 'needy' => 0, 'views' => 128, 'views_delta' => 25, 'anchored' => 33, 'citations' => 0 );

$h = render( $checks, $components, $meas, $brief );

// ── the composition ─────────────────────────────────────────────────────────
ok( false !== strpos( $h, 'sn-dash-briefing' ), 'the band opens the page' );
ok( false !== strpos( $h, 'sn-console' ),       'the console wrapper renders' );
ok( false !== strpos( $h, 'sn-rail' ),          'the rail renders' );
ok( false !== strpos( $h, 'sn-stage' ),         'the stage renders' );
ok( strpos( $h, 'sn-dash-briefing' ) < strpos( $h, 'sn-console' ), 'THE BAND LEADS — it is the backstop and cannot be scrolled past' );
ok( strpos( $h, 'sn-rail' ) < strpos( $h, 'sn-stage' ), 'the rail precedes the stage in source order, so it reads first without CSS' );

// ── DENSITY AT REST. The old design collapsed to three lines when healthy. ──
ok( false !== strpos( $h, 'Health' ) && false !== strpos( $h, 'Cron' ) && false !== strpos( $h, 'Caches' ),
	'EVERY CHECK IS VISIBLE WITH THE SITE HEALTHY — nothing collapses' );
ok( false !== strpos( $h, 'Theme' ) && false !== strpos( $h, 'Remote MCP' ), 'every component is visible too' );
ok( false === strpos( $h, '<details' ), 'and nothing on the console is a <details> the reader must open' );
ok( 5 === substr_count( $h, '<li class="sn-rail__row">' ), 'one rail row per check AND per component — five in, five out' );

// A card that opted out of promotion must not read as an alarm in the rail
// either — the same v11.16.0 predicate the sort and the zone state honour.
$cold = array( 'label' => 'Edge', 'value' => '—', 'pill' => array( 'kind' => 'warn', 'text' => 'warming' ), 'attention' => false );
$h2   = render( array( $cold ), array(), $meas, $brief );
ok( false === strpos( $h2, 'sn-rail__dot--warn' ), 'A COLD PROBE DOES NOT PAINT AN ALARM DOT IN THE RAIL' );
ok( false !== strpos( $h2, 'sn-rail__dot--muted' ), 'it renders muted instead' );

// A real warning still does.
ok( false !== strpos( $h, 'sn-rail__dot--warn' ), 'but a genuine warn card does paint one' );

// Links go to the tab that owns the number; a card without one is not a link.
ok( false !== strpos( $h, '<a class="sn-rail__label" href="/wp-admin/h">' ), 'a card with an href links to its owning tab' );
ok( false !== strpos( $h, '<span class="sn-rail__label">Cron</span>' ), 'one without an href does not' );

// Escaping on both halves of a row.
$evil = array( array( 'label' => '<script>a</script>', 'value' => '<script>b</script>' ) );
ok( false === strpos( render( $evil, array(), $meas, $brief ), '<script>a' ), 'rail label and value are escaped' );

// Empty sections emit no heading rather than an empty list.
$h3 = render( array(), array(), $meas, $brief );
ok( false === strpos( $h3, 'Systems</h2>' ), 'no checks means no Systems heading' );
ok( false === strpos( $h3, 'Fleet</h2>' ),   'no components means no Fleet heading' );
ok( false !== strpos( $h3, 'sn-stage' ),     'but the stage still renders' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
