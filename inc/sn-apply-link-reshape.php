<?php
/**
 * Signal & Noise Tools — sn_apply change.type "link_reshape".
 *
 * ── Origin (2026-08-08 audit, item 5 — owner-confirmed after item 4) ──
 *
 * There was no path to change where an anchor tag starts and stops:
 * sentence_replace takes plain prose only, so replacing a span containing
 * an <a> would DELETE the link rather than reshape it. link_reshape moves
 * the two tag positions inside one existing text node, nothing else.
 *
 * ── Why no re-signing is needed (audit item 4, decided) ──
 *
 * The provenance payload is built from sn_prov_normalize_v1(), whose step 2
 * is wp_strip_all_tags() — markup never reaches the hash — and
 * sn_prov_record() coalesces on the bearing hash, so a save whose rendered
 * prose is byte-identical appends NO new commit. A reshape is structurally
 * invisible to the ledger. The impl still ASSERTS prose byte-identity
 * after the splice (snt_sn_apply_link_reshape_compute()) rather than
 * trusting the constraint chain: on the one site whose whole argument is
 * that signed records aren't quietly revisable, "should be identical" is
 * checked, not assumed.
 *
 * ── Hard constraints (all server-side, gate 2 AND the impl) ──
 *
 *   1. new_anchor MUST be a contiguous substring of current_anchor. This
 *      single rule is what reduces the operation to moving two tag
 *      positions inside one text node: no new text can enter, no block
 *      structure can change, rendered prose is byte-identical.
 *   2. new_anchor must occur exactly ONCE inside current_anchor — two
 *      occurrences would make "which words stay linked" ambiguous, and
 *      this tool refuses ambiguity rather than guessing.
 *   3. href (and every other attribute) is CARRIED OVER from the existing
 *      tag, never accepted as a parameter — otherwise this is a
 *      link-retargeting tool wearing a formatting tool's name.
 *   4. new_anchor:"" refuses. Unlinking is its own change type (not yet
 *      built), never an overload of this one.
 *   5. Fingerprint REQUIRED: the LIVE post's content_hash (sn_posts'
 *      scheme), the sentence_replace binding exactly.
 *
 * @package SignalNoiseTools
 * @since 10.58.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// context_snippet disambiguation window, in bytes around the candidate tag.
const SNT_SN_APPLY_LINK_RESHAPE_CONTEXT_WINDOW = 250;

/**
 * Validate the current_anchor/new_anchor pair. Shared by gate 1 (422 caller
 * error, not 409) and the write impl (defense-in-depth).
 *
 * @param string $current_anchor Exact inner text of the existing <a>.
 * @param string $new_anchor     Desired inner text — contiguous substring.
 * @return true|WP_Error
 */
function snt_sn_apply_link_reshape_pair_error( $current_anchor, $new_anchor ) {
	$current_anchor = (string) $current_anchor;
	$new_anchor     = (string) $new_anchor;

	if ( '' === $current_anchor ) {
		return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'payload.current_anchor is empty.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( '' === $new_anchor ) {
		return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'payload.new_anchor is empty — unlinking is its own change type, never an overload of link_reshape.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	// Anchor values are TEXT-node content: tag-shaped sequences refuse (the
	// sentence_replace posture — '<' followed by a letter, '/', '!' or '?',
	// so prose like "<5 percent" stays legal).
	if ( preg_match( '#<[a-zA-Z/!?]#', $current_anchor . ' ' . $new_anchor ) ) {
		return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'Anchor values are plain text-node content — no markup. Copy current_anchor byte-exactly from the stored content, tags excluded.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( $new_anchor === $current_anchor ) {
		return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'payload.new_anchor equals payload.current_anchor — a no-op reshape.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$occurrences = substr_count( $current_anchor, $new_anchor );
	if ( 0 === $occurrences ) {
		return new WP_Error( 'snt_sn_apply_invalid_anchor', __( 'payload.new_anchor must be a CONTIGUOUS substring of payload.current_anchor — that constraint is what makes the operation pure tag movement with byte-identical rendered prose.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( $occurrences > 1 ) {
		return new WP_Error(
			'snt_sn_apply_invalid_anchor',
			sprintf(
				/* translators: %d: number of times new_anchor occurs inside current_anchor. */
				__( 'payload.new_anchor occurs %d times inside payload.current_anchor — which words stay linked is ambiguous, and this tool refuses ambiguity rather than guessing. Extend new_anchor until it is unique within current_anchor.', 'signal-and-noise-tools' ),
				$occurrences
			),
			array( 'status' => 422 )
		);
	}
	return true;
}

/**
 * Locate the <a> element whose inner content is exactly $current_anchor.
 * Byte-exact on the inner text (entities included, copied from the stored
 * content). Multiple matches disambiguate via $context_snippet within a
 * fixed window; still-ambiguous refuses rather than guessing.
 *
 * @param string $content         Raw post_content.
 * @param string $current_anchor  Exact inner text.
 * @param string $context_snippet Optional disambiguator (~200 chars around the tag).
 * @return array{offset:int,length:int,open_tag:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_sn_apply_anchor_not_found (409)
 *   snt_sn_apply_anchor_ambiguous (422)
 */
function snt_sn_apply_link_reshape_locate( $content, $current_anchor, $context_snippet = '' ) {
	$content = (string) $content;
	$pattern = '#(<a\b[^>]*>)' . preg_quote( (string) $current_anchor, '#' ) . '</a>#s';

	if ( ! preg_match_all( $pattern, $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return new WP_Error( 'snt_sn_apply_anchor_not_found', __( 'No <a> element with exactly this inner text exists in the post content — the match is byte-exact, entities included. Copy current_anchor verbatim from sn_posts\' content field (text between the tags only).', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$matches = array();
	foreach ( $m[0] as $i => $full ) {
		$matches[] = array(
			'offset'   => (int) $full[1],
			'length'   => strlen( $full[0] ),
			'open_tag' => (string) $m[1][ $i ][0],
		);
	}

	if ( count( $matches ) > 1 && '' !== (string) $context_snippet ) {
		$window   = SNT_SN_APPLY_LINK_RESHAPE_CONTEXT_WINDOW;
		$filtered = array_values( array_filter( $matches, static function ( $match ) use ( $content, $context_snippet, $window ) {
			$start = max( 0, $match['offset'] - $window );
			$slice = substr( $content, $start, $match['length'] + 2 * $window );
			return false !== strpos( $slice, (string) $context_snippet );
		} ) );
		if ( ! empty( $filtered ) ) {
			$matches = $filtered;
		}
	}

	if ( count( $matches ) > 1 ) {
		return new WP_Error(
			'snt_sn_apply_anchor_ambiguous',
			sprintf(
				/* translators: %d: number of identical anchor matches found. */
				__( '%d identical <a> elements match — pass payload.context_snippet (~200 chars around the intended one) to disambiguate.', 'signal-and-noise-tools' ),
				count( $matches )
			),
			array( 'status' => 422 )
		);
	}

	return $matches[0];
}

/**
 * Compute the reshaped content and ASSERT rendered-prose byte-identity.
 *
 * The splice: current_anchor = prefix . new_anchor . suffix (the pair
 * validator proved the split is unique); prefix/suffix move OUTSIDE the
 * tag, the tag (open tag verbatim — href and every attribute carried over)
 * now wraps new_anchor only.
 *
 * The assertion compares normalized prose before/after — via the
 * provenance normalizer itself when active (the exact function whose
 * output feeds the signature), else a strip-tags fallback. A violation is
 * a hard 500: it means the constraint chain above has a hole, and nothing
 * should be written.
 *
 * @param string $content        Raw post_content.
 * @param array  $match          From snt_sn_apply_link_reshape_locate().
 * @param string $current_anchor
 * @param string $new_anchor
 * @return string|WP_Error New content.
 */
function snt_sn_apply_link_reshape_compute( $content, array $match, $current_anchor, $new_anchor ) {
	$split  = strpos( $current_anchor, $new_anchor ); // Unique — validator-proved.
	$prefix = substr( $current_anchor, 0, $split );
	$suffix = substr( $current_anchor, $split + strlen( $new_anchor ) );

	$replacement = $prefix . $match['open_tag'] . $new_anchor . '</a>' . $suffix;
	$new_content = substr_replace( (string) $content, $replacement, $match['offset'], $match['length'] );

	$normalize = static function ( $s ) {
		if ( function_exists( 'sn_prov_active' ) && function_exists( 'sn_prov_normalize_v1' ) && sn_prov_active() ) {
			return sn_prov_normalize_v1( (string) $s );
		}
		$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $s ) : strip_tags( (string) $s );
		return trim( preg_replace( '/\s+/u', ' ', $stripped ) );
	};
	if ( $normalize( $content ) !== $normalize( $new_content ) ) {
		return new WP_Error( 'snt_sn_apply_identity_violation', __( 'Post-splice assertion failed: the reshape would change rendered prose. Nothing was written — this indicates a bug in link_reshape itself, not a caller error.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	return $new_content;
}

/**
 * Reshape one anchor element, gated on the LIVE content_hash. Same
 * splice/write contract as snt_sn_apply_sentence_replace_impl().
 *
 * @param int           $post_id
 * @param string        $current_anchor
 * @param string        $new_anchor
 * @param string        $fingerprint     snt_corpus_content_hash() of the live content.
 * @param string        $context_snippet Optional disambiguator.
 * @param callable|null $write_callback  ($post_id, $new_content) instead of wp_update_post().
 * @return array{ok:bool,post_id:int,reshaped_from:string,reshaped_to:string,old_content:string,new_content:string}|WP_Error
 */
function snt_sn_apply_link_reshape_impl( $post_id, $current_anchor, $new_anchor, $fingerprint, $context_snippet = '', $write_callback = null ) {
	$post_id        = (int) $post_id;
	$current_anchor = (string) $current_anchor;
	$new_anchor     = (string) $new_anchor;

	$pair = snt_sn_apply_link_reshape_pair_error( $current_anchor, $new_anchor );
	if ( is_wp_error( $pair ) ) {
		return $pair;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_sn_apply_capability', __( 'You cannot edit this post.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;
	$observed        = snt_corpus_content_hash( $current_content );
	if ( ! hash_equals( $observed, (string) $fingerprint ) ) {
		return new WP_Error( 'snt_sn_apply_fingerprint_stale', __( 'Live post content has changed since this fingerprint was observed. Re-fetch content_hash via sn_posts and retry.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$match = snt_sn_apply_link_reshape_locate( $current_content, $current_anchor, (string) $context_snippet );
	if ( is_wp_error( $match ) ) {
		return $match;
	}

	$new_content = snt_sn_apply_link_reshape_compute( $current_content, $match, $current_anchor, $new_anchor );
	if ( is_wp_error( $new_content ) ) {
		return $new_content;
	}

	if ( is_callable( $write_callback ) ) {
		$result = call_user_func( $write_callback, $post_id, $new_content );
	} else {
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);
	}
	if ( is_wp_error( $result ) ) {
		/* translators: %s is the error message from the write step */
		return new WP_Error( 'snt_sn_apply_write_failed', sprintf( __( 'Write failed: %s', 'signal-and-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
	}

	return array(
		'ok'            => true,
		'post_id'       => $post_id,
		'reshaped_from' => $current_anchor,
		'reshaped_to'   => $new_anchor,
		'old_content'   => $current_content,
		'new_content'   => $new_content,
	);
}
