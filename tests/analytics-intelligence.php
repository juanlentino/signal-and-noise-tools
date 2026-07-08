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

// ── Task 1: registration ──
t_true( isset( SN_ANALYTICS_VIEWS['intelligence'] ), 'intelligence in SN_ANALYTICS_VIEWS' );
t_true( 'Intelligence' === SN_ANALYTICS_VIEWS['intelligence'], 'intelligence label is Intelligence' );
t_true( 'intelligence' === array_key_first( SN_ANALYTICS_VIEWS ), 'intelligence is first in the strip' );
t_true( 'intelligence' === snt_analytics_resolve_view( 'intelligence' ), 'resolve_view accepts intelligence' );
t_true( 'content' === snt_analytics_resolve_view( '' ), 'default view stays content' );
t_true( snt_analytics_view_owns_chrome( 'intelligence' ), 'intelligence owns its chrome' );
t_true( function_exists( 'snt_analytics_render_intelligence_view' ), 'render entry defined' );

echo "\nResult: {$__pass} passed, {$__fail} failed.\n";
