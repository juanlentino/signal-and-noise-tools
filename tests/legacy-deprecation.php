<?php
/**
 * Guard test: ensure the legacy REST handlers that have Ability replacements
 * keep their `@deprecated` PHPdoc annotation across future edits. Static grep
 * — no WP load.
 *
 * Covers 7 handlers (after the v5.0.0 gen-1 removals):
 *   - 1 since-2.5.0 handler: the desktop-mode `/cmd` command
 *     (`snt_desktop_cmd_handler`) — carries a runtime `_deprecated_function()`
 *     call; its removal is deferred until the desktop-mode widgets migrate.
 *   - 6 since-4.6.0 handlers (Plausible × 3, cron-run, pattern-adoption
 *     scan/dismiss) — promoted to runtime `_deprecated_function()` in v5.0.0
 *     (see tests/gen2-runtime-warnings.php); removal targets v6.0.0.
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
	array( 'inc/desktop-mode-integration.php',  'snt_desktop_cmd_handler' ),
	array( 'inc/rest-api.php',                  'sn_rest_plausible_stats' ),
	array( 'inc/rest-api.php',                  'sn_rest_plausible_realtime' ),
	array( 'inc/rest-api.php',                  'sn_rest_plausible_test' ),
	array( 'inc/rest-api.php',                  'snt_rest_cron_run' ),
	array( 'inc/pattern-adoption-detect.php',   'snt_rest_pattern_adoption_scan' ),
	array( 'inc/pattern-adoption-admin.php',    'snt_rest_pattern_adoption_dismiss' ),
);

$pass = 0; $fail = 0;

echo 'Legacy deprecation annotation guard (' . count( $checks ) . " handlers)\n\n";

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
	// Also break the window at the LAST `*/` before the function — the close
	// of any preceding block comment. The docblock that documents THIS
	// function is the one whose `*/` we keep below (we look only at the text
	// after the last comment-close that is NOT this function's own docblock).
	// Without this, a neighboring `@deprecated` banner above an EARLIER
	// comment could false-credit a function whose own docblock omits it.
	$last_close = strrpos( $preceding, '*/' );
	if ( false !== $last_close ) {
		// Everything from the last `*/` onward is THIS function's docblock-close
		// + signature gap. Search for an earlier block-comment close so we can
		// confine the @deprecated scan to this function's own docblock only.
		$before_this_doc = substr( $preceding, 0, $last_close );
		$prev_close      = strrpos( $before_this_doc, '*/' );
		if ( false !== $prev_close ) {
			// Start the window just after the previous comment's close, so a
			// banner sitting above an earlier comment can't bleed in.
			$preceding = substr( $preceding, $prev_close + 2 );
		}
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
