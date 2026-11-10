<?php
/**
 * Loads core's own block and HTML readers for a standalone suite: the HTML API
 * (WP_HTML_Tag_Processor), WP_Block_Processor, WP_Block_Parser, and the
 * functions in wp-includes/blocks.php (parse_blocks(), serialize_blocks(),
 * get_comment_delimited_block_content() and friends).
 * The code under test stands on these, so a suite runs the real ones, never a
 * stub, and FAILS when they are not on the machine.
 *
 * Found through the same seam as tests/lib/wp-core-kses.php: SNT_WP_HTML_API
 * (the html-api directory `bash tools/fetch-wp-test-core.sh <dir>` fetches), or
 * the wp-includes/ above a normal plugin install.
 *
 * The classes always load. blocks.php loads only when the suite defines
 * SNT_TEST_WITH_BLOCKS_PHP first: it is functions only and they are not guarded,
 * so such a suite requires this file BEFORE any parse_blocks()/serialize_block()
 * stub of its own and guards those with function_exists().
 */
if ( ! defined( 'SNT_BLOCKS_IS_CORE' ) ) {
	$snt_blocks_api = '';
	foreach ( array( (string) getenv( 'SNT_WP_HTML_API' ), dirname( __DIR__, 5 ) . '/wp-includes/html-api' ) as $snt_blocks_dir ) {
		$snt_blocks_dir = rtrim( $snt_blocks_dir, '/' );
		if ( '' !== $snt_blocks_dir && is_file( $snt_blocks_dir . '/class-wp-html-tag-processor.php' ) && is_file( dirname( $snt_blocks_dir ) . '/class-wp-block-processor.php' ) && is_file( dirname( $snt_blocks_dir ) . '/blocks.php' ) ) {
			$snt_blocks_api = $snt_blocks_dir;
			break;
		}
	}
	if ( '' === $snt_blocks_api ) {
		echo "FAIL: core's HTML API, class-wp-block-processor.php and blocks.php are not on this machine. Run bash tools/fetch-wp-test-core.sh <dir>, then set SNT_WP_HTML_API=<dir>/wp-includes/html-api.\n";
		echo "Result: 0 passed, 1 failed.\n";
		exit( 1 );
	}
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		require_once dirname( $snt_blocks_api ) . '/class-wp-token-map.php';
		foreach ( array( 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-attribute-token', 'html5-named-character-references', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $snt_blocks_part ) {
			require_once $snt_blocks_api . '/' . $snt_blocks_part . '.php';
		}
	}
	if ( ! class_exists( 'WP_Block_Processor' ) ) {
		require_once dirname( $snt_blocks_api ) . '/class-wp-block-processor.php';
	}
	if ( ! class_exists( 'WP_Block_Parser' ) ) {
		foreach ( array( 'class-wp-block-parser-block', 'class-wp-block-parser-frame', 'class-wp-block-parser' ) as $snt_blocks_part ) {
			require_once dirname( $snt_blocks_api ) . '/' . $snt_blocks_part . '.php';
		}
	}
	if ( defined( 'SNT_TEST_WITH_BLOCKS_PHP' ) && ! function_exists( 'get_comment_delimited_block_content' ) ) {
		// The one function the serializers reach outside blocks.php; core's adds only a retry for invalid UTF-8.
		if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); } }
		require_once dirname( $snt_blocks_api ) . '/blocks.php';
	}
	define( 'SNT_BLOCKS_IS_CORE', true );
	unset( $snt_blocks_api, $snt_blocks_dir, $snt_blocks_part );
}
