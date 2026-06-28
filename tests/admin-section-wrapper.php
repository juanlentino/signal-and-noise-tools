<?php
/**
 * Standalone test: sn_admin_render_section() leaf wrapper contract (Phase 1
 * "open and wide" redesign — DEFAULT-SAFE wrapper).
 *
 * The wrapper is the single, uniform container every normal sub-tab leaf is
 * rendered inside (inc/admin-dispatch.php). Most leaves render bare content and
 * rely on this wrapper for their card, so the wrapper DEFAULTS to the capped
 * .sn-fieldset card — byte-identical to the pre-redesign behaviour. Only the
 * full-width-shell leaves (insights/health/music/indexnow) OPT IN to a bare
 * full-width .sn-section by passing $wide = true. This test locks BOTH modes,
 * and that the #sn-sec-<slug> anchor target survives in each.
 *
 * Standalone — no PHPUnit. Run: php tests/admin-section-wrapper.php
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
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
// sn_admin_render_section is the only function under test; the rest of
// inc/admin-tabs.php references sn_admin_top_tabs() etc. but only inside OTHER
// functions, so requiring the file is safe without those stubs.

require_once __DIR__ . '/../inc/admin-tabs.php';

function asw_contains( $haystack, $needle, $msg ) {
	global $pass, $fail;
	if ( false !== strpos( $haystack, $needle ) ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Missing: $needle\n";
	}
}
function asw_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

// ─── Test A: DEFAULT emits the capped .sn-fieldset card ──────────────
// (byte-identical to the pre-redesign behaviour — the card EVERY un-marked
// leaf relies on).
echo "Test A: default mode → capped .sn-fieldset card\n";
ob_start();
sn_admin_render_section( 'cloudflare', function () { echo 'BODY'; } );
$def = ob_get_clean();
asw_contains( $def, '<div class="sn-fieldset"', 'default wraps the leaf in the capped .sn-fieldset card' );
asw_assert( false === strpos( $def, 'class="sn-section"' ), 'default does NOT emit the bare full-width .sn-section' );
asw_contains( $def, 'id="sn-sec-cloudflare"', 'default carries the #sn-sec-<slug> anchor id' );
asw_assert( strpos( $def, 'BODY' ) > strpos( $def, '<div class="sn-fieldset"' ), 'default: body renders inside the card' );
asw_assert( 1 === substr_count( $def, '<div' ) && 1 === substr_count( $def, '</div>' ), 'default: exactly one wrapper div (open + close balanced)' );

// ─── Test B: $wide=true emits the bare full-width .sn-section ─────────
echo "\nTest B: wide mode → bare full-width .sn-section\n";
ob_start();
sn_admin_render_section( 'insights', function () { echo 'BODY'; }, true );
$wide = ob_get_clean();
asw_contains( $wide, '<div class="sn-section"', 'wide wraps the leaf in the full-width .sn-section block' );
asw_assert( false === strpos( $wide, 'class="sn-fieldset"' ), 'wide does NOT emit the capped .sn-fieldset card' );
asw_contains( $wide, 'id="sn-sec-insights"', 'wide carries the #sn-sec-<slug> anchor id' );
asw_assert( strpos( $wide, 'BODY' ) > strpos( $wide, '<div class="sn-section"' ), 'wide: body renders inside the section' );
asw_assert( 1 === substr_count( $wide, '<div' ) && 1 === substr_count( $wide, '</div>' ), 'wide: exactly one wrapper div (open + close balanced)' );

// ─── Test C: explicit $wide=false matches the default ────────────────
echo "\nTest C: explicit wide=false == default\n";
ob_start();
sn_admin_render_section( 'cloudflare', function () { echo 'BODY'; }, false );
$explicit_false = ob_get_clean();
asw_assert( $explicit_false === $def, 'explicit $wide=false is byte-identical to the default call' );

// ─── Test D: the slug is attribute-escaped (both modes) ──────────────
echo "\nTest D: slug escaping\n";
ob_start();
sn_admin_render_section( 'a"b<c', function () {} );
$escaped_def = ob_get_clean();
ob_start();
sn_admin_render_section( 'a"b<c', function () {}, true );
$escaped_wide = ob_get_clean();
asw_assert( false === strpos( $escaped_def, 'id="sn-sec-a"b<c"' ), 'default: raw unescaped slug is not present in the id' );
asw_contains( $escaped_def, 'sn-sec-a&quot;b&lt;c', 'default: slug is HTML-attribute escaped' );
asw_contains( $escaped_wide, 'sn-sec-a&quot;b&lt;c', 'wide: slug is HTML-attribute escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
