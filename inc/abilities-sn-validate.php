<?php
/**
 * Signal & Noise Tools — Abilities API: sn_validate (MCP consolidation, session 5).
 *
 * Deterministic, model-free validation of PROPOSED content before it is
 * ever written. Turns the ai-*-suggest layer's implicit rules into an
 * explicit, callable gate — the inversion documented in
 * ~/.claude/session-data/SN-MCP-new/sn-validate-spec.md.
 *
 *   excerpt          -> word_count, sentence_count                (inc/sn-validate-checks.php)
 *   meta_description -> char_range, corpus_collision               (inc/sn-validate-checks.php)
 *   og_card_title     -> char_range, title_divergence               (inc/sn-validate-checks.php)
 *   note_summary      -> single_sentence, word_count                (inc/sn-validate-checks.php)
 *   tags              -> tag_vocabulary                             (inc/sn-validate-checks.php)
 *   alt_text          -> char_range, filename_pattern, redundant_prefix (inc/sn-validate-checks-media.php)
 *   links             -> target_exists, not_self, not_already_linked, anchor_present (inc/sn-validate-checks-media.php)
 *   body              -> drift_lexicon, block_pattern_registered    (inc/sn-validate-checks-media.php)
 *   brand_voice       -> banned_phrase, sentence_length, em_dash_count — INFO ONLY, never blocks (inc/sn-validate-checks-media.php)
 *
 * STATELESS + ZERO WRITES: every check is a pure read + compute, proven by
 * a write-recorder guard in tests/abilities-sn-validate.php (same pattern
 * as sn_scan's zero-writes guard, docs/mcp-consolidation/FINDINGS.md
 * session-4). ZERO MODEL CALLS: this file and both checks files never
 * reference an AI transport — pinned by a source-scan structural test
 * (acceptance test 6).
 *
 * Severity model (exact, per spec): error blocks (ready_to_apply:false),
 * warning is a heuristic signal that never blocks, info is evidence for a
 * judgment call (brand_voice) and never blocks, never scores.
 *
 * SCOPE CUT (session 5): sn_apply does not exist yet (sessions 6-7). This
 * session pins ready_to_apply SEMANTICS only — it does not wire an actual
 * apply-time enforcement call, because there is no apply tool to enforce
 * from yet. See docs/mcp-consolidation/FINDINGS.md session-5.
 *
 * @package SignalNoiseTools
 * @since 10.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Surface tokens accepted in `checks`. 'brand_voice' is not a literal
// proposed-content field (see the input_schema below) but IS a valid
// checks token — session-5 decision, documented in FINDINGS.md: it applies
// evidence-only checks to whichever text surfaces (excerpt/meta_description/
// og_card_title/note_summary/body) are actually resolved.
const SNT_SN_VALIDATE_SURFACES = array(
	'excerpt', 'meta_description', 'og_card_title', 'note_summary',
	'body', 'tags', 'alt_text', 'links', 'brand_voice',
);

// Text-bearing surfaces brand_voice evidence can attach to.
const SNT_SN_VALIDATE_TEXT_SURFACES = array( 'excerpt', 'meta_description', 'og_card_title', 'note_summary', 'body' );

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-validate', array(
		'label'               => 'Validate proposed content before writing (consolidated, deterministic)',
		'description'         => 'Deterministic, model-free validation of proposed content — the verification layer behind the ai-*-suggest tools, not a replacement generator. Every check is either an ERROR (objective rule violation, blocks ready_to_apply), a WARNING (heuristic signal, never blocks), or INFO (evidence for a human judgment call — brand_voice findings are never a score or a verdict). Stateless and side-effect free: call it as many times as needed while iterating on a draft. Zero model calls, ever — every check is a pure read + compute against the corpus (length caps, corpus-wide collision checks, link-graph state, tag vocabulary membership, block-pattern registry). checks:"all" with no proposed content validates the post\'s currently PUBLISHED surfaces instead of erroring. DRAFTING HARNESS: post_id is required, but proposed content does NOT need to belong to that post — pass any existing corpus post_id with compare_against:"none" and the body/tags/excerpt/meta checks evaluate the proposed content entirely on its own terms (no diff against the host post is computed), so content for a post that does not exist yet can be validated before create_draft is ever called. brand_voice REPORTING: its findings are attributed to the text surface they were found on (surface:"body", check:"banned_phrase"/"sentence_length"/"em_dash_count"), never to a "brand_voice" surface; when the pass ran, the literal token "brand_voice" appears in surfaces_checked alongside every text surface it evaluated (even ones with zero findings), and when it was requested but no text surface resolved, a WARNING finding (check:"not_evaluated") says so explicitly — it never silently no-ops. finding_id is content-derived (sha256 of surface + check + the specific content identity) — the same scheme as sn_scan\'s candidate_id — so identical input reruns byte-identical, and a suppressed finding reappears honestly when the content changes.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_read_corpus',
		'execute_callback'    => 'snt_ability_sn_validate',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'proposed'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'excerpt'          => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
						'og_card_title'    => array( 'type' => 'string' ),
						'note_summary'     => array( 'type' => 'string' ),
						'body'             => array( 'type' => 'string' ),
						'tags'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'alt_text'         => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'attachment_id' => array( 'type' => 'integer' ),
									'inline_index'  => array( 'type' => 'integer' ),
									'text'          => array( 'type' => 'string' ),
								),
							),
						),
						'links'            => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'anchor_text'    => array( 'type' => 'string' ),
									'target_post_id' => array( 'type' => 'integer' ),
								),
							),
						),
					),
					'additionalProperties' => false,
				),
				'checks'          => array(
					// v10.41.1: same failure class as inc/abilities-sn-apply.php's
					// `target` (found by that fix's projection sweep, not independently
					// reported live) -- a nested 'oneOf' with NO top-level 'type' key
					// left this property advertised as untyped after projection, the
					// same shape that made an MCP client stringify `target` on every
					// call. `type` as an ARRAY union says the same thing without a
					// combinator: a string (only 'all' is valid, enforced server-side
					// below -- see sn_mcp_normalize_schema()'s own precedent for
					// dropping schema-level strictness in favor of execute-time
					// enforcement) OR an array of surface tokens.
					'type'    => array( 'string', 'array' ),
					'items'   => array( 'type' => 'string', 'enum' => SNT_SN_VALIDATE_SURFACES ),
					'default' => 'all',
				),
				'compare_against' => array( 'type' => 'string', 'enum' => array( 'published', 'none' ), 'default' => 'published' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'         => array( 'type' => 'integer' ),
				'validated_at'    => array( 'type' => 'string' ),
				'surfaces_checked' => array( 'type' => 'array' ),
				'status'          => array( 'type' => 'string' ),
				'ready_to_apply'  => array( 'type' => 'boolean' ),
				'findings'        => array( 'type' => 'array' ),
				'diff'            => array( 'type' => array( 'object', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * Resolve the value to validate for a given text/list surface: proposed
 * (if present) else the published value (else null — surface is skipped,
 * never an error). Post is assumed already validated to exist.
 *
 * @param string  $surface
 * @param WP_Post $post
 * @param array   $proposed
 * @return array{value:mixed,source:string}|null
 */
function snt_sn_validate_resolve_surface( $surface, $post, array $proposed ) {
	if ( array_key_exists( $surface, $proposed ) ) {
		return array( 'value' => $proposed[ $surface ], 'source' => 'proposed' );
	}

	switch ( $surface ) {
		case 'excerpt':
			$v = (string) ( $post->post_excerpt ?? '' );
			return '' !== trim( $v ) ? array( 'value' => $v, 'source' => 'published' ) : null;
		case 'meta_description':
			$v = (string) get_post_meta( $post->ID, '_sn_meta_description', true );
			return '' !== trim( $v ) ? array( 'value' => $v, 'source' => 'published' ) : null;
		case 'og_card_title':
			$v = (string) get_post_meta( $post->ID, '_sn_og_card_title', true );
			return '' !== trim( $v ) ? array( 'value' => $v, 'source' => 'published' ) : null;
		case 'body':
			$v = (string) ( $post->post_content ?? '' );
			return '' !== trim( $v ) ? array( 'value' => $v, 'source' => 'published' ) : null;
		case 'tags':
			$names = function_exists( 'snt_corpus_term_names' ) ? snt_corpus_term_names( $post->ID, 'post_tag' ) : array();
			return ! empty( $names ) ? array( 'value' => $names, 'source' => 'published' ) : null;
		default:
			// note_summary / alt_text / links have no natural published form
			// this plugin stores — only ever checked from a proposal.
			return null;
	}
}

/**
 * Ability execute callback: signal-noise/sn-validate.
 *
 * @param array|null $input
 * @return array|WP_Error
 */
function snt_ability_sn_validate( $input ) {
	$input   = is_array( $input ) ? $input : array();
	$post_id = (int) ( $input['post_id'] ?? 0 );

	$post      = get_post( $post_id );
	$status_ok = $post && function_exists( 'snt_corpus_post_type_allowed' ) && defined( 'SNT_CORPUS_STATUSES' )
		&& in_array( (string) $post->post_status, SNT_CORPUS_STATUSES, true )
		&& snt_corpus_post_type_allowed( (string) $post->post_type );
	if ( ! $status_ok ) {
		return new WP_Error( 'snt_validate_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$checks_input = $input['checks'] ?? 'all';
	// Transport tolerance (v10.41.1, same reasoning as sn_apply's `target`):
	// a client that stringified `checks` while its own schema cache still
	// reflected the pre-fix untyped shape may send a JSON-encoded array as a
	// string. Decode before validating; an undecodable non-"all" string
	// falls straight through to the existing 422 below unchanged.
	if ( is_string( $checks_input ) && 'all' !== $checks_input ) {
		$decoded_checks = json_decode( $checks_input, true );
		if ( is_array( $decoded_checks ) ) {
			$checks_input = $decoded_checks;
		}
	}
	if ( 'all' === $checks_input ) {
		$requested = SNT_SN_VALIDATE_SURFACES;
	} elseif ( is_array( $checks_input ) ) {
		$requested = array_values( array_unique( array_map( 'strval', $checks_input ) ) );
		$invalid   = array_diff( $requested, SNT_SN_VALIDATE_SURFACES );
		if ( ! empty( $invalid ) ) {
			return new WP_Error(
				'snt_validate_bad_checks',
				sprintf(
					/* translators: %s: comma-separated list of invalid check tokens. */
					__( 'Unknown checks value(s): %s.', 'signal-and-noise-tools' ),
					implode( ', ', $invalid )
				),
				array( 'status' => 422 )
			);
		}
		if ( empty( $requested ) ) {
			return new WP_Error( 'snt_validate_bad_checks', __( 'checks must be "all" or a non-empty array.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
	} else {
		return new WP_Error( 'snt_validate_bad_checks', __( 'checks must be "all" or an array of check tokens.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$compare_against = isset( $input['compare_against'] ) ? (string) $input['compare_against'] : 'published';
	if ( ! in_array( $compare_against, array( 'published', 'none' ), true ) ) {
		return new WP_Error( 'snt_validate_bad_compare_against', __( 'compare_against must be "published" or "none".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$proposed = is_array( $input['proposed'] ?? null ) ? $input['proposed'] : array();

	$findings         = array();
	$surfaces_checked = array();
	$resolved_text    = array(); // surface => value, for the brand_voice pass below.

	foreach ( array_diff( $requested, array( 'brand_voice' ) ) as $surface ) {
		$resolved = snt_sn_validate_resolve_surface( $surface, $post, $proposed );
		if ( null === $resolved ) {
			continue; // Nothing to check — never an error (acceptance test 5).
		}
		$value = $resolved['value'];

		switch ( $surface ) {
			case 'excerpt':
				$findings = array_merge( $findings, snt_sn_validate_check_excerpt( (string) $value, $post_id ) );
				break;
			case 'meta_description':
				$findings = array_merge( $findings, snt_sn_validate_check_meta_description( (string) $value, $post_id ) );
				break;
			case 'og_card_title':
				$findings = array_merge( $findings, snt_sn_validate_check_og_card_title( (string) $value, $post_id, (string) $post->post_title ) );
				break;
			case 'note_summary':
				$findings = array_merge( $findings, snt_sn_validate_check_note_summary( (string) $value, $post_id ) );
				break;
			case 'body':
				$findings = array_merge( $findings, snt_sn_validate_check_body( (string) $value, $post_id ) );
				break;
			case 'tags':
				$findings = array_merge( $findings, snt_sn_validate_check_tags( (array) $value, $post_id ) );
				break;
			case 'alt_text':
				$findings = array_merge( $findings, snt_sn_validate_check_alt_text( (array) $value, $post_id ) );
				break;
			case 'links':
				$body_for_anchor = array_key_exists( 'body', $proposed ) ? (string) $proposed['body'] : (string) $post->post_content;
				$findings         = array_merge( $findings, snt_sn_validate_check_links( (array) $value, $post_id, $body_for_anchor ) );
				break;
		}

		$surfaces_checked[] = $surface;
		if ( in_array( $surface, SNT_SN_VALIDATE_TEXT_SURFACES, true ) ) {
			$resolved_text[ $surface ] = (string) $value;
		}
	}

	// brand_voice — evidence only, applied to every resolved text surface,
	// even one not itself in $requested (its own structural check may not
	// have been asked for, but the caller explicitly asked for brand_voice).
	//
	// AUDIT FIX (2026-08-08): this pass used to be UNREPORTABLE from outside
	// — its findings carry the text surface's name (surface:"body", check:
	// "banned_phrase"/"sentence_length"/"em_dash_count"), the 'brand_voice'
	// token never entered surfaces_checked, and a surface it evaluated
	// cleanly left no trace at all. A caller grepping the response for
	// "brand_voice" concluded the check silently no-oped even when it ran.
	// Now: (a) 'brand_voice' itself joins surfaces_checked whenever the pass
	// evaluated at least one text surface; (b) every surface it evaluated is
	// recorded even with zero findings; (c) when it was requested but NO text
	// surface resolved (nothing to evaluate), that is a WARNING finding —
	// loud, never blocking — instead of a silent skip. The silent-skip rule
	// (acceptance test 5) still governs the STRUCTURAL surfaces; brand_voice
	// is different because it is a cross-surface check the caller asked for
	// by name.
	if ( in_array( 'brand_voice', $requested, true ) ) {
		if ( empty( $resolved_text ) ) {
			foreach ( SNT_SN_VALIDATE_TEXT_SURFACES as $surface ) {
				$resolved = snt_sn_validate_resolve_surface( $surface, $post, $proposed );
				if ( null !== $resolved ) {
					$resolved_text[ $surface ] = (string) $resolved['value'];
				}
			}
		}
		if ( empty( $resolved_text ) ) {
			$findings[] = snt_sn_validate_finding(
				'brand_voice', 'not_evaluated', 'warning',
				__( 'brand_voice was requested but no text surface resolved (no proposed or published excerpt/meta_description/og_card_title/note_summary/body) — the check did not run.', 'signal-and-noise-tools' ),
				null, null, array( 'text_surfaces' => SNT_SN_VALIDATE_TEXT_SURFACES ), $post_id . '|brand_voice|not_evaluated'
			);
		} else {
			$surfaces_checked[] = 'brand_voice';
			foreach ( $resolved_text as $surface => $value ) {
				$bv = snt_sn_validate_brand_voice_findings( $surface, $value, $post_id );
				if ( ! empty( $bv ) ) {
					$findings = array_merge( $findings, $bv );
				}
				if ( ! in_array( $surface, $surfaces_checked, true ) ) {
					$surfaces_checked[] = $surface;
				}
			}
		}
	}

	$has_error   = false;
	$has_warning = false;
	foreach ( $findings as $f ) {
		if ( 'error' === $f['severity'] ) {
			$has_error = true;
		} elseif ( 'warning' === $f['severity'] ) {
			$has_warning = true;
		}
	}
	$status = $has_error ? 'fail' : ( $has_warning ? 'pass_with_warnings' : 'pass' );

	$diff = null;
	if ( 'published' === $compare_against && ! empty( $proposed ) ) {
		$diff = snt_sn_validate_build_diff( $post, $proposed );
	}

	return array(
		'post_id'          => $post_id,
		'validated_at'     => gmdate( 'c' ),
		'surfaces_checked' => array_values( array_unique( $surfaces_checked ) ),
		'status'           => $status,
		'ready_to_apply'   => ! $has_error,
		'findings'         => $findings,
		'diff'             => $diff,
	);
}

/**
 * compare_against:"published" — a simple per-surface {published, proposed}
 * presence diff. Deliberately NOT over-built (session-5 scope cut, per the
 * kickoff): no semantic diff/patch, just what changed at the surface level.
 *
 * @param WP_Post $post
 * @param array   $proposed
 * @return array
 */
function snt_sn_validate_build_diff( $post, array $proposed ) {
	$diff = array();

	$scalar_map = array(
		'excerpt'          => (string) ( $post->post_excerpt ?? '' ),
		'meta_description' => (string) get_post_meta( $post->ID, '_sn_meta_description', true ),
		'og_card_title'    => (string) get_post_meta( $post->ID, '_sn_og_card_title', true ),
		'body'             => (string) ( $post->post_content ?? '' ),
	);
	foreach ( $scalar_map as $surface => $published ) {
		if ( array_key_exists( $surface, $proposed ) ) {
			$diff[ $surface ] = array( 'published' => $published, 'proposed' => (string) $proposed[ $surface ] );
		}
	}

	if ( array_key_exists( 'tags', $proposed ) ) {
		$published_tags   = function_exists( 'snt_corpus_term_names' ) ? snt_corpus_term_names( $post->ID, 'post_tag' ) : array();
		$diff['tags']      = array( 'published' => $published_tags, 'proposed' => (array) $proposed['tags'] );
	}

	// note_summary / alt_text / links have no single published counterpart
	// to diff against — omitted rather than fabricating a null comparison.
	return $diff;
}
