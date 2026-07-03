<?php
/**
 * Standalone test: sn_admin_glance_grid() first-glance stat-card grid helper
 * (Phase 1 "open and wide" redesign).
 *
 * Locks the markup contract of the reusable glance grid: a .sn-glance grid
 * wrapper containing one .sn-glance-card per card, each with a muted label, a
 * value, an optional sn-pill (ok/warn/err), and optional pre-escaped meta_html.
 * Every plain field is escaped; meta_html passes through wp_kses_post. Empty
 * input emits nothing.
 *
 * Standalone — no PHPUnit. Run: php tests/admin-glance.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

// ─── WP stubs ────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
// wp_kses_post stub: allow a small safe tag set, strip <script>. Good enough to
// prove the helper routes meta_html through kses (markup survives, script dies).
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $s ) {
		$s = preg_replace( '!<script\b[^>]*>.*?</script>!is', '', (string) $s );
		return $s;
	}
}

require_once __DIR__ . '/../inc/admin-glance.php';

function ag_contains( $haystack, $needle, $msg ) {
	global $pass, $fail;
	if ( false !== strpos( $haystack, $needle ) ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Missing: $needle\n"; }
}
function ag_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test A: grid wrapper + per-card label/value ────────────────────
echo "Test A: grid wrapper + label/value per card\n";
ob_start();
sn_admin_glance_grid(
	array(
		array( 'label' => 'Theme', 'value' => '10.18.0' ),
		array( 'label' => 'Plugin', 'value' => '6.42.0' ),
	)
);
$out = ob_get_clean();
ag_contains( $out, '<div class="sn-glance">', 'opens the .sn-glance grid wrapper' );
ag_assert( 2 === substr_count( $out, '<div class="sn-glance-card">' ), 'emits one .sn-glance-card per card' );
ag_contains( $out, 'Theme', 'renders the first label' );
ag_contains( $out, '10.18.0', 'renders the first value' );
ag_contains( $out, '6.42.0', 'renders the second value' );

// ─── Test B: pill kind + text ───────────────────────────────────────
echo "\nTest B: optional pill\n";
ob_start();
sn_admin_glance_grid(
	array(
		array( 'label' => 'Health', 'value' => '3 findings', 'pill' => array( 'kind' => 'warn', 'text' => 'needs attention' ) ),
		array( 'label' => 'Deploys', 'value' => '14m ago', 'pill' => array( 'kind' => 'ok', 'text' => 'fresh' ) ),
	)
);
$out_b = ob_get_clean();
ag_contains( $out_b, 'sn-pill sn-pill--warn', 'renders the warn pill kind' );
ag_contains( $out_b, 'needs attention', 'renders the warn pill text' );
ag_contains( $out_b, 'sn-pill sn-pill--ok', 'renders the ok pill kind' );
ag_contains( $out_b, 'fresh', 'renders the ok pill text' );

// ─── Test C: meta_html via kses ─────────────────────────────────────
echo "\nTest C: meta_html passes kses\n";
ob_start();
sn_admin_glance_grid(
	array(
		array( 'label' => 'Views', 'value' => '1,204', 'meta_html' => '<span class="up">▲ 12%</span>' ),
	)
);
$out_c = ob_get_clean();
ag_contains( $out_c, '<span class="up">▲ 12%</span>', 'safe meta_html markup survives kses' );

// Negative: a <script> in meta_html must be stripped by wp_kses_post — the
// helper routes meta_html through kses rather than echoing it raw.
echo "\nTest C2: meta_html <script> is stripped by kses\n";
ob_start();
sn_admin_glance_grid(
	array(
		array( 'label' => 'Views', 'value' => '1,204', 'meta_html' => 'safe<script>alert(1)</script>tail' ),
	)
);
$out_c2 = ob_get_clean();
ag_assert( false === strpos( $out_c2, '<script>' ), 'a <script> tag in meta_html is stripped' );
ag_assert( false === strpos( $out_c2, 'alert(1)' ), 'the <script> payload does not survive' );
ag_contains( $out_c2, 'safe', 'surrounding safe meta_html text is retained' );

// ─── Test D: escaping of a malicious label / value ──────────────────
echo "\nTest D: escaping\n";
ob_start();
sn_admin_glance_grid(
	array(
		array( 'label' => '<script>x</script>', 'value' => 'a"b<c', 'pill' => array( 'kind' => 'err', 'text' => '<b>boom</b>' ) ),
	)
);
$out_d = ob_get_clean();
ag_assert( false === strpos( $out_d, '<script>x</script>' ), 'malicious label is escaped (no raw <script>)' );
ag_assert( false === strpos( $out_d, 'a"b<c' ), 'malicious value is escaped' );
ag_assert( false === strpos( $out_d, '<b>boom</b>' ), 'malicious pill text is escaped' );
ag_contains( $out_d, 'sn-pill sn-pill--err', 'pill kind is constrained to a class token' );

// An unknown pill kind must not inject an arbitrary class fragment.
echo "\nTest E: pill kind allowlist\n";
ob_start();
sn_admin_glance_grid(
	array(
		array( 'label' => 'X', 'value' => 'Y', 'pill' => array( 'kind' => 'evil" onmouseover="alert(1)', 'text' => 'z' ) ),
	)
);
$out_e = ob_get_clean();
ag_assert( false === strpos( $out_e, 'onmouseover' ), 'a bogus pill kind cannot break out of the class attribute' );

// ─── Test F: empty-cards guard ──────────────────────────────────────
echo "\nTest F: empty input guard\n";
ob_start();
sn_admin_glance_grid( array() );
$out_f = ob_get_clean();
ag_assert( '' === trim( $out_f ), 'empty cards array emits nothing' );

// ─── Test G: optional card id hook ──────────────────────────────────
echo "\nTest G: optional card id\n";
ob_start();
sn_admin_glance_grid( array(
	array( 'label' => 'Caches', 'value' => 'Checking…', 'id' => 'snt-freshness-card' ),
) );
$out_g = ob_get_clean();
ag_contains( $out_g, 'id="snt-freshness-card"', 'renders the optional card id' );

echo "\nTest H: card id is escaped\n";
ob_start();
sn_admin_glance_grid( array(
	array( 'label' => 'X', 'value' => 'Y', 'id' => 'a" onload="x' ),
) );
$out_h = ob_get_clean();
ag_assert( false === strpos( $out_h, 'a" onload="x' ), 'the raw quote-breakout payload does not survive (the quote is escaped)' );
ag_contains( $out_h, '&quot;', 'the malicious double-quote in the id is escaped to &quot;' );

echo "\nTest I: no id attribute when omitted\n";
ob_start();
sn_admin_glance_grid( array( array( 'label' => 'A', 'value' => 'B' ) ) );
$out_i = ob_get_clean();
ag_assert( false === strpos( $out_i, ' id=' ), 'no id attribute is emitted when the card omits id' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
