<?php
/**
 * S&N Home, the Systems wall (apps/sn-dashboard/parts/leaves/dashboard-systems.php::systems_html).
 *
 * The wall is server-rendered cells. The Caches card, the one cell a script
 * filled after load, is gone, and with it the id and the data attribute.
 *
 * 2026-10-11 (owner rule: no collapsible sections): Operations no longer folds
 * the wall behind "N of M systems need a look". The finding names the systems,
 * only those paint as cards, the rest stand in a band of chips (Not measured,
 * then Holding), the database overrides are open, and the north star's "All
 * signals" is an open band under the hero. The whole Home paint is fold-free.
 *
 * Run: php tests/os-leaf-dashboard-systems-wall.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard-north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$checks = array(
	array( 'label' => 'Views 7d', 'value' => '1,204', 'id' => 'some-id', 'pill' => array( 'kind' => 'ok', 'text' => '' ), 'meta_html' => 'up 12%' ),
	array( 'label' => 'Health', 'value' => '0 findings', 'href' => 'https://example.test/wp-admin/admin.php?page=sn-tools&tab=monitoring&sub=health', 'pill' => array( 'kind' => 'ok', 'text' => '' ) ),
);
$components = array( array( 'label' => 'Plugin', 'value' => '15.3.2', 'measured' => true ) );
$html = \SignalNoise\OpenStationHost\Dashboard\Leaves\systems_html( $checks, $components, 'dashboard' );

ok( 0 === substr_count( $html, 'data-snt-freshness-value' ) && 0 === substr_count( $html, ' id="' ), 'no cell carries an id or a filler attribute, even when a card names one: nothing writes into the wall after load' );
ok( false === strpos( $html, 'sn-glance-card__value' ), 'the native wall carries no classic glance class at all' );
ok( ! file_exists( __DIR__ . '/../assets/freshness-dot.js' ) && false === strpos( (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard.php' ), 'freshness' ), 'the filler script is gone and the leaf names no freshness card' );
// 2026-10-11: a holding system is a chip in the Holding band, not a card; its
// value and its meta line are still painted (the figures, not the box).
ok( false !== strpos( $html, '<os-chip>Views 7d · 1,204 · up 12%</os-chip>' ) && false !== strpos( $html, '<os-chip>Plugin · 15.3.2</os-chip>' ) && false !== strpos( $html, '<os-chip>Health · 0 findings</os-chip>' ), 'the meta line and the plain values still paint, each holding system as one chip: label, value, meta' );

// ── 15.3.2: the Detail split. detail_html paints ONE group; the audience group
// carries the pulse's heading and the queries' unit; an empty group paints nothing.
$panels = array(
	array( 'title' => 'Recent deploys', 'group' => 'ops', 'rows' => array( array( 'label' => 'plugin v15.3.2', 'value' => 'now' ) ) ),
	array( 'title' => 'Top pages', 'group' => 'audience', 'rows' => array( array( 'label' => '/', 'value' => '85' ) ) ),
	array( 'title' => 'Top queries', 'group' => 'audience', 'caption' => 'clicks, 28 days', 'rows' => array( array( 'label' => 'aaru vs evidenza', 'value' => '0' ) ) ),
	array( 'title' => 'API limits', 'group' => 'ops', 'rows' => array( array( 'label' => 'GitHub API', 'value' => '4,882 / 5,000' ) ) ),
);
$ops = \SignalNoise\OpenStationHost\Dashboard\Leaves\detail_html( $panels, 'ops' );
$aud = \SignalNoise\OpenStationHost\Dashboard\Leaves\detail_html( $panels, 'audience', 'Audience detail, 7 days' );
ok( false !== strpos( $ops, '<div class="snt-home__pulse-group-label">Operations detail</div>' ) && false !== strpos( $ops, '>Recent deploys</h3>' ) && false !== strpos( $ops, '>API limits</h3>' ) && false === strpos( $ops, 'Top pages' ) && false === strpos( $ops, 'Top queries' ) && false !== strpos( $ops, '--snt-detail-cols:2' ) && false === strpos( $ops, '<os-section' ) && false === strpos( $ops, 'snt-col"' ), '15.3.3: the ops detail is an eyebrow over two flat columns, no section, no card: deploys and API limits only' );
ok( false !== strpos( $aud, '<div class="snt-home__pulse-group-label">Audience detail, 7 days</div>' ) && false !== strpos( $aud, '>Top pages</h3>' ) && false !== strpos( $aud, '>Top queries · clicks, 28 days</h3>' ) && false === strpos( $aud, 'Recent deploys' ) && false !== strpos( $aud, '--snt-detail-cols:2' ) && 2 === substr_count( $aud, 'class="snt-home__detail-col"' ), 'the audience detail is the same eyebrow shape, one column per panel, the queries column named by its unit' );
ok( '' === \SignalNoise\OpenStationHost\Dashboard\Leaves\detail_html( array( $panels[0] ), 'audience' ), 'a group with no panels paints nothing, not an empty section' );

require __DIR__ . '/fixtures/dashboard-home.php';

$L   = '\\SignalNoise\\OpenStationHost\\Dashboard\\Leaves\\';
$ops = ( $L . 'home_operations_html' )( snt_home_fixture(), 'dashboard' );

echo "\nOperations: the finding names the systems that need a look\n";
ok( array() === snt_leaf_folds( $ops ), 'Operations paints no fold: ' . implode( ', ', snt_leaf_folds( $ops ) ) );
ok( false !== strpos( $ops, '<p class="snt-prose">2 of 10 systems need a look: Health and Cron. Remote MCP is not measured: no version reported yet.</p>' ), 'one finding names WHICH systems need a look, and the one with no reading as not measured' );
ok( 2 === substr_count( $ops, '<div class="snt-sys' ) && false !== strpos( $ops, 'class="snt-sys snt-sys--warn" data-tone="warning"><span class="snt-sys__k">Health</span>' ) && false !== strpos( $ops, '<span class="snt-sys__k">Cron</span>' ), 'only the two that need a look paint as system cards, in their tone' );
ok( false !== strpos( $ops, '>3 findings</os-button>' ) && false !== strpos( $ops, '>41 events</os-button>' ) && false !== strpos( $ops, '<os-badge tone="warning">issues found</os-badge>' ) && false !== strpos( $ops, '<os-badge tone="warning">2 orphans</os-badge>' ) && false !== strpos( $ops, '<span class="snt-sys__meta">scanned 3 hours ago</span>' ), 'each card keeps its value (a go link to its leaf), its pill and its meta line' );
ok( false !== strpos( $ops, '<os-fact label="Not measured (1)"><span class="snt-kv__v"><os-cluster gap="6"><os-chip>Remote MCP</os-chip></os-cluster>' ), 'the unmeasured system stands in its own Not measured row, never among the holding' );
$holding_ok = true;
foreach ( array( 'Provenance · 212 confirmed', 'Theme · 8.4.1', 'Plugin · 2026.10.1', 'Analytics · 3.2.0', 'Provenance edge · 1.9.4', 'Login guard · 2.1.0', 'Rights signals · 1.3.0' ) as $chip ) {
	$holding_ok = $holding_ok && false !== strpos( $ops, '<os-chip>' . $chip . '</os-chip>' );
}
ok( $holding_ok && false !== strpos( $ops, '<os-fact label="Holding (7)">' ), 'every holding system keeps its figure, one chip each, under Holding (7)' );
ok( false === strpos( $ops, '<os-chip>Health' ) && false === strpos( $ops, '<os-chip>Cron' ) && false === strpos( $ops, 'Holding (8)' ), 'a system that needs a look is a card, not also a holding chip' );
ok( false !== strpos( $ops, 'description="3 checks · 7 components"' ), 'the Systems section still says how many checks and components it read' );

echo "\nOperations: the database overrides are open\n";
ok( 1 === substr_count( $ops, 'id="sn-dash-diagnostics"' ) && false !== strpos( $ops, 'heading="Database overrides"' ), 'the overrides section keeps the #sn-dash-diagnostics anchor the attention strip links' );
ok( false !== strpos( $ops, '<p class="snt-prose">3 theme items are served from the database, not from the theme files.</p><os-cluster gap="6"><os-code>wp_template/home</os-code><os-code>wp_template_part/header</os-code><os-code>wp_navigation/navigation</os-code></os-cluster>' ), 'a one-line finding over every override name, open' );
ok( false === strpos( $ops, 'snt-ledger-more' ), 'three overrides are under the cap: no "+N more" line' );
$many = array();
for ( $i = 1; $i <= 13; $i++ ) { $many[] = 'wp_template/page-' . $i; }
$capped = ( $L . 'home_operations_html' )( array( 'overrides' => $many ) + snt_home_fixture(), 'dashboard' );
ok( 10 === substr_count( $capped, '<os-code>' ) && false !== strpos( $capped, '<p class="snt-hint snt-ledger-more">+3 more overrides; the list is capped, not complete.</p>' ) && false !== strpos( $capped, '13 theme items are served' ), 'thirteen overrides: ten names, then "+3 more", the count in the finding is the whole thirteen' );
$at_cap = ( $L . 'home_operations_html' )( array( 'overrides' => array_slice( $many, 0, 10 ) ) + snt_home_fixture(), 'dashboard' );
ok( 10 === substr_count( $at_cap, '<os-code>' ) && false === strpos( $at_cap, 'snt-ledger-more' ), 'exactly at the cap: all ten names, no "+N more" line' );

echo "\nOperations: calm, and unknown is never zero\n";
$calm = ( $L . 'home_operations_html' )( snt_home_fixture( true ), 'dashboard' );
ok( array() === snt_leaf_folds( $calm ) && false !== strpos( $calm, '<p class="snt-prose">All 10 systems are holding.</p>' ) && 0 === substr_count( $calm, '<div class="snt-sys' ) && false !== strpos( $calm, 'Holding (10)' ), 'all healthy: one calm sentence, no wall of cards, the ten as chips' );
ok( false === strpos( $calm, 'sn-dash-diagnostics' ), 'no overrides: no overrides section' );
$one_dark = snt_home_fixture( true );
$one_dark['components'][5] = array( 'label' => 'Remote MCP', 'value' => "\u{2014}", 'measured' => false, 'attention' => false );
$dark = ( $L . 'home_operations_html' )( $one_dark, 'dashboard' );
ok( false !== strpos( $dark, '<p class="snt-prose">9 of 10 systems are holding. Remote MCP is not measured: no version reported yet.</p>' ) && false === strpos( $dark, 'All 10' ) && false !== strpos( $dark, 'Holding (9)' ), 'one system with no reading: nine holding and one not measured, never "All 10 systems are holding"' );
$warm = snt_home_fixture( true );
$warm['components'][2] = array( 'label' => 'Analytics', 'value' => 'warming…', 'measured' => true, 'attention' => false );
$warm_html = ( $L . 'home_operations_html' )( $warm, 'dashboard' );
ok( false !== strpos( $warm_html, 'Analytics is not measured: no version reported yet.' ) && false !== strpos( $warm_html, 'Not measured (1)"><span class="snt-kv__v"><os-cluster gap="6"><os-chip>Analytics</os-chip>' ), 'a probe still warming is a skipped reading: not measured, never holding' );
$one_bad = snt_home_fixture( true );
$one_bad['checks'][2] = array( 'label' => 'Cron', 'value' => '41 events', 'pill' => array( 'kind' => 'warn', 'text' => '2 orphans' ) );
ok( false !== strpos( ( $L . 'home_operations_html' )( $one_bad, 'dashboard' ), '1 of 10 systems needs a look: Cron.' ), 'one: "needs", singular' );
$none = ( $L . 'home_operations_html' )( array(), 'dashboard' );
ok( false !== strpos( $none, 'No system has reported in, so none is measured.' ) && false === strpos( $none, 'holding' ), 'nothing reported in: said so, never "holding"' );
$three = snt_home_fixture();
$three['checks'][0]['pill'] = array( 'kind' => 'err', 'text' => '4 pending' );
ok( false !== strpos( ( $L . 'home_operations_html' )( $three, 'dashboard' ), '3 of 10 systems need a look: Provenance, Health, and Cron.' ), 'three names join with commas and a serial "and"' );

echo "\nNorth star: All signals is an open band\n";
$ns = ( $L . 'north_star_html' )( 'dashboard' );
ok( array() === snt_leaf_folds( $ns ), 'the north star paints no fold: ' . implode( ', ', snt_leaf_folds( $ns ) ) );
ok( false !== strpos( $ns, '<div class="snt-home__detail-group snt-ns-all"><div class="snt-home__pulse-group-label">All signals, last 7 days</div><div class="snt-home__detail">' ) && 3 === substr_count( $ns, '<section class="snt-home__detail-col">' ), 'All signals is an eyebrow (its window, last 7 days) over three flat columns, open' );
ok( false !== strpos( $ns, '>Intent</h3>' ) && false !== strpos( $ns, '>Return</h3>' ) && false !== strpos( $ns, '>Inputs</h3>' ) && strpos( $ns, 'snt-ns-hero' ) < strpos( $ns, 'All signals' ), 'Intent, Return and Inputs, under the hero' );
$signals_ok = true;
foreach ( snt_home_signal_figures() as $label => $value ) {
	$signals_ok = $signals_ok && 1 === preg_match( '#<li class="snt-list__row"><span class="snt-list__label">' . preg_quote( $label, '#' ) . '</span><span class="snt-list__value"[^>]*>' . preg_quote( $value, '#' ) . '</span></li>#', $ns );
}
ok( $signals_ok, 'every signal the fold held is painted with its figure (' . count( snt_home_signal_figures() ) . ' signals)' );
ok( false !== strpos( $ns, '<span class="snt-list__label">Inquiries</span><span class="snt-list__value" data-tone="neutral">not measured</span>' ) && false !== strpos( $ns, '<p class="snt-hint">Inquiries: The forms plugin is not active.</p>' ) && false === strpos( $ns, 'No data yet' ), 'a signal with no reading says "not measured", its reason stands under the column, never "No data yet"' );
ok( false !== strpos( $ns, 'Counted per visitor per day: cookieless, so a reader on two days counts twice.' ) && false !== strpos( $ns, '>Change what counts</os-button>' ) && false !== strpos( $ns, 'Feed opens: notes opened in a feed reader' ), 'the counting note, the feed-opens note and the Change what counts link stay' );
ok( false === strpos( $ns, 'feed_subscribers' ) && false === strpos( $ns, 'max_day_7d' ), 'no raw key or window token reaches the screen' );

echo "\nThe whole Home paint\n";
$GLOBALS['__home_data'] = snt_home_fixture();
$kit = snt_leaf_paint( 'dashboard', '' );
ok( '' !== $kit && array() === snt_leaf_folds( $kit ), 'S&N Home paints and is fold-free: ' . implode( ', ', snt_leaf_folds( $kit ) ) );
$at = static fn( $needle ) => (int) strpos( $kit, $needle );
ok( $at( 'snt-home-ns-heading' ) > 0 && $at( 'snt-home-ns-heading' ) < $at( 'snt-home-pulse-heading' ) && $at( 'snt-home-pulse-heading' ) < $at( 'snt-home-attention-heading' ) && $at( 'snt-home-attention-heading' ) < $at( 'snt-home-ops-heading' ), 'the hierarchy holds: north star, pulse, needs attention, then Operations' );
ok( 1 === substr_count( $kit, 'All signals' ) && false !== strpos( $kit, '2 of 10 systems need a look: Health and Cron.' ), 'the open band and the named finding are on the painted page' );
snt_leaf_preview( 'dashboard-home', $kit );
$GLOBALS['__home_data'] = snt_home_fixture( true );
$kit_calm = snt_leaf_paint( 'dashboard', '' );
ok( array() === snt_leaf_folds( $kit_calm ) && false !== strpos( $kit_calm, 'All 10 systems are holding.' ) && false === strpos( $kit_calm, 'snt-home-attention-heading' ), 'calm Home: fold-free, one calm line, and no Needs attention section' );
snt_leaf_preview( 'dashboard-home-calm', $kit_calm );
$folded = array();
foreach ( compact( 'html', 'ops', 'capped', 'at_cap', 'calm', 'dark', 'warm_html', 'none', 'ns', 'kit', 'kit_calm' ) as $state => $paint ) {
	if ( array() !== snt_leaf_folds( $paint ) ) { $folded[] = $state; }
}
ok( array() === $folded, 'every state this suite paints is fold-free: ' . implode( ', ', $folded ) );
ok( array() === snt_leaf_classic_markers( $kit ) && array() === snt_leaf_classic_markers( $kit_calm ), 'the Home paint carries no classic markup: ' . implode( ', ', snt_leaf_classic_markers( $kit ) ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
