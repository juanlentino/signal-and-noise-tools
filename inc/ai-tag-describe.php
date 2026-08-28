<?php
/**
 * AI tag-description generator: draft the one-sentence description for
 * undescribed tags, in the house register.
 *
 * v13.25.0, the generative sibling of the v13.23.0 seed and the v13.24.0
 * tag_hygiene check: the seed described the 23 tags that existed, the check
 * keeps new undescribed tags visible, and this drafts the missing sentence
 * when one appears. The VOICE REFERENCE is the seed map itself —
 * sn_tag_description_seed_map()'s owner-approved sentences are the few-shot
 * examples, so the prompt's register is pinned to prose the owner already
 * signed off on rather than to an adjective list.
 *
 * Suggest and apply are SEPARATE (the alt-text pair's shape): the suggest
 * impl is AI-billed and returns-only; the apply impl writes ONE description
 * and only where the term's description is still empty — the same
 * never-clobber rule as the seed, so an owner edit always wins the race.
 *
 * Spend: every call carries feature 'tag_describe', so it lands as its own
 * row in the per-feature monthly itemization (v13.21.0).
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bounded per run: each undescribed tag is one AI call, so a runaway
// vocabulary cannot turn one ability call into an unbounded spend.
const SN_AI_TAG_DESCRIBE_MAX_PER_RUN = 10;
const SN_AI_TAG_DESCRIBE_MAX_TOKENS  = 160;
// Few-shot budget: enough approved sentences to pin the register without
// paying for all 23 on every call.
const SN_AI_TAG_DESCRIBE_SHOTS = 6;
// Post-title context per tag: what the tag actually collects.
const SN_AI_TAG_DESCRIBE_TITLES = 5;

/**
 * Draft descriptions for undescribed, in-use tags.
 *
 * @param array $names Tag NAMES to draft for. Empty = every undescribed
 *                     in-use tag (zero-post tags are excluded on purpose:
 *                     their fix is pruning, not describing — the
 *                     tag_hygiene report-once rule, applied here too).
 * @return array|WP_Error { ok, suggested:[{name,description}], skipped:[{name,reason}] } or WP_Error.
 */
function snt_ai_tag_describe_impl( $names = array() ) {
	$gate = function_exists( 'snt_ai_require_text_generation' ) ? snt_ai_require_text_generation() : null;
	if ( is_wp_error( $gate ) ) {
		return $gate;
	}

	$targets = snt_ai_tag_describe_targets( is_array( $names ) ? $names : array() );
	if ( is_wp_error( $targets ) ) {
		return $targets;
	}

	$suggested = array();
	$skipped   = $targets['skipped'];
	$shots     = snt_ai_tag_describe_shots();

	foreach ( array_slice( $targets['terms'], 0, SN_AI_TAG_DESCRIBE_MAX_PER_RUN ) as $term ) {
		$prompt = snt_ai_tag_describe_prompt( $term, $shots );
		$text   = snt_ai_generate_with_constraints(
			$prompt,
			'You write one-sentence tag descriptions for a personal site about music provenance, in the site owner\'s voice. The sentence doubles as the tag archive\'s hero dek and the page\'s meta description. Match the register of the examples exactly: declarative, concrete, often an em-dash turn; no filler, no "This tag", no "Explore", under 170 characters. Return ONLY the sentence.',
			SN_AI_TAG_DESCRIBE_MAX_TOKENS,
			'tag_describe'
		);
		if ( is_wp_error( $text ) ) {
			// One tag failing must not void the batch already drafted; the
			// caller sees exactly which tag failed and why.
			$skipped[] = array(
				'name'   => (string) $term->name,
				'reason' => 'generation_failed: ' . $text->get_error_message(),
			);
			continue;
		}
		$sentence = trim( wp_strip_all_tags( (string) $text ) );
		$sentence = trim( $sentence, "\"' \t\n" );
		if ( '' === $sentence ) {
			$skipped[] = array(
				'name'   => (string) $term->name,
				'reason' => 'empty_generation',
			);
			continue;
		}
		$suggested[] = array(
			'name'        => (string) $term->name,
			'description' => $sentence,
		);
	}

	if ( count( $targets['terms'] ) > SN_AI_TAG_DESCRIBE_MAX_PER_RUN ) {
		foreach ( array_slice( $targets['terms'], SN_AI_TAG_DESCRIBE_MAX_PER_RUN ) as $term ) {
			$skipped[] = array(
				'name'   => (string) $term->name,
				'reason' => 'over_per_run_cap',
			);
		}
	}

	return array(
		'ok'        => true,
		'suggested' => $suggested,
		'skipped'   => $skipped,
	);
}

/**
 * Resolve the target term list: named tags, or every undescribed in-use tag.
 *
 * @param array $names Requested tag names (may be empty).
 * @return array|WP_Error { terms: WP_Term-like[], skipped: [{name,reason}] }.
 */
function snt_ai_tag_describe_targets( $names ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return new WP_Error( 'snt_tag_describe_terms_failed', 'The tag vocabulary could not be read.', array( 'status' => 500 ) );
	}

	$by_name = array();
	foreach ( $terms as $term ) {
		if ( is_object( $term ) && isset( $term->name ) ) {
			$by_name[ (string) $term->name ] = $term;
		}
	}

	$skipped = array();
	$out     = array();

	if ( array() !== $names ) {
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( ! isset( $by_name[ $name ] ) ) {
				$skipped[] = array(
					'name'   => $name,
					'reason' => 'not_found',
				);
				continue;
			}
			$term = $by_name[ $name ];
			if ( '' !== trim( (string) ( $term->description ?? '' ) ) ) {
				$skipped[] = array(
					'name'   => $name,
					'reason' => 'already_described',
				);
				continue;
			}
			if ( 0 === (int) ( $term->count ?? 0 ) ) {
				$skipped[] = array(
					'name'   => $name,
					'reason' => 'unused_prune_instead',
				);
				continue;
			}
			$out[] = $term;
		}
		return array(
			'terms'   => $out,
			'skipped' => $skipped,
		);
	}

	foreach ( $by_name as $term ) {
		if ( 0 === (int) ( $term->count ?? 0 ) ) {
			continue; // Prune candidates never earn a sentence.
		}
		if ( '' === trim( (string) ( $term->description ?? '' ) ) ) {
			$out[] = $term;
		}
	}
	return array(
		'terms'   => $out,
		'skipped' => $skipped,
	);
}

/**
 * The few-shot examples: a slice of the owner-approved seed sentences.
 *
 * @return array<string,string> tag name => sentence (possibly empty).
 */
function snt_ai_tag_describe_shots() {
	if ( ! function_exists( 'sn_tag_description_seed_map' ) ) {
		return array();
	}
	return array_slice( sn_tag_description_seed_map(), 0, SN_AI_TAG_DESCRIBE_SHOTS, true );
}

/**
 * Build one tag's prompt: the approved examples, then the tag and what it
 * actually collects (up to five post titles).
 *
 * @param object $term  Term-like object (name, term_id).
 * @param array  $shots name => sentence examples.
 * @return string
 */
function snt_ai_tag_describe_prompt( $term, $shots ) {
	$lines = array( 'Approved examples (tag -> its one-sentence description):' );
	foreach ( $shots as $shot_name => $sentence ) {
		$lines[] = $shot_name . ' -> ' . $sentence;
	}

	$titles = array();
	if ( function_exists( 'get_posts' ) && isset( $term->term_id ) ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => SN_AI_TAG_DESCRIBE_TITLES,
				'tag_id'         => (int) $term->term_id,
			)
		);
		if ( is_array( $posts ) ) {
			foreach ( $posts as $p ) {
				if ( is_object( $p ) && ! empty( $p->post_title ) ) {
					$titles[] = (string) $p->post_title;
				}
			}
		}
	}

	$lines[] = '';
	$lines[] = 'Now write the description for this tag:';
	$lines[] = 'Tag: ' . (string) $term->name;
	if ( array() !== $titles ) {
		$lines[] = 'Notes under it: ' . implode( ' | ', $titles );
	}
	return implode( "\n", $lines );
}

/**
 * Write ONE tag description, only where the term's description is still
 * empty. The seed's never-clobber rule: an owner edit always wins.
 *
 * @param string $name        Tag name.
 * @param string $description The sentence to write.
 * @return array|WP_Error { ok, name, status: written|skipped_nonempty } or WP_Error.
 */
function snt_ai_tag_describe_apply_impl( $name, $description ) {
	$name        = (string) $name;
	$description = trim( (string) $description );
	if ( '' === $name || '' === $description ) {
		return new WP_Error( 'snt_tag_describe_bad_input', 'Both a tag name and a non-empty description are required.', array( 'status' => 400 ) );
	}

	$term = get_term_by( 'name', $name, 'post_tag' );
	if ( ! is_object( $term ) || empty( $term->term_id ) ) {
		return new WP_Error( 'snt_tag_describe_not_found', sprintf( 'Tag "%s" not found.', $name ), array( 'status' => 404 ) );
	}
	if ( '' !== trim( (string) ( $term->description ?? '' ) ) ) {
		return array(
			'ok'     => true,
			'name'   => $name,
			'status' => 'skipped_nonempty',
		);
	}

	$result = wp_update_term( (int) $term->term_id, 'post_tag', array( 'description' => $description ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'ok'     => true,
		'name'   => $name,
		'status' => 'written',
	);
}
