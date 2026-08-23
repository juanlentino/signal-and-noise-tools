<?php
/**
 * The inc/ai-bootstrap.php surface survives intact.
 *
 * WHY THIS EXISTS. ai-bootstrap.php is 1,054 lines and slated to be split into
 * per-concern files. Unlike the two splits before it, this file has NO registry
 * and NO dispatch map — nothing binds a name to a callable that a guard could
 * simply walk. What it has instead is a large PUBLIC SURFACE: other modules call
 * its functions directly. snt_ai_is_available() is called from 26 files,
 * snt_ai_generate_with_constraints() from 25, snt_ai_require_text_generation()
 * from 17. That surface IS the contract, so it is what this suite pins.
 *
 * Three hazards are specific to this file, and each is silent:
 *
 * 1. A BY-REFERENCE declaration. `function &snt_ai_availability_cache()` does
 *    not match the naive /^function\s+(\w+)/ used to inventory the previous two
 *    splits — the `&` breaks it. A split tool built on that regex would leave
 *    the function behind AND a guard built on it would not notice, because the
 *    tool and the check would share one blind spot. Every parse here is
 *    `&`-aware, and the by-reference declaration is asserted by name.
 *
 * 2. LOAD-TIME REGISTRATION CALLS. snt_ai_register_alt_text_model_route() and
 *    snt_ai_register_economy_model_route() are invoked at top level, not hooked.
 *    Each adds a filter on snt_ai_model_preference. Lose the CALL in a move and
 *    no filter is registered: alt-text generation silently falls back to the
 *    default model. Nothing errors — the work still happens, on the wrong model,
 *    at the wrong price. Run them TWICE and the filter is added twice.
 *
 * 3. EIGHT define() CONSTANTS. define() is a runtime statement, so a duplicate
 *    raises a notice and keeps the FIRST value rather than failing loudly.
 *
 * Written BEFORE the refactor deliberately: a guard added afterwards proves the
 * end state, not the move. It walks inc/ recursively, so it keeps working once
 * the surface lives in inc/ai-bootstrap/*.php.
 *
 * Run: php tests/ai-bootstrap-surface-coverage.php
 *
 * @since 12.21.4
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$root = realpath( __DIR__ . '/..' );
$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

/** The layer: the loader plus anything split out beneath it. */
$layer = array_merge(
	array( "$root/inc/ai-bootstrap.php" ),
	glob( "$root/inc/ai-bootstrap/*.php" ) ?: array()
);

// Every declaration in the layer. NOTE the `&?` — see hazard 1 above.
$declared = array();
foreach ( $layer as $f ) {
	preg_match_all( '/^function\s+&?\s*([A-Za-z0-9_]+)\s*\(/m', (string) file_get_contents( $f ), $m );
	foreach ( $m[1] as $fn ) { $declared[ $fn ][] = basename( $f ); }
}
ok( 21 === count( $declared ), 'the layer declares 21 functions (found ' . count( $declared ) . ')' );

// The by-reference declaration specifically — the one a naive regex misses.
ok( isset( $declared['snt_ai_availability_cache'] ),
	'snt_ai_availability_cache() is found despite its by-reference `&`' );
$byref = 0;
foreach ( $layer as $f ) {
	$byref += preg_match_all( '/^function\s+&\s*snt_ai_availability_cache\s*\(/m', (string) file_get_contents( $f ) );
}
ok( 1 === $byref, "the by-reference form is preserved verbatim (found $byref)" );

// 1. The PUBLIC SURFACE. These are called from other modules; dropping one is a
//    real break. Listed explicitly so that changing the contract is a deliberate
//    edit to this array, not a silent consequence of a move.
$api = array(
	'snt_ai_can_text_generate', 'snt_ai_availability_cache', 'snt_ai_reset_availability_cache',
	'snt_ai_is_available', 'snt_ai_require_text_generation', 'snt_ai_generate_with_constraints',
	'snt_ai_error_with_message', 'snt_ai_register_alt_text_model_route', 'snt_ai_economy_features',
	'snt_ai_register_economy_model_route', 'snt_ai_record_usage', 'snt_ai_spend_month_key',
	'snt_ai_add_month_spend', 'snt_ai_spend_this_month', 'snt_ai_model_pricing',
	'snt_ai_estimate_cost', 'snt_ai_usage_summary', 'snt_ai_extract_post_text',
	'snt_ai_post_signal', 'snt_register_status_script', 'snt_ai_enqueue_editor_script',
);
foreach ( $api as $fn ) {
	ok( isset( $declared[ $fn ] ), "surface function $fn() is declared" );
}

// 2. Nothing declared twice. A split done by copy rather than cut fatals with
//    "Cannot redeclare" at load.
foreach ( $declared as $fn => $files ) {
	ok( 1 === count( $files ), "$fn() declared exactly once (in " . implode( ', ', $files ) . ')' );
}

// 3. The eight constants, each defined exactly once across inc/.
$consts = array(
	'SN_AI_USAGE_LOG_OPT', 'SN_AI_USAGE_LOG_CAP', 'SN_AI_SPEND_ROLLUP_OPT', 'SN_AI_SPEND_MONTHS',
	'SN_AI_DEFAULT_MODEL', 'SN_AI_FALLBACK_MODEL', 'SN_AI_CACHE_WRITE_MULT', 'SN_AI_CACHE_READ_MULT',
);
$inc_src = '';
$dir = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/inc" ) );
foreach ( $dir as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$inc_src .= (string) file_get_contents( $file->getPathname() ) . "\n";
	}
}
foreach ( $consts as $c ) {
	$n = preg_match_all( "/define\(\s*'" . preg_quote( $c, '/' ) . "'\s*,/", $inc_src );
	ok( 1 === $n, "$c defined exactly once across inc/ (found $n)" );
}

// 4. The load-time registration CALLS. Not hooks — bare top-level invocations.
//    Lose one and the filter is never added; run one twice and it is added
//    twice. Both are silent.
foreach ( array( 'snt_ai_register_alt_text_model_route', 'snt_ai_register_economy_model_route' ) as $reg ) {
	$calls = 0;
	foreach ( $layer as $f ) {
		$calls += preg_match_all( '/^' . preg_quote( $reg, '/' ) . '\(\s*\);$/m', (string) file_get_contents( $f ) );
	}
	ok( 1 === $calls, "$reg() is invoked exactly once at load time (found $calls)" );
}

// 5. The one hooked callback.
$hook = 0;
foreach ( $layer as $f ) {
	$hook += preg_match_all( "/add_action\(\s*'admin_enqueue_scripts'\s*,\s*'snt_register_status_script'\s*\)/", (string) file_get_contents( $f ) );
}
ok( 1 === $hook, "snt_register_status_script hooked to admin_enqueue_scripts exactly once (found $hook)" );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
