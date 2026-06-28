<?php
/**
 * Standalone test: Identity & SEO form — open-and-wide 2-up field layout (Phase 4, v6.46.0).
 *
 * The change is CSS-only (the composite form's section cards uncap + grid their
 * fields), so this locks (a) the render still emits the 4 sections + the single
 * save button (no regression), and (b) the .sn-identity-form .sn-fieldset CSS is
 * a full-width grid.
 *
 * Run: php tests/identity-seo-form.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function id_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $k, $d = '' ) { return $d; } }

require_once __DIR__ . '/../inc/admin-tabs.php';          // sn_admin_render_section (the section-card wrapper)
require_once __DIR__ . '/../inc/admin-forms/identity-and-seo.php';

// ─── Render-smoke: the composite form is unchanged (CSS-only redesign) ──────
echo "Group: render — composite form + 4 sections + one save button\n";
ob_start();
sn_admin_render_identity_and_seo_form();
$html = ob_get_clean();

id_assert( false !== strpos( $html, '<form method="post" class="sn-identity-form">' ), 'emits the sn-identity-form' );
id_assert( false !== strpos( $html, 'name="sn_action" value="save_identity"' ), 'carries the single save_identity action' );
foreach ( array( 'sn-sec-identity', 'sn-sec-social', 'sn-sec-open-graph', 'sn-sec-seo-copy' ) as $anchor ) {
	id_assert( false !== strpos( $html, 'id="' . $anchor . '"' ), "section anchor #$anchor present" );
}
// Each section is wrapped in a real .sn-fieldset card (the grid container).
id_assert( 4 === substr_count( $html, 'class="sn-fieldset"' ), 'exactly four real .sn-fieldset section cards' );
id_assert( false !== strpos( $html, 'name="identity_site_name"' ) && false !== strpos( $html, 'name="seo_provenance_description"' ), 'first + last fields render (form body intact)' );
id_assert( false !== strpos( $html, 'Save Identity Settings' ), 'single sticky save button present' );

// ─── CSS-lock: the form's section cards are a full-width grid ────────────────
echo "\nGroup: CSS — .sn-identity-form .sn-fieldset is a full-width grid\n";
$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );
$at  = strpos( $css, '.sn-identity-form .sn-fieldset {' );
$block = false !== $at ? substr( $css, $at, strpos( $css, '}', $at ) - $at ) : '';
id_assert( false !== strpos( $block, 'display: grid' ) || false !== strpos( $block, 'display:grid' ), 'section cards are a grid container' );
id_assert( false !== strpos( $block, 'max-width: none' ) || false !== strpos( $block, 'max-width:none' ), 'section cards uncap to full width (no 820px cap)' );
id_assert( false !== strpos( $block, 'auto-fit' ), 'fields lay out in auto-fit columns (2-up on a laptop, more when wide)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
