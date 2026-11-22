<?php
/**
 * signal-noise/relay-networks (owner-only, local door, read-only): which
 * network numbers (ASNs) the page views on akamai- or fastly-named networks
 * arrive from, by stored class, browser and OS. Read before the relay rule
 * (matched by network NAME today) is changed; nothing here changes or
 * subtracts anything.
 *
 * Pins: the statement (page views only, the window, the worker's pattern, the
 * five grouping columns, the visitor hash only inside a distinct count); the
 * fold (halves merged, sorted, capped at 200 with truncated, the summary read
 * before the cap, the Safari-on-Apple split, ASN 0 kept out of the list); the
 * input bounds; the capability; and the real payload on core's validator.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/analytics-relay-networks.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'WPINC', 'wp-includes' );
require __DIR__ . '/lib/wp-html-api.php';
snt_test_require_core( dirname( snt_test_html_api_dir() ), array( 'class-wp-error.php', 'plugin.php', 'rest-api.php', 'json-schema.php' ) );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function __( $t, $d = '' ) { return $t; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_is_numeric_array( $data ) { return is_array( $data ) && 0 === count( array_filter( array_keys( $data ), 'is_string' ) ); }
function _doing_it_wrong( $fn, $msg, $ver ) {}
function get_option( $k, $d = false ) { return $d; }
function wp_register_ability( $slug, $def ) { $GLOBALS['abilities'][ $slug ] = $def; }
$GLOBALS['can'] = false;
function current_user_can( $cap ) { $GLOBALS['asked'][] = $cap; return $GLOBALS['can']; }

$GLOBALS['ae']  = array();
$GLOBALS['sql'] = array();
function sn_analytics_query( $sql ) { $GLOBALS['sql'][] = $sql; return $GLOBALS['ae']; }

$root = dirname( __DIR__ );
require $root . '/inc/abilities-permission-helpers.php';
require $root . '/inc/analytics-rows.php';
foreach ( array( 'analytics-relay-networks.php', 'abilities-relay-networks.php' ) as $f ) {
	if ( ! is_file( "$root/inc/$f" ) ) { echo "FAIL: inc/$f does not exist\n\n0 passed, 1 failed\n"; exit( 1 ); }
	require "$root/inc/$f";
}
do_action( 'wp_abilities_api_init' );

echo "SQL: page views on the relay-named networks, by ASN, name, class, browser and OS\n";
$range = " AND timestamp >= toDateTime('2026-10-05 04:00:00')";
$sql   = sn_relay_networks_sql( 90, 'sn_pageviews_v2', $range );
ok( 'akamai|fastly' === SNT_ANALYTICS_RELAY_ASN_SOURCE && explode( '|', SNT_ANALYTICS_RELAY_ASN_SOURCE ) === sn_analytics_network_terms()['relay'], "the pattern is the worker's RELAY_ASN text, akamai|fastly, and the terms are its alternatives" );
ok( false !== strpos( $sql, " AND blob1 = 'pv' AND (blob12 ILIKE '%akamai%' OR blob12 ILIKE '%fastly%') GROUP BY " ), "page views only, on a network whose name matches akamai or fastly, case-insensitively (ILIKE in WHERE, as the counted rule runs it)" );
ok( false !== strpos( $sql, "FROM sn_pageviews_v2 WHERE timestamp >= toStartOfDay(now() - INTERVAL '90' DAY){$range} AND " ), 'the trailing window and one side of the split' );
ok( 0 === strpos( $sql, 'SELECT toUInt32(double12) AS asn, blob12 AS network, blob7 AS class, blob8 AS browser, blob9 AS os, sum(_sample_interval) AS views, count(DISTINCT substring(index1, 1, 16)) AS visitor_days FROM ' ), 'the ASN is double12, the class is the stored blob7, views are sample-corrected' );
ok( false !== strpos( $sql, ' GROUP BY asn, network, class, browser, os ORDER BY views DESC LIMIT ' . SNT_RELAY_NETWORKS_READ_LIMIT ) && SNT_RELAY_NETWORKS_READ_LIMIT < 10000, 'grouped by aliases (no function in GROUP BY), most views first, under the AE row cap' );
ok( 1 === substr_count( $sql, 'index1' ) && false === strpos( preg_replace( '/count\(DISTINCT substring\(index1, 1, 16\)\)/', '', $sql ), 'index1' ), 'the visitor hash appears only inside the distinct count' );
ok( 0 === preg_match( '/\(\s*SELECT|HAVING|match\(|lower\(/i', $sql ) && ! sn_analytics_sql_too_long( $sql ), 'no subquery, no regex, no function over the name; fits the statement cap' );
ok( false === strpos( sn_relay_networks_sql( 90, 'sn_pageviews', ' AND 1=1' ), '1=1' ), 'only a split bound is interpolated' );
ok( false !== strpos( sn_relay_networks_sql( 500, 'sn_pageviews', '' ), "INTERVAL '90' DAY" ) && false !== strpos( sn_relay_networks_sql( -3, 'sn_pageviews', '' ), "INTERVAL '1' DAY" ), 'the builder clamps the window to 1..90 whatever it is handed' );

echo "\nFold: rows and summary\n";
$g = static fn( $asn, $net, $class, $browser, $os, $views, $vd ) => array( 'asn' => (string) $asn, 'network' => $net, 'class' => $class, 'browser' => $browser, 'os' => $os, 'views' => (string) $views, 'visitor_days' => (string) $vd );
$legacy = array(
	$g( 0, 'Akamai International B.V.', 'human', 'Safari', 'iOS', 40, 9 ),   // before the second generation: no ASN stored
	$g( 0, 'Akamai International B.V.', 'suspect', 'Chrome', 'Windows', 7, 2 ),
);
$v2 = array(
	$g( 36183, 'Akamai Technologies, Inc.', 'human', 'Safari', 'iOS', 30.4, 11 ),
	$g( 54113, 'Fastly, Inc.', 'human', 'Safari', 'macOS', 12, 5 ),
	$g( 36183, 'Akamai Technologies, Inc.', 'human', 'Safari', 'macOS', 8, 3 ),
	$g( 36183, 'Akamai Technologies, Inc.', 'suspect', 'Chrome', 'Windows', 20, 4 ),
	$g( 63949, "Akamai\x07 Connected Cloud", 'suspect', 'Firefox', 'Linux', 5, 1 ),
	$g( 63949, 'Akamai Connected Cloud', 'bot', '', '', 9, 2 ),
	$g( 54113, 'Fastly, Inc.', 'suspect', 'Safari', 'iOS', 3, 1 ),              // Safari on Apple, stored suspect
	$g( 54113, 'Fastly, Inc.', 'human', 'Safari', 'Windows', 2, 1 ),            // Safari, not on Apple
	$g( 54113, 'Fastly, Inc.', 'weird', 'Chrome', 'macOS', 1, 1 ),
	$g( 0, 'Akamai International B.V.', 'human', 'Safari', 'iOS', 4, 2 ),       // the same group as the legacy half's
);
$out = sn_relay_networks_fold( array( $legacy, $v2 ) );
$r0  = $out['rows'][0];
ok( array( 'asn', 'network', 'class', 'browser', 'os', 'views', 'visitor_days' ) === array_keys( $r0 ), 'a row is asn, network, class, browser, os, views, visitor_days' );
ok( array( 0, 'Akamai International B.V.', 'human', 'Safari', 'iOS', 44, 11 ) === array_values( $r0 ), 'the same group from both halves is one row: views and visitor-days add' );
$views = array_column( $out['rows'], 'views' );
$desc  = $views; rsort( $desc );
ok( $views === $desc && 30 === $out['rows'][1]['views'] && 36183 === $out['rows'][1]['asn'], 'sorted by views, most first; views are whole numbers' );
ok( 11 === count( $out['rows'] ) && false === $out['truncated'], 'eleven groups, nothing cut' );
$nets = array_column( $out['rows'], 'network' );
ok( in_array( 'Akamai Connected Cloud', $nets, true ) && ! in_array( "Akamai\x07 Connected Cloud", $nets, true ), 'network names are cleaned as place names are: control characters dropped' );
$cls = array_count_values( array_column( $out['rows'], 'class' ) );
ok( 1 === ( $cls['(unknown)'] ?? 0 ) && ! isset( $cls['weird'] ), 'a class that is not human, suspect or bot reads (unknown), never passed through' );
$bot = array_values( array_filter( $out['rows'], static fn( $r ) => 'bot' === $r['class'] ) );
ok( '(unknown)' === $bot[0]['browser'] && '(unknown)' === $bot[0]['os'], 'a blank browser or OS reads (unknown)' );
$s = $out['summary'];
ok( 141 === $s['views'] && array_sum( $views ) === $s['views'], 'summary.views is every page view read' );
ok( 94 === $s['relay_human']['views'] && 44 === $s['relay_human']['asn_unrecorded_views'], 'relay_human: stored human, Safari, iOS or macOS (94 views), 44 of them on rows with no ASN stored' );
ok( array( array( 'asn' => 36183, 'views' => 38 ), array( 'asn' => 54113, 'views' => 12 ) ) === $s['relay_human']['asns'], 'its distinct ASNs with their views, most first; iOS and macOS add; ASN 0 is not listed as a network' );
ok( array( 'human' => 94, 'suspect' => 3, 'bot' => 0, 'unknown' => 0 ) === $s['safari_apple'], 'Safari on Apple by stored class' );
ok( array( 'human' => 2, 'suspect' => 32, 'bot' => 9, 'unknown' => 1 ) === $s['not_safari_apple'], 'every other browser or OS on the same networks, by stored class (Safari on Windows is not the relay rule)' );
ok( $s['views'] === array_sum( $s['safari_apple'] ) + array_sum( $s['not_safari_apple'] ), 'the two halves of the split add up to the total' );
$json = json_encode( $out );
ok( false === strpos( $json, 'vid' ) && false === strpos( $json, 'index1' ) && false === strpos( $json, '"ip"' ) && false === strpos( $json, 'user_agent' ), 'no visitor code, IP address or user agent' );

$many = array();
for ( $i = 1; $i <= SNT_RELAY_NETWORKS_MAX + 5; $i++ ) {
	$many[] = $g( 1000 + $i, 'Fastly, Inc.', 'human', 'Safari', 'iOS', $i, 1 );
}
$cut = sn_relay_networks_fold( array( $many ) );
ok( 200 === SNT_RELAY_NETWORKS_MAX && 200 === count( $cut['rows'] ) && true === $cut['truncated'] && 205 === $cut['rows'][0]['views'], 'more than 200 groups: the 200 with the most views, truncated' );
ok( 205 === count( $cut['summary']['relay_human']['asns'] ) && array_sum( array_column( $many, 'views' ) ) == $cut['summary']['views'], 'the summary is read before the cap: all 205 ASNs' );
ok( false === sn_relay_networks_fold( array( array_slice( $many, 0, 200 ) ) )['truncated'], 'exactly 200 is not truncated' );
ok( true === sn_relay_networks_fold( array( array_fill( 0, SNT_RELAY_NETWORKS_READ_LIMIT, $many[0] ) ) )['truncated'], 'a half that reached the read limit is truncated' );

echo "\nThe read and the ability\n";
$ab = $GLOBALS['abilities']['signal-noise/relay-networks'] ?? array();
ok( 'snt_ability_perm_manage_options' === ( $ab['permission_callback'] ?? '' ), 'owner-only: the manage_options permission callback' );
$GLOBALS['asked'] = array();
ok( false === call_user_func( $ab['permission_callback'] ?? '__return_true_missing' ) && array( 'manage_options' ) === $GLOBALS['asked'], 'a user without manage_options is refused' );
$GLOBALS['can'] = true;
ok( true === call_user_func( $ab['permission_callback'] ), 'the owner is let in' );
ok( ! empty( $ab['meta']['annotations']['readonly'] ) && empty( $ab['meta']['annotations']['destructive'] ) && false === ( $ab['meta']['mcp']['public'] ?? null ), 'read-only, not a door tool of its own' );
$days = $ab['input_schema']['properties']['days'] ?? array();
ok( 90 === ( $days['default'] ?? null ) && 1 === ( $days['minimum'] ?? null ) && 90 === ( $days['maximum'] ?? null ) && false === ( $ab['input_schema']['additionalProperties'] ?? null ), 'days: 1 to 90, default 90, nothing else accepted' );
foreach ( array( 0, 91, 'all', '7 days', 1.5 ) as $bad ) {
	ok( is_wp_error( snt_ability_relay_networks( array( 'days' => $bad ) ) ), 'days ' . json_encode( $bad ) . ' is refused, as the sibling read refuses it' );
}
$GLOBALS['ae']  = $v2;
$GLOBALS['sql'] = array();
$res = snt_ability_relay_networks( null );
ok( is_array( $res ) && 90 === $res['days'] && false !== strpos( $GLOBALS['sql'][0] ?? '', "INTERVAL '90' DAY" ), 'no input reads 90 days' );
ok( 7 === snt_ability_relay_networks( array( 'days' => '7' ) )['days'] && false !== strpos( end( $GLOBALS['sql'] ), "INTERVAL '7' DAY" ), 'days: 7 reads 7 days' );
ok( 'akamai|fastly' === $res['pattern'] && false === $res['subtracted'] && false !== strpos( $res['basis'], 'Nothing is changed or subtracted' ) && false !== strpos( $res['basis'], 'ASN 0' ), 'it names the pattern, says what it is for, that nothing is changed or subtracted, and what ASN 0 means' );
$schema = $ab['output_schema'] ?? array();
$valid  = static function ( $payload ) use ( $schema ) {
	$v = rest_validate_value_from_schema( $payload, $schema, 'output' );
	return is_wp_error( $v ) ? $v->get_error_message() : true;
};
ok( array( 'days', 'pattern', 'rows', 'truncated', 'summary', 'subtracted', 'basis' ) === array_keys( $res ) && array_keys( $res ) === array_keys( $schema['properties'] ?? array() ), 'the payload carries exactly the keys the output schema declares' );
ok( false === ( $schema['additionalProperties'] ?? null ) && false === ( $schema['properties']['rows']['items']['additionalProperties'] ?? null ) && SNT_RELAY_NETWORKS_MAX === ( $schema['properties']['rows']['maxItems'] ?? null ), 'the schema is closed: a row can carry nothing but its seven fields, and at most 200 rows' );
ok( true === $valid( $res ), "core's validator accepts the real payload: " . json_encode( $valid( $res ) ) );
$GLOBALS['ae'] = array();
ok( true === $valid( snt_ability_relay_networks( array() ) ) && array() === snt_ability_relay_networks( array() )['rows'], 'and an empty window' );
ok( true !== $valid( array( 'rows' => array( array( 'asn' => 1, 'vid' => 'abc' ) ) ) + $res ), 'and refuses a row carrying a visitor code' );
$GLOBALS['ae'] = null;
ok( is_wp_error( snt_ability_relay_networks( array() ) ), 'a failed read is an error, never an empty list' );
$text = (string) ( $ab['description'] ?? '' ) . $res['basis'];
ok( false !== strpos( (string) $ab['description'], 'no visitor code, IP address or user agent' ) && false === strpos( $text, "\u{2014}" ), 'the description says it returns no identity; no em dash' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
