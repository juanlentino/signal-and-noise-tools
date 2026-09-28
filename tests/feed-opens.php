<?php
/**
 * Feed reach: opens are counted once per (day, note, hashed UA), pruned to 90
 * days, bounded per day, windowed per note; and they sit beside the north
 * star as an input without ever moving the star itself.
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
function get_transient() { return false; }
function set_transient() { return true; }
function get_posts() { return array(); }
require __DIR__ . '/../inc/feed-opens.php';
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Feed opens\n\n";

$now = gmmktime( 12, 0, 0, 9, 27, 2026 );
$s   = snt_feed_opens_record( array(), 10, 'aaaa', $now );
$s   = snt_feed_opens_record( $s, 10, 'aaaa', $now + 60 );
ok( 1 === snt_feed_opens_window( $s, 7, $now )['total'], 'the same reader opening the same note the same day counts once' );
$s = snt_feed_opens_record( $s, 10, 'bbbb', $now );
$s = snt_feed_opens_record( $s, 11, 'aaaa', $now );
$s = snt_feed_opens_record( $s, 10, 'aaaa', $now + DAY_IN_SECONDS );
$w = snt_feed_opens_window( $s, 7, $now + DAY_IN_SECONDS );
ok( 4 === $w['total'] && array( 10 => 3, 11 => 1 ) === $w['notes'], 'another reader, another note and another day each count; per-note totals, busiest first' );
ok( 1 === snt_feed_opens_window( $s, 1, $now + DAY_IN_SECONDS )['total'], 'a 1-day window reads only today' );
$old = snt_feed_opens_record( array(), 9, 'x', $now - 100 * DAY_IN_SECONDS );
ok( ! isset( snt_feed_opens_record( $old, 10, 'y', $now )[ gmdate( 'Y-m-d', $now - 100 * DAY_IN_SECONDS ) ] ), 'days older than 90 are pruned on write' );
$cap = array();
for ( $i = 0; $i < SNT_FEED_OPENS_PER_DAY + 20; $i++ ) {
	$cap = snt_feed_opens_record( $cap, 10, 'ua' . $i, $now );
}
ok( SNT_FEED_OPENS_PER_DAY === snt_feed_opens_window( $cap, 1, $now )['total'], 'a UA-spraying flood is bounded per note per day' );
$all = array();
for ( $i = 0; $i < 40000; $i++ ) {
	$all = snt_feed_opens_record( $all, 1 + ( $i % 80 ), 'ua' . $i, $now );
}
ok( snt_feed_opens_window( $all, 1, $now )['total'] <= 1000, 'a flood sprayed across every note is bounded per day in total, not only per note' );
ok( strlen( serialize( $all ) ) * SNT_FEED_OPENS_DAYS < 4 * 1024 * 1024, 'ninety flooded days stay a few MB, not tens' );

// Report summary: 7d/30d totals and a top list, labelled a floor.
$sum = array();
$sum = snt_feed_opens_record( $sum, 7, 'r1', $now );
$sum = snt_feed_opens_record( $sum, 7, 'r2', $now );
$sum = snt_feed_opens_record( $sum, 8, 'r1', $now - 20 * DAY_IN_SECONDS );
$r   = snt_feed_opens_summary( $sum, $now );
ok( 2 === $r['total_7d'] && 3 === $r['total_30d'], 'the summary carries 7-day and 30-day totals' );
ok( array( 7, 8 ) === array_column( $r['top_30d'], 'post_id' ) && 2 === $r['top_30d'][0]['opens'], 'the top list is per note, most opened first' );
ok( 'floor' === $r['basis'] && false !== stripos( $r['note'], 'at least' ), 'the summary is labelled a floor, "at least"' );

// North star pin: the star is on-site reads only; feed opens are an input beside it.
$t  = time();
$ev = function ( $vid, $e, $path, $x = array() ) use ( $t ) { return array_merge( array( 'vid' => $vid, 'ev' => $e, 'path' => $path, 'ts' => $t - 3600 ), $x ); };
function sn_analytics_fetch_session_events() {
	return array( 'visits' => $GLOBALS['fo_visits'], 'capped' => false, 'configured' => true );
}
$GLOBALS['fo_visits'] = array( array( $ev( 'a', 'pv', '/notes/x/' ), $ev( 'a', 'sc', '/notes/x/', array( 'scroll' => 80 ) ) ) );

$GLOBALS['fo_opts'][ SNT_FEED_OPENS_OPT ] = array();
$base = snt_nsm_reading( true );
$flood = array();
for ( $i = 0; $i < 400; $i++ ) {
	$flood = snt_feed_opens_record( $flood, 100 + $i, 'ua', $t );
}
$GLOBALS['fo_opts'][ SNT_FEED_OPENS_OPT ] = $flood;
$with = snt_nsm_reading( true );
ok( 1 === $base['value'] && $base['value'] === $with['value'] && $base['series'] === $with['series'] && $base['trend'] === $with['trend'], 'the north star value, series and trend are unchanged by 400 feed opens' );
ok( 400 === $with['layers']['inputs']['feed_opens']['value'] && '7d' === $with['layers']['inputs']['feed_opens']['window'], 'feed opens show as their own input, 7-day window' );
ok( 0 === $base['layers']['inputs']['feed_opens']['value'] && array_key_exists( 'rss_readers', $with['layers']['inputs'] ) && array_key_exists( 'feed_clicks', $with['layers']['inputs'] ), 'beside rss_readers and feed_clicks, zero when none' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
