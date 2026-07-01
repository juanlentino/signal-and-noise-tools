<?php
/**
 * Standalone test: Health sub-tab open-and-wide layout contract (v6.44.0).
 *
 * Replaces the v6.42.0 two-column shell. The tab now leads with a first-glance
 * hero (sn_admin_glance_grid), then the run-scan control, then full-width finding
 * tables for checks WITH issues, then a compact "pass board" for clean checks —
 * no .sn-shell / .sn-shell__rail. Also unit-tests snt_health_glance_cards().
 *
 * Run: php tests/health-layout.php
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
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '3 hours'; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); } }
if ( ! function_exists( 'snt_ai_is_available' ) ) { function snt_ai_is_available() { return false; } }

$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 3600,
	'elapsed_ms' => 800,
	'checks'     => array(
		'missing_alt'    => array(
			'label'    => 'Missing alt text',
			'count'    => 2,
			'fix_hint' => 'Add descriptive alt text.',
			'findings' => array(
				array( 'subject_label' => 'img-1', 'subject_url' => 'https://x/1', 'note' => 'no alt', 'edit_url' => 'https://x/edit/1' ),
				array( 'subject_label' => 'img-2', 'note' => 'no alt' ),
			),
		),
		'orphaned_media' => array(
			'label'    => 'Orphaned media',
			'count'    => 0,
			'fix_hint' => '',
			'findings' => array(),
		),
	),
);
if ( ! function_exists( 'sn_health_last_scan' ) ) { function sn_health_last_scan() { return $GLOBALS['__scan']; } }

require_once __DIR__ . '/../inc/health-summary.php'; // finding-total + flagged-checks accessors the glance hero shares
require_once __DIR__ . '/../inc/admin-glance.php';
require_once __DIR__ . '/../inc/health-checks-admin.php';

function he_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Unit: snt_health_glance_cards() ────────────────────────────────────────
echo "Unit: snt_health_glance_cards()\n";
$cards = snt_health_glance_cards( $GLOBALS['__scan'] );
he_assert( 3 === count( $cards ), 'a scan yields exactly 3 hero cards' );
he_assert( 'Findings' === $cards[0]['label'] && '2 findings' === $cards[0]['value'], 'Findings card sums the per-check counts' );
he_assert( 'warn' === $cards[0]['pill']['kind'], 'Findings card pills warn when issues exist' );
he_assert( 'Checks passed' === $cards[1]['label'] && '1 / 2' === $cards[1]['value'], 'Checks-passed card counts the clean checks' );
he_assert( 'Last scan' === $cards[2]['label'] && false !== strpos( $cards[2]['value'], 'ago' ), 'Last-scan card shows the age' );
$nocards = snt_health_glance_cards( null );
he_assert( 1 === count( $nocards ) && 'no scan' === $nocards[0]['value'] && 'warn' === $nocards[0]['pill']['kind'], 'no scan → a single warn "no scan" card' );

// ─── Test A: with findings — hero + full-width table + pass board, no shell ──
echo "\nTest A: Health with findings (hero → run-scan → Findings table → pass board)\n";
ob_start();
sn_health_render_admin_tab();
$html = ob_get_clean();

he_assert( false === strpos( $html, 'sn-shell' ), 'no two-column shell/rail — full-width layout' );
he_assert( false !== strpos( $html, '<div class="sn-glance">' ), 'leads with the first-glance hero' );
he_assert( false !== strpos( $html, 'value="health_scan"' ), 'run-scan control present' );
he_assert( false !== strpos( $html, 'name="_wpnonce"' ), 'run-scan form is nonce-protected' );
he_assert( false !== strpos( $html, '<h2 class="sn-section-h">Findings</h2>' ), 'Findings section heading present' );
he_assert( false !== strpos( $html, 'Missing alt text' ), 'finding card for the failing check' );
he_assert( false !== strpos( $html, '<table class="widefat striped' ), 'full-width finding table present' );
he_assert( false !== strpos( $html, '<h2 class="sn-section-h">Passing checks</h2>' ), 'pass board present (the clean orphaned_media check)' );
he_assert( false !== strpos( $html, 'Orphaned media' ), 'passing check appears as a pass tile' );

$glance_at   = strpos( $html, '<div class="sn-glance">' );
$findings_at = strpos( $html, '<h2 class="sn-section-h">Findings</h2>' );
he_assert( is_int( $glance_at ) && is_int( $findings_at ) && $glance_at < $findings_at, 'hero precedes the findings' );

// ─── Test B: NO scan — hero shows the no-scan card, no tables ────────────────
echo "\nTest B: Health with no scan — no-scan hero, no tables\n";
$GLOBALS['__scan'] = null;
ob_start();
sn_health_render_admin_tab();
$html2 = ob_get_clean();
he_assert( false === strpos( $html2, 'sn-shell' ), 'no shell on the no-scan path' );
he_assert( false !== strpos( $html2, 'no scan' ), 'hero shows the no-scan card' );
he_assert( false !== strpos( $html2, '>Run scan<' ), 'run-scan button reads "Run scan" before any scan' );
he_assert( false === strpos( $html2, '<table class="widefat striped' ), 'no finding tables without a scan' );
he_assert( false === strpos( $html2, '<h2 class="sn-section-h">Findings</h2>' ), 'no Findings section without a scan' );

// ─── Test C: clean scan — pass board only, no Findings section/table ─────────
echo "\nTest C: clean scan — pass board only, no Findings section\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 600,
	'elapsed_ms' => 300,
	'checks'     => array(
		'missing_alt' => array( 'label' => 'Missing alt text', 'count' => 0, 'fix_hint' => '', 'findings' => array() ),
	),
);
ob_start();
sn_health_render_admin_tab();
$html3 = ob_get_clean();
he_assert( false !== strpos( $html3, 'all clear' ), 'hero pills all-clear when nothing is found' );
he_assert( false === strpos( $html3, '<h2 class="sn-section-h">Findings</h2>' ), 'no Findings section when all checks pass' );
he_assert( false === strpos( $html3, '<table class="widefat striped' ), 'no finding table when clean' );
he_assert( false !== strpos( $html3, '<h2 class="sn-section-h">Passing checks</h2>' ), 'pass board present for the clean check' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
