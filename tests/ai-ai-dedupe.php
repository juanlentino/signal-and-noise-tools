<?php
/**
 * Tests for inc/ai-ai-dedupe.php — the two wpai_feature_*_enabled filters
 * that disable ai/ai's duplicate meta-description + excerpt-generation panels.
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

// ─── add_filter / apply_filters registry stub ───
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
		$GLOBALS['__test_filters'][ $tag ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( empty( $GLOBALS['__test_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__test_filters'][ $tag ] as $cb ) {
			$value = call_user_func( $cb, $value );
		}
		return $value;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}

require_once __DIR__ . '/../inc/ai-ai-dedupe.php';

$pass = 0; $fail = 0;
function dd_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

dd_true(
	false === apply_filters( 'wpai_feature_meta-description_enabled', true ),
	'ai/ai meta-description feature is force-disabled'
);
dd_true(
	false === apply_filters( 'wpai_feature_excerpt-generation_enabled', true ),
	'ai/ai excerpt-generation feature is force-disabled'
);
dd_true(
	true === apply_filters( 'wpai_feature_editorial-notes_enabled', true ),
	'ai/ai non-duplicated features (editorial-notes) are left untouched'
);

// The revisit trigger must not call an existing-vocab tag mode future: the AI
// plugin's Content Classification has offered "Only suggest existing terms"
// since it landed (read at the 0.7.0 and 1.0.2 tags). Docblock only; the two
// filters above are the behavior and stay as they are.
$dd_src = (string) file_get_contents( __DIR__ . '/../inc/ai-ai-dedupe.php' );
dd_true(
	false === strpos( $dd_src, 'or an existing-vocab tag mode, revisit' ) && false !== strpos( $dd_src, 'Only suggest existing terms' ),
	'the docblock names the existing-terms strategy as shipped, not as a future revisit trigger'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
