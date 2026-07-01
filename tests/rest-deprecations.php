<?php
/**
 * Standalone fixture tests for the legacy REST route deprecation pass (v6.54.0).
 *
 * Guards two things:
 *  1. snt_rest_deprecated_notice() emits _deprecated_function() with the route +
 *     the canonical Abilities run-path replacement.
 *  2. PLACEMENT: every legacy route file carries the expected number of notice
 *     calls, and (for the closure-based routes that SHARE a snt_*_impl with the
 *     Ability) the notice sits in the rest_api_init registration block — never in
 *     the shared impl, which the Abilities run-path also calls and must NOT warn.
 *
 * @since plugin v6.54.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS: $msg\n"; } else { $fail++; echo "FAIL: $msg\n"; } }

// --- Behavioral: the helper ---
$GLOBALS['__dep'] = array();
function _deprecated_function( $fn, $ver, $repl = '' ) { $GLOBALS['__dep'][] = array( $fn, $ver, $repl ); }
function esc_html( $s ) { return $s; } // route/slug strings carry no HTML; passthrough keeps assertions exact

require __DIR__ . '/../inc/rest-deprecations.php';

snt_rest_deprecated_notice( '/signal-noise/v1/ai/alt-suggest', 'signal-noise/ai-alt-suggest' );
ok( count( $GLOBALS['__dep'] ) === 1, 'helper triggers exactly one _deprecated_function()' );
ok( $GLOBALS['__dep'][0][0] === 'REST route /signal-noise/v1/ai/alt-suggest', 'labels the deprecated thing as the REST route' );
ok( $GLOBALS['__dep'][0][1] === '6.54.0', 'versions the deprecation at 6.54.0' );
ok( $GLOBALS['__dep'][0][2] === 'the Abilities run-path /wp-abilities/v1/abilities/signal-noise/ai-alt-suggest/run', 'points at the Abilities run-path replacement' );

// v6.56.0: the version arg is parameterized (defaults to 6.54.0 for the original
// pass) so the newly-deprecated caller-free routes read the accurate version.
$GLOBALS['__dep'] = array();
snt_rest_deprecated_notice( '/signal-noise/v1/cron/unschedule', 'signal-noise/unschedule-cron-event', '6.56.0' );
ok( $GLOBALS['__dep'][0][1] === '6.56.0', 'accepts an explicit deprecation version (6.56.0 for the v6.55.0 caller-free routes)' );
$GLOBALS['__dep'] = array();
snt_rest_deprecated_notice( '/signal-noise/v1/x', 'signal-noise/y' );
ok( $GLOBALS['__dep'][0][1] === '6.54.0', 'omitted version still defaults to 6.54.0 (the original pass)' );

// --- Coverage + placement across the deprecate-now set ---
$expected = array(
	'rest-api.php'                 => 7, // purge-cache, clear-overrides, full-reset, insights/run, insights/last + (v6.56.0) cron/unschedule, cron/history
	'analytics-rest.php'           => 2, // analytics/summary, analytics/events
	'ai-alt-text-suggest.php'      => 2, // alt-suggest, alt-apply
	'ai-alt-inline-suggest.php'    => 1,
	'ai-drift-phrase-suggest.php'  => 2, // drift-suggest, drift-apply
	'ai-orphan-suggest.php'        => 2, // orphan-suggest, orphan-apply
	'pattern-adoption-suggest.php' => 1,
	'pattern-adoption-apply.php'   => 1,
	'block-migrations-detect.php'  => 1, // block-migrations-scan
	'block-migrations-suggest.php' => 1,
	'block-migrations-apply.php'   => 1,
	'block-migrations-admin.php'   => 1, // (v6.56.0) block-migrations-dismiss
	'audit-log.php'                => 4, // audit/counters, audit/prune + (v6.56.0) audit/summary, audit/login-successes
	'ai-prepopulate-notice.php'    => 1, // (v6.56.0) prepop/dismiss
);

// Files whose REST callback closures SHARE a snt_*_impl with the Ability: the
// notice MUST live in the rest_api_init block (after the impls), never in the impl.
$closure_files = array(
	'ai-alt-text-suggest.php', 'ai-alt-inline-suggest.php', 'ai-drift-phrase-suggest.php',
	'ai-orphan-suggest.php', 'pattern-adoption-suggest.php', 'pattern-adoption-apply.php',
	'block-migrations-detect.php', 'block-migrations-suggest.php', 'block-migrations-apply.php',
	'block-migrations-admin.php', 'audit-log.php',
);

$total = 0;
foreach ( $expected as $file => $n ) {
	$src = (string) file_get_contents( __DIR__ . '/../inc/' . $file );
	$count = substr_count( $src, 'snt_rest_deprecated_notice(' );
	ok( $count === $n, "$file carries $n deprecation notice call(s) (found $count)" );
	$total += $count;

	if ( in_array( $file, $closure_files, true ) && $count > 0 ) {
		$reg          = strpos( $src, "add_action( 'rest_api_init'" );
		$first_notice = strpos( $src, 'snt_rest_deprecated_notice(' );
		ok(
			false !== $reg && false !== $first_notice && $first_notice > $reg,
			"$file: notice sits in the rest_api_init registration block, not in a shared snt_*_impl"
		);
	}
}
ok( $total === 27, "27 legacy routes carry a helper deprecation notice in total (found $total)" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
