<?php
/**
 * Standalone fixture tests for the Content → Now Page / Uses Page structured
 * forms (inc/admin-forms/now-page.php + uses-page.php, plugin v10.41.0).
 *
 * v10.41.0 swaps the `## Label` textareas for structured forms — group cards
 * with a label field and repeatable item rows (Uses items split name/note into
 * two fields). The text document STAYS the stored format (data layers, sync
 * engines, and migrations untouched); these tests pin the render contracts the
 * save path and the shared sn-rsm repeatable-row JS depend on: input names the
 * handler serializes back to text, one data-rsm-list / data-rsm-tpl /
 * data-rsm-add triple per repeatable list, and tokens baked into nested
 * templates for the clone-time rewrite (assets/resume-admin.js).
 *
 * Run: php tests/now-uses-admin-form.php
 * @since plugin v10.41.0
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
// v10.48.0: items are ONE TEXTAREA per section, not a [] leaf of inputs. The
// nested repeatable modelled the data wrongly — the stored artifact is a text
// document whose items are lines — and cost an input plus three buttons per line.
ok( false !== strpos( $html, 'name="now[groups][__G__][items]"' ), 'template: items post as a single textarea value, not a [] leaf' );
ok( false === strpos( $html, 'name="now[groups][__G__][items][]"' ), 'template: the per-item [] leaf is gone' );
ok( false === strpos( $html, 'data-rsm-add="nit-__G__"' ), 'template: no nested add-item button (Return adds a line)' );
ok( false !== strpos( $html, 'data-rsm-token="__G__"', strpos( $html, 'data-rsm-tpl="now-groups"' ) ), 'group template declares its token' );

echo "\nTest: sn_admin_render_now_section (stored prefill)\n";
sn_now_page_save( "## Building\n- shipping MCP\n- writing tests\n\n## Reading\n- a book" );
ob_start();
sn_admin_render_now_section();
$html = ob_get_clean();

ok( false !== strpos( $html, 'name="now[groups][0][label]"' ), 'first group label field, indexed' );
ok( false !== strpos( $html, 'value="Building"' ), 'first group label prefilled' );
ok( false !== strpos( $html, 'name="now[groups][0][items]"' ), 'first group items post as one textarea' );
ok( false !== strpos( $html, '>shipping MCP' ), 'first item prefilled as textarea CONTENT (not a value attribute)' );
ok( false !== strpos( $html, 'value="Reading"' ), 'second group label prefilled' );
ok( false !== strpos( $html, 'id="nit-0"' ), 'first group textarea keeps its own id (label association)' );
ok( false !== strpos( $html, 'id="nit-1"' ), 'second group textarea has a distinct id' );
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
// v10.48.0: `name | note` per line — the exact shape the stored document uses,
// so the field shows what actually gets saved.
ok( false !== strpos( $html, 'name="uses[groups][0][items]"' ), 'uses items post as one textarea per group' );
ok( false === strpos( $html, 'name="uses[groups][0][items][0][name]"' ), 'the indexed name/note inputs are gone' );
ok( false !== strpos( $html, 'SSL UF8 | Advanced DAW controller' ), 'a stored pair renders as one `name | note` line' );

// Token discipline: group template bakes __U__, nested item template __I__.
ok( false !== strpos( $html, 'name="uses[groups][__U__][label]"' ), 'group template bakes the __U__ token' );
ok( false !== strpos( $html, 'name="uses[groups][__U__][items]"' ), 'template: group token only — there is no longer an item token to bake' );
ok( false === strpos( $html, '__I__' ), 'template: the nested item token is gone entirely' );

echo "\nTest: sn_admin_render_uses_section (stored prefill wins over theme)\n";
sn_uses_page_save( "## Desk\n- Thing | with note\n- Bare name" );
ob_start();
sn_admin_render_uses_section();
$html = ob_get_clean();
ok( false !== strpos( $html, 'value="Desk"' ), 'stored group label prefilled' );
ok( false === strpos( $html, 'value="Interface"' ), 'theme prefill NOT used once a document is stored' );
ok( false !== strpos( $html, 'Thing | with note' ), 'a name+note pair renders as one piped line' );

preg_match_all( '/data-rsm-add="([^"]+)"/', $html, $m_add );
preg_match_all( '/data-rsm-tpl="([^"]+)"/', $html, $m_tpl );
preg_match_all( '/data-rsm-list="([^"]+)"/', $html, $m_list );
ok( array() === array_diff( array_unique( $m_add[1] ), $m_tpl[1] ), 'uses: every add id has a template' );
ok( array() === array_diff( array_unique( $m_add[1] ), $m_list[1] ), 'uses: every add id has a list' );
ok( substr_count( $html, '<div' ) === substr_count( $html, '</div>' ), 'uses: div tags balance' );
ok( substr_count( $html, '<template' ) === substr_count( $html, '</template>' ), 'uses: template tags balance' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
