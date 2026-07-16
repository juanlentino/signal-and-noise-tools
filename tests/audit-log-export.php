<?php
/**
 * Standalone fixture tests for inc/audit-log-export.php (v4.10.0).
 *
 * Verifies the PURE builders:
 *   - sn_audit_export_build_json() decodes to an array carrying counters,
 *     login_successes, retention_days, and a seeded username.
 *   - sn_audit_export_build_csv() emits both section markers, a seeded date +
 *     username, and correctly QUOTES a comma-containing username (the fputcsv
 *     correctness property).
 *   - snt_ability_export_audit_log() returns { format, content } and defaults
 *     to json on null input.
 *
 * The exit()ing admin_post download handler is NOT exercised here (it streams
 * + exit()s). The handler is a thin wrapper around the builders.
 *
 * The source-of-truth impls (snt_audit_get_counters_impl /
 * snt_audit_get_login_successes_impl) are stubbed with fixtures matching their
 * REAL row shapes verified against inc/audit-log.php:171-223 —
 *   counters: { date, login_failed, wp_login_404, wp_admin_unauth_404,
 *               lockout_triggered, password_reset, unique_ips_count }
 *   logins:   { ts, user, formatted }
 * so the builder is fed exactly what production feeds it.
 *
 * Run: php tests/audit-log-export.php
 *
 * @since plugin v4.10.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
}

// sn_setting drives the retention_days reported by the JSON builder.
$GLOBALS['__test_retention_days'] = 30;
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = null ) {
		if ( 'audit.retention_days' === $key ) {
			return $GLOBALS['__test_retention_days'];
		}
		return $default;
	}
}

// ─── WP_Error + is_wp_error (the ability may return one) ──────────────
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $c = '', $m = '', $d = array() ) {
			$this->code    = $c;
			$this->message = $m;
			$this->data    = $d;
		}
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data()    { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

// ─── Source impl stubs (shapes verified against inc/audit-log.php) ─────
// One counter row + two login rows. One login username contains a comma to
// prove CSV quoting; another carries a known date string + plain username.
if ( ! function_exists( 'snt_audit_get_counters_impl' ) ) {
	function snt_audit_get_counters_impl( $days = 30 ) {
		return array(
			array(
				'date'                => '2026-06-01',
				'login_failed'        => 4,
				'wp_login_404'        => 12,
				'wp_admin_unauth_404' => 3,
				'lockout_triggered'   => 1,
				'password_reset'      => 0,
				'unique_ips_count'    => 7,
			),
		);
	}
}
// v9.51.0 (lane SEC-C, R8): mode-switchable via $GLOBALS['__test_many_logins_mode']
// so the page-size-cap end-to-end test (below) can prove the cap engages
// against a source impl returning MORE rows than the cap, without redeclaring
// this function mid-file (a fatal error) — one definition, one flag.
$GLOBALS['__test_many_logins_mode'] = false;
if ( ! function_exists( 'snt_audit_get_login_successes_impl' ) ) {
	function snt_audit_get_login_successes_impl( $days = 30 ) {
		if ( ! empty( $GLOBALS['__test_many_logins_mode'] ) ) {
			$rows = array();
			for ( $i = 0; $i < SNT_AUDIT_EXPORT_LOGINS_PAGE_CAP + 50; $i++ ) {
				$rows[] = array( 'ts' => $i, 'user' => "user$i", 'formatted' => "f$i" );
			}
			return $rows;
		}
		return array(
			array(
				'ts'        => 1748736000,
				'user'      => 'juan',
				'formatted' => '2026-06-01 00:00:00',
			),
			array(
				'ts'        => 1748739600,
				'user'      => 'Last, First',  // comma forces CSV field quoting
				'formatted' => '2026-06-01 01:00:00',
			),
		);
	}
}

// ─── Load the SUT ─────────────────────────────────────────────────────
require __DIR__ . '/../inc/audit-log-export.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function assertEq( $expected, $actual, $label ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "FAIL: $label — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n"; }
}
function assertTrue( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "FAIL: $label\n"; }
}

// ════════════════════════════════════════════════════════════════════
// JSON builder
// ════════════════════════════════════════════════════════════════════

$view = array(
	'days'            => 30,
	'counters'        => snt_audit_get_counters_impl( 30 ),
	'login_successes' => snt_audit_get_login_successes_impl( 30 ),
);

$json = sn_audit_export_build_json( $view );
assertTrue( is_string( $json ) && '' !== $json, 'JSON builder returns a non-empty string' );

$decoded = json_decode( $json, true );
assertTrue( is_array( $decoded ), 'JSON decodes to an array' );
assertTrue( isset( $decoded['counters'], $decoded['login_successes'], $decoded['retention_days'] ), 'JSON has counters + login_successes + retention_days' );
assertEq( 30, $decoded['retention_days'], 'JSON retention_days = 30 (from sn_setting fixture)' );
assertEq( 'https://juanlentino.com/', $decoded['site'], 'JSON site = home_url' );
assertTrue( isset( $decoded['generated_at'] ) && is_int( $decoded['generated_at'] ), 'JSON generated_at is an int timestamp' );
assertEq( 'juan', $decoded['login_successes'][0]['user'], 'JSON carries seeded username juan' );
assertEq( 4, $decoded['counters'][0]['login_failed'], 'JSON carries seeded counter value' );
assertEq( '2026-06-01', $decoded['counters'][0]['date'], 'JSON carries seeded counter date' );

// ════════════════════════════════════════════════════════════════════
// CSV builder
// ════════════════════════════════════════════════════════════════════

$csv = sn_audit_export_build_csv( $view );
assertTrue( is_string( $csv ) && '' !== $csv, 'CSV builder returns a non-empty string' );
assertTrue( false !== strpos( $csv, '# counters' ), 'CSV has the # counters section marker' );
assertTrue( false !== strpos( $csv, '# login_successes' ), 'CSV has the # login_successes section marker' );
assertTrue( false !== strpos( $csv, '2026-06-01' ), 'CSV contains a seeded date' );
assertTrue( false !== strpos( $csv, 'juan' ), 'CSV contains the seeded username juan' );

// fputcsv quotes a field containing a comma — the comma-username must appear
// wrapped in double quotes, NOT split across two columns.
assertTrue( false !== strpos( $csv, '"Last, First"' ), 'CSV quotes the comma-containing username correctly' );
// And it must NOT leak as an unquoted Last,First column pair.
$has_unquoted = ( false !== strpos( $csv, 'Last, First' ) ) && ( false === strpos( $csv, '"Last, First"' ) );
assertTrue( ! $has_unquoted, 'CSV does not emit the comma username unquoted' );

// CSV / spreadsheet formula injection: a username beginning with =, +, -, or @
// (a formula trigger in Excel/Sheets) must be neutralized with a leading single
// quote so it renders as text, not evaluated. The username is the user-
// controllable field and the export ability is REST-reachable.
$inj_view = array(
	'days'            => 30,
	'counters'        => array(),
	'login_successes' => array(
		array( 'ts' => 1, 'user' => '=1+1',     'formatted' => 'f1' ),
		array( 'ts' => 2, 'user' => '+phone',   'formatted' => 'f2' ),
		array( 'ts' => 3, 'user' => '-cmd',     'formatted' => 'f3' ),
		array( 'ts' => 4, 'user' => '@at',      'formatted' => 'f4' ),
		array( 'ts' => 5, 'user' => 'safeuser', 'formatted' => 'f5' ),
	),
);
$inj_csv = sn_audit_export_build_csv( $inj_view );
assertTrue( false !== strpos( $inj_csv, "'=1+1" ),     'CSV neutralizes a leading = (formula trigger)' );
assertTrue( false !== strpos( $inj_csv, "'+phone" ),   'CSV neutralizes a leading +' );
assertTrue( false !== strpos( $inj_csv, "'-cmd" ),     'CSV neutralizes a leading -' );
assertTrue( false !== strpos( $inj_csv, "'@at" ),      'CSV neutralizes a leading @' );
assertTrue( false === strpos( $inj_csv, "'safeuser" ), 'CSV does not prefix a safe username (no over-quoting)' );

// Section ordering: # counters appears before # login_successes.
assertTrue( strpos( $csv, '# counters' ) < strpos( $csv, '# login_successes' ), 'CSV counters section precedes login_successes section' );

// ════════════════════════════════════════════════════════════════════
// Ability wrapper
// ════════════════════════════════════════════════════════════════════

$out = snt_ability_export_audit_log( array( 'format' => 'csv' ) );
assertTrue( is_array( $out ) && isset( $out['format'], $out['content'] ), 'ability: returns { format, content }' );
assertEq( 'csv', $out['format'], 'ability: format echoes csv' );
assertTrue( is_string( $out['content'] ) && '' !== $out['content'], 'ability: csv content non-empty' );
assertTrue( false !== strpos( $out['content'], '# counters' ), 'ability: csv content has section marker' );

$out_json = snt_ability_export_audit_log( array( 'format' => 'json' ) );
assertEq( 'json', $out_json['format'], 'ability: format echoes json' );
assertTrue( is_array( json_decode( $out_json['content'], true ) ), 'ability: json content decodes' );

// null input → defaults to json
$out_null = snt_ability_export_audit_log( null );
assertEq( 'json', $out_null['format'], 'ability: null input defaults to json' );
assertTrue( is_array( json_decode( $out_null['content'], true ) ), 'ability: null-input content is valid json' );

// unknown format → defaults to json (enum-guarded)
$out_bogus = snt_ability_export_audit_log( array( 'format' => 'xml' ) );
assertEq( 'json', $out_bogus['format'], 'ability: unknown format falls back to json' );

// ════════════════════════════════════════════════════════════════════
// v9.51.0 (lane SEC-C, R8) — PII cap on export-audit-log: default-redacted
// usernames + a per-call page-size cap, independent of the storage/retention
// cap. This is the ABILITY-layer surface only — the admin_post download
// handler (sn_audit_export_download_handler(), a human wp-admin click, not
// exercised here per the file's own docblock) is UNCHANGED: it still calls
// sn_audit_export_render() -> sn_audit_export_build_view() directly, full
// plaintext, for the authenticated owner's own download.
// ════════════════════════════════════════════════════════════════════
echo "\n-- R8: PII mask + row cap (pure helpers) --\n";
assertEq( 'j***', sn_audit_export_pii_mask_username( 'juan' ), 'mask: keeps first char, stars the rest' );
assertEq( '*', sn_audit_export_pii_mask_username( 'x' ), 'mask: a 1-char username still stars at least one char' );
assertEq( '', sn_audit_export_pii_mask_username( '' ), 'mask: empty username stays empty' );

$many_rows = array();
for ( $i = 0; $i < 10; $i++ ) {
	$many_rows[] = array( 'ts' => $i, 'user' => "user$i", 'formatted' => "f$i" );
}
$capped = sn_audit_export_cap_rows( $many_rows, 3 );
assertEq( 3, count( $capped ), 'cap: slices down to the cap' );
assertEq( 'user0', $capped[0]['user'], 'cap: keeps the FIRST N rows (login_successes rides newest-first already)' );
$uncapped = sn_audit_export_cap_rows( $many_rows, 100 );
assertEq( 10, count( $uncapped ), 'cap: a cap larger than the row count is a no-op' );

echo "\n-- R8: default (no include_pii) redacts usernames + applies the page cap --\n";
$default_out = snt_ability_export_audit_log( array( 'format' => 'json' ) );
$default_decoded = json_decode( $default_out['content'], true );
assertEq( 'j***', $default_decoded['login_successes'][0]['user'] ?? null, 'default call: JSON export username is masked, not plaintext' );
// Checks the decoded login_successes VALUES specifically — the raw JSON string
// also legitimately contains "juan" inside the unrelated site field
// (home_url() = https://juanlentino.com/), so a whole-string search would
// false-positive on that; the PII surface under test is the username field.
$default_users = array_column( $default_decoded['login_successes'] ?? array(), 'user' );
assertTrue( ! in_array( 'juan', $default_users, true ), 'PROBE PIN: the plaintext seeded username never appears as a login_successes.user value in a default-call export' );

$default_out_csv = snt_ability_export_audit_log( array( 'format' => 'csv' ) );
assertTrue( false === strpos( $default_out_csv['content'], 'juan' ), 'default CSV call: the plaintext seeded username never appears' );
assertTrue( false !== strpos( $default_out_csv['content'], 'j***' ), 'default CSV call: the masked username IS present' );

echo "\n-- R8: include_pii:true returns full plaintext (still gated the same as ever — capability/rw-door unchanged) --\n";
$pii_out = snt_ability_export_audit_log( array( 'format' => 'json', 'include_pii' => true ) );
$pii_decoded = json_decode( $pii_out['content'], true );
assertEq( 'juan', $pii_decoded['login_successes'][0]['user'] ?? null, 'include_pii:true: JSON export carries the real plaintext username' );

echo "\n-- R8: the page-size cap engages end-to-end when the source impl returns more rows than the cap --\n";
// Flips the mode-switchable stub (defined near the top of this file) to its
// many-rows fixture for THIS section only — proves sn_audit_export_cap_rows()
// (already unit-tested above) is actually WIRED into the ability wrapper's
// view, not just defined in isolation.
$GLOBALS['__test_many_logins_mode'] = true;
$capped_out     = snt_ability_export_audit_log( array( 'format' => 'json' ) );
$capped_decoded = json_decode( $capped_out['content'], true );
assertTrue( count( $capped_decoded['login_successes'] ?? array() ) === SNT_AUDIT_EXPORT_LOGINS_PAGE_CAP, 'PROBE PIN: export login_successes never exceeds the page-size cap, even when the source impl returns more rows than that' );
$GLOBALS['__test_many_logins_mode'] = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
