<?php
/**
 * Standalone fixture tests for inc/desktop-mode-attention.php — the desktop
 * attention badge.
 *
 * THE RULE: the badge READS, it never COMPUTES. Every source is already cached.
 * A test that lets it scan is a test that approved a per-shell-load query.
 *
 * null IS NOT 0. All three accessors return array|null — null means NEVER
 * SCANNED. A never-measured queue contributes NOTHING; it is not an empty
 * queue. This codebase shipped that confusion in both directions inside one day.
 *
 * Run: php tests/desktop-mode-attention.php
 *
 * @since plugin v9.58.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'SNT_VERSION', '9.58.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; }
	else { $fail++; echo "  FAIL— $label\n"; }
}

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][ $p ][] = $cb; }
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {}

/** Fire a hook, priority ascending. */
function fire( $hook ) {
	$by_priority = $GLOBALS['__actions'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) { foreach ( $cbs as $cb ) { $cb(); } }
}

$GLOBALS['__localized'] = array();
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['__localized'][ $name ] = $data; return true; }

function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function esc_html( $t ) { return $t; }
function __( $t, $d = null ) { return $t; }
function current_user_can( $cap ) { return true; }

// ── The three cached sources, stubbed at their REAL shapes ───────────
// Shapes read from source, NOT imagined:
//   sn_health_last_scan()               → inc/health-checks.php:135 — cached scan array|null
//   snt_block_migrations_last_scan()    → inc/block-migrations-detect.php:161 — array|null
//   snt_pattern_adoption_last_scan()    → inc/pattern-adoption-detect.php:170 — array|null
// null = NEVER SCANNED, in all three. That is the whole point of this module.
$GLOBALS['__health']  = null;
$GLOBALS['__blockmig'] = null;
$GLOBALS['__pattern']  = null;
$GLOBALS['__scan_calls'] = 0;

function sn_health_last_scan() { $GLOBALS['__scan_calls']++; return $GLOBALS['__health']; }
function snt_block_migrations_last_scan() { $GLOBALS['__scan_calls']++; return $GLOBALS['__blockmig']; }
function snt_pattern_adoption_last_scan() { $GLOBALS['__scan_calls']++; return $GLOBALS['__pattern']; }

// These are the SCAN TRIGGERS. If the module ever calls one, the badge is not
// free — it would run a full post sweep on every shell load. Blow up loudly.
//
// NAMES VERIFIED AGAINST SOURCE, and two were wrong in the plan that produced
// this file. The real triggers are *_run_scan:
//   sn_health_run_scan()              inc/health-checks.php:94
//   snt_block_migrations_run_scan()   inc/block-migrations-detect.php:127
//   snt_pattern_adoption_run_scan()   inc/pattern-adoption-detect.php:135
// A sentinel named after a function that does not exist protects nothing. This
// house guards every cross-module call with function_exists() — the module below
// does it five times — so the realistic wrong-code path is a GUARDED call to the
// real trigger. Under a misnamed sentinel, function_exists() returns false, the
// call is skipped, and the suite goes GREEN while production sweeps every post on
// every shell load. That is the failure this file exists to prevent.
function sn_health_run_scan() { throw new RuntimeException( 'BADGE TRIGGERED A HEALTH SCAN' ); }
function snt_block_migrations_run_scan() { throw new RuntimeException( 'BADGE TRIGGERED A BLOCK-MIGRATION SCAN' ); }
function snt_pattern_adoption_run_scan() { throw new RuntimeException( 'BADGE TRIGGERED A PATTERN-ADOPTION SCAN' ); }

// REQUIRE the real health-summary — it is PURE (verified: zero WP calls), so a
// stub could only drift from it. This is the house rule.
require_once __DIR__ . '/../inc/health-summary.php';
require_once __DIR__ . '/../inc/desktop-mode-attention.php';

/** Reset every source to "never scanned". */
function att_reset() {
	$GLOBALS['__health']     = null;
	$GLOBALS['__blockmig']   = null;
	$GLOBALS['__pattern']    = null;
	$GLOBALS['__scan_calls'] = 0;
}

/**
 * "count is PRESENT and explicitly NULL" — the never-measured contract.
 *
 * Deliberately NOT `( $src['count'] ?? 'missing' ) === null`. `??` collapses
 * "key absent" and "key set to null" into the SAME branch, so that expression
 * yields 'missing' in both cases and can never be identical to null: the
 * assertion is UNSATISFIABLE, not merely weak. The first draft of this file
 * shipped that idiom on the three assertions guarding the null-vs-zero rule —
 * a test for null-confusion, defeated by null-confusion. It failed loudly
 * rather than silently, which is the only reason it was caught at Step 4.
 *
 * array_key_exists() is the only way to tell the two apart in PHP.
 */
function att_is_unmeasured( $src ) {
	return is_array( $src ) && array_key_exists( 'count', $src ) && null === $src['count'];
}

echo "\n── null IS NOT zero ──\n";
att_reset();
ok( snt_desktop_attention_total() === 0,
	'nothing measured yet → total 0 (nothing to show), not a fabricated count' );
$srcs = snt_desktop_attention_sources();
ok( att_is_unmeasured( $srcs['health'] ?? null ),
	'an unscanned source reports count NULL — never measured is not zero' );
ok( att_is_unmeasured( $srcs['block_migrations'] ?? null ),
	'block_migrations reports NULL when never scanned' );
ok( att_is_unmeasured( $srcs['pattern_adoption'] ?? null ),
	'pattern_adoption reports NULL when never scanned' );

echo "\n── a measured-but-empty queue is a REAL zero ──\n";
att_reset();
$GLOBALS['__blockmig'] = array( 'candidates' => array() ); // scanned; found nothing
$srcs = snt_desktop_attention_sources();
ok( ( $srcs['block_migrations']['count'] ?? 'missing' ) === 0,
	'a scan that found nothing reports 0 — NOT null. Measured-zero and never-measured are different answers' );
ok( snt_desktop_attention_total() === 0, 'a measured-empty queue adds 0 to the total' );

echo "\n── health counts FLAGGED checks, and NEVER advisory ones ──\n";
// Check keys are the REAL ones from sn_health_run_scan() (inc/health-checks.php:100-110).
// sn_health_advisory_checks() === array( 'external_links', 'link_opportunities' )
// (inc/health-summary.php:38) — advisory is "surfaced, never alarming" per the
// owner's 2026-07-02 re-tier. Badging advisory findings would alarm about exactly
// the checks that were re-tiered NOT to alarm. This is REQUIRED, not stubbed, so
// the rule is enforced by the real implementation.
att_reset();
$GLOBALS['__health'] = array( 'checks' => array(
	'missing_alt'    => array( 'count' => 3 ),  // flagged
	'orphaned_media' => array( 'count' => 0 ),  // scanned, clean → not flagged
	'external_links' => array( 'count' => 9 ),  // ADVISORY → must never badge
) );
$srcs = snt_desktop_attention_sources();
ok( ( $srcs['health']['count'] ?? 'missing' ) === 1,
	'health contributes the number of FLAGGED checks (1) — not the checks run (3), not the finding total (12)' );
ok( snt_desktop_attention_total() === 1, 'the health count reaches the total' );

att_reset();
$GLOBALS['__health'] = array( 'checks' => array(
	'external_links'     => array( 'count' => 9 ),
	'link_opportunities' => array( 'count' => 4 ),
) );
ok( snt_desktop_attention_total() === 0,
	'a scan finding ONLY advisory items badges NOTHING — advisory is surfaced, never alarming' );

echo "\n── counts sum ──\n";
att_reset();
$GLOBALS['__blockmig'] = array( 'candidates' => array( 1, 2, 3 ) );
$GLOBALS['__pattern']  = array( 'candidates' => array( 1, 2 ) );
ok( snt_desktop_attention_total() === 5, 'total sums the non-null counts (3 + 2)' );

echo "\n── null is EXCLUDED from the sum, not counted as 0 ──\n";
att_reset();
$GLOBALS['__blockmig'] = array( 'candidates' => array( 1, 2, 3 ) );
// pattern + health stay null (never scanned)
ok( snt_desktop_attention_total() === 3,
	'unmeasured sources are skipped, not zero-filled — the total is 3, and it is honest about what it knows' );

echo "\n── THE BADGE NEVER COMPUTES ──\n";
att_reset();
$GLOBALS['__blockmig'] = array( 'candidates' => array( 1 ) );
$threw = false;
try { snt_desktop_attention_total(); } catch ( RuntimeException $e ) { $threw = true; }
ok( ! $threw, 'reading the total triggers NO scan — the scan stubs throw if called' );
ok( $GLOBALS['__scan_calls'] > 0, 'it did read the CACHED accessors (sanity: the test is exercising something)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
