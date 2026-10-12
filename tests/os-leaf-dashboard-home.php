<?php
/**
 * S&N Home, the 2026.10.4 audit (batch 4), painted from the live-shaped
 * fixture: the line under the greeting counts the problems Needs attention
 * lists, each problem is listed once with the problem as its text, a failed
 * deploy links its run, Visits is the gated Visits, a missing reading is
 * never 0, and Continue working's link counts what it opens.
 *
 * Run: php tests/os-leaf-dashboard-home.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard-north-star.php';
require __DIR__ . '/fixtures/dashboard-home.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$L    = '\\SignalNoise\\OpenStationHost\\Dashboard\\Leaves\\';
$url  = static fn( $q ) => 'https://example.test/wp-admin/admin.php?page=sn-theme-options&' . $q;
$data = snt_home_fixture();

echo "Needs attention: each problem once, as its own sentence\n";
$rows = ( $L . 'home_attention_rows' )( $data );
ok( array( 'Health', 'Cron', 'Database overrides' ) === array_column( $rows, 'label' ), 'three problems, three rows: Health, Cron, Database overrides (the fixture said "Health" and "3 health findings" twice)' );
ok( '3 health findings in the last scan.' === $rows[0]['description'] && '2 scheduled cron events have no handler, so nothing runs when they fire.' === $rows[1]['description'], 'a card row says what is wrong, never its figure alone ("41 events")' );
$att = ( $L . 'home_attention_html' )( $data, 'dashboard' );
ok( 1 === substr_count( $att, 'health finding' ) && 3 === substr_count( $att, 'class="snt-home__attention-row ' ), 'the painted list: three rows, the health findings said once' );
ok( false === strpos( $att, 'Operational item pending review' ) && false === strpos( $att, 'Requires review' ) && false === strpos( $att, 'View all' ), 'no filler line, and no "View all" to a list that shows only health checks' );
ok( 3 === substr_count( $att, 'role="img" aria-label="Warning"' ), 'each row says its status in words for a screen reader, not only by a colored icon' );
ok( false !== strpos( $att, 'os-action="go" os-arg-anchor="sn-dash-diagnostics"' ) && false !== strpos( $att, 'data-snt-tab="connections" data-snt-sub="cron"' ), 'each row goes where its problem is fixed: the overrides on this tab, cron on Connections' );

echo "\nThe line under the greeting\n";
$o = ( $L . 'home_orientation_info' )( $data );
ok( 'warn' === $o['state'] && '3 things need attention.' === $o['text'], 'it counts the rows listed under it: "3 things need attention."' );
$calm = ( $L . 'home_orientation_info' )( snt_home_fixture( true ) );
ok( 'ok' === $calm['state'] && 'Pick up where you left off on Signal & Noise. Nothing needs attention.' === $calm['text'], 'calm: one plain line, and no "healthy and growing"' );
$stale = snt_home_fixture( true );
$stale['checks'][] = array( 'label' => 'Analytics rollup', 'href' => $url( 'tab=monitoring&sub=analytics' ), 'value' => 'last measured 3 days ago', 'pill' => array( 'kind' => 'warn', 'text' => 'overdue' ) );
$stale_rows = ( $L . 'home_attention_rows' )( $stale );
ok( '1 thing needs attention.' === ( $L . 'home_orientation_info' )( $stale )['text'] && 'Overdue (last measured 3 days ago).' === $stale_rows[0]['description'] && $url( 'tab=monitoring&sub=analytics' ) === $stale_rows[0]['href'], 'an overdue rollup alone: never "healthy", its row says overdue and links the Analytics leaf, not Health' );
$pending = snt_home_fixture( true );
$pending['checks'][0]['pill'] = array( 'kind' => 'warn', 'text' => '3 pending' );
ok( '3 pending (212 confirmed).' === ( $L . 'home_attention_rows' )( $pending )[0]['description'], 'pending provenance: "3 pending (212 confirmed).", the problem first' );
ok( 'Nothing has reported in, so nothing is measured.' === ( $L . 'home_orientation_info' )( array( 'verdict' => array( 'state' => 'unknown' ) ) )['text'], 'nothing reported in: said so, never healthy' );

echo "\nA failed deploy links its run\n";
$broke = snt_home_fixture( true );
$broke['attention'] = array( array( 'label' => 'Deploys', 'text' => 'The newest plugin deploy failed.', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/actions/runs/7' ) );
$o2 = ( $L . 'home_orientation_info' )( $broke );
$a2 = ( $L . 'home_attention_html' )( $broke, 'dashboard' );
ok( 'err' === $o2['state'] && '1 thing needs attention.' === $o2['text'], 'a failed deploy is an error, counted once' );
ok( false !== strpos( $a2, 'href="https://github.com/juanlentino/signal-and-noise-tools/actions/runs/7" target="_blank" rel="noopener noreferrer"' ) && false !== strpos( $a2, 'Open the run' ) && false !== strpos( $a2, 'aria-label="Error"' ) && false === strpos( $a2, 'tab=dashboard' ), 'it opens the failed run on GitHub, never Home again' );
$skew = snt_home_fixture( true );
$skew['components'][5] = array( 'label' => 'Remote door', 'value' => '1.4.0; contract skew: live 15, expected 16', 'measured' => true, 'attention' => true );
$a3 = ( $L . 'home_attention_html' )( $skew, 'dashboard' );
ok( false !== strpos( $a3, '<div class="snt-home__attention-row snt-home__attention-row--warn">' ) && false !== strpos( $a3, '1.4.0; contract skew: live 15, expected 16.' ) && false === strpos( $a3, 'Inspect' ), 'a contract skew is a row too (Systems calls it one that needs a look); with no leaf to open it is not a link' );

echo "\nNeeds attention beside Continue working\n";
$desk = ( $L . 'home_desk_html' )( $data, 'dashboard' );
ok( 0 === strpos( $desk, '<os-grid ' ) && false !== strpos( $desk, 'class="snt-home__desk"' ) && strpos( $desk, 'snt-home-attention-heading' ) < strpos( $desk, 'snt-home-work-heading' ), 'three problems and three recent items share a row' );
$many = $data;
for ( $i = 1; $i <= 2; $i++ ) { $many['attention'][] = array( 'label' => 'Extra ' . $i, 'text' => 'Extra problem.', 'href' => '' ); }
ok( false === strpos( ( $L . 'home_desk_html' )( $many, 'dashboard' ), '<os-grid' ), 'five problems take the width; Continue working follows' );

echo "\nSite pulse: the gated Visits, and never 0 for a missing reading\n";
$p = ( $L . 'home_pulse_html' )( $data, 'dashboard' );
ok( false !== strpos( $p, '<span>Visits</span></div><strong>812</strong>' ) && false === strpos( $p, '1,270' ), 'Visits is pageview_visits (812), the Analytics app\'s Visits, never the ungated 1,270' );
ok( false !== strpos( $p, '<span>Views per visit</span></div><strong>2.4</strong>' ) && false === strpos( $p, 'Pages per visit' ), 'views per visit is 1,914 / 812 = 2.4, named as the desktop widget names it' );
ok( false !== strpos( $p, '<span class="snt-home__metric-delta">last 28 days</span>' ) && false === strpos( $p, '28d' ), 'Search clicks says its window in words: "last 28 days"' );
ok( false === strpos( $p, "\u{2014}" ), 'no dash stands in for a figure' );
$GLOBALS['__home_analytics'] = false;
$off = ( $L . 'home_pulse_html' )( $data, 'dashboard' );
ok( 3 === substr_count( $off, '<strong data-none>not measured</strong><span class="snt-home__metric-delta">analytics is off</span>' ) && false === strpos( $off, '<strong>0</strong>' ), 'analytics off: Views, Visits and the ratio say "not measured", never 0' );
$GLOBALS['__home_analytics'] = true;
$GLOBALS['__home_deltas']    = array(
	'views'           => array( 'current' => 0, 'previous' => 0, 'pct' => null ),
	'visits'          => array( 'current' => 0, 'previous' => 0, 'pct' => null ),
	'pageview_visits' => array( 'current' => null, 'previous' => null, 'pct' => null ),
);
$failed = ( $L . 'home_pulse_html' )( $data, 'dashboard' );
ok( 3 === substr_count( $failed, '<strong data-none>not read</strong><span class="snt-home__metric-delta">the analytics read failed</span>' ) && false === strpos( $failed, '<strong>0</strong>' ), 'a failed read (views 0 by design, Visits null): "not read", never 0' );
unset( $GLOBALS['__home_deltas'] );

echo "\nNorth star: a failed read is not missing credentials\n";
$GLOBALS['__nsm'] = array( 'configured' => false, 'read_failed' => true );
$ns = ( $L . 'north_star_html' )( 'dashboard' );
ok( false !== strpos( $ns, 'heading="Not read: the analytics query failed."' ) && false === strpos( $ns, 'credentials' ), 'a failed read says so, never "Needs the analytics credentials"' );
unset( $GLOBALS['__nsm'] );

echo "\nContinue working counts what its link opens\n";
$work = ( $L . 'home_continue_working_html' )( 'dashboard' );
ok( false !== strpos( $work, '>All posts (59)</a>' ) && false === strpos( $work, 'View all' ), '"All posts (59)": the posts the Posts list\'s All view counts (no pages, no trash)' );
ok( 3 === substr_count( $work, 'class="snt-home__work-row"' ), 'three recent items' );

echo "\nThe whole Home paint\n";
$GLOBALS['__home_data'] = $data;
$kit = snt_leaf_paint( 'dashboard', '' );
ok( false !== strpos( $kit, '<p class="snt-home-heading__state">3 things need attention.</p>' ) && false === strpos( $kit, 'growing' ), 'the painted headline counts the painted rows' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_classic_markers( $kit ), 'fold-free, no classic markup' );
snt_leaf_preview( 'dashboard-home-b4', $kit );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
