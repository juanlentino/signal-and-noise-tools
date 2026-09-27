<?php
/**
 * The notes feed carries the whole note. /notes/feed/ is core's posts feed,
 * and core picks excerpt or full text from the site option
 * `rss_use_excerpt` (Settings › Reading, "For each post in a feed, include").
 * Nothing in the theme or plugin forced excerpts: the option was on. This
 * module turns it off for the notes feed and the main posts feed /feed/
 * (same posts) only (`option_rss_use_excerpt`), never comment feeds or admin,
 * leaving the site-wide setting alone, so each item gains content:encoded
 * while <description> stays the excerpt.
 *
 * The content a feed reader gets is made safe for a foreign renderer: root
 * relative and fragment links become absolute, iframes and other embeds
 * become plain links, provenance panels become one link to the note, and
 * wp_kses_post() drops scripts, forms and event attributes. Each item ends
 * with a plain "Read on the site" link (tagged by inc/feed-utm.php) and the
 * feed-open pixel (inc/feed-opens.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a request path is a full-text posts feed: /notes/feed or the main
 * /feed (same posts), RSS or Atom. PURE.
 *
 * @param string $uri REQUEST_URI.
 * @return bool
 */
function snt_feed_is_notes_path( $uri ) {
	$path = (string) strtok( (string) $uri, '?' );
	return 1 === preg_match( '#^/(notes/)?feed(/|$)#', $path );
}

/**
 * Whether the current request is the notes feed.
 *
 * @return bool
 */
function snt_feed_is_notes_request() {
	if ( is_admin() || ! did_action( 'wp' ) || ! is_feed() || is_comment_feed() ) {
		return false;
	}
	return snt_feed_is_notes_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a fixed pattern.
}

/**
 * Make a feed body safe for a feed reader. PURE apart from wp_kses_post().
 *
 * @param string $html      Rendered post content.
 * @param string $home      Site root, no trailing slash (https://example.com).
 * @param string $permalink The note's permalink.
 * @return string
 */
function snt_feed_safe_content( $html, $home, $permalink ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return '';
	}
	$doc = new DOMDocument();
	$old = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="snt-feed-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $old );
	$xp = new DOMXPath( $doc );

	// Provenance panels and the in-page table of contents are site furniture:
	// one link to the note stands in for each panel, the TOC goes.
	foreach ( iterator_to_array( $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " sn-article-toc ")]' ) ) as $n ) {
		$n->parentNode->removeChild( $n );
	}
	foreach ( iterator_to_array( $xp->query( '//*[contains(@class, "sn-prov-") and not(ancestor::*[contains(@class, "sn-prov-")])]' ) ) as $n ) {
		$n->parentNode->replaceChild( snt_feed_link_p( $doc, $permalink . '#provenance', 'Provenance record on the site' ), $n );
	}
	foreach ( iterator_to_array( $xp->query( '//iframe | //embed | //object' ) ) as $n ) {
		$src = (string) ( $n->getAttribute( 'src' ) ?: $n->getAttribute( 'data' ) );
		$n->parentNode->replaceChild( '' === $src ? $doc->createTextNode( '' ) : snt_feed_link_p( $doc, $src, $src ), $n );
	}
	foreach ( iterator_to_array( $xp->query( '//script | //style | //noscript | //template | //form | //button | //svg' ) ) as $n ) {
		$n->parentNode->removeChild( $n );
	}
	foreach ( $xp->query( '//@href | //@src' ) as $attr ) {
		$v = trim( (string) $attr->value );
		if ( 0 === strpos( $v, '//' ) ) {
			$attr->value = 'https:' . $v;
		} elseif ( 0 === strpos( $v, '/' ) ) {
			$attr->value = $home . $v;
		} elseif ( 0 === strpos( $v, '#' ) ) {
			$attr->value = $permalink . $v;
		}
	}
	foreach ( iterator_to_array( $xp->query( '//@srcset' ) ) as $attr ) {
		$attr->ownerElement->removeAttribute( 'srcset' ); // relative srcset candidates would break; src is enough in a reader.
	}

	$out  = '';
	$root = $doc->getElementById( 'snt-feed-root' );
	foreach ( $root->childNodes as $c ) {
		$out .= $doc->saveHTML( $c );
	}
	return wp_kses_post( $out );
}

/**
 * <p><a href>text</a></p>.
 *
 * @param DOMDocument $doc  Document.
 * @param string      $href Target.
 * @param string      $text Link text.
 * @return DOMElement
 */
function snt_feed_link_p( DOMDocument $doc, $href, $text ) {
	$p = $doc->createElement( 'p' );
	$a = $doc->createElement( 'a' );
	$a->setAttribute( 'href', $href );
	$a->appendChild( $doc->createTextNode( $text ) );
	$p->appendChild( $a );
	return $p;
}

/**
 * The item's tail: the plain link back and the feed-open pixel. Added after
 * kses, so nothing here passes through it.
 *
 * @param string $link  Tagged item link (the_permalink_rss).
 * @param string $pixel Pixel URL, or '' when there is none.
 * @return string
 */
function snt_feed_item_tail( $link, $pixel ) {
	$out = '<p><a href="' . esc_url( $link ) . '">Read on the site</a></p>';
	if ( '' !== $pixel ) {
		$out .= '<img src="' . esc_url( $pixel ) . '" width="1" height="1" alt="" />';
	}
	return $out;
}

add_filter(
	'option_rss_use_excerpt',
	static function ( $value ) {
		return snt_feed_is_notes_request() ? 0 : $value;
	}
);

add_filter(
	'the_content_feed',
	static function ( $content ) {
		if ( ! snt_feed_is_notes_request() ) {
			return $content;
		}
		$id   = (int) get_the_ID();
		$body = snt_feed_safe_content( $content, untrailingslashit( home_url() ), get_permalink( $id ) );
		return $body . snt_feed_item_tail( apply_filters( 'the_permalink_rss', get_permalink( $id ) ), function_exists( 'snt_feed_open_pixel_url' ) ? snt_feed_open_pixel_url( $id ) : '' );
	}
);
