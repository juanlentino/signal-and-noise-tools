<?php
/**
 * Standalone test: Reading Time sub-tab — open-and-wide shell (Phase 4b, v6.46.0).
 *
 * The cleanup tool's payload is a wide matches table (ID / Title / Where /
 * Match+snippet) that was truncating at the old 820px cap. The leaf goes full
 * width via the two-column sn_admin_shell: the cleanup tool + the matches table
 * (wide content) in MAIN, a compact "how reading time works" readout in the
 * rail. The single most important contract this locks is the shell's HARD rule
 * — never return between open() and close() — across all three render paths
 * (no preview / preview-with-matches / preview-clean), the prior clean-state
 * early-return being the trap.
 *
 * Run: php tests/reading-time-admin.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function rt_assert( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// Capture the sn_admin_reading_time_tab callback; no-op the other hooks the
// module registers at require time.
$GLOBALS['__rt_cb'] = null;
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $p = 10, $a = 1 ) {
		if ( 'sn_admin_reading_time_tab' === $hook ) { $GLOBALS['__rt_cb'] = $cb; }
		return true;
	}
}
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $c ) { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }

// WP function stubs the admin callback touches.
$GLOBALS['__rt_cap'] = true;
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return $GLOBALS['__rt_cap']; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $k, $v = null, $u = null ) { return ( $u ? $u : '/x' ) . '?' . $k . '=' . $v; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $id ) { return '/edit?p=' . $id; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return 'Post ' . $id; } }

// Data seams for the REAL sn_find_legacy_reading_time() scan (defined in the
// module, so we drive its dependencies, not stub it — exercising the real regex).
$GLOBALS['__rt_posts'] = array(); // id => post object { post_content, post_excerpt }
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $a = array() ) { return array_keys( $GLOBALS['__rt_posts'] ); } }
if ( ! function_exists( 'get_post' ) ) { function get_post( $id ) { return $GLOBALS['__rt_posts'][ $id ] ?? null; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $k = '', $s = false ) { return array(); } }

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/reading-time.php';

$cb = $GLOBALS['__rt_cb'];
rt_assert( is_callable( $cb ), 'reading-time admin-tab callback captured' );

function rt_balanced( $h ) {
	return 1 === substr_count( $h, 'sn-shell__main' )
		&& 1 === substr_count( $h, 'sn-shell__rail' )
		&& false !== strpos( $h, '</aside></div>' );
}

// ── Path 1: no preview ──────────────────────────────────────────────────
unset( $_GET['sn_rt_preview'] );
ob_start();
if ( is_callable( $cb ) ) { call_user_func( $cb ); }
$h = ob_get_clean();
echo "Group: no-preview — shell renders, balanced\n";
rt_assert( false !== strpos( $h, 'class="sn-shell"' ), 'uses the full-width shell' );
rt_assert( rt_balanced( $h ), 'shell divs balanced (one main + one rail + close)' );
rt_assert( false !== stripos( $h, 'Preview' ), 'cleanup tool (Preview) present in main' );
$rail_pos = strpos( $h, 'sn-shell__rail' );
$rail     = false !== $rail_pos ? substr( $h, $rail_pos ) : '';
rt_assert( false !== strpos( $rail, '225' ) || false !== stripos( $rail, 'wpm' ) || false !== stripos( $rail, 'word' ), 'rail carries the reading-time readout (WPM / formula)' );

// ── Path 2: preview WITH matches — table in main, balanced ───────────────
$_GET['sn_rt_preview'] = '1';
$GLOBALS['__rt_posts'] = array(
	7 => (object) array( 'post_content' => 'Intro paragraph. 8 min read here. Outro.', 'post_excerpt' => '' ),
);
ob_start();
call_user_func( $cb );
$h = ob_get_clean();
echo "\nGroup: preview-with-matches — matches table in main, balanced\n";
rt_assert( rt_balanced( $h ), 'shell still balanced with matches' );
rt_assert( false !== stripos( $h, 'Matches' ), 'matches table heading present' );
$rail_pos = strpos( $h, 'sn-shell__rail' );
$main     = false !== $rail_pos ? substr( $h, 0, $rail_pos ) : $h;
rt_assert( false !== stripos( $main, '8 min read' ), 'the matches table renders in the MAIN column (wide content), not the rail' );

// ── Path 3: preview, ZERO matches — clean state STILL balanced ───────────
$GLOBALS['__rt_posts'] = array(
	7 => (object) array( 'post_content' => 'A clean post with no legacy strings.', 'post_excerpt' => '' ),
);
ob_start();
call_user_func( $cb );
$h = ob_get_clean();
echo "\nGroup: preview-clean — clean state keeps the shell balanced (no early-return imbalance)\n";
rt_assert( false !== stripos( $h, 'clean' ) || false !== stripos( $h, 'No legacy' ), 'clean state message shown' );
rt_assert( rt_balanced( $h ), 'shell STILL balanced on the clean path (the HARD shell contract — converted the early return)' );
unset( $_GET['sn_rt_preview'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
