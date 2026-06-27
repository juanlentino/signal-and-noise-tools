<?php
/**
 * Standalone test: Health sub-tab two-column shell contract (v6.42.0).
 *
 * The Run-scan control + per-check finding tables stay in the main column;
 * the scan status box moves to the rail. Also guards the early-return path:
 * the shell must stay balanced whether or not a scan has run.
 *
 * Run: php tests/health-shell.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '3 hours'; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); } }
if ( ! function_exists( 'snt_ai_is_available' ) ) { function snt_ai_is_available() { return false; } }

$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 3600,
	'elapsed_ms' => 800,
	'checks'     => array(
		'missing_alt' => array(
			'label'    => 'Missing alt text',
			'count'    => 2,
			'fix_hint' => 'Add descriptive alt text.',
			'findings' => array(
				array( 'subject_label' => 'img-1', 'subject_url' => 'https://x/1', 'note' => 'no alt', 'edit_url' => 'https://x/edit/1' ),
				array( 'subject_label' => 'img-2', 'note' => 'no alt' ),
			),
		),
	),
);
if ( ! function_exists( 'sn_health_last_scan' ) ) { function sn_health_last_scan() { return $GLOBALS['__scan']; } }

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/health-checks-admin.php';

function he_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test A: with a scan — controls/tables in main, status in rail ───
echo "Test A: Health with a scan (controls + tables in main, status in rail)\n";
ob_start();
sn_health_render_admin_tab();
$html = ob_get_clean();
$rail_at = strpos( $html, '<aside class="sn-shell__rail"' );

he_assert( false !== strpos( $html, '<div class="sn-shell">' ), 'wrapped in the two-column shell' );
he_assert( false !== $rail_at, 'has a right rail' );

$run_at   = strpos( $html, 'Run scan' );
$check_at = strpos( $html, 'Missing alt text' );
he_assert( false !== $run_at && $run_at < $rail_at, 'Run scan control sits in the main column' );
he_assert( false !== $check_at && $check_at < $rail_at, 'per-check finding table sits in the main column' );

$status_at = strpos( $html, 'Last scan' );
// is_int( $rail_at ) gates against a false (missing-rail) spurious pass.
he_assert( is_int( $rail_at ) && false !== $status_at && $status_at > $rail_at, 'scan status box sits in the rail' );
he_assert( 1 === substr_count( $html, '</aside>' ), 'rail aside closes exactly once' );

// ─── Test B: NO scan — shell still balanced (early-return guard) ─────
echo "\nTest B: Health with no scan — shell stays balanced\n";
$GLOBALS['__scan'] = null;
ob_start();
sn_health_render_admin_tab();
$html2 = ob_get_clean();
he_assert( false !== strpos( $html2, '<div class="sn-shell">' ), 'still wrapped in the shell with no scan' );
he_assert( 1 === substr_count( $html2, '<aside class="sn-shell__rail"' ) && 1 === substr_count( $html2, '</aside>' ), 'rail aside balanced with no scan (no early-return leak)' );
he_assert( false !== strpos( $html2, 'No scan has run yet' ), 'renders the no-scan status in the rail' );

// ─── Test C: clean scan — "All clear" pill lands in the rail ─────────
echo "\nTest C: clean scan — All clear status in the rail\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 600,
	'elapsed_ms' => 300,
	'checks'     => array(
		'missing_alt' => array( 'label' => 'Missing alt text', 'count' => 0, 'fix_hint' => '', 'findings' => array() ),
	),
);
ob_start();
sn_health_render_admin_tab();
$html3     = ob_get_clean();
$rail3     = strpos( $html3, '<aside class="sn-shell__rail"' );
$clear_at  = strpos( $html3, 'All clear' );
$nofind_at = strpos( $html3, 'No findings.' );
he_assert( is_int( $rail3 ) && false !== $clear_at && $clear_at > $rail3, 'All clear status sits in the rail' );
he_assert( is_int( $rail3 ) && false !== $nofind_at && $nofind_at < $rail3, 'zero-count check renders in the main column' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
