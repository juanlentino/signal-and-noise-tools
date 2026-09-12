<?php
/**
 * #1208 — inc/admin-tab-dashboard.php fetched the merged deploy feed with
 * limit 5 (snt_dashboard_tab_data()), then downstream code assumed a 6-row
 * pool: `$ops_data['deploys'] = array_slice( $runs, 0, 6 )` (the Ops Wall)
 * and `snt_dashboard_count_recent_runs( $runs, DAY_IN_SECONDS )` (the
 * Deploys glance card's "N in last 24h") both read a feed that could never
 * hold more than 5 rows. The slice-to-6 was therefore always a no-op, and
 * the 24h count silently dropped a 6th-or-later deploy inside the window.
 *
 * Source-text pin: the fetch limit must be at least as large as the largest
 * downstream consumer (the slice-to-6), so both readings see the real pool.
 *
 * Standalone — no PHPUnit. Run: php tests/dashboard-deploys-feed-limit.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$src = (string) file_get_contents( __DIR__ . '/../inc/admin-tab-dashboard.php' );

echo "admin-tab-dashboard.php — runs feed limit vs. downstream slice (#1208)\n";

ok( false !== strpos( $src, "array_slice( \$runs, 0, 6 )" ), 'sanity: the Ops Wall still slices the merged feed to 6 rows' );

$fetch_match = array();
ok(
	1 === preg_match( "/snt_deploy_history_merged\\(\\s*array_values\\(\\s*SNT_DEPLOY_REPOS\\s*\\),\\s*(\\d+)\\s*\\)/", $src, $fetch_match ),
	'the merged-feed fetch call is found (limit is a literal int)'
);
$fetch_limit = isset( $fetch_match[1] ) ? (int) $fetch_match[1] : 0;
ok( $fetch_limit >= 6, "#1208: fetch limit ({$fetch_limit}) must be >= the 6-row slice downstream reads — otherwise the slice is a no-op and the 24h count undercounts" );

// Functional half: snt_dashboard_count_recent_runs() itself is correct given
// a real pool — the bug was ONLY the pool being starved at the source. Prove
// that with 6 real rows inside the window, the count sees all 6.
require_once __DIR__ . '/../inc/dash-deploy-rows.php';
$six_runs = array();
for ( $i = 0; $i < 6; $i++ ) {
	$six_runs[] = array( 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - $i * 3600 ) );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
ok( 6 === snt_dashboard_count_recent_runs( $six_runs, DAY_IN_SECONDS ), 'given a real 6-row pool, the 24h count reads all 6 (the counter itself was always correct)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
