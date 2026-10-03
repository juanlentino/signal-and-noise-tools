<?php
/**
 * S&N Home, the Systems wall (apps/sn-dashboard/parts/leaves/dashboard.php::systems_html).
 *
 * The wall is server-rendered cells. The Caches card, the one cell a script
 * filled after load, is gone, and with it the id and the data attribute.
 *
 * Run: php tests/os-leaf-dashboard-systems-wall.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard.php';

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
ok( false !== strpos( $html, 'up 12%' ) && false !== strpos( $html, '>15.3.2</span>' ), 'the meta line and the plain cards paint as before' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
