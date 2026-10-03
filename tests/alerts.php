<?php
/**
 * Guard: spike and break alerts (Unreleased). The evaluation and the email
 * are pure; the hourly run is driven against stubs, with a mail recorder in
 * place of wp_mail. No mail leaves and no network is touched.
 *
 * Run: php tests/alerts.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['opt'] = array( 'admin_email' => 'owner@example.com' ); $GLOBALS['set'] = array(); $GLOBALS['mail'] = array(); $GLOBALS['mail_ok'] = true;
$GLOBALS['rows'] = array(); $GLOBALS['err'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function sn_setting( $k, $d = null ) { return $GLOBALS['set'][ $k ] ?? $d; }
function wp_mail( $to, $s, $b ) { $GLOBALS['mail'][] = compact( 'to', 's', 'b' ); return $GLOBALS['mail_ok']; }
function is_email( $e ) { return false !== strpos( $e, '@' ); }
function get_bloginfo() { return 'Test Site'; }
function wp_specialchars_decode( $v ) { return $v; }
function admin_url() { return 'https://example.test/wp-admin/'; }
function sn_analytics_local_day( $now ) { return gmdate( 'Y-m-d', $now ); }
function sn_analytics_daily_range( $from, $to ) { return array_values( array_filter( $GLOBALS['rows'], static fn( $r ) => $r['day'] >= $from && $r['day'] <= $to ) ); }
function sn_edge_top_dim( $dim, $from, $to ) { return $GLOBALS['err'][ $from ] ?? array(); }
function sn_analytics_top_sources() { return array( array( 'value' => 'Hacker News', 'views' => 20 ), array( 'value' => '(direct)', 'views' => 9 ) ); }
require __DIR__ . '/../inc/analytics-human-rule.php'; // the real excluded-path rule.
require __DIR__ . '/../inc/alerts.php';
require __DIR__ . '/../inc/alerts-cron.php';

$T    = snt_alerts_thresholds();
$junk = 'sn_analytics_is_excluded_path';
$eval = static fn( $in ) => snt_alerts_evaluate( $in + array( 'today' => '2026-10-03', 'excluded' => 'sn_analytics_is_excluded_path' ), $GLOBALS['T'] );
$keys = static fn( $a ) => array_column( $a, 'key' );

echo "Spikes\n";
ok( array( 15.0, 5.0, 40.0, 4.0, 3.0 ) === array_values( $T ), 'the thresholds are 15 and 5x for a path, 40 and 4x for the site, 3 errors for a break' );
ok( array() === $eval( array( 'views' => array( '/a/' => 15 ), 'history' => array() ) ), 'a new path AT the floor (15) is quiet: the line is "more than"' );
ok( array( 'spike|/a/|2026-10-03' ) === $keys( $eval( array( 'views' => array( '/a/' => 16 ), 'history' => array() ) ) ), 'a new path past the floor fires, keyed kind|path|day' );
ok( array() === $eval( array( 'views' => array( '/a/' => 3 ), 'history' => array( '/a/' => 1 ) ) ), 'the floor wins on a quiet path: 3 views against a 0.14 mean is 21x and still quiet' );
ok( array() === $eval( array( 'views' => array( '/a/' => 30 ), 'history' => array( '/a/' => 49 ) ) ), 'the ratio wins on a busy path: 30 views against a mean of 7 (line 35) is quiet' );
ok( array( 'spike|/a/|2026-10-03' ) === $keys( $eval( array( 'views' => array( '/a/' => 36 ), 'history' => array( '/a/' => 49 ) ) ) ), 'and 36 fires; the mean divides by 7 days, absent days counting as zero' );
$site = $eval( array( 'views' => array( '/a/' => 14, '/b/' => 14, '/c/' => 13 ), 'history' => array( '/a/' => 7 ) ) );
ok( array( 'spike_site||2026-10-03' ) === $keys( $site ) && 41 === $site[0]['value'], 'no path spikes but the site total (41) passes 40: one site alert' );
ok( array() === $eval( array( 'views' => array( '/a/' => 14, '/b/' => 14, '/c/' => 12 ), 'history' => array() ) ), 'a site total of exactly 40 is quiet' );
ok( array() === $eval( array( 'views' => array( '/a/' => 14, '/b/' => 14, '/c/' => 14, '/d/' => 14 ), 'history' => array( '/a/' => 105 ) ) ), 'a site that averages 15 a day needs more than 60: 56 is quiet' );
ok( array() === $eval( array( 'views' => array( '/wp-content/x.css' => 99 ), 'history' => array() ) ), 'an asset path never spikes, and never counts toward the site total' );

echo "\nBreaks\n";
$errs = array( '2026-10-02' => array( '/notes/a/' => 3, '/notes/b/' => 2, '/wp-json/wp/v2/posts' => 50, '/wp-json' => 9, '/wp-admin/admin-ajax.php' => 9, '/xmlrpc.php' => 9, '/wp-login.php' => 9, '/.env' => 9 ) );
ok( array( 'break|/notes/a/|2026-10-02' ) === $keys( $eval( array( 'errors' => $errs ) ) ), '3 errors on a real page fires; 2 does not; admin, wp-json, xmlrpc, login and junk paths never do' );
ok( 6 === count( snt_alerts_evaluate( array( 'errors' => $errs, 'excluded' => null ), $T ) ), 'control: without the excluded-path rule every one of those rows at 3 or more would fire' );

echo "\nOnce per key per day\n";
$sent = array( 'spike|/a/|2026-10-03' => 100 );
ok( array() === $eval( array( 'views' => array( '/a/' => 30 ), 'history' => array(), 'sent' => $sent ) ), 'a key already sent today is not due again' );
ok( array( 'spike|/a/|2026-10-04' ) === $keys( snt_alerts_evaluate( array( 'today' => '2026-10-04', 'views' => array( '/a/' => 30 ), 'sent' => $sent ), $T ) ), 'the same path on the next day is a new key' );
$now = strtotime( '2026-10-03 12:00:00 UTC' );
ok( array( 'new' => $now - 13 * 86400 ) === snt_alerts_prune( array( 'old' => $now - 15 * 86400, 'new' => $now - 13 * 86400 ), $now ), 'stamps older than 14 days are dropped, so the option stays bounded' );

echo "\nThe email\n";
$msg = snt_alerts_compose( array_merge( $eval( array( 'views' => array( "/a/\r\nBcc: x@y" => 36 ), 'history' => array(), 'errors' => $errs ) ) ), sn_analytics_top_sources(), 'Test Site', 'https://example.test/wp-admin/admin.php?page=sn-analytics' );
ok( '[Test Site] Alert: 1 spike, 1 break' === $msg[0], 'the subject counts what fired' );
ok( false !== strpos( $msg[1], 'has 36 human views today (2026-10-03). The prior 7-day mean is 0 a day; the alert line is more than 15.' ), 'a spike line carries the number, the baseline and the line' );
ok( false !== strpos( $msg[1], 'BREAK: /notes/a/ answered a server error 3 times on 2026-10-02 (UTC)' ), 'a break line carries the page, the count and the day' );
ok( false !== strpos( $msg[1], 'Top sources today, site-wide (views): Hacker News 20, (direct) 9.' ) && false !== strpos( $msg[1], 'Visits with no referrer (apps, RSS readers, privacy browsers) show as direct.' ), 'a spike names the top sources and says what direct means' );
ok( false !== strpos( $msg[1], 'page=sn-analytics' ), 'and where to look' );
ok( false === strpos( $msg[1], "\r" ) && false !== strpos( $msg[1], '/a/??Bcc: x@y' ), 'a path is request text: control characters never reach the email' );
ok( false === strpos( snt_alerts_compose( $eval( array( 'errors' => $errs ) ), array(), 'S', 'u' )[1], 'Top sources' ), 'a break alone carries no sources paragraph' );
ok( false === strpos( $msg[0] . $msg[1], "\u{2014}" ), 'no em dashes in the copy' );

echo "\nThe hourly run\n";
ok( true === snt_alerts_enabled(), 'alerts are ON by default' );
$GLOBALS['rows'] = array( array( 'day' => '2026-10-03', 'path' => '/a/', 'views' => 20 ), array( 'day' => '2026-10-01', 'path' => '/a/', 'views' => 7 ), array( 'day' => '2026-09-25', 'path' => '/a/', 'views' => 700 ) );
$GLOBALS['err']  = array( '2026-10-02' => array( array( 'value' => '/notes/a/', 'requests' => 4 ) ) );
$in = snt_alerts_gather( $now );
ok( array( '/a/' => 20 ) === $in['views'] && array( '/a/' => 7 ) === $in['history'], 'gather: today is today; history is the 7 days before it and nothing older' );
$last = snt_alerts_run( $now );
ok( 1 === count( $GLOBALS['mail'] ) && 'owner@example.com' === $GLOBALS['mail'][0]['to'] && $last['mailed'] && 2 === count( $last['fired'] ), 'one email for the run, to the admin address, naming both alerts' );
snt_alerts_run( $now + 3600 );
ok( 1 === count( $GLOBALS['mail'] ), 'the next hour sends nothing for the same keys' );
ok( array() === $GLOBALS['opt'][ SNT_ALERTS_LAST_OPT ]['fired'] && 'evaluated' === $GLOBALS['opt'][ SNT_ALERTS_LAST_OPT ]['state'], 'and the last evaluation says it ran and nothing new fired' );
$w = snt_watch_ripe_alerts( array(), $now + 3600 );
ok( $w['ripe'] && false !== strpos( $w['note'], 'spike|/a/|2026-10-03' ) && false !== strpos( $w['note'], 'last evaluated 2026-10-03 13:00 UTC' ), 'the watch is ripe for a day after a mail and names what fired and when' );
ok( ! snt_watch_ripe_alerts( array(), $now + 2 * 86400 )['ripe'], 'and quiet again after 24 hours' );
$GLOBALS['opt'][ SNT_ALERTS_SENT_OPT ] = array(); $GLOBALS['mail_ok'] = false;
$last = snt_alerts_run( $now );
ok( array() === $GLOBALS['opt'][ SNT_ALERTS_SENT_OPT ] && 'wp_mail returned false' === $last['error'] && snt_watch_ripe_alerts( array(), $now )['ripe'], 'a failed send stamps nothing (next hour retries) and the watch says fired but not mailed' );
$GLOBALS['mail_ok'] = true; $GLOBALS['mail'] = array(); $GLOBALS['set']['operations.alerts_enabled'] = false;
$last = snt_alerts_run( $now );
ok( array() === $GLOBALS['mail'] && 'off' === $last['state'], 'switched off: nothing is evaluated or sent, and the record says off' );
ok( ! snt_watch_ripe_alerts( array(), $now, array( 'sent' => array(), 'last' => array() ) )['ripe'], 'never evaluated is not a finding' );

echo "\nRegistration\n";
$src = static fn( $f ) => (string) file_get_contents( __DIR__ . '/../' . $f );
ok( false !== strpos( $src( 'inc/watches.php' ), "'ripe'      => 'snt_watch_ripe_alerts'" ), 'the watch is registered' );
ok( false !== strpos( $src( 'inc/cron-lifecycle.php' ), 'SNT_ALERTS_HOOK,' ) && false !== strpos( $src( 'inc/cron-dashboard.php' ), "array( 'SNT_ALERTS_HOOK', 'snt_alerts_hourly' )" ), 'the hook is in the deactivation list and the SN-owned list' );
ok( false !== strpos( $src( 'inc/settings.php' ), "'alerts_enabled'          => true," ), 'the stored default is ON' );
ok( false === strpos( $src( 'inc/alerts-cron.php' ) . $src( 'inc/alerts.php' ), 'set_transient' ) && false === strpos( $src( 'inc/alerts-cron.php' ) . $src( 'inc/alerts.php' ), 'wp_remote_' ), 'no transient holds a stamp, and the module makes no outside call' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
