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
$GLOBALS['ids'] = array( '/notes/a/' => 11, '/notes/b/' => 12, '/notes/draft/' => 13 );
function apply_filters( $h, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function url_to_postid( $u ) { return $GLOBALS['ids'][ substr( $u, 20 ) ] ?? 0; }
function get_post_status( $id ) { return 13 === $id ? 'draft' : 'publish'; }
function sn_analytics_local_day( $now ) { return gmdate( 'Y-m-d', $now ); }
$GLOBALS['wpdb'] = (object) array( 'last_error' => '' ); $GLOBALS['fail_read'] = '';
function sn_analytics_daily_range( $from, $to ) { $GLOBALS['wpdb']->last_error = ( 'history' === $GLOBALS['fail_read'] && $from !== $to ) || ( 'today' === $GLOBALS['fail_read'] && $from === $to ) ? 'Table is marked as crashed' : ''; if ( '' !== $GLOBALS['wpdb']->last_error ) { return array(); } return array_values( array_filter( $GLOBALS['rows'], static fn( $r ) => $r['day'] >= $from && $r['day'] <= $to ) ); }
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) { $GLOBALS["wpdb"]->last_error = ""; return array_slice( $GLOBALS['err'][ $from ] ?? array(), 0, $limit ); }
define( 'SN_EDGE_ERRORS_READ_OPT', 'sn_edge_errors_read_days' ); $GLOBALS['src_fail'] = false;
function sn_analytics_top_sources() { if ( $GLOBALS['src_fail'] ) { return null; } return array( array( 'value' => 'Hacker News', 'views' => 20 ), array( 'value' => '(direct)', 'views' => 9 ) ); }
function __return_true_t() { return true; }
require __DIR__ . '/../inc/analytics-human-rule.php'; // the real excluded-path rule.
require __DIR__ . '/../inc/alerts.php';
require __DIR__ . '/../inc/alerts-cron.php';
require __DIR__ . '/../inc/alerts-watch.php';
require __DIR__ . '/../inc/alerts-notice.php';

$T    = snt_alerts_thresholds();
$junk = 'sn_analytics_is_excluded_path';
$eval = static fn( $in ) => snt_alerts_evaluate( $in + array( 'today' => '2026-10-03', 'excluded' => 'sn_analytics_is_excluded_path', 'real' => 'snt_alerts_is_real_page' ), $GLOBALS['T'] );
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
ok( 6 === count( snt_alerts_evaluate( array( 'errors' => $errs, 'excluded' => null, 'real' => '__return_true_t' ), $T ) ), 'control: without the excluded-path rule every one of those rows at 3 or more would fire' );

echo "\nA break is a REAL page\n";
$probes = array( '2026-10-02' => array( '/_security/_authenticate' => 50, '/model/info' => 50, '/Dockerfile' => 50, '/notes/draft/' => 50, '/notes/gone/' => 50 ) );
ok( array() === $eval( array( 'errors' => $probes ) ), 'scanner probes, a draft and a URL that resolves to nothing send nothing, even at 50 errors' );
ok( array( 'break|/|2026-10-02', 'break|/notes/a/|2026-10-02', 'break|/notes/|2026-10-02' ) === $keys( $eval( array( 'errors' => array( '2026-10-02' => array( '/' => 3, '/notes/a/' => 3, '/notes/' => 3 ) ) + $probes ) ) ), 'the home page, a published note and a known archive route at 3 errors do' );
ok( snt_alerts_is_real_page( '/notes/a' ) && snt_alerts_is_real_page( '/provenance' ) && ! snt_alerts_is_real_page( '/notes/a/extra/' ), 'a missing trailing slash still resolves; a longer path under a note does not' );
ok( array() === snt_alerts_evaluate( array( 'errors' => array( '2026-10-02' => array( '/' => 9 ) ), 'real' => null ), $T ), 'with no resolver nothing breaks: the gate fails quiet, never open' );
ok( 'snt_alerts_is_real_page' === snt_alerts_gather( 0 )['real'], 'the hourly gather hands the resolver to the evaluation' );

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
$GLOBALS['mail_ok'] = true; $GLOBALS['mail'] = array();

echo "\nA failed read is not a quiet day\n";
$GLOBALS['fail_read'] = 'history';
$last = snt_alerts_run( $now );
ok( array() === $GLOBALS['mail'] && 'read_failed' === $last['state'] && 'stored read failed: views history' === $last['error'] && array() === $last['fired'], 'the history read fails: 20 views against a baseline read as zero would spike, and nothing mails; the record names the read' );
$w = snt_watch_ripe_alerts( array(), $now );
ok( $w['ripe'] && false !== strpos( $w['note'], 'NOT evaluated, stored read failed: views history' ), 'and the watch says the run did not evaluate' );
$GLOBALS['fail_read'] = 'today';
ok( 'stored read failed: views today' === snt_alerts_run( $now )['error'] && array() === $GLOBALS['mail'], 'a failed read of today is named too, not recorded as an evaluated quiet hour' );
$GLOBALS['fail_read'] = '';
ok( 'evaluated' === snt_alerts_run( $now )['state'] && 1 === count( $GLOBALS['mail'] ), 'the next good read evaluates and mails what was held' );
$GLOBALS['err']['2026-10-02'] = array_merge( array_map( static fn( $i ) => array( 'value' => "/probe-$i", 'requests' => 900 ), range( 1, 49 ) ), array( array( 'value' => '/notes/b/', 'requests' => 3 ) ) );
$last = snt_alerts_run( $now );
ok( in_array( 'break|/notes/b/|2026-10-02', $last['fired'], true ), 'filter, then truncate: 49 louder probe rows do not push a real page with 3 errors out of the local read' );
ok( array( '2026-10-02' ) === $last['capped'] && false !== strpos( snt_watch_ripe_alerts( array(), $now )['note'], 'the stored 5xx list was full on 2026-10-02' ), 'a day that stored all 50 path groups is named: upstream may have cut a quieter page' );

echo "\nAn unread 5xx day is not a clean day\n";
$D1 = '2026-10-02'; $D2 = '2026-10-01';
ok( array() === snt_alerts_edge_unread( array(), $now ), 'no bookkeeping at all (edge never set up) is quiet' );
ok( array() === snt_alerts_edge_unread( array( $D2 => '', $D1 => '' ), $now ) && array() === snt_alerts_edge_unread( array( $D2 => '' ), $now ), 'both days read is clean; yesterday not yet run is pending, not overdue' );
ok( array( $D1 => 'failed: HTTP 403?quota' ) === snt_alerts_edge_unread( array( $D2 => '', $D1 => "HTTP 403\nquota" ), $now ), 'a day the rollup marked failed is unread, with its cleaned reason' );
ok( array( $D2 => 'never read (the daily edge rollup is overdue)' ) === snt_alerts_edge_unread( array( '2026-09-30' => '' ), $now ), 'a day two days old that was never marked is overdue' );
$GLOBALS['opt']['sn_edge_errors_read_days'] = array( $D2 => '', $D1 => 'HTTP 403' );
$GLOBALS['err'] = array();
$last = snt_alerts_run( $now );
ok( 'evaluated' === $last['state'] && array( $D1 => 'failed: HTTP 403' ) === $last['unread'], 'the run records the unread day beside an evaluation that found no rows' );
$w = snt_watch_ripe_alerts( array(), $now + 2 * 86400, array( 'sent' => array(), 'last' => $last ) );
ok( $w['ripe'] && false !== strpos( $w['note'], '5xx NOT read for 2026-10-02 failed: HTTP 403' ), 'and the watch is ripe for it: no rows did not mean no errors' );
$GLOBALS['opt']['sn_edge_errors_read_days'] = array( $D2 => '', $D1 => '' );
$GLOBALS['err'] = array( $D2 => array( array( 'value' => '/notes/b/', 'requests' => 5 ) ) );
ok( in_array( "break|/notes/b/|$D2", snt_alerts_run( $now )['fired'], true ), 'a day filed late is still read: the window is three UTC days' );

echo "\nA failed sources read is said, not shown as none\n";
$GLOBALS['opt'][ SNT_ALERTS_SENT_OPT ] = array(); $GLOBALS['mail'] = array(); $GLOBALS['src_fail'] = true; $GLOBALS['err'] = array();
$last = snt_alerts_run( $now );
$body = $GLOBALS['mail'][0]['b'] ?? '';
ok( false !== strpos( $body, 'Top sources could not be read just now' ) && false === strpos( $body, 'No sources are stored' ) && 'read failed' === $last['sources'], 'the mail says the sources could not be read, and the record keeps the failed read' );
ok( false !== strpos( snt_alerts_compose( $eval( array( 'views' => array( '/a/' => 16 ) ) ), array(), 'S', 'u' )[1], 'No sources are stored for today yet.' ), 'control: a read that answered with nothing still says none stored' );
$GLOBALS['src_fail'] = false;
$GLOBALS['mail'] = array(); $GLOBALS['set']['operations.alerts_enabled'] = false;
$last = snt_alerts_run( $now );
ok( array() === $GLOBALS['mail'] && 'off' === $last['state'], 'switched off: nothing is evaluated or sent, and the record says off' );
ok( ! snt_watch_ripe_alerts( array(), $now, array( 'sent' => array(), 'last' => array() ) )['ripe'], 'never evaluated is not a finding' );

echo "\nA cache refresh nothing could retry\n";
$cf = array( 'time' => gmmktime( 14, 5, 0, 10, 3, 2026 ), 'http' => 401, 'endpoint' => 'purge_cache', 'attempts' => 1, 'what' => '12 urls' );
$a  = $eval( array( 'cache' => $cf ) );
ok( array( 'cache|12 urls|2026-10-03 14:05:00' ) === $keys( $a ), 'a recorded failure is one alert, keyed on the failure\'s own time' );
ok( array() === $eval( array( 'cache' => $cf, 'sent' => array( 'cache|12 urls|2026-10-03 14:05:00' => 1 ) ) ) && array() === $eval( array( 'cache' => null ) ), 'mailed once; no record, no alert' );
$m = snt_alerts_compose( $a, array(), 'S', 'u' );
ok( '[S] Alert: cache refresh failed' === $m[0] && false !== strpos( $m[1], 'CACHE: Cloudflare did not accept a cache refresh (12 urls) at 2026-10-03 14:05:00 UTC: HTTP 401 after 1 try.' ) && false === strpos( $m[1], 'Top sources' ), 'the mail says what failed and how, and is neither a spike nor a break' );
function sn_cf_purge_failure() { return $GLOBALS['cf_fail'] ?? null; }
$GLOBALS['set']['operations.alerts_enabled'] = true; $GLOBALS['cf_fail'] = $cf; $GLOBALS['fail_read'] = 'history'; $GLOBALS['mail'] = array(); $GLOBALS['opt'][ SNT_ALERTS_SENT_OPT ] = array();
$last = snt_alerts_run( $now );
ok( 'read_failed' === $last['state'] && array( 'cache|12 urls|2026-10-03 14:05:00' ) === $last['fired'] && 1 === count( $GLOBALS['mail'] ), 'a failed analytics read still mails the cache failure, and only that' );
$GLOBALS['cf_fail'] = null; $GLOBALS['fail_read'] = '';

echo "\nThe alert in the app\n";
$nb = snt_alerts_notice_build( $a, $m[0], $m[1], 1791050000 );
ok( 1791050000 === $nb['id'] && 'Alert: cache refresh failed' === $nb['title'] && 0 === strpos( $nb['body'], 'CACHE: Cloudflare did not accept' ) && 'signal-noise' === $nb['app'] && 'attention' === $nb['section'], 'a cache failure: the subject without the site tag, the alert line, and a tap lands on the attention list' );
$sp = $eval( array( 'views' => array( '/a/' => 36, '/b/' => 40 ), 'history' => array() ) );
$sm = snt_alerts_compose( $sp, array(), 'S', 'u' );
$nb = snt_alerts_notice_build( $sp, $sm[0], $sm[1], 5 );
ok( 'sn-analytics' === $nb['app'] && '' === $nb['section'] && 0 === strpos( $nb['body'], 'SPIKE: ' ) && false !== strpos( $nb['body'], ' more)' ) && strlen( $nb['body'] ) < 260, 'spikes open Analytics; the body is the first line, bounded, with a count of the rest' );
ok( null === snt_alerts_notice_build( array(), 's', 'b', 5 ), 'nothing fired, no notice' );
$GLOBALS['opt'][ SNT_ALERTS_NOTICE_OPT ] = array( 'id' => $now - 3600, 'title' => 'Alert: 1 spike', 'body' => 'SPIKE: x', 'app' => 'sn-analytics' );
ok( 'Alert: 1 spike' === snt_alerts_notice( $now )['title'] && null === snt_alerts_notice( $now + 2 * DAY_IN_SECONDS ), 'the app reads it for a day, then it is gone' );
$GLOBALS['cf_fail'] = $cf; $GLOBALS['mail'] = array(); $GLOBALS['mail_ok'] = false; $GLOBALS['opt'][ SNT_ALERTS_SENT_OPT ] = array(); unset( $GLOBALS['opt'][ SNT_ALERTS_NOTICE_OPT ] );
snt_alerts_run( $now );
ok( $now === ( $GLOBALS['opt'][ SNT_ALERTS_NOTICE_OPT ]['id'] ?? null ), 'the hourly run stores the notice even when the mail does not leave' );
$first_id = $GLOBALS['opt'][ SNT_ALERTS_NOTICE_OPT ]['id'];
snt_alerts_run( $now + 3600 );
ok( $first_id === $GLOBALS['opt'][ SNT_ALERTS_NOTICE_OPT ]['id'], 'the same alert an hour later (its mail still not sent) keeps its notice id: a device shows it once' );
$GLOBALS['opt'][ SNT_ALERTS_NOTICE_OPT ] = snt_alerts_notice_build( $a, $m[0], $m[1], $now );
ok( is_array( snt_alerts_notice( $now ) ), 'a cache notice is readable while its failure stands' );
$GLOBALS['cf_fail'] = null;
ok( null === snt_alerts_notice( $now ), 'and gone once the failure record is cleared' );
$GLOBALS['mail_ok'] = true;
$njs = (string) file_get_contents( __DIR__ . '/../assets/snt-alert-notify.js' );
ok( false !== strpos( $njs, '( function boot() {' ) && false !== strpos( $njs, 'window.wp.os.whenReady( boot )' ) && false !== strpos( $njs, "document.addEventListener( 'DOMContentLoaded', boot )" ) && false !== strpos( $njs, 'boot._retried' ), 'the shell bundle is deferred: a failed gate retries once on the shell\'s readiness, it does not give up' );
ok( false !== strpos( $njs, "typeof window.wp.os.notify !== 'function'" ) && strpos( $njs, "typeof window.wp.os.notify !== 'function'" ) < strpos( $njs, 'window.setInterval' ) && false !== strpos( $njs, 'n.id <= seen()' ) && false !== strpos( $njs, "tag: 'signal-noise/alert'" ) && false !== strpos( $njs, 'os.openWindow( n.app, { params: { section: String( n.section ) } } )' ), 'the script runs only where wp.os.notify exists, shows a notice once per device, and collapses on one tag' );

echo "\nRegistration\n";
$src = static fn( $f ) => (string) file_get_contents( __DIR__ . '/../' . $f );
ok( false !== strpos( $src( 'inc/watches.php' ), "'ripe'      => 'snt_watch_ripe_alerts'" ), 'the watch is registered' );
ok( false !== strpos( $src( 'inc/cron-lifecycle.php' ), 'SNT_ALERTS_HOOK,' ) && false !== strpos( $src( 'inc/cron-dashboard.php' ), "array( 'SNT_ALERTS_HOOK', 'snt_alerts_hourly' )" ), 'the hook is in the deactivation list and the SN-owned list' );
ok( false !== strpos( $src( 'inc/settings.php' ), "'alerts_enabled'          => true," ), 'the stored default is ON' );
ok( false === strpos( $src( 'inc/alerts-cron.php' ) . $src( 'inc/alerts.php' ), 'set_transient' ) && false === strpos( $src( 'inc/alerts-cron.php' ) . $src( 'inc/alerts.php' ), 'wp_remote_' ), 'no transient holds a stamp, and the module makes no outside call' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
