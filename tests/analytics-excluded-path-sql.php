<?php
/**
 * Guard: the dims rollup drops the same paths the daily rollup drops.
 *
 * sn_analytics_excluded_path_sql() mirrors sn_analytics_is_excluded_path() as
 * AE ILIKE terms (AE has no regex). The pin runs both over the site's real
 * paths and the junk the daily table drops, with ILIKE emulated in PHP, and
 * checks the dims SQL carries the clause and the site's day. Before this, the
 * dims totals ran 291 views against the daily table's 235 (2026-10-02).
 *
 * Run: php tests/analytics-excluded-path-sql.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Inert WordPress surface the human-rule file touches at load.
function get_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return false; }
function set_transient() { return true; }
function add_action() {}
function apply_filters( $t, $v ) { return $v; }

require __DIR__ . '/../inc/analytics-human-rule.php';
if ( ! function_exists( 'sn_analytics_rollup_window' ) ) { function sn_analytics_rollup_window() { return array( 'days' => 3, 'until' => 0 ); } }
if ( ! defined( 'SN_ANALYTICS_DATASET' ) ) { define( 'SN_ANALYTICS_DATASET', 'sn_analytics' ); }
require __DIR__ . '/../inc/analytics-dims.php';

/** ILIKE as AE reads it: % any run, _ one character, case-insensitive. */
function ilike( $s, $pattern ) {
	$re = '';
	foreach ( str_split( $pattern ) as $ch ) {
		$re .= '%' === $ch ? '.*' : ( '_' === $ch ? '.' : preg_quote( $ch, '/' ) );
	}
	return 1 === preg_match( '/^' . $re . '$/is', $s );
}

/** Whether the SQL clause drops a path: NOT (any term matches). */
function sql_drops( $path ) {
	preg_match_all( "/blob2 ILIKE '([^']*)'/", sn_analytics_excluded_path_sql(), $mm );
	foreach ( $mm[1] as $p ) {
		if ( ilike( $path, $p ) ) {
			return true;
		}
	}
	return false;
}

echo "The two rules agree\n";
$kept = array( '/', '/notes', '/resume', '/about', '/music', '/now', '/services', '/provenance', '/provenance/over-detection', '/notes/tags', '/notes/there-is-no-test-that-returns-human', '/wp-admin-guide/' );
$junk = array( '/wp-admin', '/wp-admin/edit.php', '/wp-login.php', '/wp-login.php?action=lostpassword', '/wp-content/uploads/sn-css/abc123', '/wp-includes/js/x', '/wp-json/wp/v2/posts', '/favicon.ico', '/robots.txt', '/notes/feed.xml' );
foreach ( $kept as $p ) {
	ok( ! sn_analytics_is_excluded_path( $p ) && ! sql_drops( $p ), "kept by both: $p" );
}
foreach ( $junk as $p ) {
	ok( sn_analytics_is_excluded_path( $p ) && sql_drops( $p ), "dropped by both: $p" );
}

echo "\nThe dims rollup uses it\n";
$sql = sn_analytics_dims_rollup_sql( 'country', 3, 'America/New_York' );
ok( false !== strpos( $sql, sn_analytics_excluded_path_sql() ), 'the dims SQL drops the excluded paths' );
ok( false !== strpos( $sql, "formatDateTime(timestamp, '%Y-%m-%d', 'America/New_York') AS day" ), 'the dims SQL keys days on the site\'s zone, like the daily rollup' );
ok( false !== strpos( sn_analytics_dims_rollup_sql( 'country', 3 ), "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day" ), 'no zone: the UTC day, as before' );
ok( strlen( $sql ) < 9000, 'the statement stays well under AE\'s 10,000-character cap before the over-cap list (' . strlen( $sql ) . ')' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
