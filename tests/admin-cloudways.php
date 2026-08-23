<?php
/**
 * Standalone tests for inc/admin-forms/cloudways.php — the display-only leaf.
 *
 * Drives the REAL builders (sn_admin_cloudways_outcome / _cards) with injected
 * state. The assertions that matter are the ones about what this surface must
 * NEVER do: render a credential value, or collapse the purge module's four
 * distinct outcomes into pass/fail.
 *
 * @package SignalNoiseTools
 * @since 12.17.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
define( 'SN_ADMIN_CLOUDWAYS_TEST', true );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! defined( 'SNT_CW_LAST_PURGE_OPT' ) ) { define( 'SNT_CW_LAST_PURGE_OPT', 'sn_cloudways_last_purge' ); }

require_once __DIR__ . '/../inc/admin-forms/cloudways.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
ob_start();
echo "Connections → Cloudways status leaf (v12.17.0)\n\n";

// ---- the four constants, names only ---------------------------------------
$consts = sn_admin_cloudways_constants();
ok( count( $consts ) === 4, 'names the four credentials the purge module requires' );
ok( in_array( 'SN_CLOUDWAYS_API_KEY', $consts, true ), 'includes the API key constant' );

// ---- outcome classification: the four states the module bothers to record --
$never = sn_admin_cloudways_outcome( null );
ok( 'Never run' === $never['value'], 'no record → "Never run"' );
ok( 'err' !== $never['kind'], 'NEVER-RUN IS NOT AN ERROR — a site that has not purged has nothing wrong' );

$failed = sn_admin_cloudways_outcome( array( 'ok' => false, 'stage' => 'auth', 'http' => 401, 'error' => 'bad credential' ) );
ok( 'Failed' === $failed['value'] && 'err' === $failed['kind'], 'ok=false → Failed/err' );
ok( false !== strpos( $failed['meta'], 'bad credential' ), 'the captured error envelope is actually shown' );
ok( false !== strpos( $failed['meta'], 'auth' ) && false !== strpos( $failed['meta'], '401' ), 'stage and HTTP are named, not left to inference' );

$inconc = sn_admin_cloudways_outcome( array( 'ok' => false, 'inconclusive' => true, 'error' => 'timeout' ) );
ok( 'Inconclusive' === $inconc['value'], 'inconclusive is its OWN outcome, not a failure' );
ok( 'err' !== $inconc['kind'], 'inconclusive never renders as an error — we simply never heard back' );

$reauth = sn_admin_cloudways_outcome( array( 'ok' => true, 'reauthed' => true ) );
ok( 'warn' === $reauth['kind'], 'a purge that only worked after re-auth warns rather than reading clean' );

$coal = sn_admin_cloudways_outcome( array( 'ok' => true, 'coalesced' => true ) );
ok( 'Coalesced' === $coal['value'] && 'ok' === $coal['kind'], 'coalesced is distinguishable from a fresh 200' );

$clean = sn_admin_cloudways_outcome( array( 'ok' => true, 'stage' => 'dispatch', 'http' => 200 ) );
ok( 'OK' === $clean['value'] && 'ok' === $clean['kind'], 'a clean dispatch reads OK' );

// All five outcomes are distinct — the point of not collapsing them.
$values = array( $never['value'], $failed['value'], $inconc['value'], $reauth['value'], $coal['value'], $clean['value'] );
ok( count( array_unique( $values ) ) === 6, 'all six outcomes are distinguishable from one another' );

// ---- cards ----------------------------------------------------------------
$cards = sn_admin_cloudways_cards( array( 'configured' => true, 'missing' => array(), 'last' => array( 'ok' => true, 'http' => 200 ), 'ago' => '3 hours ago' ) );
ok( count( $cards ) === 3, 'renders exactly three glance cards' );
ok( 'Configured' === $cards[0]['value'], 'configured state reads Configured' );
ok( '3 hours ago' === $cards[1]['value'], 'last-purge card shows the injected relative time' );
foreach ( $cards as $c ) {
	ok( ! empty( $c['label'] ) && isset( $c['value'] ), 'card "' . $c['label'] . '" has label + value' );
}
$kinds = array();
foreach ( $cards as $c ) { if ( isset( $c['pill']['kind'] ) ) { $kinds[] = $c['pill']['kind']; } }
ok( ! array_diff( $kinds, array( 'ok', 'warn', 'err' ) ), 'every pill kind is one the grid will actually render' );

$un = sn_admin_cloudways_cards( array( 'configured' => false, 'missing' => array( 'SN_CLOUDWAYS_API_KEY' ), 'last' => null, 'ago' => '' ) );
ok( 'Not configured' === $un[0]['value'], 'unconfigured state reads Not configured' );
ok( false !== strpos( $un[0]['meta_html'], 'SN_CLOUDWAYS_API_KEY' ), 'names the MISSING constant so the fix is actionable' );
ok( 'Never' === $un[1]['value'], 'no record → Never' );

// ---- THE SECURITY ASSERTION -----------------------------------------------
// The four credentials are wp-config-only because the API key is account-wide.
// This surface must render whether they are PRESENT and never what they hold.
// ALL FOUR, deliberately. With only some defined, state() computes
// configured=false and the CONFIGURED branch — the only one that could ever
// touch a credential value — never runs. A mutation test proved that version of
// this assertion passed with a live leak in the code: it was checking a path
// structurally incapable of containing a secret.
define( 'SN_CLOUDWAYS_EMAIL', 'owner@example.test' );
define( 'SN_CLOUDWAYS_API_KEY', 'super-secret-account-wide-key' );
define( 'SN_CLOUDWAYS_SERVER_ID', 'srv-999999' );
define( 'SN_CLOUDWAYS_APP_ID', 'app-888888' );
$blob = wp_json_encode_shim( sn_admin_cloudways_cards( sn_admin_cloudways_state_shim() ) );
ok( false === strpos( $blob, 'super-secret-account-wide-key' ), 'NEVER renders the API key value' );
ok( false === strpos( $blob, 'owner@example.test' ), 'NEVER renders the account email' );
$state_now = sn_admin_cloudways_state_shim();
ok( true === $state_now['configured'], 'the security assertion runs against the CONFIGURED branch (not a vacuous path)' );
ok( false === strpos( $blob, 'srv-999999' ) && false === strpos( $blob, 'app-888888' ), 'NEVER renders the server or app id' );
$blob_un = wp_json_encode_shim( sn_admin_cloudways_cards( array( 'configured' => false, 'missing' => sn_admin_cloudways_constants(), 'last' => null, 'ago' => '' ) ) );
ok( false !== strpos( $blob_un, 'SN_CLOUDWAYS_' ), 'the unconfigured card does render constant NAMES (that is the actionable part)' );

$report = ob_get_clean(); echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

/** Minimal stand-ins so the security assertion can run without WP. */
function wp_json_encode_shim( $d ) { return json_encode( $d ); }
function sn_admin_cloudways_state_shim() {
	$missing = array();
	foreach ( sn_admin_cloudways_constants() as $n ) {
		if ( ! defined( $n ) || '' === (string) constant( $n ) ) { $missing[] = $n; }
	}
	return array( 'configured' => empty( $missing ), 'missing' => $missing, 'last' => null, 'ago' => '' );
}
