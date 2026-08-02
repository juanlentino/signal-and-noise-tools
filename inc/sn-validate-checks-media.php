<?php
/**
 * Signal & Noise Tools — sn_validate checks continued: alt_text, links,
 * body, brand_voice (MCP consolidation, session 5).
 *
 * Split out of inc/sn-validate-checks.php purely to hold each file under
 * the ~450-line house convention — same module, same constants (defined
 * in the sibling file, loaded first), same ZERO MODEL CALLS / ZERO WRITES
 * contract. See inc/sn-validate-checks.php's header for the shared
 * anti-drift discipline this file follows.
 *
 * @package SignalNoiseTools
 * @since 10.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * alt_text — char_range, filename_pattern, redundant_prefix. Inverts
 * ai-alt-suggest / ai-alt-inline-suggest.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param array $items [{attachment_id?:int, inline_index?:int, text:string}]
 * @param int   $post_id
 * @return array
 */
function snt_sn_validate_check_alt_text( array $items, $post_id ) {
	$findings = array();
	// Hard cap REUSED from ai-alt-text-suggest.php's own apply-time cap.
	$cap = defined( 'SNT_AI_ALT_APPLY_MAX_LENGTH' ) ? SNT_AI_ALT_APPLY_MAX_LENGTH : 250;

	foreach ( $items as $item ) {
		$item          = (array) $item;
		$text          = isset( $item['text'] ) ? (string) $item['text'] : '';
		$attachment_id = isset( $item['attachment_id'] ) ? (int) $item['attachment_id'] : 0;
		$inline_index  = isset( $item['inline_index'] ) ? (int) $item['inline_index'] : null;
		$item_ref      = $attachment_id > 0 ? 'attachment:' . $attachment_id : 'inline:' . (string) $inline_index;
		$identity      = $post_id . '|' . $item_ref . '|' . $text;
		$len           = mb_strlen( $text );

		if ( 0 === $len || $len > $cap ) {
			$findings[] = snt_sn_validate_finding(
				'alt_text', 'char_range', 'error',
				__( 'Alt text is empty or exceeds the hard character cap.', 'signal-and-noise-tools' ),
				$len, '1-' . $cap, array( 'item' => $item_ref ), $identity
			);
		} elseif ( $len < SNT_SN_VALIDATE_ALT_SOFT_MIN || $len > SNT_SN_VALIDATE_ALT_SOFT_MAX ) {
			$findings[] = snt_sn_validate_finding(
				'alt_text', 'char_range', 'warning',
				__( 'Alt text length is outside the guideline range.', 'signal-and-noise-tools' ),
				$len,
				SNT_SN_VALIDATE_ALT_SOFT_MIN . '-' . SNT_SN_VALIDATE_ALT_SOFT_MAX,
				array( 'item' => $item_ref ), $identity
			);
		}

		$looks_like_filename   = 1 === preg_match( SNT_SN_VALIDATE_ALT_FILENAME_PATTERN, trim( $text ) );
		$matches_real_filename = false;
		if ( $attachment_id > 0 && function_exists( 'get_attached_file' ) ) {
			$real                   = wp_basename( (string) get_attached_file( $attachment_id ) );
			$matches_real_filename = '' !== $real && 0 === strcasecmp( $real, trim( $text ) );
		}
		if ( $looks_like_filename || $matches_real_filename ) {
			$findings[] = snt_sn_validate_finding(
				'alt_text', 'filename_pattern', 'error',
				__( 'Alt text looks like a filename, not a description.', 'signal-and-noise-tools' ),
				$text, null, array( 'item' => $item_ref ), $identity
			);
		}

		foreach ( SNT_SN_VALIDATE_ALT_REDUNDANT_PREFIXES as $prefix ) {
			if ( 0 === stripos( trim( $text ), $prefix ) ) {
				$findings[] = snt_sn_validate_finding(
					'alt_text', 'redundant_prefix', 'error',
					sprintf(
						/* translators: %s: the redundant prefix phrase found. */
						__( 'Alt text starts with a redundant "%s" preamble.', 'signal-and-noise-tools' ),
						$prefix
					),
					$prefix, null, array( 'item' => $item_ref ), $identity
				);
				break;
			}
		}
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * links — target_exists, not_self, not_already_linked, anchor_present.
 * Inverts ai-link-suggest / ai-pair-suggest.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param array  $items [{anchor_text:string, target_post_id:int}]
 * @param int    $post_id
 * @param string $body  Body text to check anchor presence / existing links against.
 * @return array
 */
function snt_sn_validate_check_links( array $items, $post_id, $body ) {
	$findings = array();
	$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $body ) : strip_tags( (string) $body );

	foreach ( $items as $item ) {
		$item      = (array) $item;
		$anchor    = isset( $item['anchor_text'] ) ? (string) $item['anchor_text'] : '';
		$target_id = isset( $item['target_post_id'] ) ? (int) $item['target_post_id'] : 0;
		$identity  = $post_id . '|' . $target_id . '|' . $anchor;

		if ( $target_id === (int) $post_id ) {
			$findings[] = snt_sn_validate_finding(
				'links', 'not_self', 'error',
				__( 'Link target is the same post as the source.', 'signal-and-noise-tools' ),
				$target_id, null, array(), $identity
			);
			continue; // Every remaining check is meaningless against a self-link.
		}

		$target = get_post( $target_id );
		// Mirrors ai-link-suggest's own target contract: must exist AND be published.
		if ( ! $target || 'publish' !== (string) $target->post_status ) {
			$findings[] = snt_sn_validate_finding(
				'links', 'target_exists', 'error',
				__( 'Link target post does not exist or is not published.', 'signal-and-noise-tools' ),
				$target_id, null, array(), $identity
			);
			continue; // Nothing further to check without a real target.
		}

		if ( function_exists( 'sn_health_contains_note_link' ) && sn_health_contains_note_link( $body, (string) $target->post_name ) ) {
			$findings[] = snt_sn_validate_finding(
				'links', 'not_already_linked', 'error',
				__( 'The source already links to this target.', 'signal-and-noise-tools' ),
				true, false, array( 'target_slug' => $target->post_name ), $identity
			);
		}

		if ( '' === $anchor || false === stripos( $stripped, $anchor ) ) {
			$findings[] = snt_sn_validate_finding(
				'links', 'anchor_present', 'error',
				__( 'Anchor text was not found in the source body.', 'signal-and-noise-tools' ),
				$anchor, null, array(), $identity
			);
		}
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * body — drift_lexicon, block_pattern_registered.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $value
 * @param int    $post_id
 * @return array
 */
function snt_sn_validate_check_body( $value, $post_id ) {
	$findings = array();

	// drift_lexicon REUSES sn_health_drift_time_patterns() verbatim — never a
	// second copy of the pattern set (inc/health-check-drift-time-phrases.php).
	if ( function_exists( 'sn_health_drift_time_patterns' ) ) {
		$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $value ) : strip_tags( (string) $value );
		foreach ( sn_health_drift_time_patterns() as $pattern ) {
			if ( preg_match_all( $pattern, $stripped, $m ) && ! empty( $m[0] ) ) {
				foreach ( array_unique( $m[0] ) as $phrase ) {
					$findings[] = snt_sn_validate_finding(
						'body', 'drift_lexicon', 'warning',
						sprintf(
							/* translators: %s: the matched time-relative phrase. */
							__( 'Time-relative phrase "%s" may decay.', 'signal-and-noise-tools' ),
							$phrase
						),
						$phrase, null, array(), $post_id . '|' . $phrase
					);
				}
			}
		}
	}

	// block_pattern_registered — only when the body references a core/pattern
	// block (parse_blocks); registry existence check, WP core primitive.
	if ( function_exists( 'parse_blocks' ) && class_exists( 'WP_Block_Patterns_Registry' ) ) {
		$slugs    = snt_sn_validate_pattern_slugs_in_blocks( parse_blocks( (string) $value ) );
		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( array_unique( $slugs ) as $slug ) {
			if ( ! $registry->is_registered( $slug ) ) {
				$findings[] = snt_sn_validate_finding(
					'body', 'block_pattern_registered', 'error',
					sprintf(
						/* translators: %s: the unregistered pattern slug. */
						__( 'Block pattern "%s" is not registered in the active theme.', 'signal-and-noise-tools' ),
						$slug
					),
					$slug, null, array(), $post_id . '|' . $slug
				);
			}
		}
	}

	return $findings;
}

/**
 * @param array $blocks parse_blocks() output.
 * @return string[] core/pattern slugs referenced anywhere in the tree.
 */
function snt_sn_validate_pattern_slugs_in_blocks( $blocks ) {
	$out = array();
	foreach ( (array) $blocks as $b ) {
		$b = (array) $b;
		if ( isset( $b['blockName'] ) && 'core/pattern' === $b['blockName'] && ! empty( $b['attrs']['slug'] ) ) {
			$out[] = (string) $b['attrs']['slug'];
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$out = array_merge( $out, snt_sn_validate_pattern_slugs_in_blocks( $b['innerBlocks'] ) );
		}
	}
	return $out;
}

/* ════════════════════════════════════════════════════════════════════════
 * brand_voice — EVIDENCE ONLY, always severity 'info'. Never a score, never
 * a verdict — see the spec's "what does not invert" section. Applies to
 * whichever text surface is resolved (excerpt/meta_description/
 * og_card_title/note_summary/body).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $surface
 * @param string $value
 * @param int    $post_id
 * @return array
 */
function snt_sn_validate_brand_voice_findings( $surface, $value, $post_id ) {
	$findings       = array();
	$text           = (string) $value;
	$identity_base  = $post_id . '|' . $surface . '|' . $text;

	foreach ( SNT_SN_VALIDATE_BANNED_PHRASES as $phrase ) {
		if ( false !== stripos( $text, $phrase ) ) {
			$findings[] = snt_sn_validate_finding(
				$surface, 'banned_phrase', 'info',
				sprintf(
					/* translators: %s: the banned phrase found in the text. */
					__( 'Contains a phrase flagged by the voice register list: "%s".', 'signal-and-noise-tools' ),
					$phrase
				),
				$phrase, null, array(), $identity_base . '|' . $phrase
			);
		}
	}

	$sentences = preg_split( '/[.!?]+(?:\s+|$)/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	$sentences = is_array( $sentences ) ? $sentences : array();
	if ( ! empty( $sentences ) ) {
		$lengths = array_map( static function ( $s ) {
			return function_exists( 'snt_word_count' ) ? snt_word_count( $s ) : str_word_count( $s );
		}, $sentences );
		$findings[] = snt_sn_validate_finding(
			$surface, 'sentence_length', 'info',
			__( 'Sentence-length distribution (evidence, not a verdict).', 'signal-and-noise-tools' ),
			round( array_sum( $lengths ) / count( $lengths ), 1 ), null,
			array( 'min' => min( $lengths ), 'max' => max( $lengths ), 'count' => count( $lengths ) ),
			$identity_base
		);
	}

	// Literal U+2014 EM DASH only — a scoped, honest count, not an attempt to
	// catch every spaced-hyphen approximation of one.
	$em_dash_count = substr_count( $text, "\xE2\x80\x94" );
	if ( $em_dash_count > 0 ) {
		$findings[] = snt_sn_validate_finding(
			$surface, 'em_dash_count', 'info',
			__( 'Em dash count (evidence, not a verdict).', 'signal-and-noise-tools' ),
			$em_dash_count, 0, array(), $identity_base
		);
	}

	return $findings;
}
