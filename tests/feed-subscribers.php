<?php
/**
 * Feed reach, subscribers: the counts aggregators report in their fetcher UA,
 * kept as the MAX per (fetcher, feed) per day, plus 1 per direct reader; parsed
 * fields only, never the raw UA; a spoofed claim capped and flagged; shown as
 * one of three labelled numbers and never moving the north star.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['fo_opts'] = array();
function __( $s ) { return $s; }
function add_action() {}
function add_filter() {}
function apply_filters( $h, $v ) { return $v; }
function sn_setting( $k, $d = null ) { return $d; }
function get_option( $k, $d = false ) { return $GLOBALS['fo_opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['fo_opts'][ $k ] = $v; return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function get_posts() { return array(); }
require __DIR__ . '/../inc/feed-subscribers.php';
require __DIR__ . '/../inc/feed-opens.php';
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Feed subscribers\n\n";

// Parser, one per format. Formats as the aggregators document them; none were sampled from this site's traffic.
$cases = array(
	'Feedly/1.0 (+http://www.feedly.com/fetcher.html; 12 subscribers; like FeedFetcher-Google)' => array( 'Feedly', '', 12 ),
	'NewsBlur Feed Fetcher - 5 subscribers - https://www.newsblur.com/site/123/x (Mozilla/5.0)' => array( 'NewsBlur Feed Fetcher', '', 5 ),
	'Feedbin feed-id:123 - 3 subscribers'                                                         => array( 'Feedbin', '123', 3 ),
	'Mozilla/5.0 (compatible; inoreader.com; 7 subscribers)'                                      => array( 'inoreader.com', '', 7 ),
	'Mozilla/5.0 (compatible; theoldreader.com; 2 subscribers; feed-id=abc9)'                      => array( 'theoldreader.com', 'abc9', 2 ),
	'SomeReader/2.1 (1,204 readers)'                                                              => array( 'SomeReader', '', 1204 ),
);
foreach ( $cases as $ua => $want ) {
	$p = snt_feed_subs_parse( $ua );
	ok( is_array( $p ) && array( $p['fetcher'], $p['feed_id'], $p['n'] ) === $want && ! $p['capped'], "parses {$want[0]}: n={$want[2]}" . ( $want[1] ? ", feed-id {$want[1]}" : '' ) );
}
ok( null === snt_feed_subs_parse( 'NetNewsWire (RSS Reader; https://netnewswire.com/)' ), 'a UA without a count is a direct reader (null)' );
$spoof = snt_feed_subs_parse( 'Evil/1.0 (1000000000 subscribers)' );
ok( SNT_FEED_SUBS_CAP === $spoof['n'] && true === $spoof['capped'], 'an absurd claim (10^9) is capped at 1,000,000 and flagged' );

$now  = gmmktime( 12, 0, 0, 9, 27, 2026 );
$fly  = 'Feedly/1.0 (+http://www.feedly.com/fetcher.html; %d subscribers; like FeedFetcher-Google)';
$rec  = fn( $s, $ua, $t, $path = '/feed/' ) => snt_feed_subs_record( $s, snt_feed_subs_parse( $ua ), substr( hash( 'sha256', $ua ), 0, 16 ), $path, $t );

// Max per fetcher per day: 12 then 14 the same day is 14, not 26.
$s = $rec( array(), sprintf( $fly, 12 ), $now );
$s = $rec( $s, sprintf( $fly, 14 ), $now + 600 );
$s = $rec( $s, sprintf( $fly, 13 ), $now + 1200 );
ok( 14 === snt_feed_subs_day( $s[ gmdate( 'Y-m-d', $now ) ] ), 'Feedly reporting 12, 14, 13 the same day counts 14, not 39' );

// One fetcher keys once across versions (#1961): max, not a sum per version.
$s2 = $rec( array(), sprintf( $fly, 12 ), $now );
$s2 = $rec( $s2, 'Feedly/2.1 (+http://www.feedly.com/fetcher.html; 15 subscribers; like FeedFetcher-Google)', $now + 60 );
ok( 15 === snt_feed_subs_day( $s2[ gmdate( 'Y-m-d', $now ) ] ), 'Feedly/1.0 and Feedly/2.1 the same day count once (max 15)' );
$bq = 'Mozilla/5.0 (compatible; BazQux/%s; +https://bazqux.com/fetcher; %d subscribers)';
$s2 = $rec( array(), sprintf( $bq, '2.4', 9 ), $now );
$s2 = $rec( $s2, sprintf( $bq, '2.5', 11 ), $now + 60 );
ok( 11 === snt_feed_subs_day( $s2[ gmdate( 'Y-m-d', $now ) ] ), 'BazQux/2.4 and BazQux/2.5 the same day count once (max 11)' );
ok( 'BazQux' === snt_feed_subs_parse( sprintf( $bq, '2.4', 9 ) )['fetcher'], 'the compatible-branch name drops its version (BazQux, not BazQux2.4)' );

// Direct readers once each, same day.
$s = $rec( $s, 'NetNewsWire (RSS Reader)', $now );
$s = $rec( $s, 'NetNewsWire (RSS Reader)', $now + 60 );
$s = $rec( $s, 'Reeder/5.0', $now + 60 );
ok( 16 === snt_feed_subs_day( $s[ gmdate( 'Y-m-d', $now ) ] ), 'two direct readers add 1 each, a repeat fetch adds nothing (14 + 2)' );

// Different feeds of one fetcher are different subscriptions.
$s = $rec( $s, sprintf( $fly, 4 ), $now, '/notes/feed/' );
ok( 20 === snt_feed_subs_day( $s[ gmdate( 'Y-m-d', $now ) ] ), 'the same fetcher on another feed path is its own subscription (+4)' );

// No raw UA anywhere in the store.
ok( false === strpos( json_encode( $s ), 'FeedFetcher' ) && false === strpos( json_encode( $s ), 'NetNewsWire' ), 'the store holds parsed fields only, never the raw UA' );

// Summary: the busiest day in 7, not a sum of days.
$y = $rec( $s, sprintf( $fly, 30 ), $now - DAY_IN_SECONDS );
$r = snt_feed_subs_summary( $y, $now );
ok( 30 === $r['estimate_7d'] && gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ) === $r['peak_day'], 'the 7-day figure is the busiest day (30), not today (20) and not the sum (50)' );
ok( 'ceiling' === $r['basis'] && 'max_day_7d' === $r['window'] && false !== stripos( $r['note'], 'ceiling' ), 'labelled a ceiling, busiest day in 7' );
$old = $rec( array(), sprintf( $fly, 99 ), $now - 10 * DAY_IN_SECONDS );
ok( 0 === snt_feed_subs_summary( $old, $now )['estimate_7d'], 'a day older than 7 is outside the reading' );
ok( ! isset( $rec( $rec( array(), sprintf( $fly, 1 ), $now - 100 * DAY_IN_SECONDS ), sprintf( $fly, 1 ), $now )[ gmdate( 'Y-m-d', $now - 100 * DAY_IN_SECONDS ) ] ), 'days older than 90 are pruned on write' );
$f = snt_feed_subs_summary( $rec( array(), 'Evil/1.0 (1000000000 subscribers)', $now ), $now );
ok( array( 'Evil' ) === $f['flagged'] && SNT_FEED_SUBS_CAP === $f['estimate_7d'], 'a capped claim is flagged in the summary' );
$cap = array();
for ( $i = 0; $i < SNT_FEED_SUBS_PER_DAY + 20; $i++ ) {
	$cap = $rec( $cap, "Spray$i/1.0", $now );
}
ok( SNT_FEED_SUBS_PER_DAY === snt_feed_subs_day( $cap[ gmdate( 'Y-m-d', $now ) ] ), 'a UA-spraying flood is bounded per day' );
$big = array();
for ( $i = 0; $i < 1200; $i++ ) {
	$big = $rec( $big, "F$i" . str_repeat( 'F', 34 ) . '/1.0 (' . ( $i + 1 ) . ' subscribers)', $now, '/' . str_repeat( 'p', 90 ) );
	$big = $rec( $big, "Direct$i/1.0", $now );
}
ok( strlen( serialize( $big ) ) * SNT_FEED_SUBS_DAYS < 4 * 1024 * 1024, 'ninety flooded days of long spoofed keys stay a few MB, not tens' );

// Three labelled numbers, never summed.
$GLOBALS['fo_opts'][ SNT_FEED_SUBS_OPT ] = $y;
$labels = array_column( snt_feed_reach_tiles(), 'label' );
ok( array( 'Subscribers (reported by readers, a ceiling)', 'Opens (at least; many readers block images)', 'Clicks (visits from feed links)' ) === $labels, 'feed reach is three separately labelled numbers' );

// North star pin: subscribers are an input beside the star, never in it.
$t  = time();
$ev = function ( $vid, $e, $path, $x = array() ) use ( $t ) { return array_merge( array( 'vid' => $vid, 'ev' => $e, 'path' => $path, 'ts' => $t - 3600 ), $x ); };
function sn_analytics_fetch_session_events() {
	return array( 'visits' => $GLOBALS['fo_visits'], 'capped' => false, 'configured' => true );
}
$GLOBALS['fo_visits'] = array( array( $ev( 'a', 'pv', '/notes/x/' ), $ev( 'a', 'sc', '/notes/x/', array( 'scroll' => 80 ) ) ) );
$GLOBALS['fo_opts'][ SNT_FEED_SUBS_OPT ] = array();
$base = snt_nsm_reading( true );
$GLOBALS['fo_opts'][ SNT_FEED_SUBS_OPT ] = $rec( array(), sprintf( $fly, 5000 ), $t );
$with = snt_nsm_reading( true );
ok( 1 === $base['value'] && $base['value'] === $with['value'] && $base['series'] === $with['series'] && $base['trend'] === $with['trend'], 'the north star value, series and trend are unchanged by 5,000 reported subscribers' );
ok( 5000 === $with['layers']['inputs']['feed_subscribers']['value'] && 'max_day_7d' === $with['layers']['inputs']['feed_subscribers']['window'], 'feed_subscribers shows as its own input' );
ok( array_key_exists( 'rss_readers', $with['layers']['inputs'] ) && array_key_exists( 'feed_opens', $with['layers']['inputs'] ), 'rss_readers and feed_opens stay beside it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
