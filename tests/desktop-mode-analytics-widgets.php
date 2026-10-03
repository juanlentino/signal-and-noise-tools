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
ok( array() === snt_desktop_audience_search_rows( null, null ) && 1 === count( snt_desktop_audience_search_rows( null, array( 'totals' => array( 'clicks' => 0, 'impressions' => 0 ) ) ) ), 'an engine with no stored reading has no row; one that read zero has a row' );

echo "\nReading\n";
$d = static fn( array $v ) => array_map( static fn( $n ) => array( 'label' => 'x', 'views' => $n ), $v );
$sc = snt_desktop_reading_scroll_rows( $d( array( 10, 30, 40, 20 ) ) );
ok( '60%' === $sc[0]['value'] && '20%' === $sc[1]['value'], 'scroll: half is the top two bands, three quarters the top one' );
ok( array() === snt_desktop_reading_scroll_rows( $d( array( 0, 0, 0, 0 ) ) ) && array() === snt_desktop_reading_scroll_rows( array() ), 'no scroll events gives no rows' );
$v = snt_desktop_reading_visit_rows( array( array( 'visits' => 30, 'bounce_pct' => 80.0, 'ppv' => 1.2, 'median_dur' => 20 ), array( 'visits' => 10, 'bounce_pct' => 40.0, 'ppv' => 2.0, 'median_dur' => 100 ) ) );
ok( array( '40', '70%', '1.40', '40s' ) === array_column( $v, 'value' ), 'visits fold weighted by each day\'s visits, not as a plain mean of days' );
ok( array() === snt_desktop_reading_visit_rows( null ) && array() === snt_desktop_reading_visit_rows( array() ), 'no rolled-up day gives no rows' );
ok( '42s' === snt_desktop_reading_seconds( 42 ) && '3m 05s' === snt_desktop_reading_seconds( 185 ), 'seconds read as people say them' );
ok( 'LCP' === snt_desktop_reading_vital_row( 'LCP', $d( array( 8, 1, 1 ) ) )['label'] && '80% good · 10% poor · 10 loads' === snt_desktop_reading_vital_row( 'LCP', $d( array( 8, 1, 1 ) ) )['value'], 'a vital reads as its good and poor shares over its loads' );
ok( null === snt_desktop_reading_vital_row( 'INP', $d( array( 0, 0, 0 ) ) ), 'a vital nobody measured is absent, not 0% good' );

echo "\nThe painter\n";
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) { echo "SKIP: node not found\n"; } else {
	$run = static fn( $id, $arg ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/groups-render.js' ) . ' ' . escapeshellarg( $id ) . ' ' . escapeshellarg( $arg ) ), true );
	$out = $run( 'sn-audience', json_encode( array( 'window' => array( 'days' => 14 ), 'groups' => array( snt_desktop_group( 'Countries', $rows, 'x' ), snt_desktop_group( 'Hacker News', array(), 'No story links here yet.' ) ) ) ) );
	ok( array( 'Last 14 days', 'Countries', 'US', '30 · 75%', '(unknown)', '10 · 25%', 'Hacker News', 'No story links here yet.', 'Open Analytics →' ) === $out['lines'], 'groups, rows and an empty group paint in order, then the link' );
	ok( array( '/signal-noise/v1/desktop/audience' ) === $out['paths'] && 'function' === $out['teardown'] && true === $out['same'], 'Audience fetches its own route and returns a teardown' );
	$bad = $run( 'sn-reading', 'FAIL' );
	ok( array( '/signal-noise/v1/desktop/reading' ) === $bad['paths'] && 'Could not load this reading.' === $bad['lines'][0], 'a failed fetch says so; Reading fetches its own route' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
