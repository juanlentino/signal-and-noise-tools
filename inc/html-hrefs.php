<?php
/**
 * Signal & Noise Tools: the one reader of link targets in markup.
 *
 * Every module that asks "what does this HTML link to?" asks here, and this
 * asks core's HTML parser. A regex over the bytes reads links a browser never
 * renders (inside a comment, a script, a textarea, another attribute's value,
 * a data-href) and misses ones it does (an unquoted href, a tag with ">" in an
 * earlier attribute), and each module used to carry its own. Pure: no hooks,
 * no network.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sn_html_a_hrefs' ) ) {
	/**
	 * The href of every <a> in a fragment: decoded as a browser decodes the
	 * attribute, in document order, duplicates kept. An <a> with no href, or
	 * with a bare `href` and no value, contributes nothing.
	 *
	 * @param string $html Markup (stored block content, rendered or third-party HTML).
	 * @return string[]
	 */
	function sn_html_a_hrefs( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return array();
		}
		$tags  = new WP_HTML_Tag_Processor( $html );
		$hrefs = array();
		while ( $tags->next_tag( 'A' ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$hrefs[] = $href;
			}
		}
		return $hrefs;
	}
}
