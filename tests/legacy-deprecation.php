<?php
/**
 * Guard test: ensure the legacy REST handlers that have Ability replacements
 * keep their `@deprecated` PHPdoc annotation across future edits. Static grep
 * — no WP load.
 *
 * Covers 10 handlers:
 *   - 4 since-2.5.0 handlers (AI post-editor + desktop-mode command) — these
 *     also carry runtime `_deprecated_function()` calls.
 *   - 6 since-4.6.0 handlers (Plausible × 3, cron-run, pattern-adoption
 *     scan/dismiss) — PHPdoc-level `@deprecated` only; runtime
 *     `_deprecated_function()` promotion is scheduled for v5.0.0.
 *
 * The assertion checks each named function for deprecation evidence in
 * EITHER form:
 *   - `@deprecated` PHPdoc in the docblock above the function, OR
 *   - a runtime `_deprecated_function()` call in the function body.
 * All 10 handlers carry at least one; the 6 since-4.6.0 handlers carry the
 * PHPdoc form, the 4 since-2.5.0 handlers carry both (the `snt_desktop_cmd_handler`
 * `@deprecated` lives in a file-section banner, not its own docblock, so the
 * runtime-call form is what guards it).
 *
 * @since plugin v4.5.0 (extended to 10 handlers in v4.6.0)
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

$plugin_root = dirname( __DIR__ );

// List-of-arrays (not file-keyed map) because four of the since-4.6.0
// handlers live in the same file (inc/rest-api.php) and array keys must
// be unique.
$checks = array(
	array( 'inc/ai-meta-description.php',       'snt_ai_meta_desc_rest_handler' ),
	array( 'inc/ai-excerpt.php',                'snt_ai_excerpt_rest_handler' ),
	array( 'inc/ai-og-card-title.php',          'snt_ai_og_card_title_rest_handler' ),
	array( 'inc/desktop-mode-integration.php',  'snt_desktop_cmd_handler' ),
	array( 'inc/rest-api.php',                  'sn_rest_plausible_stats' ),
	array( 'inc/rest-api.php',                  'sn_rest_plausible_realtime' ),
	array( 'inc/rest-api.php',                  'sn_rest_plausible_test' ),
	array( 'inc/rest-api.php',                  'snt_rest_cron_run' ),
	array( 'inc/pattern-adoption-detect.php',   'snt_rest_pattern_adoption_scan' ),
	array( 'inc/pattern-adoption-admin.php',    'snt_rest_pattern_adoption_dismiss' ),
);

$pass = 0; $fail = 0;

echo "Legacy deprecation annotation guard — plugin v4.6.0 (10 handlers)\n\n";

foreach ( $checks as $entry ) {
	$rel_path      = $entry[0];
	$function_name = $entry[1];

	$src = file_get_contents( $plugin_root . '/' . $rel_path );
	if ( false === $src ) {
		$fail++;
		echo "  FAIL: cannot read $rel_path\n";
		continue;
	}
	$needle = "function $function_name(";
	$pos    = strpos( $src, $needle );
	if ( false === $pos ) {
		$fail++;
		echo "  FAIL: $function_name not found in $rel_path\n";
		continue;
	}
	// Deprecation evidence may sit in the docblock ABOVE the function
	// (`@deprecated` PHPdoc) or in the body just BELOW the signature
	// (runtime `_deprecated_function()` call). Scan a window spanning both:
	// back to the previous function/docblock boundary, forward into the body.
	$doc_start = max( 0, $pos - 600 );
	$preceding = substr( $src, $doc_start, $pos - $doc_start );
	// Trim the preceding window at the last `}` or earlier `function ` so we
	// don't credit a different function's docblock.
	$boundary  = max( strrpos( $preceding, "\n}" ), strrpos( $preceding, "\nfunction " ) );
	if ( false !== $boundary ) {
		$preceding = substr( $preceding, $boundary );
	}
	$body      = substr( $src, $pos, 400 );
	$has_phpdoc  = false !== strpos( $preceding, '@deprecated' );
	$has_runtime = false !== strpos( $body, '_deprecated_function(' );
	if ( ! $has_phpdoc && ! $has_runtime ) {
		$fail++;
		echo "  FAIL: $function_name in $rel_path missing @deprecated PHPdoc and _deprecated_function() call\n";
	} else {
		$pass++;
		$form = $has_phpdoc ? '@deprecated PHPdoc' : '_deprecated_function() call';
		echo "  PASS: $function_name in $rel_path has $form\n";
	}
}

$pass++;
echo "  PASS: guard test completed without PHP errors\n";

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
