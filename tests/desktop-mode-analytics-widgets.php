<?php
/**
 * Standalone test: the SN Audience and SN Reading desktop widgets. The row
 * builders are pure; the painter runs in node against a fake DOM.
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
ok( 1 === $calls && $a->data === $b->data && 900 === $GLOBALS['ttl'] && isset( $GLOBALS['tr']['sn_desktop_audience_2026-10-03'] ), 'a payload is built once and held 15 minutes under a day-stamped key' );

echo "\nAudience\n";
$rows = snt_desktop_audience_rows( array( array( 'value' => 'US', 'views' => 30 ), array( 'value' => '', 'views' => 10 ), array( 'value' => 'AR', 'views' => 0 ) ), 'value', 5 );
ok( array( array( 'label' => 'US', 'value' => '30 · 75%' ), array( 'label' => '(unknown)', 'value' => '10 · 25%' ) ) === $rows, 'rows carry views and share; a zero row is dropped; a blank name says unknown' );
ok( array() === snt_desktop_audience_rows( null, 'value', 5 ), 'a failed read gives no rows (the group then says so), never zeros' );
$many = array(); foreach ( range( 1, 8 ) as $i ) { $many[] = array( 'value' => "c$i", 'views' => 10 ); }
$top = snt_desktop_audience_rows( $many, 'value', 5 );
ok( 5 === count( $top ) && '10 · 13%' === $top[0]['value'], 'the share is of ALL rows, not of the rows kept' );
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
$e = static fn( $rate, $pts ) => snt_desktop_reading_page_rows( array( 'views' => 10 ), array(), array( 'rate' => $rate, 'pts' => $pts ) )[0];
ok( array( 'label' => 'Engaged', 'value' => '37% ▼ 10 pts', 'tone' => 'down' ) === $e( 37, -10 ), 'engaged, 10 points down: arrow, no sign, toned down' );
ok( array( 'label' => 'Engaged', 'value' => '40% ▲ 5 pts', 'tone' => 'up' ) === $e( 40, 5 ), 'at the 5-point threshold: toned up' );
ok( array( 'label' => 'Engaged', 'value' => '40% ▲ 4 pts' ) === $e( 40, 4 ), 'just under: the change is shown but not colored' );
ok( array( 'label' => 'Engaged', 'value' => '0%' ) === snt_desktop_reading_page_rows( array( 'views' => 10 ), array(), array( 'rate' => 0 ) )[0], 'a measured 0% with no prior window is a row, without a change' );
ok( 'Sessions' === snt_desktop_reading_visit_rows( array( array( 'visits' => 3, 'bounce_pct' => 0.0, 'ppv' => 1.0, 'median_dur' => 5 ) ) )[0]['label'], 'the unit is sessions, so it cannot be read as SN Site Views visitor-days' );
$v = snt_desktop_reading_visit_rows( array( array( 'visits' => 30, 'bounce_pct' => 80.0, 'ppv' => 1.2, 'median_dur' => 20 ), array( 'visits' => 10, 'bounce_pct' => 40.0, 'ppv' => 2.0, 'median_dur' => 100 ) ) );
ok( array( '40', '70%', '1.40', '40s' ) === array_column( $v, 'value' ), 'visits fold weighted by each day\'s visits, not as a plain mean of days' );
ok( null === snt_desktop_reading_visit_rows( null ) && array() === snt_desktop_reading_visit_rows( array() ), 'a failed visits read stays null (the group says it could not be read); an empty window is an empty list' );
ok( '42s' === snt_desktop_reading_seconds( 42 ) && '3m 05s' === snt_desktop_reading_seconds( 185 ), 'seconds read as people say them' );
ok( array( 'label' => 'LCP', 'value' => '80% good · 10% poor' ) === snt_desktop_reading_vital_row( 'LCP', $d( array( 8, 1, 1 ) ) ), 'a vital whose percentile could not be read still shows its good and poor shares' );
$p75 = static fn( $x ) => array( array( 'label' => 'p50', 'value' => 1.0 ), array( 'label' => 'p75', 'value' => $x ) );
ok( 'LCP · p75 1.8s' === snt_desktop_reading_vital_row( 'LCP', $d( array( 8, 1, 1 ) ), $p75( 1840.0 ) )['label'] && 'INP · p75 120ms' === snt_desktop_reading_vital_row( 'INP', $d( array( 8, 1, 1 ) ), $p75( 120.0 ) )['label'] && 'CLS · p75 0.05' === snt_desktop_reading_vital_row( 'CLS', $d( array( 8, 1, 1 ) ), $p75( 50.0 ) )['label'], 'the 75th percentile reads in each vital\'s own unit: seconds, milliseconds, and CLS back from its x1000 storage' );
ok( null === snt_desktop_reading_vital_row( 'INP', $d( array( 0, 0, 0 ) ) ), 'a vital nobody measured is absent, not 0% good' );

$au2 = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-audience.php' );
ok( false !== strpos( $au2, "sn_analytics_top_sources( \$win['from'], \$win['to'], 'human', 500 )" ) && false === strpos( $au2, 'sn_analytics_referrer_categories' ), 'Sources are the named ones (Hacker News, LinkedIn), not the five categories' );
ok( false !== strpos( $au2, "'(none)' !== (string) ( \$r['value'] ?? '' )" ), 'the no-campaign bucket is not a campaign' );

echo "\nSource pins\n";
$rd = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-reading.php' );
ok( false !== strpos( $rd, "'Custom events · all traffic'" ) && false === strpos( $rd, "'Goal events'" ), 'the event group is named for what the table holds: custom events, not goals' );
ok( false !== strpos( $rd, '$ask = $ask && null !== $pct;' ) && false === strpos( $rd, "sn_analytics_percentiles( 'time'" ), 'after one percentile that cannot be read the rest are not asked; time needs no request at all' );
ok( false !== strpos( $rd, "'The sessions could not be read.'" ), 'a failed sessions read has its own sentence' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-audience.php' ), "'Hacker News · latest stories'" ), 'the Hacker News group says it is not bound to the window' );

echo "\nThe painter\n";
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) { echo "SKIP: node not found\n"; } else {
	$run = static fn( $id, $arg ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/groups-render.js' ) . ' ' . escapeshellarg( $id ) . ' ' . escapeshellarg( $arg ) ), true );
	$out = $run( 'sn-audience', json_encode( array( 'window' => array( 'days' => 14 ), 'groups' => array( snt_desktop_group( 'Countries', $rows, 'x' ), snt_desktop_group( 'Hacker News', array(), 'No story links here yet.' ) ) ) ) );
	ok( array( 'Last 14 days', 'Countries', 'US', '30 · 75%', '(unknown)', '10 · 25%', 'Hacker News', 'No story links here yet.', 'Open Analytics →' ) === $out['lines'], 'groups, rows and an empty group paint in order, then the link' );
	ok( array( '/signal-noise/v1/desktop/audience' ) === $out['paths'] && 'function' === $out['teardown'] && true === $out['same'], 'Audience fetches its own route and returns a teardown' );
	$tone = (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-groups.js' );
	ok( false !== strpos( $tone, "up: '#3fb950', down: '#c9503f'" ) && false !== strpos( $tone, 'TONE[ r.tone ]' ), 'the painter colors a toned row with the two Site Views colors, and nothing else' );
	$bad = $run( 'sn-reading', 'FAIL' );
	ok( array( '/signal-noise/v1/desktop/reading' ) === $bad['paths'] && 'Could not load this reading.' === $bad['lines'][0], 'a failed fetch says so; Reading fetches its own route' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
