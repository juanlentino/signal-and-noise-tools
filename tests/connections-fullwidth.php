<?php
/**
 * Standalone test: Connections-tab full-width conversion (Phase 3, v6.45.0).
 *
 * Locks: every Connections leaf is marked 'wide'; the cron + scheduled glance
 * helpers; the webhooks render emits the two-column shell with the payload
 * reference in the rail; and cloudflare/cron wire the shell / glance grid.
 *
 * Run: php tests/connections-fullwidth.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function cf_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { return $a == $b ? ' checked' : ''; } } // phpcs:ignore
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $k, $d = null ) { return $d; } }

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/admin-glance.php';
require_once __DIR__ . '/../inc/cron-dashboard-admin.php';
require_once __DIR__ . '/../inc/schedule-admin.php';

// ─── 1. Registry: every Connections leaf is marked 'wide' ───────────────────
echo "Group: registry — Connections leaves are full-width\n";
$reg = (string) file_get_contents( __DIR__ . '/../inc/admin-tabs-data.php' );
foreach ( array( 'cloudflare', 'webhooks', 'cron', 'scheduled-content' ) as $leaf ) {
	cf_assert(
		(bool) preg_match( "/'" . preg_quote( $leaf, '/' ) . "'\\s*=>\\s*array\\([^\\n]*'wide'\\s*=>\\s*true/", $reg ),
		"leaf '$leaf' is marked 'wide'"
	);
}

// ─── 2. Cron glance helper ──────────────────────────────────────────────────
echo "\nGroup: snt_cron_glance_cards()\n";
$cron_cards = snt_cron_glance_cards( array(
	array( 'is_sn_owned' => true,  'has_handler' => true ),
	array( 'is_sn_owned' => false, 'has_handler' => false ), // orphan
	array( 'is_sn_owned' => true,  'has_handler' => true ),
) );
cf_assert( 3 === count( $cron_cards ), 'emits 3 hero cards' );
cf_assert( '3' === $cron_cards[0]['value'], 'total events = row count (3)' );
cf_assert( '2' === $cron_cards[1]['value'], 'Signal & Noise count = owned rows (2)' );
cf_assert( '1' === $cron_cards[2]['value'] && 'warn' === $cron_cards[2]['pill']['kind'], 'orphan count (1) pills warn' );
$cron_clean = snt_cron_glance_cards( array( array( 'is_sn_owned' => true, 'has_handler' => true ) ) );
cf_assert( 'ok' === $cron_clean[2]['pill']['kind'], 'zero orphans pills ok' );

// ─── 3. Scheduled glance helper ─────────────────────────────────────────────
echo "\nGroup: snt_schedule_glance_cards()\n";
$sch_cards = snt_schedule_glance_cards( array( 1, 2 ), array( 3 ) );
cf_assert( 3 === count( $sch_cards ), 'emits 3 hero cards' );
cf_assert( '3' === $sch_cards[0]['value'], 'total = fragments + posts (3)' );
cf_assert( '2' === $sch_cards[1]['value'], 'fragments count (2)' );
cf_assert( '1' === $sch_cards[2]['value'], 'future posts count (1)' );

// ─── 4. Webhooks render: shell + payload reference in the rail ──────────────
echo "\nGroup: webhooks render — shell with payload in the rail\n";
if ( ! function_exists( 'sn_webhooks_all' ) ) { function sn_webhooks_all() { return array(); } }
if ( ! function_exists( 'sn_webhook_events' ) ) { function sn_webhook_events() { return array(); } }
require_once __DIR__ . '/../inc/webhooks-admin.php';
ob_start();
sn_webhooks_render_admin_tab();
$wh = ob_get_clean();
$wh_rail = strpos( $wh, '<aside class="sn-shell__rail"' );
cf_assert( false !== strpos( $wh, '<div class="sn-shell">' ), 'webhooks renders the two-column shell' );
cf_assert( false !== $wh_rail, 'webhooks has a rail' );
$wh_add = strpos( $wh, 'Add a webhook' );
$wh_pay = strpos( $wh, 'Payload reference' );
cf_assert( false !== $wh_add && is_int( $wh_rail ) && $wh_add < $wh_rail, 'the add-webhook form sits in the main column' );
cf_assert( false !== $wh_pay && is_int( $wh_rail ) && $wh_pay > $wh_rail, 'the payload reference sits in the rail' );
cf_assert( 1 === substr_count( $wh, '</aside>' ), 'webhooks rail aside closes exactly once (balanced shell)' );

// ─── 5. Source wiring: cloudflare uses the shell; cron renders the glance hero
echo "\nGroup: source wiring (cloudflare shell, cron glance)\n";
$cf_src = (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' );
cf_assert(
	1 === substr_count( $cf_src, 'sn_admin_shell_open()' )
	&& 1 === substr_count( $cf_src, 'sn_admin_shell_rail(' )
	&& 1 === substr_count( $cf_src, 'sn_admin_shell_close()' ),
	'cloudflare wires a single balanced shell (open/rail/close)'
);
$cron_src = (string) file_get_contents( __DIR__ . '/../inc/cron-dashboard-admin.php' );
cf_assert( false !== strpos( $cron_src, 'sn_admin_glance_grid( snt_cron_glance_cards(' ), 'cron renders the glance hero' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
