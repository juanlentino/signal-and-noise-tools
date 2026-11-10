<?php
/**
 * WordPress core's REAL HTML API and block parser for standalone suites.
 *
 * NOT a suite (tests/run.sh sweeps tests/*.php only). A suite whose subject
 * reads markup through WP_HTML_Tag_Processor, WP_HTML_Processor or
 * parse_blocks() requires this file. A stub of those classes would prove
 * nothing, so there is none: when core's files are not on disk the suite
 * FAILS with a summary line tests/run.sh counts. It never skips.
 *
 * Core is found at SNT_WP_HTML_API=<wp>/wp-includes/html-api (CI fetches it
 * with tools/fetch-wp-test-core.sh), or beside a normal plugin install.
 *
 * Requiring this file loads the Tag Processor. Call
 * snt_test_load_html_processor() for WP_HTML_Processor and
 * snt_test_load_block_parser() for core's parse_blocks().
 */

if ( ! function_exists( 'snt_test_core_missing' ) ) {
	function snt_test_core_missing( $what ) {
		echo "FAIL: $what is missing. Fetch core with: bash tools/fetch-wp-test-core.sh <dir>, then SNT_WP_HTML_API=<dir>/wp-includes/html-api\n\nResult: 0 passed, 1 failed.\n";
		exit( 1 );
	}

	function snt_test_html_api_dir() {
		foreach ( array( rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' ), dirname( __DIR__, 5 ) . '/wp-includes/html-api' ) as $dir ) {
			if ( '' !== $dir && is_file( $dir . '/class-wp-html-tag-processor.php' ) ) {
				return $dir;
			}
		}
		snt_test_core_missing( "core's HTML API (wp-includes/html-api)" );
	}

	/** Require core files in order; the suite fails when one is not on disk. */
	function snt_test_require_core( $dir, array $files ) {
		foreach ( $files as $f ) {
			if ( ! is_file( "$dir/$f" ) ) {
				snt_test_core_missing( "core's $f" );
			}
			require_once "$dir/$f";
		}
	}

	function snt_test_load_html_processor() {
		if ( class_exists( 'WP_HTML_Processor' ) ) {
			return;
		}
		// The order wp-settings.php loads them in.
		snt_test_require_core( snt_test_html_api_dir(), array( 'class-wp-html-doctype-info.php', 'class-wp-html-unsupported-exception.php', 'class-wp-html-active-formatting-elements.php', 'class-wp-html-open-elements.php', 'class-wp-html-token.php', 'class-wp-html-stack-event.php', 'class-wp-html-processor-state.php', 'class-wp-html-processor.php' ) );
	}

	/** Core's WP_Block_Parser behind parse_blocks(), as wp-includes/blocks.php calls it. */
	function snt_test_load_block_parser() {
		if ( ! class_exists( 'WP_Block_Parser' ) ) {
			snt_test_require_core( dirname( snt_test_html_api_dir() ), array( 'class-wp-block-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php' ) );
		}
		if ( ! function_exists( 'parse_blocks' ) ) {
			function parse_blocks( $content ) {
				return ( new WP_Block_Parser() )->parse( (string) $content );
			}
		}
		if ( ! function_exists( 'has_blocks' ) ) {
			function has_blocks( $content ) {
				return false !== strpos( (string) $content, '<!-- wp:' );
			}
		}
	}

	/**
	 * Core's excerpt_remove_blocks(): the real source, cut out of
	 * wp-includes/blocks.php (the file as a whole needs WP_Block and the block
	 * registry). render_block() is the one stand-in, and answers as core does
	 * for a static block: the saved markup, inner blocks in place, passed
	 * through the render_block filter.
	 */
	function snt_test_load_excerpt_remove_blocks() {
		snt_test_load_block_parser();
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			return;
		}
		$file = dirname( snt_test_html_api_dir() ) . '/blocks.php';
		$src  = is_file( $file ) ? (string) file_get_contents( $file ) : '';
		foreach ( array( '_excerpt_render_inner_blocks', 'excerpt_remove_blocks' ) as $fn ) {
			if ( ! preg_match( '/^function ' . $fn . '\(.*?^}/ms', $src, $m ) ) {
				snt_test_core_missing( "core's $fn() (wp-includes/blocks.php)" );
			}
			eval( $m[0] ); // Test-only: defines core's own function from the core checkout the suite was pointed at.
		}
		if ( ! function_exists( 'apply_filters' ) ) {
			function apply_filters( $hook, $value ) { return $value; }
		}
		if ( ! function_exists( 'render_block' ) ) {
			function render_block( $block ) {
				$out = '';
				$i   = 0;
				foreach ( $block['innerContent'] as $chunk ) {
					$out .= is_string( $chunk ) ? $chunk : render_block( $block['innerBlocks'][ $i++ ] );
				}
				return apply_filters( 'render_block', $out, $block );
			}
		}
	}

	// What the classes call outside themselves.
	if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
	if ( ! function_exists( '_doing_it_wrong' ) ) { function _doing_it_wrong( $f, $m, $v ) { throw new LogicException( "_doing_it_wrong in $f: $m" ); } }
	if ( ! function_exists( 'wp_kses_uri_attributes' ) ) { function wp_kses_uri_attributes() { return array( 'href', 'src', 'action', 'cite', 'poster', 'xlink:href' ); } }
	if ( ! function_exists( 'wp_has_noncharacters' ) ) { function wp_has_noncharacters( string $text ): bool { return false; } }

	$snt_html_api = snt_test_html_api_dir();
	snt_test_require_core( dirname( $snt_html_api ), array( 'class-wp-token-map.php' ) );
	snt_test_require_core( $snt_html_api, array( 'html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) );
	unset( $snt_html_api );
}
