<?php
/**
 * Tests inc/privacy-exporters.php — the GDPR personal-data exporter + eraser
 * for login-audit usernames, plus the suggested Privacy Policy text.
 *
 * The only persisted PII is plaintext usernames in SN_AUDIT_OPTION
 * (`sn_audit_log_v1`) `login_success[]` rows (each { ts, user }). Aggregate
 * counters + the ephemeral 25h salted-hash IP transient are NOT exported or
 * erased (no per-person PII).
 *
 * Pure-PHP CLI harness — no WordPress runtime. Stubs match the real WP return
 * shapes so the exporter/eraser contracts are exercised for real, not just
 * registered.
 *
 * @since plugin v4.10.0
 */

// SECURITY: CLI / WP-CLI only (mirrors sibling fixtures).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

/* ── WordPress stubs (shapes match real core) ───────────────────────────── */

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__options']         = array();
$GLOBALS['__filters']         = array();
$GLOBALS['__actions']         = array();
$GLOBALS['__users_by_email']  = array();
$GLOBALS['__privacy_content'] = array();
$GLOBALS['__settings']        = array(); // path => value for sn_setting() stub
$GLOBALS['__webhooks_all']    = array(); // sn_webhooks_all() stub return

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = $cb;
	return true;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $cb;
	return true;
}

// Minimal WP_User: only ->user_login is consulted by the exporter/eraser.
class WP_User {
	public $ID;
	public $user_login;
	public function __construct( $id, $login ) {
		$this->ID         = (int) $id;
		$this->user_login = (string) $login;
	}
}
function get_user_by( $field, $value ) {
	if ( 'email' === $field && isset( $GLOBALS['__users_by_email'][ $value ] ) ) {
		return $GLOBALS['__users_by_email'][ $value ];
	}
	return false;
}

function wp_date( $format, $ts = null ) {
	if ( null === $ts ) {
		$ts = time();
	}
	return gmdate( $format, (int) $ts );
}

// translation + escaping passthroughs
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function wpautop( $s ) { return '<p>' . str_replace( "\n\n", "</p>\n<p>", trim( (string) $s ) ) . '</p>'; }
function wp_kses_post( $s ) { return (string) $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }

// sn_setting() stub — reads from $GLOBALS['__settings'] with default fallback.
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}

// sn_webhooks_all() stub (lives in inc/webhooks.php at runtime; stubbed here).
function sn_webhooks_all() {
	return $GLOBALS['__webhooks_all'];
}

// WP_Privacy_Policy_Content::add() captures plugin_name + policy_text.
class WP_Privacy_Policy_Content {
	public static function add( $plugin_name, $policy_text ) {
		$GLOBALS['__privacy_content'][] = array(
			'plugin_name' => $plugin_name,
			'policy_text' => $policy_text,
		);
	}
}
// The wrapper wp_add_privacy_policy_content() is defined per-test so the
// function_exists() guard can be exercised (Task 4 fatal-safety assertion).

/* ── Load the audit-log constants + the module under test ────────────────── */

// We only need SN_AUDIT_OPTION + the blob shape; define the constant directly
// rather than loading inc/audit-log.php (which registers hooks/REST at load).
const SN_AUDIT_OPTION = 'sn_audit_log_v1';

require __DIR__ . '/../inc/privacy-exporters.php';

/* ── Assertion helpers ──────────────────────────────────────────────────── */

$pass = 0;
$fail = 0;

function pv_eq( $expected, $actual, $label ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label — expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}
function pv_true( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

function pv_seed_audit_rows() {
	$GLOBALS['__options'][ SN_AUDIT_OPTION ] = array(
		'schema_version' => 1,
		'created_at'     => 1000,
		'counters'       => array( '2026-06-01' => array( 'login_failed' => 5 ) ),
		'login_success'  => array(
			array( 'ts' => 1700000000, 'user' => 'juan' ),
			array( 'ts' => 1700000100, 'user' => 'someoneelse' ),
			array( 'ts' => 1700000200, 'user' => 'juan' ),
		),
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * ITEM A — exporter + eraser
 * ════════════════════════════════════════════════════════════════════════ */

// Filters registered.
pv_true( in_array( 'sn_privacy_register_exporter', $GLOBALS['__filters']['wp_privacy_personal_data_exporters'] ?? array(), true ),
	'exporter filter callback registered' );
pv_true( in_array( 'sn_privacy_register_eraser', $GLOBALS['__filters']['wp_privacy_personal_data_erasers'] ?? array(), true ),
	'eraser filter callback registered' );

// Registrar shape — adds the slug with friendly name + callback.
$exporters = sn_privacy_register_exporter( array() );
pv_true( isset( $exporters['signal-noise-tools'] ), 'exporter registrar adds signal-noise-tools key' );
pv_eq( 'sn_privacy_export_login_audit', $exporters['signal-noise-tools']['callback'] ?? null, 'exporter callback wired' );
pv_true( ! empty( $exporters['signal-noise-tools']['exporter_friendly_name'] ), 'exporter has a friendly name' );

$erasers = sn_privacy_register_eraser( array() );
pv_true( isset( $erasers['signal-noise-tools'] ), 'eraser registrar adds signal-noise-tools key' );
pv_eq( 'sn_privacy_erase_login_audit', $erasers['signal-noise-tools']['callback'] ?? null, 'eraser callback wired' );
pv_true( ! empty( $erasers['signal-noise-tools']['eraser_friendly_name'] ), 'eraser has a friendly name' );

// Matcher splits matched vs remaining rows.
pv_seed_audit_rows();
$blob = get_option( SN_AUDIT_OPTION );
list( $matched, $remaining ) = sn_privacy_match_login_rows( $blob, 'juan' );
pv_eq( 2, count( $matched ), 'matcher returns 2 matched rows for juan' );
pv_eq( 1, count( $remaining ), 'matcher returns 1 remaining row (someoneelse)' );
pv_eq( 'someoneelse', $remaining[0]['user'], 'matcher remaining row is someoneelse' );

list( $m0, $r0 ) = sn_privacy_match_login_rows( array(), 'juan' );
pv_eq( 0, count( $m0 ), 'matcher tolerates a blob with no login_success key (matched=0)' );
pv_eq( 0, count( $r0 ), 'matcher tolerates a blob with no login_success key (remaining=0)' );

/* Exporter: known user with 2 matching rows → 2 items, done=true. */
pv_seed_audit_rows();
$GLOBALS['__users_by_email']['juan@example.com'] = new WP_User( 7, 'juan' );
$resp = sn_privacy_export_login_audit( 'juan@example.com', 1 );
pv_true( array_key_exists( 'data', $resp ) && array_key_exists( 'done', $resp ), 'exporter returns data + done keys (strict contract)' );
pv_true( is_array( $resp['data'] ), 'exporter data is an array' );
pv_eq( true, $resp['done'], 'exporter done=true (single page)' );
pv_eq( 2, count( $resp['data'] ), 'exporter returns 2 items for juan' );
// Each item must carry group_id, group_label, item_id, and data[] of {name,value}.
$item = $resp['data'][0];
pv_eq( 'sn-login-audit', $item['group_id'], 'export item group_id is sn-login-audit' );
pv_true( ! empty( $item['group_label'] ), 'export item has a group_label' );
pv_true( ! empty( $item['item_id'] ), 'export item has an item_id' );
pv_true( is_array( $item['data'] ), 'export item data is an array' );
$names = array_column( $item['data'], 'name' );
pv_true( in_array( 'Username', $names, true ), 'export item includes a Username field' );
$values = array_column( $item['data'], 'value' );
pv_true( in_array( 'juan', $values, true ), 'export item Username value is juan' );

/* Exporter: unknown email → empty data, done=true. */
$resp_none = sn_privacy_export_login_audit( 'nobody@example.com', 1 );
pv_eq( array(), $resp_none['data'], 'exporter returns empty data for unknown email' );
pv_eq( true, $resp_none['done'], 'exporter done=true for unknown email' );

/* Eraser: known user → removes 2 rows, retains 1, contract keys present. */
pv_seed_audit_rows();
$GLOBALS['__users_by_email']['juan@example.com'] = new WP_User( 7, 'juan' );
$er = sn_privacy_erase_login_audit( 'juan@example.com', 1 );
foreach ( array( 'items_removed', 'items_retained', 'messages', 'done' ) as $k ) {
	pv_true( array_key_exists( $k, $er ), "eraser response has '$k' key (strict contract)" );
}
pv_eq( true, $er['items_removed'], 'eraser items_removed=true when rows removed' );
pv_eq( false, $er['items_retained'], 'eraser items_retained=false' );
pv_eq( true, $er['done'], 'eraser done=true' );
pv_true( is_array( $er['messages'] ), 'eraser messages is an array' );
// The persisted blob now retains exactly the one non-matching row.
$after = get_option( SN_AUDIT_OPTION );
pv_eq( 1, count( $after['login_success'] ), 'eraser persisted blob retains 1 row' );
pv_eq( 'someoneelse', $after['login_success'][0]['user'], 'eraser kept the someoneelse row' );
// Non-PII subtrees untouched.
pv_true( isset( $after['counters']['2026-06-01'] ), 'eraser leaves aggregate counters intact' );
pv_eq( 1, $after['schema_version'], 'eraser leaves schema_version intact' );

/* Eraser: unknown email → no-op (items_removed=false), blob unchanged. */
pv_seed_audit_rows();
$er_none = sn_privacy_erase_login_audit( 'nobody@example.com', 1 );
pv_eq( false, $er_none['items_removed'], 'eraser items_removed=false for unknown email' );
pv_eq( true, $er_none['done'], 'eraser done=true for unknown email' );
$after_none = get_option( SN_AUDIT_OPTION );
pv_eq( 3, count( $after_none['login_success'] ), 'eraser unknown-email no-op leaves all 3 rows' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
