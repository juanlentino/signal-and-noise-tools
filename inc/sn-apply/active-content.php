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
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The active constructs a fragment carries. PURE.
 *
 * Entities are decoded before the URL check, and ASCII control characters
 * and spaces are dropped from the probe, because a browser does both before
 * it reads a URL scheme (`&#106;avascript:` and `java&#9;script:` both run).
 *
 * @param string $fragment Markup or prose the change adds.
 * @return string[] Construct names, e.g. array( '<script', 'onerror=' ). Empty when clean.
 */
function snt_sn_apply_active_constructs( $fragment ) {
	$fragment = (string) $fragment;
	$found    = array();
	if ( preg_match_all( '/<\s*(script|iframe|object|embed)\b/i', $fragment, $m ) ) {
		foreach ( $m[1] as $tag ) {
			$found[] = '<' . strtolower( $tag );
		}
	}
	// An on*= attribute inside a tag: `<img src=x onerror=...>`, `<svg/onload=...>`.
	if ( preg_match_all( '#<[a-z][^>]*?[\s/"\']+(on[a-z]+)\s*=#i', $fragment, $m ) ) {
		foreach ( $m[1] as $attr ) {
			$found[] = strtolower( $attr ) . '=';
		}
	}
	$probe = preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( preg_match( '/["\'=(]javascript:/i', (string) $probe ) ) {
		$found[] = 'javascript:';
	}
	return array_values( array_unique( $found ) );
}

/**
 * The named 422 for a fragment that carries active content, or true.
 *
 * @param string $fragment What the change adds.
 * @param string $field    Where it came from, for the message (e.g. "payload.blocks").
 * @return true|WP_Error snt_sn_apply_active_content (422), data.constructs listing what was found.
 */
function snt_sn_apply_active_content_error( $fragment, $field ) {
	$found = snt_sn_apply_active_constructs( $fragment );
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
