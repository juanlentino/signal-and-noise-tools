<?php
/**
 * Standalone test: Dashboard tab first-glance grid + attention strip
 * (Phase 1 "open and wide" redesign).
 *
 * The Dashboard now opens with a glance grid (sourced ONLY from accessors the
 * plugin already computes) and a CONDITIONAL attention strip that appears only
 * when something is off (health findings, DB overrides, cron orphans, etc.).
 * This drives snt_dashboard_glance_cards() (the conditional card builder) and
 * snt_dashboard_render_attention_strip() with stubbed accessors and asserts on
 * captured markup.
 *
 * Standalone — no PHPUnit. Run: php tests/admin-tab-dashboard-glance.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

// ─── WP + plugin stubs ───────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return preg_replace( '!<script\b[^>]*>.*?</script>!is', '', (string) $s ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '3 hours'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }

// ── Stubbed plugin accessors (the card builder is function_exists-guarded) ──
$GLOBALS['__d'] = array(
	'health'  => array(
		'scanned_at' => time() - 3600,
		'elapsed_ms' => 900,
		'checks'     => array(
			'missing_alt'  => array( 'count' => 2 ),
			'broken_links' => array( 'count' => 1 ),
		),
	),
	'ai'      => array( 'calls' => 4, 'total' => 6000, 'cost' => 0.0123 ),
	'cron'    => array( 'total' => 9, 'sn_count' => 3, 'orphans' => 0 ),
	'login'   => array( 'configured' => true, 'blocked' => 42, 'block_rate' => 18, 'top_network' => 'AS1234' ),
	'config'  => true,
	'deltas'  => array( 'views' => array( 'current' => 1204, 'previous' => 1100, 'pct' => 9, 'dir' => 'up' ) ),
	'overrides_ids' => array(),
);

function sn_health_last_scan() { return $GLOBALS['__d']['health']; }
function snt_ai_usage_summary( $days = 30 ) { return $GLOBALS['__d']['ai']; }
function snt_cron_summary_for_localize() { return $GLOBALS['__d']['cron']; }
function sn_login_defense_headline() { return $GLOBALS['__d']['login']; }
function sn_analytics_config() { return $GLOBALS['__d']['config']; }
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) { return $GLOBALS['__d']['deltas']; }
// snt_dashboard_override_count() is defined inside the file under test; it calls
// get_posts() with fields=ids. Drive the override count via this stub so we
// don't redeclare the real function.
function get_posts( $a = array() ) { return $GLOBALS['__d']['overrides_ids']; }
// Deploy-status accessor the file relies on (snt_deploy_status_for is defined
// in the file; it calls these two).
function wp_get_theme( $s = null ) { return new class { public function get( $k ) { return '10.18.0'; } }; }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '6.42.0' ); }
function apply_filters( $t, $v = null ) { return $v; }

require_once __DIR__ . '/../inc/admin-glance.php';
require_once __DIR__ . '/../inc/admin-tab-dashboard.php';

function dg_contains( $h, $n, $msg ) {
	global $pass, $fail;
	if ( false !== strpos( $h, $n ) ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Missing: $n\n"; }
}
function dg_absent( $h, $n, $msg ) {
	global $pass, $fail;
	if ( false === strpos( $h, $n ) ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Unexpectedly present: $n\n"; }
}
function dg_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

$theme  = snt_deploy_status_for( 'theme' );
$plugin = snt_deploy_status_for( 'plugin' );

// ─── Test A: card builder emits the expected cards ───────────────────
echo "Test A: glance card builder (all sources available)\n";
$cards = snt_dashboard_glance_cards( $theme, $plugin, array(), '—' );
$labels = array_map( function ( $c ) { return $c['label'] ?? ''; }, $cards );
dg_assert( in_array( 'Theme', $labels, true ), 'includes a Theme card' );
dg_assert( in_array( 'Plugin', $labels, true ), 'includes a Plugin card' );
dg_assert( in_array( 'Health', $labels, true ), 'includes a Health card (sn_health_last_scan present)' );
dg_assert( in_array( 'AI spend 30d', $labels, true ), 'includes an AI spend card (snt_ai_usage_summary present)' );
dg_assert( in_array( 'Cron', $labels, true ), 'includes a Cron card (snt_cron_summary_for_localize present)' );
dg_assert( in_array( 'Login blocks 7d', $labels, true ), 'includes a Login blocks card (sn_login_defense_headline present)' );
dg_assert( in_array( 'Views 7d', $labels, true ), 'includes a Views card (analytics configured)' );

// The grid renders via sn_admin_glance_grid.
ob_start();
sn_admin_glance_grid( $cards );
$grid = ob_get_clean();
dg_contains( $grid, '<div class="sn-glance">', 'cards render through the glance grid helper' );
dg_contains( $grid, '42', 'login blocks value (42) rendered' );

// ─── Test B: a missing accessor OMITS its card (never fabricated) ────
echo "\nTest B: absent accessor omits its card\n";
// Re-evaluate the builder behaviour: with login NOT configured, no login card.
$GLOBALS['__d']['login'] = array( 'configured' => false, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '' );
$cards_b = snt_dashboard_glance_cards( $theme, $plugin, array(), '—' );
$labels_b = array_map( function ( $c ) { return $c['label'] ?? ''; }, $cards_b );
dg_assert( ! in_array( 'Login blocks 7d', $labels_b, true ), 'login card omitted when not configured' );
// Analytics not configured → no Views card.
$GLOBALS['__d']['config'] = false;
$cards_b2 = snt_dashboard_glance_cards( $theme, $plugin, array(), '—' );
$labels_b2 = array_map( function ( $c ) { return $c['label'] ?? ''; }, $cards_b2 );
dg_assert( ! in_array( 'Views 7d', $labels_b2, true ), 'views card omitted when analytics not configured' );
// Restore.
$GLOBALS['__d']['login']  = array( 'configured' => true, 'blocked' => 42, 'block_rate' => 18, 'top_network' => 'AS1234' );
$GLOBALS['__d']['config'] = true;

// ─── Test C: attention strip ABSENT when nothing is off ──────────────
echo "\nTest C: attention strip hidden when all clear\n";
$GLOBALS['__d']['health']['checks'] = array( 'missing_alt' => array( 'count' => 0 ) );
$GLOBALS['__d']['cron']['orphans']  = 0;
ob_start();
snt_dashboard_render_attention_strip( array() /* runs */, 0 /* overrides */ );
$strip_clear = ob_get_clean();
dg_assert( '' === trim( $strip_clear ), 'no attention strip when nothing is off' );

// ─── Test D: attention strip SHOWN when something is off ─────────────
echo "\nTest D: attention strip shown on findings / overrides / orphans\n";
$GLOBALS['__d']['health']['checks'] = array( 'missing_alt' => array( 'count' => 3 ) );
ob_start();
snt_dashboard_render_attention_strip( array(), 5 /* overrides */ );
$strip = ob_get_clean();
dg_assert( '' !== trim( $strip ), 'attention strip renders when something is off' );
dg_contains( $strip, 'notice-warning', 'attention strip uses a warning treatment' );
dg_contains( $strip, '5', 'attention strip mentions the override count' );

// ─── Test D2: attention strip STALE-SCAN branch ──────────────────────
// 0 findings, 0 orphans, 0 overrides — the ONLY trigger is a scan older than
// the health cache TTL (defaults to DAY_IN_SECONDS when the const is absent).
echo "\nTest D2: attention strip — stale-scan branch\n";
$GLOBALS['__d']['health'] = array(
	'scanned_at' => time() - ( DAY_IN_SECONDS + 1 ),
	'elapsed_ms' => 900,
	'checks'     => array( 'missing_alt' => array( 'count' => 0 ) ),
);
$GLOBALS['__d']['cron']['orphans'] = 0;
ob_start();
snt_dashboard_render_attention_strip( array() /* runs */, 0 /* overrides */ );
$strip_stale = ob_get_clean();
dg_assert( '' !== trim( $strip_stale ), 'attention strip renders when the scan is stale' );
dg_contains( $strip_stale, 'stale', 'stale-scan message is surfaced' );
// And a FRESH scan with 0 findings/orphans/overrides shows nothing.
$GLOBALS['__d']['health']['scanned_at'] = time() - 60;
ob_start();
snt_dashboard_render_attention_strip( array(), 0 );
$strip_fresh = ob_get_clean();
dg_assert( '' === trim( $strip_fresh ), 'a fresh clean scan does not trigger the strip' );

// ─── Test D3: attention strip FAILED-DEPLOY branch ───────────────────
echo "\nTest D3: attention strip — failed-deploy branch\n";
$GLOBALS['__d']['health'] = array(
	'scanned_at' => time() - 60,
	'elapsed_ms' => 900,
	'checks'     => array( 'missing_alt' => array( 'count' => 0 ) ),
);
$failed_runs = array(
	array( 'status' => 'completed', 'conclusion' => 'failure' ),
);
ob_start();
snt_dashboard_render_attention_strip( $failed_runs, 0 );
$strip_deploy = ob_get_clean();
dg_assert( '' !== trim( $strip_deploy ), 'attention strip renders when a recent deploy failed' );
dg_contains( $strip_deploy, 'deploy failed', 'failed-deploy message is surfaced' );
// A successful run does NOT trigger the failed-deploy item.
ob_start();
snt_dashboard_render_attention_strip( array( array( 'status' => 'completed', 'conclusion' => 'success' ) ), 0 );
$strip_ok_run = ob_get_clean();
dg_assert( '' === trim( $strip_ok_run ), 'a successful deploy does not trigger the strip' );

// ─── Test D4: glance Health card — no-scan (null) path ───────────────
echo "\nTest D4: glance Health card when sn_health_last_scan returns null\n";
$GLOBALS['__d']['health'] = null;
$cards_noscan = snt_dashboard_glance_cards( $theme, $plugin, array(), '—' );
$health_card  = null;
foreach ( $cards_noscan as $c ) {
	if ( 'Health' === ( $c['label'] ?? '' ) ) { $health_card = $c; break; }
}
dg_assert( null !== $health_card, 'a Health card is still emitted when no scan has run' );
dg_assert( null !== $health_card && 'no scan' === ( $health_card['value'] ?? '' ), 'no-scan Health card reads "no scan"' );
dg_assert( null !== $health_card && 'warn' === ( $health_card['pill']['kind'] ?? '' ), 'no-scan Health card carries a warn pill' );
// Restore a healthy scan for Test E.
$GLOBALS['__d']['health'] = array(
	'scanned_at' => time() - 3600,
	'elapsed_ms' => 900,
	'checks'     => array( 'missing_alt' => array( 'count' => 0 ) ),
);

// ─── Test E: full tab render still works + opens with the glance grid ─
echo "\nTest E: full dashboard render includes the glance grid\n";
function current_user_can( $c ) { return true; }
function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; }
function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u . '&_n=1'; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
ob_start();
snt_dashboard_tab_render();
$tab = ob_get_clean();
dg_contains( $tab, '<div class="sn-glance">', 'dashboard tab opens with the glance grid' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
