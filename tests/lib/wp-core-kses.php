<?php
/**
 * Loads core's own HTML API (WP_HTML_Tag_Processor) and wp-includes/kses.php
 * for a standalone suite, from the SNT_WP_HTML_API seam CI fetches (or the
 * wp-includes/ above a normal plugin install). SNT_KSES_IS_CORE says whether
 * it found them, so a suite whose claim rests on core can FAIL without them.
 *
 * Require it AFTER the suite's own hook stubs (kses.php's helpers reach
 * apply_filters; a missing one is defined here as a pass-through) and BEFORE
 * any wp_kses_* stub of its own, which then stays unused.
 *
 * @since Unreleased
 */
if ( ! defined( 'SNT_KSES_IS_CORE' ) ) {
	$snt_kses_api = '';
	foreach ( array( (string) getenv( 'SNT_WP_HTML_API' ), dirname( __DIR__, 5 ) . '/wp-includes/html-api' ) as $snt_kses_dir ) {
		$snt_kses_dir = rtrim( $snt_kses_dir, '/' );
		if ( '' !== $snt_kses_dir && is_file( $snt_kses_dir . '/class-wp-html-tag-processor.php' ) && is_file( dirname( $snt_kses_dir ) . '/kses.php' ) ) {
			$snt_kses_api = $snt_kses_dir;
			break;
		}
	}
	if ( '' !== $snt_kses_api ) {
		// The two functions kses.php reaches outside itself and the HTML API.
		if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
		// Core's compat.php polyfill: kses.php calls array_any(), native only from PHP 8.4,
		// and production loads compat.php before it. CI's lane runs PHP 8.3.
		if ( ! function_exists( 'array_any' ) ) { function array_any( array $a, callable $f ) { foreach ( $a as $k => $v ) { if ( $f( $v, $k ) ) { return true; } } return false; } }
		if ( ! function_exists( 'wp_allowed_protocols' ) ) { function wp_allowed_protocols() { return array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' ); } }
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			require_once dirname( $snt_kses_api ) . '/class-wp-token-map.php';
			foreach ( array( 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-attribute-token', 'html5-named-character-references', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $snt_kses_part ) {
				require_once $snt_kses_api . '/' . $snt_kses_part . '.php';
			}
		}
		require_once dirname( $snt_kses_api ) . '/kses.php';
	}
	define( 'SNT_KSES_IS_CORE', '' !== $snt_kses_api );
	unset( $snt_kses_api, $snt_kses_dir, $snt_kses_part );
}
