<?php
/**
 * Contract tests for snt_an_range_pills() (inc/analytics-panels.php) — the range-
 * pill primitive extracted from login-defense's hand-rolled control clone
 * (inc/login-defense-analytics.php, the last hand-rolled control after D5).
 *
 * Two halves:
 *  (a) Parity proof: drives the REAL sn_login_defense_render_header() and pins
 *      the exact pill-row substring captured from origin/main @ 7f45b51
 *      (v9.42.1), BEFORE the extraction. This assertion must keep passing
 *      byte-identical after login-defense adopts the primitive.
 *  (b) Primitive contract: snt_an_range_pills( $param, $allowed, $active_value,
 *      $opts ) in isolation — active/inactive pill markup, href wiring through
 *      esc_url(add_query_arg()), label escaping, the role="group" + aria-label
 *      wrapper, custom label overrides, and i18n wrapping (the recording
 *      __-family stub idiom from tests/analytics-i18n.php).
 *
 * Run: php tests/analytics-range-pills.php
 * @since plugin v9.42.2
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$fails  = 0;
$passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

// ---- Recording __-family stubs (tests/analytics-i18n.php idiom): return the
// msgid unchanged/escaped-unchanged (en_US behavior), so ONE stub set serves
// both the byte-pin assertion below AND the i18n-domain contract assertions.
$GLOBALS['__i18n'] = array();
function sn_test_i18n_record( $s, $d ) { $GLOBALS['__i18n'][] = array( (string) $s, $d ); }
function __( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return (string) $s; }
function esc_html__( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return htmlspecialchars( (string) $s, ENT_QUOTES ); }
/** True iff $text was routed through a translation fn with the plugin domain. */
function sn_i18n_seen( $text ) {
	foreach ( $GLOBALS['__i18n'] as $c ) {
		if ( $c[0] === $text && 'signal-and-noise-tools' === $c[1] ) { return true; }
	}
	return false;
}

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) $n; }
// $base . '?' . http_build_query($args) — the tests/analytics-controls-render.php
// / tests/analytics-trend-render.php idiom: unlike login-defense-analytics.php's
// own suite (which drops $u entirely), this one actually merges $base into the
// href, so group (b) below can prove the primitive's 'base' opt is wired through.
function add_query_arg( $args, $base = '' ) { return $base . '?' . http_build_query( (array) $args ); }
function remove_query_arg( $keys, $url = '' ) { return (string) $url; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// Edge glance seams (login-defense's body reads these; unused by the header
// path this suite drives, but required so login-defense.php's requires resolve).
$GLOBALS['__edge_cfg'] = null;
function sn_edge_config() { return $GLOBALS['__edge_cfg']; }
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) { return array(); }

$GLOBALS['__cfg'] = array( 'account_id' => 'x', 'token' => 'y' );
$GLOBALS['__q']   = array( array( 'decision' => 'block', 'hits' => 3 ), array( 'decision' => 'pass', 'hits' => 7 ) );
function sn_analytics_config() { return $GLOBALS['__cfg']; }
function sn_analytics_query( $sql ) { return $GLOBALS['__q']; }

require __DIR__ . '/../inc/login-defense.php';
require __DIR__ . '/../inc/login-defense-analytics.php'; // pulls in analytics-panels.php — the primitive's home

function cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

// --- (a) Parity proof: the pinned pre-extraction pill row ---------------------
// Captured from origin/main @ 7f45b51 (v9.42.1) via the real render fn, driven
// through this exact stub set (days defaults to 7 — no $_GET['sn_lg_range']).
$PINNED_PILL_ROW = '<div class="sn-toolbar"><div class="sn-control-group" role="group" aria-label="Date range"><span class="sn-control-label">Range</span><span class="button-group"><a class="button button-small active" aria-pressed="true" href="?sn_lg_range=7">7d</a><a class="button button-small" href="?sn_lg_range=30">30d</a><a class="button button-small" href="?sn_lg_range=90">90d</a></span></div></div>';

$hd = cap( 'sn_login_defense_render_header' );
ok( 0 === strpos( $hd, $PINNED_PILL_ROW ), 'login-defense header pill row is byte-identical to the pre-extraction markup (parity proof)' );

// --- (b) Contract: the new snt_an_range_pills() primitive ---------------------
ok( function_exists( 'snt_an_range_pills' ), 'snt_an_range_pills() exists (the extracted primitive)' );

if ( function_exists( 'snt_an_range_pills' ) ) {
	echo "\nGroup: snt_an_range_pills — active vs inactive pills\n";
	$h = cap( function () {
		snt_an_range_pills( 'sn_lg_range', array( 7, 30, 90 ), 7, array( 'base' => '/base' ) );
	} );
	ok( false !== strpos( $h, '<a class="button button-small active" aria-pressed="true" href="/base?sn_lg_range=7">7d</a>' ),
		'active pill (7) carries the active class + aria-pressed="true"' );
	ok( false !== strpos( $h, '<a class="button button-small" href="/base?sn_lg_range=30">30d</a>' ),
		'inactive pill (30) carries neither the active class nor aria-pressed' );
	ok( false !== strpos( $h, '<a class="button button-small" href="/base?sn_lg_range=90">90d</a>' ),
		'inactive pill (90) carries neither the active class nor aria-pressed' );
	ok( 1 === substr_count( $h, 'aria-pressed' ), 'exactly one pill carries aria-pressed (the active one)' );
	ok( 1 === substr_count( $h, 'button-small active' ), 'exactly one pill carries the active class' );

	echo "\nGroup: snt_an_range_pills — href wiring (esc_url + add_query_arg(param => value, base))\n";
	$h = cap( function () {
		snt_an_range_pills( 'sn_test_range', array( 5, 15 ), 15, array( 'base' => '/custom-base' ) );
	} );
	ok( false !== strpos( $h, 'href="/custom-base?sn_test_range=5"' ), 'href builds from add_query_arg(array(param => value), base)' );
	ok( false !== strpos( $h, 'href="/custom-base?sn_test_range=15"' ), 'the base opt rides every pill href, not just the active one' );

	echo "\nGroup: snt_an_range_pills — value-to-label formatting (<int>d)\n";
	$h = cap( function () {
		snt_an_range_pills( 'sn_lg_range', array( 7, 30, 90 ), 30, array( 'base' => '' ) );
	} );
	ok( false !== strpos( $h, '>7d</a>' ) && false !== strpos( $h, '>30d</a>' ) && false !== strpos( $h, '>90d</a>' ),
		'each allowed value renders as "<int>d" (login-defense\'s one shape — not a speculative formatter hook)' );

	echo "\nGroup: snt_an_range_pills — labels + group chrome escape through esc_html/esc_attr\n";
	$h = cap( function () {
		snt_an_range_pills( 'sn_lg_range', array( 7 ), 7, array( 'base' => '' ) );
	} );
	ok( false !== strpos( $h, '>7d</a>' ), 'pill label renders through esc_html' );
	ok( false !== strpos( $h, '<span class="sn-control-label">Range</span>' ), 'default group label ("Range") renders through esc_html' );
	ok( false !== strpos( $h, 'role="group" aria-label="Date range"' ), 'the group carries role="group" + the default aria-label, escaped via esc_attr' );

	echo "\nGroup: snt_an_range_pills — custom label text overrides render\n";
	$h = cap( function () {
		snt_an_range_pills( 'sn_lg_range', array( 7 ), 7, array( 'base' => '', 'label' => 'Window', 'aria_label' => 'Login range' ) );
	} );
	ok( false !== strpos( $h, '<span class="sn-control-label">Window</span>' ), 'custom label opt overrides the default "Range" text' );
	ok( false !== strpos( $h, 'aria-label="Login range"' ), 'custom aria_label opt overrides the default "Date range" text' );
	ok( false === strpos( $h, '>Range<' ), 'the default label text is fully replaced, not appended alongside the custom one' );

	echo "\nGroup: snt_an_range_pills — i18n: label + aria-label route through the plugin text domain\n";
	$GLOBALS['__i18n'] = array();
	cap( function () {
		snt_an_range_pills( 'sn_lg_range', array( 7 ), 7, array( 'base' => '' ) );
	} );
	ok( sn_i18n_seen( 'Range' ), 'the default group label is a translatable string on the plugin domain' );
	ok( sn_i18n_seen( 'Date range' ), 'the default aria-label is a translatable string on the plugin domain' );

	echo "\nGroup: snt_an_range_pills — malformed input degrades silently\n";
	$h = cap( function () {
		snt_an_range_pills( 'sn_lg_range', array(), 7, array( 'base' => '' ) );
	} );
	ok( false !== strpos( $h, 'role="group"' ) && false === strpos( $h, '<a ' ), 'an empty $allowed list renders the group chrome with zero pills (no notice, no fatal)' );
}

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
