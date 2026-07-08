<?php
/**
 * Tests for inc/analytics-intelligence.php + the Intelligence view registration
 * in inc/analytics-admin.php (slice a of the Analytics Intelligence tab).
 * Run: php tests/analytics-intelligence.php
 * @since plugin v9.2.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

// --- minimal WP stubs the two files reference at include + resolver time ---
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function checked( $a, $b = true, $e = true ) { $r = ( $a == $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function human_time_diff( $a, $b = 0 ) { return '2 days'; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return (string) $url . ( strpos( (string) $url, '?' ) !== false ? '&' : '?' ) . http_build_query( (array) $args ); }
// Analytics panel chrome (real ones live in inc/analytics-panels.php).
function snt_an_panel_open( $t, $a = array() ) { echo '<section class="sn-an-panel"><h2>' . esc_html( $t ) . '</h2>'; }
function snt_an_panel_close() { echo '</section>'; }

$__pass = 0; $__fail = 0;
function t_true( $c, $m ) { global $__pass, $__fail; if ( $c ) { $__pass++; echo "  PASS: $m\n"; } else { $__fail++; echo "  FAIL: $m\n"; } }
function t_contains( $h, $n, $m ) { t_true( strpos( (string) $h, (string) $n ) !== false, $m ); }

require __DIR__ . '/../inc/analytics-admin.php';
require __DIR__ . '/../inc/analytics-intelligence.php';
require __DIR__ . '/../inc/health-summary.php'; // real snt_health_format_elapsed (digest-meta humanization moved here from insights-shell)

// ── Task 1: registration ──
t_true( isset( SN_ANALYTICS_VIEWS['intelligence'] ), 'intelligence in SN_ANALYTICS_VIEWS' );
t_true( 'Intelligence' === SN_ANALYTICS_VIEWS['intelligence'], 'intelligence label is Intelligence' );
t_true( 'intelligence' === array_key_first( SN_ANALYTICS_VIEWS ), 'intelligence is first in the strip' );
t_true( 'intelligence' === snt_analytics_resolve_view( 'intelligence' ), 'resolve_view accepts intelligence' );
t_true( 'content' === snt_analytics_resolve_view( '' ), 'default view stays content' );
t_true( snt_analytics_view_owns_chrome( 'intelligence' ), 'intelligence owns its chrome' );
t_true( function_exists( 'snt_analytics_render_intelligence_view' ), 'render entry defined' );

// ── Task 2: digest read from cache ──
$GLOBALS['__narration'] = array(
	'headline'     => 'Views up 12% to 1,430',
	'paragraphs'   => array( 'Traffic rose on the back of /notes/foo.' ),
	'highlights'   => array( 'views 1,430 (+12%)' ),
	'generated_at' => 1700000000,
	'elapsed_ms'   => 1234,
);
function snt_narration_last() { return $GLOBALS['__narration']; }
function snt_ai_is_available() { return true; }

ob_start(); snt_intelligence_render_digest( true ); $out = ob_get_clean();
t_contains( $out, 'Views up 12% to 1,430', 'digest headline rendered' );
t_contains( $out, 'views 1,430 (+12%)', 'digest highlight rendered' );
// Digest meta humanizes elapsed via the real snt_health_format_elapsed (coverage
// relocated from tests/insights-shell.php Scenario C when the digest moved here).
t_contains( $out, 'in 1.2s', 'digest meta humanizes elapsed (1234ms → 1.2s)' );
t_true( strpos( $out, '1234ms' ) === false, 'no raw-millisecond digest meta remains' );

$GLOBALS['__narration'] = null;
ob_start(); snt_intelligence_render_digest( true ); $out2 = ob_get_clean();
t_contains( $out2, 'No digest yet', 'empty state when no cached digest' );

// ── Task 3: Refresh/Generate form ──
$GLOBALS['__narration'] = null;
ob_start(); snt_intelligence_render_digest( true ); $out3 = ob_get_clean();
t_contains( $out3, 'name="sn_action" value="narration_run"', 'Refresh form posts narration_run' );
t_contains( $out3, 'name="_wpnonce"', 'Refresh form carries a nonce' );
t_contains( $out3, 'Generate digest', 'button reads Generate when no digest' );

$GLOBALS['__narration'] = array( 'headline' => 'x', 'paragraphs' => array( 'y' ), 'highlights' => array(), 'generated_at' => 1700000000, 'elapsed_ms' => 1 );
ob_start(); snt_intelligence_render_digest( true ); $out4 = ob_get_clean();
t_contains( $out4, 'Regenerate digest', 'button reads Regenerate when a digest exists' );

ob_start(); snt_intelligence_render_digest( false ); $out5 = ob_get_clean();
t_contains( $out5, 'disabled', 'button disabled when AI not ready' );

// ── Task 4: digest-automation toggle form ──
$GLOBALS['__setting_narration'] = false;
function snt_narration_enabled() { return $GLOBALS['__setting_narration']; }
ob_start(); snt_intelligence_render_digest( true ); $out6 = ob_get_clean();
t_contains( $out6, 'name="sn_action" value="narration_settings_save"', 'automation toggle posts narration_settings_save' );
t_contains( $out6, 'name="insights_narration"', 'automation toggle field present' );

echo "\nResult: {$__pass} passed, {$__fail} failed.\n";
