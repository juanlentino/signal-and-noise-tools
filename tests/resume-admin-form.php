<?php
/**
 * Standalone fixture tests for the Content → Resume Page structured form
 * (inc/admin-forms/resume-page.php, plugin v10.33.0).
 *
 * The form is the STRUCTURED editor (real fields, repeatable rows) — these
 * tests pin the contracts the save path and the repeatable-row JS depend on:
 * input names that mirror the canonical document shape exactly, one
 * data-rsm-list / data-rsm-tpl / data-rsm-add triple per repeatable list,
 * and tokens baked into nested templates for the clone-time rewrite.
 *
 * Run: php tests/resume-admin-form.php
 * @since plugin v10.33.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $a ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $t = null ) { return '2026-08-03'; } }
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

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/admin-forms/resume-page.php';

echo "\nTest: sn_admin_render_resume_section (seed prefill)\n";
ob_start();
sn_admin_render_resume_section();
$html = ob_get_clean();

ok( false !== strpos( $html, 'name="_wpnonce"' ), 'nonce field rendered' );
ok( false !== strpos( $html, 'value="resume_save"' ), 'submit posts sn_action=resume_save' );
ok( false !== strpos( $html, 'prefilled from the current published content' ), 'unsaved state explains the first-save takeover' );

// Input names mirror the document shape the handler passes straight through.
ok( false !== strpos( $html, 'name="resume[hero][summary]"' ), 'hero summary field' );
ok( false !== strpos( $html, 'name="resume[hero][chips][]"' ), 'chips are a plain [] leaf list' );
ok( false !== strpos( $html, 'name="resume[experience][0][org]"' ), 'employer org field, indexed' );
ok( false !== strpos( $html, 'name="resume[experience][1][roles][1][title]"' ), 'nested role title (Panacea second role)' );
ok( false !== strpos( $html, 'name="resume[experience][1][roles][0][bullets][]"' ), 'bullets are a [] leaf under their role' );
ok( false !== strpos( $html, 'name="resume[earlier][entries][1][roles][1][title]"' ), 'earlier fold nests the same role shape' );
ok( false !== strpos( $html, 'name="resume[publications][1][url]"' ), 'publication url field' );
ok( false !== strpos( $html, 'name="resume[skills][5][items]"' ), 'sixth skills row present' );

// Seed content actually prefills.
ok( false !== strpos( $html, 'PANACEA STUDIO' ), 'seed org prefilled' );
ok( false !== strpos( $html, 'roughly 110 releases' ), 'seed bullet prefilled in its textarea' );
ok( false !== strpos( $html, 'https://ssrn.com/abstract=6730343' ), 'seed publication URL prefilled' );

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

// Token discipline: the employer template bakes __E__ everywhere it must be
// rewritten, and its NESTED role template carries __E__ too (the JS rewrites
// template content at clone time — a missed token would collide employer keys).
ok( false !== strpos( $html, 'name="resume[experience][__E__][org]"' ), 'employer template bakes the __E__ token' );
ok( false !== strpos( $html, 'data-rsm-token="__E__"', strpos( $html, 'data-rsm-tpl="exp"' ) ), 'employer template declares its token' );
ok( false !== strpos( $html, 'resume[experience][__E__][roles][__R__][title]' ), 'nested role template carries BOTH tokens' );
ok( false !== strpos( $html, 'data-rsm-add="rol-__E__"' ), 'nested add-role button id carries the token for clone-time rewrite' );
ok( false !== strpos( $html, 'resume[stats][__S__][n]' ), 'stats template token' );
ok( false !== strpos( $html, 'resume[publications][__P__][title]' ), 'publications template token' );

// balanced structure: details/summary and div counts match.
ok( substr_count( $html, '<details' ) === substr_count( $html, '</details>' ), 'details tags balance' );
ok( substr_count( $html, '<div' ) === substr_count( $html, '</div>' ), 'div tags balance' );
ok( substr_count( $html, '<template' ) === substr_count( $html, '</template>' ), 'template tags balance' );

echo "\nTest: saved-state intro\n";
sn_resume_doc_save( sn_resume_seed_doc() );
ob_start();
sn_admin_render_resume_section();
$html2 = ob_get_clean();
ok( false !== strpos( $html2, 'Last saved: <code>2026-08-03</code>' ), 'saved state shows the save stamp' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
