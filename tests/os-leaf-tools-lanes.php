<?php
/**
 * Integrity › Lanes, both twins: the classic leaf (inc/analytics-lanes-render.php)
 * and the native painter (apps/sn-dashboard/parts/leaves/tools-lanes.php).
 *
 * A fixture runs through the real beacon derivation into the lanes table (a
 * fake $wpdb), then both twins paint from the table alone. Pins: the sub-tab
 * and painter are registered; both twins say the same notes and figures; the
 * invalid-token line leads; "today pending", both lanes_since dates, the rule
 * version and the visitor-day note are on both; no visitor hash, device id or
 * token reaches either page; no form, no fold; nothing on the page is color only.
 *
 * Run: php tests/os-leaf-tools-lanes.php
 */
$GLOBALS['__options'] = array( 'sn_analytics_lanes_since' => array( 'beacon' => '2026-10-16' ) );
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
require_once __DIR__ . '/lib/os-leaf-harness.php';
define( 'ARRAY_A', 'ARRAY_A' );
function sn_owner_agents() { return array( 'browser', 'chrome-extension', 'claude-code', 'playwright', 'script', 'mcp-local', 'other' ); }
require __DIR__ . '/lib/lanes-fake-wpdb.php';
require SNT_PATH . 'inc/analytics-human-rule.php';
require SNT_PATH . 'inc/analytics-bot-signals.php';
require SNT_PATH . 'inc/analytics-lanes.php';
require SNT_PATH . 'inc/analytics-lanes-render.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-lanes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$tabs = array_column( sn_admin_top_tabs(), null, 'tab' );
ok( 'sn_admin_render_lanes_section' === ( $tabs['tools']['sub_tabs']['lanes']['render'] ?? '' ), 'Integrity has a Lanes sub-tab with the classic renderer' );
$order = array_keys( $tabs['tools']['sub_tabs'] );
ok( array_search( 'lanes', $order, true ) === array_search( 'reports', $order, true ) + 1, 'Lanes sits beside Reports' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/lanes'] ), 'the native painter is registered under tools/lanes' );

// The fixture: two complete days, a visitor hash, an owner device and an invalid token.
$days = sn_lanes_complete_days( '' );
$y    = end( $days );
$vid  = 'deadbeefcafe0123';
$pub  = array(
	array( 'day' => $y, 'vid' => $vid, 'class' => 'human', 'feed' => 0, 'views' => '12' ),
	array( 'day' => $y, 'vid' => '0123456789abcdef', 'class' => 'bot', 'feed' => 0, 'views' => '4' ),
);
$own  = array(
	array( 'day' => $y, 'vid' => 'aaaaaaaaaaaaaaa1', 'identity' => '1', 'agent' => '1', 'views' => '3' ),
	array( 'day' => $y, 'vid' => 'aaaaaaaaaaaaaaa2', 'identity' => '3', 'agent' => '3', 'views' => '2' ),
);
sn_lanes_store_days( 'beacon', array( $y ), sn_lanes_beacon_derive( $pub, $own, array() ) );
sn_lanes_store_days( 'machine', array( $y ), array( $y => array( array( 'lane' => 'claimed_bot', 'reason' => 'owner_token_invalid', 'agent' => 'script', 'family' => 'search', 'purpose' => 'search', 'hits' => 5, 'visits' => null, 'visitor_days' => null ) ) ) );

$classic = snt_leaf_classic_html( 'sn_admin_render_lanes_section' );
$kit     = snt_leaf_paint( 'tools', 'lanes' );
ok( '' !== $kit && '' !== $classic, 'both twins paint' );

$text = static fn( $h ) => html_entity_decode( preg_replace( '/\s+/', ' ', strip_tags( str_replace( '><', '> <', $h ) ) ), ENT_QUOTES, 'UTF-8' );
foreach ( array( 'classic' => $classic, 'native' => $kit ) as $twin => $html ) {
	$t = 'native' === $twin ? $text( $html ) . ' ' . html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) : $text( $html );
	ok( false !== strpos( $t, 'Invalid owner tokens, last 30 days: 2 on the beacon, 5 on the machine sensor.' ), "{$twin}: the invalid-token line, both sensors" );
	ok( false !== strpos( $t, 'Today is pending: the lanes cover complete days only, through ' . $y . '.' ), "{$twin}: today pending" );
	ok( false !== strpos( $t, 'Beacon (page views): lanes since 2026-10-16.' ) && false !== strpos( $t, 'Machine sensor (reads): lanes not seen yet' ), "{$twin}: both lanes_since states" );
	ok( false !== strpos( $t, 'Rule version 1.' ), "{$twin}: the rule version" );
	ok( false !== strpos( $t, 'Visitor-days are counted per lane and do not add up across lanes.' ), "{$twin}: visitor-days do not sum across lanes" );
	ok( false !== strpos( $t, "Machine sensor days are the sensor's UTC dates." ), "{$twin}: the machine sensor's days are UTC" );
	ok( false !== strpos( $t, 'Partial: 7 days, 30 days, 90 days start before lanes began' ), "{$twin}: a range before lanes_since says partial" );
	foreach ( array( 'unverified_owner / owner_token_invalid', 'human / remainder', 'suspected_automation / ua_list', 'owner / owner_device', 'claimed_bot / owner_token_invalid' ) as $reason ) {
		ok( false !== strpos( $t, $reason ), "{$twin}: reason split row {$reason}" );
	}
	ok( false === strpos( $html, $vid ) && false === strpos( $html, '0123456789abcdef' ) && false === strpos( $html, 'aaaaaaaaaaaaaaa1' ), "{$twin}: no visitor hash or device id on the page" );
	ok( 0 === preg_match( '/v1\.[a-z0-9-]+\.\d+\./', $html ), "{$twin}: no token shape on the page" );
	ok( array() === snt_leaf_names( $html ) && array() === snt_leaf_actions( $html ), "{$twin}: no form" );
	ok( false === stripos( $html, '<details' ) && false === strpos( $html, '<os-disclosure' ), "{$twin}: no fold" );
	ok( false === strpos( $t, '—' ), "{$twin}: no em dash" );
}
ok( false !== strpos( $classic, 'notice-warning' ) && false !== strpos( $kit, 'warning' ), 'an invalid token makes the line a warning on both, and its words say so' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup in the native twin: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// Same figures: every native table's rows equal the classic table's cells.
$tables = snt_leaf_tables( $kit );
ok( 6 === count( $tables ), 'six tables per twin: lanes, reasons and days, per sensor' );
$beacon = $tables[0]['data'];
$by     = array_column( $beacon, null, 'lane' );
ok( 12 === $by['Human']['d7'] && 4 === $by['Suspected automation']['d7'] && 3 === $by['Owner, enrolled device']['d7'] && 21 === $by['All lanes']['d90'], 'native beacon lanes: human 12, automation 4, owner 3, all 21' );
preg_match_all( '#<td>([^<]*)</td>#', $classic, $cells );
foreach ( array( 'Human', '12', 'All lanes', '21', 'Owner, enrolled device' ) as $cell ) {
	ok( in_array( $cell, $cells[1], true ), "classic table carries {$cell}" );
}
ok( $y === $tables[4]['data'][0]['day'] && 12 === $tables[4]['data'][0]['human'], 'native daily view: the last complete day, by lane' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
