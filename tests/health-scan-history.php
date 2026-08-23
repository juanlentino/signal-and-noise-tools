<?php
/**
 * The Content-Health scan keeps a rolling history.
 *
 * WHY THIS EXISTS. SN_HEALTH_CACHE_KEY is `sn_health_last_scan` — one option,
 * overwritten every run — so the stored data could only ever answer "what is
 * true now". Once the scan runs daily (v12.23.0) that is no longer enough: a
 * daily verdict invites the question "since when", and there was nothing to ask.
 *
 * The assertions that matter here are the honest-reporting ones, not the
 * plumbing: a malformed scan must record NOTHING rather than a row saying zero
 * findings, and the streak must count the CURRENT consecutive run rather than a
 * lifetime total, because the log is a FIFO that forgets.
 *
 * Run: php tests/health-scan-history.php
 *
 * @since 12.23.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

$GLOBALS['__opt']     = array();
$GLOBALS['__actions'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

// The canonical summary helpers. The module must READ these rather than count
// for itself — a log that counted its own way would drift from the panel above
// it, and then neither number could be trusted without reading the source.
function sn_health_finding_total( $scan )   { return $scan['__findings']   ?? 0; }
function sn_health_advisory_total( $scan )  { return $scan['__advisories'] ?? 0; }
function sn_health_check_total( $scan )     { return $scan['__checks']     ?? 0; }
function sn_health_flagged_checks( $scan )  { return $scan['__flagged']    ?? array(); }

require __DIR__ . '/../inc/health-scan-history.php';

/** A scan result shaped like the real one. */
function hh_scan( $ts, $findings, $flagged = array(), $ms = 48000 ) {
	$f = array();
	foreach ( $flagged as $k => $n ) { $f[ $k ] = array( 'count' => $n ); }
	return array(
		'checks'       => array( 'x' => array( 'count' => 0 ) ), // presence marks it a real scan
		'scanned_at'   => $ts,
		'elapsed_ms'   => $ms,
		'__findings'   => $findings,
		'__advisories' => 37,
		'__checks'     => 8,
		'__flagged'    => $f,
	);
}

echo "Group: the row reads the canonical helpers, never its own arithmetic\n";
$row = sn_health_history_row( hh_scan( 1787523873, 4, array( 'broken_links' => 3, 'missing_alt' => 1 ) ) );
ok( 1787523873 === $row['ts'], 'records scanned_at' );
ok( 48000 === $row['ms'], 'records elapsed_ms' );
ok( 4 === $row['findings'], 'findings come from sn_health_finding_total()' );
ok( 37 === $row['advisories'], 'advisories come from sn_health_advisory_total()' );
ok( 8 === $row['checks'], 'check total comes from sn_health_check_total()' );
ok( array( 'broken_links' => 3, 'missing_alt' => 1 ) === $row['flagged'], 'flagged is key => count, from sn_health_flagged_checks()' );

echo "\nGroup: an absent row and a clean row are different claims\n";
ok( array() === sn_health_history_row( 'not a scan' ), 'a non-array records nothing' );
ok( array() === sn_health_history_row( array( 'scanned_at' => 1 ) ), 'a result with no checks records nothing' );
$GLOBALS['__opt'] = array();
sn_health_history_append( array( 'scanned_at' => 1 ) );
ok( array() === sn_health_history(), 'and appending a malformed scan writes NO row — never a row saying zero findings' );

echo "\nGroup: it is a FIFO, and it forgets the oldest\n";
$GLOBALS['__opt'] = array();
for ( $i = 1; $i <= SN_HEALTH_HISTORY_CAP + 25; $i++ ) {
	sn_health_history_append( hh_scan( 1700000000 + $i, $i ) );
}
$log = sn_health_history();
ok( SN_HEALTH_HISTORY_CAP === count( $log ), 'the log caps at SN_HEALTH_HISTORY_CAP (' . count( $log ) . ')' );
ok( SN_HEALTH_HISTORY_CAP + 25 === (int) end( $log )['findings'], 'the NEWEST row survives' );
ok( 26 === (int) $log[0]['findings'], 'and the oldest 25 were evicted, not archived' );
ok( 5 === count( sn_health_history( 5 ) ), 'a limit returns the newest N' );

echo "\nGroup: the streak answers 'how long has this been red', not 'how often ever'\n";
$GLOBALS['__opt'] = array();
// red, red, CLEAR, red, red, red  (oldest -> newest)
foreach ( array(
	array( 'broken_links' => 2 ),
	array( 'broken_links' => 2 ),
	array(),
	array( 'broken_links' => 1 ),
	array( 'broken_links' => 1 ),
	array( 'broken_links' => 4 ),
) as $i => $f ) {
	sn_health_history_append( hh_scan( 1700000000 + $i, count( $f ) ? 1 : 0, $f ) );
}
ok( 3 === sn_health_history_streak( 'broken_links' ), 'counts back to the last clear scan, not the lifetime total (3, not 5)' );
ok( 0 === sn_health_history_streak( 'missing_alt' ), 'a check the newest scan did not flag has streak 0' );
$GLOBALS['__opt'] = array();
ok( 0 === sn_health_history_streak( 'broken_links' ), 'an EMPTY log is streak 0 — "not currently red", never "measured clean"' );

echo "\nGroup: wired to the store seam, not tangled into the writer\n";
ok( in_array( 'sn_health_history_append', (array) ( $GLOBALS['__actions']['sn_health_scan_stored'] ?? array() ), true ),
	'appends on the sn_health_scan_stored action, so health-checks.php still owns only the latest verdict' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
