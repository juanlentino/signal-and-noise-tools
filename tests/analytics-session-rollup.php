<?php
/**
 * Unit test for the session-rollup row normalizer. Run: php tests/analytics-session-rollup.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
// The rollup module registers hooks at load; stub them to no-ops.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
require __DIR__ . '/../inc/analytics-session-rollup.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── wpdb stub ────────────────────────────────────────────────────────────────
// Placeholder-type-faithful like real $wpdb->prepare(): %d→int, %f→float cast,
// %s→quoted string. Lets the upsert's generated SQL be asserted directly, and a
// future %s→%f regression on a float column becomes observable (unquoted value).
class SR_Stub_wpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d': return (string) (int) $a;
				case '%f': return (string) (float) $a;
				default:   return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
}
$GLOBALS['wpdb'] = new SR_Stub_wpdb();

echo "\nGroup: sn_session_rollup_normalize\n";
$rows = array(
	array( 'day' => '2026-06-01', 'class' => 'human', 'visits' => '12.0', 'bounce_pct' => '40', 'ppv' => '1.8', 'median_dur' => '55' ),
	array( 'day' => 'bad-date',   'class' => 'human', 'visits' => 5 ),          // dropped
	array( 'day' => '2026-06-01', 'class' => 'martian', 'visits' => 3 ),        // dropped (class)
);
$clean = sn_session_rollup_normalize( $rows );
ok( 1 === count( $clean ), 'only the valid row survives' );
ok( 12 === $clean[0]['visits'], 'visits coerced to int' );
ok( '2026-06-01' === $clean[0]['day'] && 'human' === $clean[0]['class'], 'day/class preserved' );

// ── Locale-safe float binding (regression) ────────────────────────────────────
// $wpdb->prepare() routes %f through vsprintf() (LC_NUMERIC-sensitive): under a
// comma-decimal server locale (de_DE, pt_BR, …) a raw-float %f renders 42.5 as
// "42,5" — corrupt SQL. bounce_pct/ppv must bind as '.'-decimal strings
// (number_format → %s), so the generated SQL is locale-independent.
echo "\nGroup: locale-safe float binding (upsert)\n";
$__saved_numeric = setlocale( LC_NUMERIC, '0' ); // query current, for restore
setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.ISO8859-1' ); // no-op if uninstalled
$GLOBALS['wpdb'] = new SR_Stub_wpdb();
sn_session_rollup_upsert( array(
	array( 'day' => '2026-06-01', 'class' => 'human', 'visits' => 12, 'bounce_pct' => 42.5, 'ppv' => 1.75, 'median_dur' => 55 ),
) );
$q = $GLOBALS['wpdb']->queries[0];
ok( strpos( $q, "'42.50'" ) !== false, 'bounce_pct bound as a dot-decimal 2dp string (%s), not %f' );
ok( strpos( $q, "'1.75'" ) !== false, 'ppv bound as a dot-decimal 2dp string (%s), not %f' );
ok( strpos( $q, '42,5' ) === false && strpos( $q, '1,75' ) === false, 'no comma decimal under a de_DE LC_NUMERIC' );
// Column order preserved: day, class, visits, bounce_pct, ppv, median_dur.
ok( strpos( $q, "'2026-06-01', 'human', 12, '42.50', '1.75', 55" ) !== false, 'binds columns in exact order' );
if ( false !== $__saved_numeric ) { setlocale( LC_NUMERIC, $__saved_numeric ); }

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
