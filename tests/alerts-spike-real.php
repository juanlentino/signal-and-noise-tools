<?php
/**
 * Guard: a path spike is about a REAL page, and its mail says how much of the
 * path's traffic the beacon bot-signal score marks likely automated (inferred,
 * shown only, never subtracted).
 *
 * 2026-10-18: alerts_fired went ripe on a spike for /zz-sweep-missing-7f3a/,
 * a 404 the owner's own automated sweep had walked. Breaks already had to be
 * real pages; spikes passed only the junk-path rule.
 *
 * Run: php tests/alerts-spike-real.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['ids'] = array( '/notes/a/' => 11 );
function apply_filters( $h, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function url_to_postid( $u ) { return $GLOBALS['ids'][ substr( $u, 20 ) ] ?? 0; }
function get_post_status( $id ) { return 'publish'; }
// Analytics Engine, stubbed: the SQL each read sent, and the rows it answers.
$GLOBALS['sql'] = array(); $GLOBALS['ae'] = array(); $GLOBALS['ae_fail'] = false;
function add_action() {}
function get_option( $k, $d = false ) { return $d; }
function sn_analytics_query( $sql ) { $GLOBALS['sql'][] = $sql; return $GLOBALS['ae_fail'] ? null : $GLOBALS['ae']; }
require __DIR__ . '/../inc/analytics-bot-signals.php';
require __DIR__ . '/../inc/alerts.php';
require __DIR__ . '/../inc/alerts-cron.php';
require __DIR__ . '/../inc/alerts-automated.php';

$T    = snt_alerts_thresholds();
$eval = static fn( $in ) => snt_alerts_evaluate( $in + array( 'today' => '2026-10-18', 'excluded' => 'sn_analytics_is_excluded_path', 'real' => 'snt_alerts_is_real_page' ), $T );
$keys = static fn( $a ) => array_column( $a, 'key' );

echo "A spike is a real page\n";
$sweep = array( 'views' => array( '/zz-sweep-missing-7f3a/' => 99 ), 'history' => array() );
ok( array( 'spike_site||2026-10-18' ) === $keys( $eval( $sweep ) ), 'a 404 at 99 views sends no path spike; its views still count toward the site total, which is unchanged' );
ok( array( 'spike|/notes/a/|2026-10-18' ) === $keys( $eval( array( 'views' => array( '/notes/a/' => 16 ), 'history' => array() ) ) ), 'a published note past the floor still fires' );
ok( array() === $keys( snt_alerts_evaluate( array( 'today' => '2026-10-18', 'views' => array( '/notes/a/' => 16 ), 'real' => null ), $T ) ), 'with no resolver no path spikes: the gate fails quiet, like breaks' );

echo "\nHow much looks automated\n";
$now = strtotime( '2026-10-18 15:00:00 UTC' );
$row = static fn( $vid, $bits, $human = 1, $views = 1 ) => array( 'day' => '2026-10-18', 'vid' => $vid, 'webdriver' => $bits & 1, 'no_input' => $bits & 2, 'ua_mismatch' => $bits & 4, 'headless' => $bits & 8, 'tz_mismatch' => $bits & 16, 'views' => $views, 'human' => $human );
$GLOBALS['ae'] = array( $row( 'a', 1 ), $row( 'b', 2 | 4 ), $row( 'c', 0 ), $row( 'd', 8 ), $row( 'e', 1, 0 ), $row( 'f', 1, 1, 99 ) );
$spike = array( 'key' => 'spike|/notes/a/|2026-10-18', 'kind' => 'spike', 'path' => "/notes/a/'x", 'day' => '2026-10-18', 'value' => 40, 'baseline' => 0, 'line' => 15 );
$site  = array( 'kind' => 'spike_site', 'path' => '' ) + $spike;
$out   = snt_alerts_automated_attach( array( $spike, $site ), $now );
ok( array( 'day' => '2026-10-18', 'visitor_days' => 4, 'likely' => 2 ) === $out[0]['automated'], 'four presumed-human visitor-days scored, two at the threshold; a non-human row and one over the page-view cap are left out' );
ok( ! array_key_exists( 'automated', $out[1] ), 'the site-wide spike is unchanged: no per-path read' );
ok( 1 === count( $GLOBALS['sql'] ) && false !== strpos( $GLOBALS['sql'][0], "blob2 IN ('/notes/a/\\'x', '/notes/a/\\'x/')" ) || false !== strpos( $GLOBALS['sql'][0], "blob2 IN ('/notes/a/\\'x/', '/notes/a/\\'x')" ), 'one read, for that path in both spellings, the path escaped as request text' );
$GLOBALS['ae_fail'] = true;
ok( null === snt_alerts_automated_attach( array( $spike ), $now )[0]['automated'], 'a failed read is null, never zero' );
$GLOBALS['ae_fail'] = false; $GLOBALS['ae'] = array();
ok( array( 'day' => '2026-10-18', 'visitor_days' => 0, 'likely' => 0 ) === snt_alerts_automated_attach( array( $spike ), $now )[0]['automated'], 'no signal-bearing row is zero visitor-days scored, which the mail calls not measured' );

echo "\nThe mail line\n";
$mail = static fn( $auto ) => snt_alerts_compose( array( array( 'automated' => $auto ) + $spike ), array(), 'S', 'u' )[1];
$m    = $mail( array( 'day' => '2026-10-18', 'visitor_days' => 4, 'likely' => 2 ) );
ok( false !== strpos( $m, 'Likely automated (inferred from beacon signals, shown only, never subtracted): 2 of 4 presumed-human visitor-days on this path (50%), UTC day 2026-10-18.' ), 'the share, its basis and its day' );
ok( false !== strpos( $mail( array( 'day' => '2026-10-18', 'visitor_days' => 0, 'likely' => 0 ) ), 'Likely automated: not measured; no presumed-human visitor-day on this path carried beacon signals (UTC day 2026-10-18).' ), 'nothing scored says not measured, never 0%' );
ok( false !== strpos( $mail( null ), 'Likely automated: could not be read just now, which is not the same as none.' ), 'a failed read says so' );
ok( false === strpos( snt_alerts_compose( array( $site ), array(), 'S', 'u' )[1], 'Likely automated' ), 'the site-wide spike carries no such line' );
ok( 1 === substr_count( $m, "\n" . 'SPIKE: ' ) && 2 === count( explode( "\n", trim( explode( "\n\n", $m )[1] ) ) ), 'one line added under the spike line, nothing else moves' );
ok( false === strpos( $m, "\u{2014}" ), 'no em dashes' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
