<?php
/**
 * Tests: a check that could not run is not a check that passed.
 *
 * THE DEFECT. `sn_health_check_partition()` counted any check with zero
 * findings as PASSED, and `sn_health_pack_check()` gave a check no way to say
 * otherwise — the envelope was count/findings/label/fix_hint and nothing else.
 * So four of the seven Health-surface checks had a path where they cannot run
 * and were counted as passing:
 *
 *   drift_time_phrases  — "AI provider not configured: skipping"
 *   color_drift         — "Theme palette unavailable: skipping"
 *   cf_security_headers — filtered off entirely on non-Cloudflare hosting
 *   broken_links        — no site host, or the posts query failed (silent)
 *
 * The first three announced the skip in a PROSE fix_hint that the tally never
 * reads; the fourth said nothing at all. The screen therefore reported 7/7 on
 * a day when three of those seven had not run.
 *
 * This is v11.13.0's own stated failure — "silence taken for freshness" — left
 * open for UNRUN checks after that arc closed it for RELOCATED ones. It is
 * also [[realtime-zero-vs-null]] in a new place: never-measured is not
 * measured-zero.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800 ) as $k => $v ) { if ( ! defined( $k ) ) { define( $k, $v ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

require_once __DIR__ . '/../inc/health-check-surfaces.php';
require_once __DIR__ . '/../inc/health-summary.php';
// The REAL packer. Fixtures here are BUILT BY IT, never hand-written, so a
// change to the envelope shape breaks these tests instead of sailing past them.
require_once __DIR__ . '/../inc/health-checks.php';
require_once __DIR__ . '/../inc/health-render-passing.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "health: a skipped check is not a passed check\n\n";

function scan( array $checks ) { return array( 'checks' => $checks, 'scanned_at' => 1787180000 ); }

// ── THE ENVELOPE CAN SAY "I DID NOT RUN" ──────────────────────────────────
$ran     = sn_health_pack_check( 'Color drift', array(), 'some hint' );
$skipped = sn_health_pack_check( 'Color drift', array(), 'some hint', 'Theme palette unavailable' );
ok( array_key_exists( 'skipped', $ran ), 'the envelope always carries the key, so a consumer can tell old scans from new' );
ok( null === $ran['skipped'], 'a check that RAN reports null — not false, not an empty string' );
ok( 'Theme palette unavailable' === $skipped['skipped'], 'A SKIP IS STRUCTURAL — the reason is a field, not prose buried in a fix_hint' );

// ── AND THE TALLY READS IT ────────────────────────────────────────────────
$p = sn_health_check_partition( scan( array(
	'missing_alt'        => sn_health_pack_check( 'Missing alt', array() ),
	'broken_links'       => sn_health_pack_check( 'Broken links', array( 'a' ) ),
	'color_drift'        => sn_health_pack_check( 'Color drift', array(), '', 'Theme palette unavailable' ),
	'drift_time_phrases' => sn_health_pack_check( 'Drift', array(), '', 'AI provider not configured' ),
) ) );
ok( 1 === $p['passed'], 'A SKIPPED CHECK IS NOT COUNTED AS PASSED — only the one that really ran and found nothing' );
ok( 2 === $p['skipped'], 'both skips are counted, in their own bucket' );
ok( 1 === $p['findings'], 'a real finding is still a finding' );
ok( 4 === $p['total'], 'and the denominator still counts every check' );

// The closure invariant, now four-way. This is the whole contract: whatever
// left the numerator must be NAMED, or the reader cannot reconcile the line.
ok(
	$p['passed'] + $p['findings'] + $p['advisories'] + $p['reports'] + $p['skipped'] === $p['total'],
	'PASSED + FINDINGS + ADVISORIES + REPORTS + SKIPPED === TOTAL — the arithmetic closes'
);

// ── THE LINE SAYS SO OUT LOUD ─────────────────────────────────────────────
$meta = sn_health_passed_meta( scan( array(
	'missing_alt' => sn_health_pack_check( 'Missing alt', array() ),
	'color_drift' => sn_health_pack_check( 'Color drift', array(), '', 'Theme palette unavailable' ),
) ) );
ok( false !== strpos( $meta, 'skipped' ), 'the meta line NAMES the skipped bucket rather than quietly shrinking the numerator' );
ok( false !== strpos( $meta, '1' ), 'with its count' );

// An all-clear that really is all-clear still says nothing.
ok( '' === sn_health_passed_meta( scan( array( 'missing_alt' => sn_health_pack_check( 'Missing alt', array() ) ) ) ), 'a genuinely clean scan still has nothing to explain' );

// ── BACKWARD COMPATIBILITY: A CACHED SCAN PREDATES THE FIELD ──────────────
// A scan sitting in the option store from before this change has no `skipped`
// key at all. It must behave exactly as it did, not become "skipped" by
// accident — that would turn every old cached scan into a wall of unknowns.
$legacy = array( 'count' => 0, 'findings' => array(), 'label' => 'Legacy', 'fix_hint' => '' );
$lp     = sn_health_check_partition( scan( array( 'missing_alt' => $legacy ) ) );
ok( 1 === $lp['passed'] && 0 === $lp['skipped'], 'AN ENVELOPE WITHOUT THE KEY IS TREATED AS RAN — old cached scans are unchanged' );

// ── EVIDENCE BEATS ABSENCE ────────────────────────────────────────────────
// A check that bailed out but had already found something has produced real
// evidence. Filing it under "skipped" would discard a live defect, which is a
// worse error than over-reporting a partial scan.
$partial = sn_health_check_partition( scan( array(
	'broken_links' => sn_health_pack_check( 'Broken links', array( 'x', 'y' ), '', 'probe budget exhausted' ),
) ) );
ok( 1 === $partial['findings'] && 0 === $partial['skipped'], 'A PARTIAL SCAN THAT FOUND SOMETHING IS A FINDING — evidence outranks absence' );


// ── AND IT MUST NOT BE RENDERED IN THE PASSING LIST ───────────────────────
// sn_health_passing_checks() feeds the "Checks passed N / M" card, the WP
// dashboard widget's numerator, AND the rendered list of passing checks. Left
// unfixed, a check that never ran would have been PRINTED under "passing" —
// the tally lying is bad; the page naming the check as passing is worse.
$mixed = scan( array(
	'missing_alt' => sn_health_pack_check( 'Missing alt', array() ),
	'color_drift' => sn_health_pack_check( 'Color drift', array(), '', 'theme palette unavailable' ),
) );
$passing = sn_health_passing_checks( $mixed );
ok( 1 === count( $passing ), 'the passing list holds only the check that ran' );
ok( array_key_exists( 'missing_alt', $passing ), 'the one that ran is there' );
ok( ! array_key_exists( 'color_drift', $passing ), 'A SKIPPED CHECK IS NEVER LISTED AS PASSING' );

// The skipped list is its own accessor, carrying the REASON — a count alone
// tells a reader something is missing without telling them what to do.
$sk = sn_health_skipped_checks( $mixed );
ok( 1 === count( $sk ), 'the skipped accessor returns the skipped checks' );
ok( 'theme palette unavailable' === ( $sk['color_drift']['skipped'] ?? '' ), 'and each carries WHY it could not run' );
ok( array() === sn_health_skipped_checks( scan( array( 'missing_alt' => sn_health_pack_check( 'Missing alt', array() ) ) ) ), 'a scan where everything ran has an empty skipped list' );


// ── THE PAGE SAYS WHICH, AND WHY ──────────────────────────────────────────
// A count in the meta line fixes the arithmetic and still leaves the reader
// knowing only that something is missing. "AI provider not configured" tells
// them where to go; "1 skipped" does not.
ob_start();
sn_health_render_skipped_section( sn_health_skipped_checks( $mixed ) );
$html = ob_get_clean();
ok( false !== strpos( $html, 'Color drift' ), 'the section NAMES the check that could not run' );
ok( false !== strpos( $html, 'theme palette unavailable' ), 'and states the reason, so it is actionable' );
ok( false === strpos( $html, 'sn-pill--err' ), 'A GAP IN EVIDENCE IS NOT AN ERROR — no alarm colour; a red pill here would spend the one signal this page has' );
ok( false !== strpos( $html, 'not counted as passed' ), 'and it says outright that these are not passes' );

ob_start();
sn_health_render_skipped_section( array() );
ok( '' === ob_get_clean(), 'nothing skipped draws NOTHING — a healthy page gains no furniture' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
