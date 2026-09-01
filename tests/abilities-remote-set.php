<?php
/**
 * Tests: inc/abilities-remote-set.php — the remote set widens 1 -> 8, and the
 * owed test becomes real.
 *
 * THE MATRIX is the point of this suite. Increment 1's own test recorded that
 * with one remote slug, a per-slug callback carrying the WRONG member's literal
 * was untestable: every wrong literal was either the same string or a
 * non-member, so it passed every assertion anyway. With eight members that gap
 * is live — a callback carrying a SIBLING's literal now passes an ordinary
 * "switch on + capability -> true" check, because the sibling's slug is ALSO a
 * member of the same list. Catching that requires narrowing "the list" to one
 * member at a time, which a hardcoded PHP array offers no seam to do without a
 * function-mocking extension this harness does not have.
 *
 * THE TEST DOUBLE below is that seam. It stands in for
 * inc/mcp/mcp-remote-guard.php's sn_remote_analytics_allows()/
 * sn_mcp_remote_slugs() — that file's OWN correctness (kill switch precedence,
 * capability gate) is tested on its own terms by tests/mcp-remote-guard.php.
 * This suite's job is the SEVEN callbacks + registrations in
 * inc/abilities-remote-set.php (plus Increment 1's summary callback, needed to
 * complete the 8x8), so the double mirrors the guard's real semantics —
 * switch, then capability, then membership against a MUTABLE list — closely
 * enough that a bug in the double would look exactly like a bug in the guard
 * it replaces.
 *
 * WHEN sn_mcp_remote_slugs() GROWS, THIS FILE MUST GROW WITH IT. The guard
 * suite's verbatim-array pin is the tripwire that brings you here: it reds on
 * any widening, and updating it WITHOUT extending $FULL_SET + $MAP below
 * leaves the new callback's literal unexercised — a wrong literal would then
 * survive every suite. There is no mechanical link between the two lists and
 * there cannot be one (requiring the real guard here fatals on redeclare —
 * see test-unguarded-fn-declarations); this sentence is the link.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $h, $v ) { return $v; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }

$GLOBALS['__caps'] = array();
function current_user_can( $c ) { return ! empty( $GLOBALS['__caps'][ $c ] ); }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

// The eight-member list, mutable for the matrix's single-member iterations.
// Restored to the full eight before every group that follows the matrix.
$FULL_SET = array(
	'signal-noise/remote-get-analytics-summary',
	'signal-noise/remote-get-analytics-events',
	'signal-noise/remote-get-insights',
	'signal-noise/remote-get-narration',
	'signal-noise/remote-uptime-status',
	'signal-noise/remote-get-health-scan',
	'signal-noise/remote-get-rss-stats',
	'signal-noise/remote-get-deploy-status',
	// v13.52.0 — the three ratified twins.
	'signal-noise/remote-provenance-integrity-status',
	'signal-noise/remote-machine-readers-summary',
	'signal-noise/remote-cron-health-summary',
	'signal-noise/remote-search-performance', // v13.61.0
	'signal-noise/remote-search-drift',       // v13.61.0
	'signal-noise/remote-search-crossexam',   // v13.67.0
);
$GLOBALS['__remote_slugs'] = $FULL_SET;

function sn_mcp_remote_slugs() { return $GLOBALS['__remote_slugs']; }

function sn_remote_analytics_allows( $slug ) {
	if ( empty( $GLOBALS['__options']['sn_mcp_remote_enabled'] ) ) {
		return false;
	}
	if ( empty( $GLOBALS['__caps']['sn_read_remote_analytics'] ) ) {
		return false;
	}
	return is_string( $slug ) && '' !== $slug && in_array( $slug, $GLOBALS['__remote_slugs'], true );
}

// Increment 1's file, for the summary callback that completes the 8x8.
require __DIR__ . '/../inc/abilities-remote-analytics.php';
// This increment's seven callbacks + registrations.
require __DIR__ . '/../inc/abilities-remote-set.php';
// Admin registrations, required for the parity + execute-sharing groups.
require __DIR__ . '/../inc/abilities-analytics.php';
require __DIR__ . '/../inc/abilities-insights.php';
require __DIR__ . '/../inc/abilities-narration.php';
require __DIR__ . '/../inc/uptime-status.php';
require __DIR__ . '/../inc/abilities-health.php';
require __DIR__ . '/../inc/abilities-content.php';
require __DIR__ . '/../inc/abilities-system.php';
// v13.52.0 admin registrations for the three new twins' parity pairs. The
// cron pair needs the dashboard module for the impl the callback names.
require __DIR__ . '/../inc/provenance-integrity.php';
require __DIR__ . '/../inc/abilities-machine-readers.php';
require __DIR__ . '/../inc/cron-dashboard.php';
require __DIR__ . '/../inc/abilities-cron.php';
// v13.61.0 — the two Search Console twins read their schema from the admin table.
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return $d; } }
require __DIR__ . '/../inc/search-console-store.php';
require __DIR__ . '/../inc/search-console-derive.php';
require __DIR__ . '/../inc/abilities-search-console.php';
// The read/write allowlists, for the negative-space group.
require __DIR__ . '/../inc/mcp/mcp-capabilities.php';

foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

$REMOTE_SUMMARY = 'signal-noise/remote-get-analytics-summary';
$REMOTE_EVENTS  = 'signal-noise/remote-get-analytics-events';
$REMOTE_INSIGHTS = 'signal-noise/remote-get-insights';
$REMOTE_NARRATION = 'signal-noise/remote-get-narration';
$REMOTE_UPTIME  = 'signal-noise/remote-uptime-status';
$REMOTE_HEALTH  = 'signal-noise/remote-get-health-scan';
$REMOTE_RSS     = 'signal-noise/remote-get-rss-stats';
$REMOTE_DEPLOY  = 'signal-noise/remote-get-deploy-status';

$ADMIN_EVENTS   = 'signal-noise/get-analytics-events';
$ADMIN_INSIGHTS = 'signal-noise/get-insights';
$ADMIN_NARRATION = 'signal-noise/get-narration';
$ADMIN_UPTIME   = 'signal-noise/uptime-status';
$ADMIN_HEALTH   = 'signal-noise/get-health-scan';
$ADMIN_RSS      = 'signal-noise/get-rss-stats';
$ADMIN_DEPLOY   = 'signal-noise/get-deploy-status';

// v13.52.0 pairs.
$REMOTE_PROV = 'signal-noise/remote-provenance-integrity-status';
$REMOTE_MR   = 'signal-noise/remote-machine-readers-summary';
$REMOTE_CRON = 'signal-noise/remote-cron-health-summary';
// v13.61.0 pairs.
$REMOTE_SP = 'signal-noise/remote-search-performance'; $ADMIN_SP = 'signal-noise/search-performance';
$REMOTE_SD = 'signal-noise/remote-search-drift';       $ADMIN_SD = 'signal-noise/search-drift';
$REMOTE_SX = 'signal-noise/remote-search-crossexam';   $ADMIN_SX = 'signal-noise/search-crossexam'; // v13.67.0
$ADMIN_PROV  = 'signal-noise/provenance-integrity-status';
$ADMIN_MR    = 'signal-noise/get-machine-readers-summary';
$ADMIN_CRON  = 'signal-noise/cron-health-summary';

// slug => permission callback name, ALL EIGHT — the matrix's map.
$MAP = array(
	$REMOTE_SUMMARY   => 'snt_ability_perm_remote_analytics_summary',
	$REMOTE_EVENTS    => 'snt_ability_perm_remote_analytics_events',
	$REMOTE_INSIGHTS  => 'snt_ability_perm_remote_insights',
	$REMOTE_NARRATION => 'snt_ability_perm_remote_narration',
	$REMOTE_UPTIME    => 'snt_ability_perm_remote_uptime_status',
	$REMOTE_HEALTH    => 'snt_ability_perm_remote_health_scan',
	$REMOTE_RSS       => 'snt_ability_perm_remote_rss_stats',
	$REMOTE_DEPLOY    => 'snt_ability_perm_remote_deploy_status',
	$REMOTE_PROV      => 'snt_ability_perm_remote_provenance_integrity',
	$REMOTE_MR        => 'snt_ability_perm_remote_machine_readers',
	$REMOTE_CRON      => 'snt_ability_perm_remote_cron_health',
);

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );

echo "Group: THE MATRIX — each of the eight callbacks answers for its own name and no other (8x8, loop-generated)\n";
foreach ( $MAP as $solo_slug => $ignored_cb ) {
	$GLOBALS['__remote_slugs'] = array( $solo_slug );
	foreach ( $MAP as $owner_slug => $callback ) {
		$expected = ( $owner_slug === $solo_slug );
		$actual   = $callback();
		$label    = $expected
			? "solo list [$solo_slug]: $callback() is true — it is this callback's own name"
			: "solo list [$solo_slug]: $callback() is false — $owner_slug is not the solo member";
		ok( $expected === $actual, $label );
	}
}
$GLOBALS['__remote_slugs'] = $FULL_SET;

echo "Group: PARITY, output — every twin's output_schema copies the admin's byte-identically\n";
$pairs_output = array(
	array( $REMOTE_EVENTS, $ADMIN_EVENTS ),
	array( $REMOTE_INSIGHTS, $ADMIN_INSIGHTS ),
	array( $REMOTE_NARRATION, $ADMIN_NARRATION ),
	array( $REMOTE_UPTIME, $ADMIN_UPTIME ),
	array( $REMOTE_HEALTH, $ADMIN_HEALTH ),
	array( $REMOTE_RSS, $ADMIN_RSS ),
	array( $REMOTE_DEPLOY, $ADMIN_DEPLOY ),
	array( $REMOTE_PROV, $ADMIN_PROV ),
	array( $REMOTE_MR, $ADMIN_MR ),
	array( $REMOTE_CRON, $ADMIN_CRON ),
	array( $REMOTE_SP, $ADMIN_SP ),
	array( $REMOTE_SD, $ADMIN_SD ),
	array( $REMOTE_SX, $ADMIN_SX ),
);
foreach ( $pairs_output as $pair ) {
	list( $remote, $admin ) = $pair;
	ok(
		$GLOBALS['__abilities'][ $remote ]['output_schema'] === $GLOBALS['__abilities'][ $admin ]['output_schema'],
		"$remote output_schema === $admin's"
	);
}

echo "Group: PARITY, input — the twin schemas match the admin's, EXCEPT the two deliberate strips\n";
ok(
	$GLOBALS['__abilities'][ $REMOTE_EVENTS ]['input_schema'] === $GLOBALS['__abilities'][ $ADMIN_EVENTS ]['input_schema'],
	"$REMOTE_EVENTS input_schema === $ADMIN_EVENTS's"
);
$empty_pairs = array(
	array( $REMOTE_INSIGHTS, $ADMIN_INSIGHTS ),
	array( $REMOTE_NARRATION, $ADMIN_NARRATION ),
	array( $REMOTE_HEALTH, $ADMIN_HEALTH ),
	array( $REMOTE_RSS, $ADMIN_RSS ),
);
foreach ( $empty_pairs as $pair ) {
	list( $remote, $admin ) = $pair;
	ok(
		$GLOBALS['__abilities'][ $remote ]['input_schema'] === $GLOBALS['__abilities'][ $admin ]['input_schema'],
		"$remote input_schema === $admin's (both empty)"
	);
}
// THE STRIP PINS. A bare === parity pin here would be WRONG and must not be
// written: the twins deliberately differ from their admin originals.
ok(
	array_key_exists( 'force_refresh', $GLOBALS['__abilities'][ $ADMIN_UPTIME ]['input_schema']['properties'] ),
	'THE STRIP PIN: remote-uptime-status — the admin ability HAS force_refresh'
);
ok(
	! array_key_exists( 'force_refresh', $GLOBALS['__abilities'][ $REMOTE_UPTIME ]['input_schema']['properties'] ),
	'THE STRIP PIN: remote-uptime-status — the twin refuses what the admin accepts'
);
ok(
	array_key_exists( 'force_refresh', $GLOBALS['__abilities'][ $ADMIN_DEPLOY ]['input_schema']['properties'] ),
	'THE STRIP PIN: remote-get-deploy-status — the admin ability HAS force_refresh'
);
ok(
	! array_key_exists( 'force_refresh', $GLOBALS['__abilities'][ $REMOTE_DEPLOY ]['input_schema']['properties'] ),
	'THE STRIP PIN: remote-get-deploy-status — the twin refuses what the admin accepts'
);
// THE STRIP PINS, STRENGTHENED (Grok adversarial pass): the admin uptime
// ability ALSO reads `detail` — a bigger quota amplifier than force_refresh
// (SLA + response times + incidents, not just a cache bypass). A pin naming
// only force_refresh would stay green if `detail` (or any FUTURE admin key)
// were ever added to a twin. Assert `properties === array()` outright on
// both stripped twins: it subsumes force_refresh, detail, and anything not
// yet invented.
ok(
	array_key_exists( 'detail', $GLOBALS['__abilities'][ $ADMIN_UPTIME ]['input_schema']['properties'] ),
	'THE STRIP PIN (context): the admin uptime-status ability ALSO has `detail` — a bigger quota amplifier than force_refresh'
);
ok(
	array() === $GLOBALS['__abilities'][ $REMOTE_UPTIME ]['input_schema']['properties'],
	'THE STRIP PIN, STRENGTHENED: remote-uptime-status properties === array() — pins force_refresh, detail, and anything future, not just the one named key'
);
ok(
	array() === $GLOBALS['__abilities'][ $REMOTE_DEPLOY ]['input_schema']['properties'],
	'THE STRIP PIN, STRENGTHENED: remote-get-deploy-status properties === array() — pins force_refresh and anything future, not just the one named key'
);

echo "Group: show_in_rest is false for all seven — #641, applied at birth\n";
foreach ( array( $REMOTE_EVENTS, $REMOTE_INSIGHTS, $REMOTE_NARRATION, $REMOTE_UPTIME, $REMOTE_HEALTH, $REMOTE_RSS, $REMOTE_DEPLOY ) as $slug ) {
	ok( false === $GLOBALS['__abilities'][ $slug ]['meta']['show_in_rest'], "#641: $slug carries no public run route (show_in_rest: false)" );
}

echo "Group: READ-DOOR ABSENCE — all eight remote slugs stay off the laptop door's lists\n";
$read_count_before = count( sn_mcp_allowlist() );
foreach ( $MAP as $remote_slug => $ignored_cb ) {
	ok( ! in_array( $remote_slug, sn_mcp_allowlist(), true ), "$remote_slug is absent from the READ allowlist" );
	ok( ! in_array( $remote_slug, sn_mcp_rw_allowlist(), true ), "$remote_slug is absent from the WRITE allowlist" );
}
ok( $read_count_before === count( sn_mcp_allowlist() ), "the read list's cardinality pin is unchanged by widening the remote set ($read_count_before members)" );

echo "Group: EXECUTE SHARING — each twin dispatches to the SAME reader as its admin twin\n";
$execute_pairs = array(
	array( $REMOTE_EVENTS, $ADMIN_EVENTS ),
	array( $REMOTE_INSIGHTS, $ADMIN_INSIGHTS ),
	array( $REMOTE_NARRATION, $ADMIN_NARRATION ),
	array( $REMOTE_UPTIME, $ADMIN_UPTIME ),
	array( $REMOTE_HEALTH, $ADMIN_HEALTH ),
	array( $REMOTE_RSS, $ADMIN_RSS ),
	array( $REMOTE_DEPLOY, $ADMIN_DEPLOY ),
	array( $REMOTE_PROV, $ADMIN_PROV ),
	array( $REMOTE_MR, $ADMIN_MR ),
	array( $REMOTE_CRON, $ADMIN_CRON ),
);
foreach ( $execute_pairs as $pair ) {
	list( $remote, $admin ) = $pair;
	ok(
		$GLOBALS['__abilities'][ $remote ]['execute_callback'] === $GLOBALS['__abilities'][ $admin ]['execute_callback'],
		"$remote execute_callback string-equals $admin's"
	);
}

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): abilities-remote-set.php\n"
	: "\nFAILURES ($pass passed, $fail failed): abilities-remote-set.php\n";
exit( $fail > 0 ? 1 : 0 );
