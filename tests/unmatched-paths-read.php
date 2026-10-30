<?php
/**
 * Standalone test: the unmatched-paths report's read half (2026-10-29). A
 * failed read is a skipped check, never a clean report ("No visitor reached a
 * path..." on a read that did not happen), and the read keeps the most viewed
 * groups when it hits its cap.
 *
 * Run: php tests/unmatched-paths-read.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function sn_analytics_canonical_path( $p ) { $p = (string) $p; if ( '' === $p ) { return ''; } $t = rtrim( $p, '/' ); return '' === $t ? '/' : $t; }
function snt_arows_real_paths() { return array( '/' => true, '/notes/x' => true ); }
function sn_health_report_coverage( $k ) { return 'coverage'; }
// The real packer, lifted: its `skipped` field is what this suite pins.
preg_match( '/function sn_health_pack_check\(.*?\n}\n/s', (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' ), $m );
eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only: runs the plugin's own function source, no external input.
class Fake_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = array();
	public $sql = '';
	public function prepare( $sql, ...$args ) { $this->sql = $sql; return vsprintf( $sql, $args ); }
	public function get_results( $sql, $o ) { return $this->rows; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
require __DIR__ . '/../inc/health-unmatched-paths.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['wpdb']->rows = array( array( 'path' => '/old-name', 'class' => 'human', 'first_day' => '2026-10-01', 'last_day' => '2026-10-02', 'views' => 3, 'visits' => 2 ) );
$read = snt_unmatched_stored_rows();
ok( null === $read['error'] && 1 === count( $read['rows'] ), 'a read that worked has no error and its rows' );
ok( false !== strpos( $GLOBALS['wpdb']->sql, 'ORDER BY SUM(views) DESC LIMIT %d' ), 'the most viewed groups come first, so the cap drops the tail' );
$check = sn_health_check_unmatched_paths();
ok( null === $check['skipped'] && 1 === $check['report']['people_total'], 'a read that worked is a report with its paths' );

$GLOBALS['wpdb']->rows       = null;
$GLOBALS['wpdb']->last_error = "Table 'wp_sn_analytics_daily' doesn't exist";
$read  = snt_unmatched_stored_rows();
$check = sn_health_check_unmatched_paths();
ok( is_string( $read['error'] ) && array() === $read['rows'], 'a failed read says so' );
ok( is_string( $check['skipped'] ) && '' !== $check['skipped'] && ! isset( $check['report'] ), 'a failed read is a skipped check with no report, never "No visitor reached a path"' );
ok( false === strpos( (string) $check['skipped'], 'wp_sn_analytics_daily' ), 'the skip reason does not echo the database error' );

$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->rows       = array();
$check = sn_health_check_unmatched_paths();
ok( null === $check['skipped'] && 0 === $check['report']['people_total'], 'an empty table that read fine is a report of none, not a skip' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
