<?php
/**
 * Primitive-contract tests (v9.40.0 D4): the ONE delta badge, the ONE KPI row,
 * the ONE config/dormant gate. Run: php tests/analytics-primitives.php
 * @since plugin v9.40.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }

require __DIR__ . '/../inc/analytics-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "Group: snt_an_delta_badge — kpi variant (byte-parity with the old renderer)\n";
$h = cap( function () { snt_an_delta_badge( array( 'pct' => 12, 'dir' => 'up', 'previous' => 1000 ), array( 'variant' => 'kpi' ) ); } );
ok( false !== strpos( $h, 'sn-kpi-delta sn-delta-up' ) && false !== strpos( $h, '+12%' ), 'kpi up: class + signed pct' );
ok( false !== strpos( $h, 'title="previous period: 1,000"' ), 'kpi: prior-period tooltip' );
$h = cap( function () { snt_an_delta_badge( array( 'pct' => 12, 'dir' => 'up', 'previous' => 1000 ), array( 'variant' => 'kpi', 'basis_label' => 'same period last year' ) ); } );
ok( false !== strpos( $h, 'title="same period last year: 1,000"' ), 'kpi: basis label rides the tooltip' );
$h = cap( function () { snt_an_delta_badge( array( 'pct' => null, 'dir' => 'up' ), array( 'variant' => 'kpi' ) ); } );
ok( false !== strpos( $h, '>new<' ) || false !== strpos( $h, ' new</span>' ), 'kpi: null pct + up = "new"' );
ok( '' === cap( function () { snt_an_delta_badge( null, array( 'variant' => 'kpi' ) ); } ), 'kpi: null delta = silent no-op' );
ok( '' === cap( function () { snt_an_delta_badge( array( 'pct' => 5 ), array( 'variant' => 'kpi' ) ); } ), 'kpi: missing dir = silent no-op' );

echo "\nGroup: snt_an_delta_badge — inline variant\n";
$h = cap( function () { snt_an_delta_badge( array( 'pct' => -8, 'dir' => 'down' ) ); } );
ok( false !== strpos( $h, 'sn-an-delta sn-an-delta--down' ) && false !== strpos( $h, '-8%' ), 'inline down: legacy classes + signed pct (default variant)' );

echo "\nGroup: snt_an_kpi_row — the ONE card loop\n";
$cards = array(
	array( 'l' => 'Views', 'n' => '1,234', 'delta' => array( 'pct' => 5, 'dir' => 'up' ), 'promoted' => true ),
	array( 'l' => 'Now', 'n' => '3', 'live' => true ),
	array( 'l' => 'Bounce', 'n' => '40%', 'sub' => 'of 120 visits' ),
	array( 'l' => 'Cache hit', 'n' => '98%' ),
);
$h = cap( function () use ( $cards ) { snt_an_kpi_row( $cards ); } );
ok( 1 === substr_count( $h, 'sn-kpi-promoted' ), 'promoted flag renders once' );
ok( false !== strpos( $h, 'sn-delta-up' ) && false !== strpos( $h, '+5%' ), 'delta card routes through the badge' );
ok( false !== strpos( $h, '>live<' ), 'live card renders the live slot' );
ok( false !== strpos( $h, 'sn-delta-flat">of 120 visits' ), 'sub card renders flat descriptor' );
ok( false !== strpos( $h, 'no change' ), 'bare card defaults to "no change"' );
$h = cap( function () use ( $cards ) { snt_an_kpi_row( $cards, array( 'empty_slot' => 'omit', 'row_class' => 'sn-kpi-row--edge' ) ); } );
ok( false === strpos( $h, 'no change' ), 'empty_slot=omit suppresses the default slot (edge idiom)' );
ok( false !== strpos( $h, 'sn-kpi-row sn-kpi-row--edge' ), 'row_class rides the wrapper' );
$h = cap( function () { snt_an_kpi_row( array( array( 'n' => 'orphan' ), 'not-an-array' ) ); } );
ok( false !== strpos( $h, 'sn-kpi-row' ) && false === strpos( $h, 'orphan-label' ), 'malformed cards degrade silently (no notice)' );

echo "\nGroup: snt_an_gate — the ONE config/dormant gate\n";
$h = cap( function () { snt_an_gate( 'Edge', 'Not configured yet.', 'Configure →', 'https://x/wp-admin/admin.php?page=sn-theme-options' ); } );
ok( false !== strpos( $h, 'postbox' ) && false !== strpos( $h, 'sn-an-gate' ), 'gate renders panel chrome + marker class' );
ok( false !== strpos( $h, 'Not configured yet.' ) && false !== strpos( $h, 'Configure →' ), 'message + CTA' );
$h = cap( function () { snt_an_gate( 'Posts', 'No published posts yet.' ); } );
ok( false === strpos( $h, '<a ' ), 'no CTA when label/url absent' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
