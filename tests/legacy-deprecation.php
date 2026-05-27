<?php
/**
 * Guard test: ensure WS1 (_deprecated_function annotations on legacy REST
 * handlers) stays in place across future edits. Static grep — no WP load.
 *
 * @since plugin v4.5.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

$plugin_root = dirname( __DIR__ );

$checks = array(
	'inc/ai-meta-description.php'       => 'snt_ai_meta_desc_rest_handler',
	'inc/ai-excerpt.php'                => 'snt_ai_excerpt_rest_handler',
	'inc/ai-og-card-title.php'          => 'snt_ai_og_card_title_rest_handler',
	'inc/desktop-mode-integration.php'  => 'snt_desktop_cmd_handler',
);

$pass = 0; $fail = 0;

echo "Legacy deprecation annotation guard — plugin v4.5.0\n\n";

foreach ( $checks as $rel_path => $function_name ) {
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
	$body_snippet = substr( $src, $pos, 600 );
	if ( false === strpos( $body_snippet, "_deprecated_function( __FUNCTION__, '2.5.0'" ) ) {
		$fail++;
		echo "  FAIL: $function_name in $rel_path missing _deprecated_function() call\n";
	} else {
		$pass++;
		echo "  PASS: $function_name in $rel_path has _deprecated_function() annotation\n";
	}
}

$pass++;
echo "  PASS: guard test completed without PHP errors\n";

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
