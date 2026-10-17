<?php
/**
 * Signal & Noise Tools: the active-content refusal for agent writes.
 *
 * sn_apply writes RAW markup (create_draft never runs kses, and a block edit
 * stores the caller's serialized blocks byte for byte). An agent credential
 * could therefore add a script, a frame or an inline handler that runs in
 * every reader's browser. This check reads only what a change ADDS (the
 * replacement, the new blocks, the draft content), never the post's existing
 * content, and refuses the whole change by name with the constructs it found.
 *
 * Shared by block_insert, block_replace, sentence_replace (single, payload.edits
 * and batch members), the drift and em-dash batch members, and create_draft.
 *
 * @package SignalNoiseTools
 * @since 2026.10.26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elements refused by name. The first four load or run another document
 * whatever core's post allowlist says (it lists `object`). The rest are
 * refused only while `wp_kses_allowed_html( 'post' )` leaves them out: each
 * runs, restyles or redirects the page (form, base, link, meta, style, frames,
 * applet), or a browser parses it differently from the Tag Processor
 * (noscript with scripting on; xmp, noembed, noframes, plaintext inside SVG or
 * MathML), so markup could hide inside it.
 */
const SNT_SN_APPLY_ACTIVE_ALWAYS             = array( 'script', 'iframe', 'object', 'embed' );
const SNT_SN_APPLY_ACTIVE_UNLESS_CORE_ALLOWS = array( 'applet', 'base', 'form', 'frame', 'frameset', 'link', 'meta', 'noembed', 'noframes', 'noscript', 'plaintext', 'style', 'xmp' );

/** Closes a quote, a tag, a comment and every raw-text element, in that order. */
const SNT_SN_APPLY_CLOSE_ANY = '"\'>--></script></style></textarea></title></xmp></noembed></noframes></iframe>';

/**
 * The active constructs a fragment carries. PURE.
 *
 * Built on core: WP_HTML_Tag_Processor walks the tokens and decodes each
 * attribute the way a browser does (`&#106avascript:` and `java&#9;script:`
 * both read as `javascript:`), wp_kses_allowed_html( 'post' ) decides the
 * elements, and wp_kses_bad_protocol() with wp_allowed_protocols() decides
 * every URL attribute in wp_kses_uri_attributes(), plus xlink:href. Text and
 * comments keep one probe of their own for a quoted `javascript:` (a block
 * comment's `{"url":"javascript:..."}`): core has no primitive for a URL that
 * is not yet an attribute.
 *
 * @param string $fragment Markup or prose the change adds.
 * @param bool   $every    True for one entry per occurrence (for counting), false for each name once.
 * @return string[] Construct names, e.g. array( '<script', 'onerror=' ). Empty when clean.
 */
function snt_sn_apply_active_constructs( $fragment, $every = false ) {
	$fragment = (string) $fragment;
	if ( false === strpos( $fragment, '<' ) ) {
		return snt_sn_apply_text_script_url( html_entity_decode( $fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
	$found     = array();
	$allowed   = wp_kses_allowed_html( 'post' );
	$protocols = wp_allowed_protocols();
	$url_attrs = array_merge( wp_kses_uri_attributes(), array( 'xlink:href' ) );
	$p         = new WP_HTML_Tag_Processor( $fragment );
	while ( $p->next_token() ) {
		$type = $p->get_token_type();
		if ( '#tag' !== $type ) {
			// Text arrives decoded; a comment (block delimiters among them) does not.
			$text  = $p->get_modifiable_text();
			$found = array_merge( $found, snt_sn_apply_text_script_url( '#text' === $type ? $text : html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			continue;
		}
		if ( $p->is_tag_closer() ) {
			continue;
		}
		$tag = strtolower( (string) $p->get_tag() );
		if ( in_array( $tag, SNT_SN_APPLY_ACTIVE_ALWAYS, true ) || ( in_array( $tag, SNT_SN_APPLY_ACTIVE_UNLESS_CORE_ALLOWS, true ) && ! isset( $allowed[ $tag ] ) ) ) {
			$found[] = '<' . $tag;
		}
		foreach ( (array) $p->get_attribute_names_with_prefix( 'on' ) as $name ) {
			$found[] = $name . '=';
		}
		foreach ( $url_attrs as $name ) {
			$value = $p->get_attribute( $name );
			if ( is_string( $value ) && wp_kses_bad_protocol( $value, $protocols ) !== wp_kses_no_null( $value ) ) {
				// Name the scheme core refused, split on a colon the way kses splits it.
				$found[] = strtolower( preg_split( '/:|&#0*58;|&#x0*3a;/i', (string) preg_replace( '/[\x00-\x20]+/', '', $value ), 2 )[0] ) . ':';
			}
		}
		// TITLE and TEXTAREA hold their contents as text, but inside SVG or MathML a
		// browser parses those contents as markup, so read them as markup too.
		if ( 'title' === $tag || 'textarea' === $tag ) {
			$found = array_merge( $found, snt_sn_apply_active_constructs( $p->get_modifiable_text(), true ) );
		}
	}
	// A fragment cut off mid-token (`<img src=x onerror=f()` with no `>`) is closed by
	// whatever follows it in the post, so read it again whole with every opener closed.
	if ( $p->paused_at_incomplete_token() && ! str_ends_with( $fragment, SNT_SN_APPLY_CLOSE_ANY ) ) {
		$found = snt_sn_apply_active_constructs( $fragment . SNT_SN_APPLY_CLOSE_ANY, true );
	}
	return $every ? $found : array_values( array_unique( $found ) );
}

/**
 * `javascript:` in text that is not yet an attribute: a block comment's JSON
 * or a quoted URL in prose. Control characters and spaces are dropped first,
 * as a browser drops them before it reads a scheme.
 *
 * @param string $text Decoded text.
 * @return string[] array( 'javascript:' ) or empty.
 */
function snt_sn_apply_text_script_url( $text ) {
	$probe = (string) preg_replace( '/[\x00-\x20]+/', '', (string) $text );
	return preg_match( '/["\'=(]javascript:/i', $probe ) ? array( 'javascript:' ) : array();
}

/**
 * The named 422 for a fragment that carries active content, or true.
 *
 * @param string $fragment What the change adds.
 * @param string $field    Where it came from, for the message (e.g. "payload.blocks").
 * @return true|WP_Error snt_sn_apply_active_content (422), data.constructs listing what was found.
 */
function snt_sn_apply_active_content_error( $fragment, $field ) {
	return snt_sn_apply_active_refusal( snt_sn_apply_active_constructs( $fragment ), $field );
}

/**
 * The named 422 when a splice into existing content ADDS active content, or true.
 *
 * A plain-prose replacement has no `<`, so the fragment check passes it, yet
 * spliced inside an attribute value (an alt, a title) a `"` closes the value
 * and the rest becomes attributes: `" onmouseover="alert(1)`. So the whole
 * post is read before and after the splice, and any construct that occurs
 * more often after is refused. One the post already carried is not the
 * agent's doing and never blocks an unrelated edit.
 *
 * @param string $before The post content before the splice.
 * @param string $after  The post content after it.
 * @param string $field  Where the spliced text came from, for the message.
 * @return true|WP_Error snt_sn_apply_active_content (422), data.constructs listing what the splice added.
 */
function snt_sn_apply_splice_active_error( $before, $after, $field ) {
	$was   = array_count_values( snt_sn_apply_active_constructs( $before, true ) );
	$added = array();
	foreach ( array_count_values( snt_sn_apply_active_constructs( $after, true ) ) as $name => $n ) {
		if ( $n > ( $was[ $name ] ?? 0 ) ) {
			$added[] = (string) $name;
		}
	}
	return snt_sn_apply_active_refusal( $added, $field . ' (as spliced into the post)' );
}

/**
 * The named 422 for constructs found, or true when there are none.
 *
 * @param string[] $found Construct names.
 * @param string   $field Where they came from, for the message.
 * @return true|WP_Error
 */
function snt_sn_apply_active_refusal( array $found, $field ) {
	if ( array() === $found ) {
		return true;
	}
	return new WP_Error(
		'snt_sn_apply_active_content',
		sprintf(
			/* translators: 1: payload field name, 2: comma-separated list of constructs such as "<script, onerror=". */
			__( '%1$s carries active content that would run in a reader\'s browser: %2$s. Agent writes never add scripts, frames, embedded objects, inline event handlers or javascript: URLs.', 'signal-and-noise-tools' ),
			$field,
			implode( ', ', $found )
		),
		array(
			'status'     => 422,
			'constructs' => $found,
		)
	);
}
