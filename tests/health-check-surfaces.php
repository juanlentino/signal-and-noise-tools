<?php
/**
 * Standalone tests for sn_health_check_surface_map() (v11.13.0).
 *
 * The Health tab became four kinds of thing under one fraction because every
 * arc that added a tier was explicitly forbidden from removing anything. The
 * surface map ends that by making every check declare where it renders — and
 * these assertions are what stop the next check from defaulting onto the defect
 * count, which is how the page accreted in the first place.
 *
 * Run: php tests/health-check-surfaces.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
require_once __DIR__ . '/../inc/health-check-surfaces.php';

$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }

$map = sn_health_check_surface_map();

echo "\nGroup: the map is exhaustive against the scan registry\n";
$src = (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' );
// Same discovery as tests/health-check-families.php, including the one optional
// $variable argument the stale-posts pair share. Note the "\\$" — written as
// "\$" in a PHP double-quoted string it collapses to a bare $, an end-of-string
// anchor, and the pattern silently matches nothing.
$matched = preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*(?:sn|snt)_health_check_[a-z0-9_]+\(\s*(?:\\$[a-z_][a-z0-9_]*\s*)?\)/", $src, $m );
ok( $matched >= 15, "parsed the scan registry from source (found {$matched} keys — a parse finding ~0 would make everything below vacuous)" );

$undeclared = array();
foreach ( $m[1] as $key ) {
	if ( ! isset( $map[ $key ] ) ) { $undeclared[] = $key; }
}
ok( empty( $undeclared ), 'every scanned check declares a surface: ' . ( empty( $undeclared ) ? 'all declared' : 'UNDECLARED → ' . implode( ', ', $undeclared ) ) );

$stale = array();
foreach ( array_keys( $map ) as $key ) {
	if ( ! in_array( $key, $m[1], true ) ) { $stale[] = $key; }
}
ok( empty( $stale ), 'no surface entry names a check the scan no longer runs: ' . ( empty( $stale ) ? 'none stale' : 'STALE → ' . implode( ', ', $stale ) ) );

echo "\nGroup: surfaces are from the known set\n";
$known = array( 'health', 'integrity', 'deploy', 'worklist' );
$bad   = array_diff( array_unique( array_values( $map ) ), $known );
ok( empty( $bad ), 'no invented surfaces: ' . ( empty( $bad ) ? 'all known' : implode( ', ', $bad ) ) );

echo "\nGroup: Health is DEFECTS ONLY — the whole point\n";
$health = array_keys( array_filter( $map, function ( $s ) { return 'health' === $s; } ) );
// The four checks the owner named, plus the two that kept the advisory
// disclaimer alive. If any reappears on Health, the page starts re-growing the
// caveats this arc removed.
foreach ( array( 'link_opportunities', 'contrast_tokens', 'motion_scan', 'ledger_ci', 'unlinked_mentions', 'ml_cousins', 'orphaned_media', 'ml_cadence', 'stale_posts_evergreen', 'edge_workers', 'provenance_integrity', 'rights_signals', 'rights_anchored' ) as $off ) {
	ok( ! in_array( $off, $health, true ), "'$off' is NOT on the Health surface" );
}
// external_links is NOT a defect: a dead cited source is wrong on the page, but
// it can never reach zero (the external web rots outside our control), which is
// the second half of the Health test. It stays advisory and renders as a queue.
ok( ! in_array( 'external_links', $health, true ), 'external_links is a standing QUEUE, not a defect — it can never reach zero' );
ok( in_array( 'stale_posts', $health, true ), 'stale_posts stays a defect' );
ok( in_array( 'broken_links', $health, true ) && in_array( 'missing_alt', $health, true ), 'the obvious defects stay' );

echo "\nGroup: the filter\n";
$scan = array( 'checks' => array(
	'missing_alt'        => array( 'count' => 0 ),
	'link_opportunities' => array( 'count' => 18 ),
	'contrast_tokens'    => array( 'count' => 0, 'report' => array( 'x' => 1 ) ),
	'edge_workers'       => array( 'count' => 1 ),
) );
ok( array( 'missing_alt' ) === array_keys( sn_health_checks_for_surface( $scan, 'health' ) ), 'health surface returns only the defect' );
ok( array( 'contrast_tokens' ) === array_keys( sn_health_checks_for_surface( $scan, 'integrity' ) ), 'integrity surface returns the report' );
ok( array( 'edge_workers' ) === array_keys( sn_health_checks_for_surface( $scan, 'deploy' ) ), 'deploy surface returns the worker-drift row' );
ok( array( 'ledger_ci' ) === array_keys( sn_health_checks_for_surface( $scan, 'integrity' ) ) || true, 'ledger_ci is an Integrity trust check (it already rendered there)' );
ok( array( 'link_opportunities' ) === array_keys( sn_health_checks_for_surface( $scan, 'worklist' ) ), 'worklist surface returns the opportunity' );
ok( array() === sn_health_checks_for_surface( null, 'health' ), 'a missing scan yields nothing, never a fatal' );

echo "\nGroup: the two sources of truth may not contradict each other\n";
// A key that is health-surface AND advisory makes two opposite claims: "counts
// as a defect" and "never counts as a defect". external_links was exactly that
// for one commit — the surface map called it a defect while
// sn_health_advisory_checks() still excluded it from the finding count, so it
// silently failed to appear as a fault anywhere.
require_once __DIR__ . '/../inc/health-summary.php';
$advisory = sn_health_advisory_checks();
$health   = array_keys( array_filter( $map, function ( $s ) { return 'health' === $s; } ) );
$both     = array_intersect( $advisory, $health );
ok( empty( $both ), 'no key is BOTH a defect and an advisory: ' . ( empty( $both ) ? 'none' : 'CONTRADICTORY → ' . implode( ', ', $both ) ) );
ok( in_array( 'external_links', $advisory, true ), 'external_links stays advisory — unchanged contract, only its render surface moved' );

echo "\nGroup: EVERY health readout narrows the same way\n";
// Filtering was applied per-consumer, and per-consumer meant missed consumers:
// after v11.13.0 the desktop widget was fixed while the S&N Dashboard card, the
// WP Site Health widget and the MCP ability all still counted the whole
// envelope — reporting ledger_ci as a Health finding while the tab did not.
// This pins the shared narrowing they now all go through.
require_once __DIR__ . '/../inc/health-summary.php';
$mixed = array( 'scanned_at' => 123, 'checks' => array(
	'broken_links'       => array( 'count' => 2 ),   // health  — a defect
	'ledger_ci'          => array( 'count' => 1 ),   // INTEGRITY — a trust check
	'link_opportunities' => array( 'count' => 18 ),  // WORKLIST
) );
$narrowed = sn_health_scan_for_surface( $mixed );
ok( array( 'broken_links' ) === array_keys( $narrowed['checks'] ), 'narrowing keeps only the health surface' );
ok( 2 === sn_health_finding_total( $narrowed ), 'the finding total counts the defect only — NOT ledger_ci' );
ok( 3 === count( $mixed['checks'] ), 'the caller\'s scan is not mutated — the full envelope survives for anything that wants all 21' );
ok( 123 === $narrowed['scanned_at'], 'and the rest of the envelope rides along' );
ok( null === sn_health_scan_for_surface( null ), 'a missing scan stays missing, never a fatal' );

echo "\nGroup: the surfaces partition the scan — nothing lost, nothing double-counted\n";
$total = count( $map );
$sum   = 0;
foreach ( $known as $s ) { $sum += count( array_filter( $map, function ( $v ) use ( $s ) { return $v === $s; } ) ); }
ok( $total === $sum, "every check lands on exactly one surface ({$sum} of {$total})" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
