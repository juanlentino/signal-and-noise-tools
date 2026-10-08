<?php
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '0.0.0-test' ); } // the desktop payload caches are version-stamped
/**
 * Standalone test: SN Reading and the groups SN Traffic paints under its
 * sparkline (SN Audience and SN RSS Subscribers, folded in). The row builders
 * are pure; the painter runs in node against a fake DOM.
 *
 * Run: php tests/desktop-mode-analytics-widgets.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function add_action() {}
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function current_time( $t ) { return '2026-10-03 19:00:00'; }
$GLOBALS['tr'] = array();
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['tr'][ $k ] = $v; $GLOBALS['ttl'] = $ttl; return true; }
class WP_REST_Response { public $data; public function __construct( $d ) { $this->data = $d; } }
require __DIR__ . '/../inc/desktop-mode-analytics-widgets.php';
require __DIR__ . '/../inc/desktop-mode-audience.php';
require __DIR__ . '/../inc/desktop-mode-reading.php';

echo "\nShared\n";
ok( array( 'title' => 'T', 'rows' => array(), 'empty' => 'none' ) === snt_desktop_group( 'T', array(), 'none' ), 'a group with no rows carries its empty sentence' );
ok( ! array_key_exists( 'empty', snt_desktop_group( 'T', array( array( 'label' => 'a', 'value' => '1' ) ), 'none' ) ), 'a group with rows carries none' );
ok( '25%' === snt_desktop_pct( 1, 4 ) && '' === snt_desktop_pct( 1, 0 ), 'a share of nothing is blank, not 0%' );
ok( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) === snt_desktop_widget_window(), 'the window is the site\'s last 14 days, today included' );
$calls = 0;
$build = static function ( $win ) use ( &$calls ) { $calls++; return array( snt_desktop_group( 'G', array(), 'e' ) ); };
$a = snt_desktop_widget_response( 'audience', $build ); $b = snt_desktop_widget_response( 'audience', $build );
ok( 1 === $calls && $a->data === $b->data && 900 === $GLOBALS['ttl'] && isset( $GLOBALS['tr'][ 'sn_desktop_audience_2026-10-03_' . SNT_VERSION ] ), 'a payload is built once and held 15 minutes under a key stamped with the day and the plugin version' );

echo "\nAudience\n";
$rows = snt_desktop_audience_rows( array( array( 'value' => 'US', 'views' => 30 ), array( 'value' => '', 'views' => 10 ), array( 'value' => 'AR', 'views' => 0 ) ), 'value', 5 );
ok( array( array( 'label' => 'US', 'value' => '30 · 75%', 'share' => 75.0 ), array( 'label' => '(unknown)', 'value' => '10 · 25%', 'share' => 25.0 ) ) === $rows, 'rows carry views and share (as text and as a number for the bar); a zero row is dropped; a blank name says unknown' );
ok( array() === snt_desktop_audience_rows( null, 'value', 5 ), 'a failed read gives no rows (the group then says so), never zeros' );
$many = array(); foreach ( range( 1, 8 ) as $i ) { $many[] = array( 'value' => "c$i", 'views' => 10 ); }
$top = snt_desktop_audience_rows( $many, 'value', 5 );
ok( 5 === count( $top ) && '10 · 13%' === $top[0]['value'] && 12.5 === $top[0]['share'], 'the share is of ALL rows, not of the rows kept' );
$hn = snt_desktop_audience_hn_rows( array( 9 => array( 'title' => 'A note', 'points' => 14, 'comments' => 3, 'rank' => 7 ), 8 => array( 'title' => 'Older', 'points' => 2, 'comments' => 0, 'rank' => 0 ) ), 3 );
ok( '14 pts · 3 comments · #7 on the front page' === $hn[0]['value'] && '2 pts · 0 comments' === $hn[1]['value'], 'a Hacker News row names the rank only while the story is on the front page' );
$s = snt_desktop_audience_search_rows( array( 'clicks' => 12, 'impressions' => 3400, 'days' => 28 ), array( 'totals' => array( 'clicks' => 1, 'impressions' => 90, 'days' => 30 ) ) );
ok( array( 'Google · 28d', 'Bing · 30d' ) === array_column( $s, 'label' ) && '12 clicks · 3,400 impressions' === $s[0]['value'], 'search rows name their own windows' );
ok( 'Bing · 30d · last sync failed' === snt_desktop_audience_search_rows( null, array( 'last_error' => '2026-10-03 403', 'totals' => array( 'clicks' => 1, 'impressions' => 90, 'days' => 30 ) ) )[0]['label'] && 'Bing · 30d' === $s[1]['label'], 'totals kept from before a failed Bing sync are marked, not passed off as current' );
ok( 'Google · 28d · last sync failed' === snt_desktop_audience_search_rows( array( 'clicks' => 12, 'impressions' => 3400, 'days' => 28 ), null, true )[0]['label'], 'Google totals kept from before a failed sync are marked too' );
$au = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-audience.php' );
ok( false !== strpos( $au, "'human', 500 )" ) && false !== strpos( $au, "' · last check failed'" ), 'shares divide by every country, and a failed Hacker News check is said on its heading' );
ok( array() === snt_desktop_audience_search_rows( null, null ) && 1 === count( snt_desktop_audience_search_rows( null, array( 'totals' => array( 'clicks' => 0, 'impressions' => 0 ) ) ) ), 'an engine with no stored reading has no row; one that read zero has a row' );

echo "\nReading\n";
$d = static fn( array $v ) => array_map( static fn( $n ) => array( 'label' => 'x', 'views' => $n ), $v );
// Every view read to the end: each fires 25, 50, 75 and 100. The share that
// reached half is 100%, not 75% (which dividing by events would give).
$all = snt_desktop_reading_page_rows( array( 'views' => 10, 'scroll_avg_per_view' => 100.0, 'time_avg_per_view' => 42000.0 ), $d( array( 0, 10, 10, 20 ) ) );
ok( array( '100%', '100%', '42s' ) === array_column( $all, 'value' ), 'scroll reach is the 50% milestones over page views, not over events' );
$some = snt_desktop_reading_page_rows( array( 'views' => 40, 'scroll_avg_per_view' => 37.5, 'time_avg_per_view' => 185000.0 ), $d( array( 0, 30, 10, 10 ) ) );
ok( array( '25%', '38%', '3m 05s' ) === array_column( $some, 'value' ) && 'Average time per view' === $some[2]['label'], 'the depth and time are the per-view averages, labeled as averages' );
ok( array() === snt_desktop_reading_page_rows( array( 'views' => 0 ), $d( array( 0, 0, 0, 0 ) ) ) && array() === snt_desktop_reading_page_rows( null, array() ), 'no page views gives no rows' );
ok( 2 === count( snt_desktop_reading_page_rows( array( 'views' => 5, 'scroll_avg_per_view' => 50.0, 'time_avg_per_view' => 1000.0 ), $d( array( 0, 9, 9, 9 ) ) ) ), 'a milestone count above the views (windows that disagree) drops the reach row instead of printing over 100%' );
ok( array( 'label' => 'Visitor-days', 'value' => '244' ) === snt_desktop_reading_page_rows( array( 'views' => 203, 'visits' => 244 ), array() )[0], 'the visitor-days figure Site Views called Visits is kept, under its real name' );
ok( array( array( 'label' => 'Visitor-days', 'value' => '7' ) ) === snt_desktop_reading_page_rows( array( 'views' => 0, 'visits' => 7 ), array() ), 'visitor-days with no page view (feed readers) still show: the figure has no other home now' );
$e = static fn( $rate, $pts ) => snt_desktop_reading_page_rows( array( 'views' => 10 ), array(), array( 'rate' => $rate, 'pts' => $pts ) )[0];
ok( array( 'label' => 'Engaged', 'value' => '37% ▼ 10 pts', 'tone' => 'down' ) === $e( 37, -10 ), 'engaged, 10 points down: arrow, no sign, toned down' );
ok( array( 'label' => 'Engaged', 'value' => '40% ▲ 5 pts', 'tone' => 'up' ) === $e( 40, 5 ), 'at the 5-point threshold: toned up' );
ok( array( 'label' => 'Engaged', 'value' => '40% ▲ 4 pts' ) === $e( 40, 4 ), 'just under: the change is shown but not colored' );
ok( array( 'label' => 'Engaged', 'value' => '0%' ) === snt_desktop_reading_page_rows( array( 'views' => 10 ), array(), array( 'rate' => 0 ) )[0], 'a measured 0% with no prior window is a row, without a change' );
ok( 'Sessions' === snt_desktop_reading_visit_rows( array( array( 'visits' => 3, 'bounce_pct' => 0.0, 'ppv' => 1.0, 'median_dur' => 5 ) ) )[0]['label'], 'the unit is sessions, so it cannot be read as SN Site Views visitor-days' );
$v = snt_desktop_reading_visit_rows( array( array( 'visits' => 30, 'bounce_pct' => 80.0, 'ppv' => 1.2, 'median_dur' => 20 ), array( 'visits' => 10, 'bounce_pct' => 40.0, 'ppv' => 2.0, 'median_dur' => 100 ) ) );
ok( array( '40', '70%', '1.40', '40s' ) === array_column( $v, 'value' ), 'visits fold weighted by each day\'s visits, not as a plain mean of days' );
$dv = snt_desktop_reading_visit_rows( array( array( 'visits' => 10, 'bounce_pct' => 70.0, 'ppv' => 1.5, 'median_dur' => 20, 'two_pages' => 2, 'deep_pages' => 1 ), array( 'visits' => 30, 'bounce_pct' => 80.0, 'ppv' => 1.2, 'median_dur' => 20, 'two_pages' => null, 'deep_pages' => null ) ) );
ok( array( 'Sessions', 'One page only', 'Two pages · three or more', 'Pages per session', 'Typical session' ) === array_column( $dv, 'label' ) && '20% · 10%' === $dv[2]['value'] && '70%' === $dv[1]['value'] && array( 70.0, 20.0, 10.0 ) === ( $dv[1]['split'] ?? null ), 'the three depth shares (also as numbers for the bar) come from the same sessions (the 10 that measured it) and add up to 100%; two pages and three or more share one row, so the card keeps its height' );
ok( 4 === count( $v ), 'with no day measuring the split, the two rows are absent, not zero' );
ok( null === snt_desktop_reading_visit_rows( null ) && array() === snt_desktop_reading_visit_rows( array() ), 'a failed visits read stays null (the group says it could not be read); an empty window is an empty list' );
ok( '42s' === snt_desktop_reading_seconds( 42 ) && '3m 05s' === snt_desktop_reading_seconds( 185 ), 'seconds read as people say them' );
ok( array( 'label' => 'LCP', 'value' => '80% good · 10% poor', 'split' => array( 80.0, 10.0, 10.0 ), 'quality' => true ) === snt_desktop_reading_vital_row( 'LCP', $d( array( 8, 1, 1 ) ) ), 'a vital whose percentile could not be read still shows its good and poor shares, and the three bands as numbers for its bar' );
$p75 = static fn( $x ) => array( array( 'label' => 'p50', 'value' => 1.0 ), array( 'label' => 'p75', 'value' => $x ) );
ok( 'LCP · p75 1.8s' === snt_desktop_reading_vital_row( 'LCP', $d( array( 8, 1, 1 ) ), $p75( 1840.0 ) )['label'] && 'INP · p75 120ms' === snt_desktop_reading_vital_row( 'INP', $d( array( 8, 1, 1 ) ), $p75( 120.0 ) )['label'] && 'CLS · p75 0.05' === snt_desktop_reading_vital_row( 'CLS', $d( array( 8, 1, 1 ) ), $p75( 50.0 ) )['label'], 'the 75th percentile reads in each vital\'s own unit: seconds, milliseconds, and CLS back from its x1000 storage' );
ok( null === snt_desktop_reading_vital_row( 'INP', $d( array( 0, 0, 0 ) ) ), 'a vital nobody measured is absent, not 0% good' );

$au2 = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-audience.php' );
ok( false !== strpos( $au2, "sn_analytics_top_sources( \$win['from'], \$win['to'], 'human', 500 )" ) && false === strpos( $au2, 'sn_analytics_referrer_categories' ), 'Sources are the named ones (Hacker News, LinkedIn), not the five categories' );

echo "\nSN Traffic's groups\n";
ok( array( array( 'label' => '24h', 'value' => '3 unique · 40 requests' ), array( 'label' => '7d', 'value' => '9 unique · 300 requests' ), array( 'label' => '30d', 'value' => '1,204 unique · 9,001 requests' ) ) === snt_desktop_traffic_feed_rows( array( 'windows' => array( 1 => array( 'total' => 40, 'uniques' => 3 ), 7 => array( 'total' => 300, 'uniques' => 9 ), 30 => array( 'total' => 9001, 'uniques' => 1204 ) ) ) ), 'feed subscribers read as SN RSS Subscribers did: 24h, 7d, 30d, unique readers then requests' );
ok( array() === snt_desktop_traffic_feed_rows( null ) && 1 === count( snt_desktop_traffic_feed_rows( array( 'windows' => array( 7 => array( 'total' => 0, 'uniques' => 0 ) ) ) ) ), 'a failed feed read gives no rows (the group says it could not be read); a measured zero is a row' );
$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
function sn_analytics_top_dimension( $d, $f, $t, $c, $l ) { $out = array(); foreach ( range( 1, 6 ) as $i ) { $out[] = array( 'value' => "$d$i", 'views' => 10 - $i ); } return $out; }
function sn_analytics_top_sources( $f, $t, $c, $l ) { return sn_analytics_top_dimension( 'src', $f, $t, $c, $l ); }
function sn_rss_tracker_window_stats_multi( $d ) { return array( 'windows' => array( 1 => array( 'total' => 1, 'uniques' => 1 ), 7 => array( 'total' => 2, 'uniques' => 2 ), 30 => array( 'total' => 3, 'uniques' => 3 ) ) ); }
function get_option( $k, $d = null ) { return $d; }
function snt_gsc_window_totals() { return array( 'clicks' => 5, 'impressions' => 478, 'days' => 28 ); }
function sn_bing_data() { return array( 'totals' => array( 'clicks' => 0, 'impressions' => 0, 'days' => 16 ) ); }
$tg = snt_desktop_traffic_groups( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) );
ok( array( 'Countries', 'Sources', 'Hacker News · latest story', 'Devices, search, feed' ) === array_column( $tg, 'title' ), 'SN Traffic\'s groups: countries, sources, Hacker News, then devices, search and feed as one-line rows (no campaigns when no tagged link was followed)' );
ok( ! empty( $tg[0]['share'] ) && ! empty( $tg[0]['pair'] ) && ! empty( $tg[1]['share'] ) && empty( $tg[1]['pair'] ) && empty( $tg[2]['share'] ), 'Countries pairs with Sources and both draw a share bar; nothing else is hinted' );
$dev = array_values( array_filter( $tg[3]['rows'], static fn( $r ) => 'Devices' === $r['label'] ) );
ok( 1 === count( $dev ) && is_array( $dev[0]['split'] ?? null ) && count( $dev[0]['split'] ) === count( explode( ' · ', $dev[0]['value'] ) ), 'the Devices row carries one share per device it names, for its bar' );
$glance = array_column( $tg[3]['rows'], 'value', 'label' );
ok( 'Google 5 clicks · 478 impr · Bing 0 clicks · 0 impr' === ( $glance['Search'] ?? '' ), 'search keeps impressions beside clicks per engine, on its one row (owner\'s pick, 2026-10-04): ' . ( $glance['Search'] ?? '' ) );
ok( array( 3, 4 ) === array( count( $tg[0]['rows'] ), count( $tg[1]['rows'] ) ) && 1 === preg_match( '/^device1 \d+% · device2 \d+%$/', $glance['Devices'] ?? '' ) && '1 · 2 · 3' === ( $glance['Feed, unique 24h · 7d · 30d'] ?? '' ), 'top 3 countries, top 4 sources; devices and the three feed windows each fold into one row' );
function sn_analytics_top_utm_campaigns( $f, $t, $c, $l ) { return $GLOBALS['__camp'] ?? array(); }
$GLOBALS['__camp'] = array( array( 'value' => '(none)', 'views' => 9 ), array( 'value' => 'spring', 'views' => 3 ) );
$tgc = snt_desktop_traffic_groups( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) );
ok( array( 'Countries', 'Sources', 'Campaigns', 'Hacker News · latest story', 'Devices, search, feed' ) === array_column( $tgc, 'title' ) && array( 'spring' ) === array_column( $tgc[2]['rows'], 'label' ), 'a Campaigns group appears when a tagged link was followed, as SN Audience showed it; the no-campaign bucket is not a campaign' );
$GLOBALS['__camp'] = array( array( 'value' => '(none)', 'views' => 9 ) );
ok( ! in_array( 'Campaigns', array_column( snt_desktop_traffic_groups( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) ), 'title' ), true ), 'only the no-campaign bucket: no Campaigns group' );
echo "\nSN Traffic's reach row\n";
ok( array( 'countries' => 2, 'sources' => 1, 'countries_capped' => false, 'sources_capped' => false ) === snt_desktop_traffic_reach_counts( array( array( 'value' => 'US', 'views' => 3 ), array( 'value' => 'AR', 'views' => 1 ), array( 'value' => '', 'views' => 9 ), array( 'value' => 'FR', 'views' => 0 ) ), array( array( 'value' => 'direct', 'views' => 2 ) ) ), 'reach counts distinct countries and sources that had views; an unknown or zero row is not a country' );
ok( null === snt_desktop_traffic_reach_counts( null, array() ) && null === snt_desktop_traffic_reach_counts( array(), null ), 'a failed read is no count, never 0' );
$reach = snt_desktop_traffic_reach( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) );
ok( 6 === $reach['countries'] && 6 === $reach['sources'] && array( 'countries' => 6, 'sources' => 6, 'countries_capped' => false, 'sources_capped' => false ) === $reach['prior'], 'reach reads the window and the prior 14 days (the same rollup reads, earlier dates)' );
$GLOBALS['wpdb']->last_error = 'gone';
ok( null === snt_desktop_traffic_reach( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) ), 'a failed read leaves the reach row out' );
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->last_error = "Table 'wp_sn_rss_tracker' doesn't exist";
$tg = snt_desktop_traffic_groups( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) );
ok( 'could not be read' === ( array_column( $tg[3]['rows'], 'value', 'label' )['Feed'] ?? '' ), 'a broken feed table says it could not be read, never zero subscribers' );
unset( $GLOBALS['wpdb'] );

echo "\nA failed table is not an empty one\n";
$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
ok( false === snt_desktop_db_failed(), 'no database error: not failed' );
$GLOBALS['wpdb']->last_error = "Table 'wp_sn_analytics_buckets' doesn't exist";
ok( true === snt_desktop_db_failed(), 'a database error on the read just made: failed' );
function sn_analytics_distribution( $m, $f, $t, $c ) { return array( array( 'label' => 'a', 'views' => 0 ), array( 'label' => 'b', 'views' => 0 ), array( 'label' => 'c', 'views' => 0 ) ); }
function sn_analytics_top_events( $f, $t, $l ) { return array(); }
$g = snt_desktop_reading_groups( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) );
$by = array_column( $g, null, 'title' );
ok( 'The custom events could not be read.' === $by['Custom events · all traffic']['empty'] && 'The field measurements could not be read.' === $by['Core Web Vitals']['empty'], 'a broken rollup table says it could not be read, not "none" and not 0%' );
$GLOBALS['wpdb']->last_error = '';
$g = array_column( snt_desktop_reading_groups( array( 'from' => '2026-09-20', 'to' => '2026-10-03', 'days' => 14 ) ), null, 'title' );
ok( 'No custom events in this window.' === $g['Custom events · all traffic']['empty'] && 'No field measurements in this window.' === $g['Core Web Vitals']['empty'], 'the same empty answers with no error are a real "none"' );
unset( $GLOBALS['wpdb'] );

ok( 1 === snt_desktop_traffic_reach_counts( array( array( 'value' => 'US', 'views' => 3 ), array( 'value' => '(unknown)', 'views' => 5 ) ), array() )['countries'], 'the (unknown) bucket is not counted as a country' );
$cap = snt_desktop_traffic_reach_counts( array_fill( 0, 500, array( 'value' => 'x', 'views' => 1 ) ), array( array( 'value' => 's', 'views' => 2 ) ) );
ok( true === $cap['countries_capped'] && false === $cap['sources_capped'] && 500 === $cap['countries'], 'a reach list that fills the 500-row read is marked capped (a floor), a shorter one is not' );
$vjs = (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-views.js' );
ok( false !== strpos( $vjs, "f[3] && f[0] >= 500 ? '+' : ''" ) && false !== strpos( $vjs, 'p && ! f[3] ? changeNode' ), 'a capped reach count paints as "500+" and shows no change' );

echo "\nSource pins\n";
$rd = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-reading.php' );
ok( false !== strpos( $rd, "'Custom events · all traffic'" ) && false === strpos( $rd, "'Goal events'" ), 'the event group is named for what the table holds: custom events, not goals' );
ok( false !== strpos( $rd, '$ask = $ask && null !== $pct;' ) && false === strpos( $rd, "sn_analytics_percentiles( 'time'" ), 'after one percentile that cannot be read the rest are not asked; time needs no request at all' );
ok( false !== strpos( $rd, "'The sessions could not be read.'" ), 'a failed sessions read has its own sentence' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-audience.php' ), "'Hacker News · latest story'" ), 'the Hacker News group says it is not bound to the window' );

echo "\nThe painter\n";
echo "\nThe opening figure\n";
ok( array( 'value' => '40%', 'label' => 'of views engaged', 'change' => '▼ 7 pts vs. prior 14 days', 'tone' => 'down' ) === snt_desktop_reading_hero( array( 'rate' => 40, 'pts' => -7 ) ), 'the engaged share with its change, toned from 5 points' );
ok( array( 'value' => '40%', 'label' => 'of views engaged', 'change' => '▲ 4 pts vs. prior 14 days' ) === snt_desktop_reading_hero( array( 'rate' => 40, 'pts' => 4 ) ), 'under 5 points the change is shown, not colored' );
ok( array( 'value' => '0%', 'label' => 'of views engaged' ) === snt_desktop_reading_hero( array( 'rate' => 0 ) ) && null === snt_desktop_reading_hero( null ), 'a measured 0% is a figure; an unknown rate is no hero' );
ok( '1 event' === snt_desktop_reading_count( 1, 'event', 'events' ) && '1,204 visitor-days' === snt_desktop_reading_count( 1204, 'visitor-day', 'visitor-days' ), 'a count carries its unit, singular when it is one' );
$built = static fn( $win ) => array( 'hero' => array( 'value' => '9', 'label' => 'x' ), snt_desktop_group( 'G', array(), 'e' ) );
$GLOBALS['tr'] = array();
$resp = snt_desktop_widget_response( 'herotest', $built )->data;
ok( array( 'value' => '9', 'label' => 'x' ) === $resp['hero'] && 1 === count( $resp['groups'] ) && 'G' === $resp['groups'][0]['title'] && abs( time() - $resp['generated_at'] ) < 5, 'the response lifts the hero out of the groups and stamps when it was read' );

$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) { echo "SKIP: node not found\n"; } else {
	$run = static fn( $id, $arg ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/groups-render.js' ) . ' ' . escapeshellarg( $id ) . ' ' . escapeshellarg( $arg ) ), true );
	ok( ! preg_match( "/desktopModeWidgets\\[\\s*'sn-audience'/", (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-groups.js' ) ), 'the painter mounts no SN Audience: that card folded into SN Traffic' );
	$out = $run( 'sn-reading', json_encode( array( 'window' => array( 'days' => 14 ), 'groups' => array( snt_desktop_group( 'Countries', $rows, 'x' ), snt_desktop_group( 'Hacker News', array(), 'No story links here yet.' ) ) ) ) );
	ok( array( 'Last 14 days', 'Countries', 'US', '30 · 75%', '(unknown)', '10 · 25%', 'Hacker News', 'No story links here yet.', 'Open Analytics', '→' ) === $out['lines'], 'groups, rows and an empty group paint in order, then the link' );
	ok( array( 'status', 'heading', 'list', 'listitem', 'listitem', 'heading' ) === $out['roles'], 'the reading is a polite status region; each group title is a heading and its rows a list' );
	ok( 'Open Analytics, from the SN Reading widget' === $out['link']['name'] && true === $out['link']['arrowHidden'], 'the link name starts with its visible words and says which tile it is on; the arrow is hidden from assistive tech' );
	ok( array( '/signal-noise/v1/desktop/reading' ) === $out['paths'] && 'function' === $out['teardown'] && true === $out['same'], 'Reading fetches its own route and returns a teardown' );
	$tone = (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-groups.js' );
	ok( false !== strpos( $tone, "up: '#3fb950', down: '#ff9d94'" ) && false !== strpos( $tone, 'TONE[ r.tone ]' ), 'the painter colors a toned row green or a red light enough for text on the dark card, and nothing else' );
	$hero = $run( 'sn-reading', json_encode( array( 'window' => array( 'days' => 14 ), 'generated_at' => time() - 240, 'hero' => array( 'value' => '40%', 'label' => 'of views engaged', 'change' => '▼ 7 pts vs. prior 14 days', 'tone' => 'down' ), 'groups' => array() ) ) );
	ok( array( '40%', 'of views engaged · last 14 days', '▼ 7 pts vs. prior 14 days', '' ) === array_slice( $hero['lines'], 0, 4 ) && 1 === preg_match( '/^Read at \d{1,2}[:.]\d{2}/', $hero['lines'][4] ) && 'Open Analytics' === $hero['lines'][5] && 'Open Analytics, from the SN Reading widget' === $hero['link']['name'], 'a hero opens the tile with its figure, its unit and window, and its change; the foot says when the reading was taken, as a clock time' );
	$bad = $run( 'sn-reading', 'FAIL' );
	ok( array( '/signal-noise/v1/desktop/reading' ) === $bad['paths'] && 'Could not load this reading.' === $bad['lines'][0] && array( 'status', 'alert' ) === $bad['roles'], 'a failed fetch says so, as an alert; Reading fetches its own route' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
