<?php
/**
 * Standalone fixture tests for the Content → Now Page / Uses Page structured
 * forms (inc/admin-forms/now-page.php + uses-page.php, plugin v10.40.0).
 *
 * v10.40.0 swaps the `## Label` textareas for structured forms — group cards
 * with a label field and repeatable item rows (Uses items split name/note into
 * two fields). The text document STAYS the stored format (data layers, sync
 * engines, and migrations untouched); these tests pin the render contracts the
 * save path and the shared sn-rsm repeatable-row JS depend on: input names the
 * handler serializes back to text, one data-rsm-list / data-rsm-tpl /
 * data-rsm-add triple per repeatable list, and tokens baked into nested
 * templates for the clone-time rewrite (assets/resume-admin.js).
 *
 * Run: php tests/now-uses-admin-form.php
 * @since plugin v10.40.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs (the resume-admin-form.php prelude) ──
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $t = null ) { return '2026-08-04'; } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="stub">'; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }

// Theme-groups seam: /uses prefills from the theme's live list before the
// first save (the old textarea serialized these; the structured form renders
// them directly). The stub models the REAL shape sn_uses_groups() returns.
function sn_uses_groups() {
	return array(
		array( 'label' => 'Interface', 'items' => array( array( 'name' => 'SSL UF8', 'note' => 'Advanced DAW controller' ) ) ),
	);
}

require_once __DIR__ . '/../inc/now-page.php';
require_once __DIR__ . '/../inc/uses-page.php';
// The Now/Uses cards reuse the sn_rsm_* input/controls helpers; in production
// every admin-forms file is required from signal-and-noise-tools.php before
// any render runs.
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/admin-forms/resume-page.php';
require_once __DIR__ . '/../inc/admin-forms/now-page.php';
require_once __DIR__ . '/../inc/admin-forms/uses-page.php';

// ─────────────────────────────────────────────────────────────────────
echo "\nTest: sn_admin_render_now_section (empty state)\n";
ob_start();
sn_admin_render_now_section();
$html = ob_get_clean();

ok( false !== strpos( $html, 'name="_wpnonce"' ), 'nonce field rendered' );
ok( false !== strpos( $html, 'value="now_save"' ), 'submit posts sn_action=now_save (action name unchanged)' );
ok( false === strpos( $html, 'name="now_content"' ), 'the plain-text box is gone' );
ok( false !== strpos( $html, 'data-rsm-list="now-groups"' ), 'groups list container' );
ok( false !== strpos( $html, 'data-rsm-add="now-groups"' ), 'add-section button' );
ok( false === strpos( $html, 'name="now[groups][0][label]"' ), 'no group rows before first save' );

// Group template: token baked into names AND nested item-list ids.
ok( false !== strpos( $html, 'name="now[groups][__G__][label]"' ), 'group template bakes the __G__ token into the label name' );
ok( false !== strpos( $html, 'name="now[groups][__G__][items][]"' ), 'items are a plain [] leaf under their group' );
ok( false !== strpos( $html, 'data-rsm-add="nit-__G__"' ), 'nested add-item button id carries the token for clone-time rewrite' );
ok( false !== strpos( $html, 'data-rsm-token="__G__"', strpos( $html, 'data-rsm-tpl="now-groups"' ) ), 'group template declares its token' );

echo "\nTest: sn_admin_render_now_section (stored prefill)\n";
sn_now_page_save( "## Building\n- shipping MCP\n- writing tests\n\n## Reading\n- a book" );
ob_start();
sn_admin_render_now_section();
$html = ob_get_clean();

ok( false !== strpos( $html, 'name="now[groups][0][label]"' ), 'first group label field, indexed' );
ok( false !== strpos( $html, 'value="Building"' ), 'first group label prefilled' );
ok( false !== strpos( $html, 'name="now[groups][0][items][]"' ), 'first group items are a [] leaf' );
ok( false !== strpos( $html, 'value="shipping MCP"' ), 'first item prefilled' );
ok( false !== strpos( $html, 'value="Reading"' ), 'second group label prefilled' );
ok( false !== strpos( $html, 'data-rsm-list="nit-0"' ), 'first group has its own items list id' );
ok( false !== strpos( $html, 'data-rsm-list="nit-1"' ), 'second group has a distinct items list id' );
ok( false !== strpos( $html, 'Last saved: <code>2026-08-04</code>' ), 'saved state shows the save stamp' );

// Repeatable-list plumbing: every add button has a matching template and list.
preg_match_all( '/data-rsm-add="([^"]+)"/', $html, $m_add );
preg_match_all( '/data-rsm-tpl="([^"]+)"/', $html, $m_tpl );
preg_match_all( '/data-rsm-list="([^"]+)"/', $html, $m_list );
$missing_tpl  = array_diff( array_unique( $m_add[1] ), $m_tpl[1] );
$missing_list = array_diff( array_unique( $m_add[1] ), $m_list[1] );
ok( array() === $missing_tpl, 'every data-rsm-add id has a template (missing: ' . implode( ',', $missing_tpl ) . ')' );
ok( array() === $missing_list, 'every data-rsm-add id has a list container (missing: ' . implode( ',', $missing_list ) . ')' );
$dupes = array_diff_assoc( $m_tpl[1], array_unique( $m_tpl[1] ) );
ok( array() === $dupes, 'template ids are unique (dupes: ' . implode( ',', $dupes ) . ')' );
ok( substr_count( $html, '<div' ) === substr_count( $html, '</div>' ), 'div tags balance' );
ok( substr_count( $html, '<template' ) === substr_count( $html, '</template>' ), 'template tags balance' );

// Escaping: hostile stored content renders escaped, never raw.
sn_now_page_save( "## \"Quo<b>ted\"\n- an item with \"quotes\" & <tags>" );
ob_start();
sn_admin_render_now_section();
$html = ob_get_clean();
ok( false === strpos( $html, '<b>ted' ), 'stored markup never renders raw' );
ok( false !== strpos( $html, 'value="&quot;Quo&lt;b&gt;ted&quot;"' ), 'label value esc_attr-escaped' );
delete_option( SN_NOW_PAGE_OPTION );

// ─────────────────────────────────────────────────────────────────────
echo "\nTest: sn_admin_render_uses_section (theme prefill before first save)\n";
ob_start();
sn_admin_render_uses_section();
$html = ob_get_clean();

ok( false !== strpos( $html, 'value="uses_save"' ), 'submit posts sn_action=uses_save (action name unchanged)' );
ok( false === strpos( $html, 'name="uses_content"' ), 'the plain-text box is gone' );
ok( false !== strpos( $html, 'name="uses[groups][0][label]"' ), 'theme groups prefill the form before first save' );
ok( false !== strpos( $html, 'value="Interface"' ), 'theme group label prefilled' );
ok( false !== strpos( $html, 'name="uses[groups][0][items][0][name]"' ), 'items are INDEXED name/note pairs' );
ok( false !== strpos( $html, 'value="SSL UF8"' ), 'item name prefilled' );
ok( false !== strpos( $html, 'name="uses[groups][0][items][0][note]"' ), 'item note field' );
ok( false !== strpos( $html, 'value="Advanced DAW controller"' ), 'item note prefilled' );

// Token discipline: group template bakes __U__, nested item template __I__.
ok( false !== strpos( $html, 'name="uses[groups][__U__][label]"' ), 'group template bakes the __U__ token' );
ok( false !== strpos( $html, 'name="uses[groups][__U__][items][__I__][name]"' ), 'nested item template carries BOTH tokens' );
ok( false !== strpos( $html, 'data-rsm-add="uit-__U__"' ), 'nested add-item button id carries the group token' );
ok( false !== strpos( $html, 'data-rsm-token="__I__"', strpos( $html, 'data-rsm-tpl="uit-0"' ) ), 'per-group item template declares the item token' );

echo "\nTest: sn_admin_render_uses_section (stored prefill wins over theme)\n";
sn_uses_page_save( "## Desk\n- Thing | with note\n- Bare name" );
ob_start();
sn_admin_render_uses_section();
$html = ob_get_clean();
ok( false !== strpos( $html, 'value="Desk"' ), 'stored group label prefilled' );
ok( false === strpos( $html, 'value="Interface"' ), 'theme prefill NOT used once a document is stored' );
ok( false !== strpos( $html, 'value="Thing"' ), 'name side of the pair split' );
ok( false !== strpos( $html, 'value="with note"' ), 'note side of the pair split' );
ok( false !== strpos( $html, 'name="uses[groups][0][items][1][name]"' ), 'second item indexed' );

preg_match_all( '/data-rsm-add="([^"]+)"/', $html, $m_add );
preg_match_all( '/data-rsm-tpl="([^"]+)"/', $html, $m_tpl );
preg_match_all( '/data-rsm-list="([^"]+)"/', $html, $m_list );
ok( array() === array_diff( array_unique( $m_add[1] ), $m_tpl[1] ), 'uses: every add id has a template' );
ok( array() === array_diff( array_unique( $m_add[1] ), $m_list[1] ), 'uses: every add id has a list' );
ok( substr_count( $html, '<div' ) === substr_count( $html, '</div>' ), 'uses: div tags balance' );
ok( substr_count( $html, '<template' ) === substr_count( $html, '</template>' ), 'uses: template tags balance' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
