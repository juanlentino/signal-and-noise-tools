<?php
/**
 * Signal & Noise Tools — AI-assisted semantic-pair linking (v8.1.0).
 *
 * Suggest impl for the link_opportunities Health check
 * (inc/health-link-opportunities.php). Unlike ai-link-suggest there is no
 * mention to locate: the AI judges whether the source should link to the
 * target at all AND nominates an anchor phrase — which MUST already exist
 * verbatim in the source prose. The impl (never the AI) validates the
 * nomination against CURRENT content; a fabricated / markup-split /
 * out-of-bounds anchor degrades to advice-only (verdict + reason stand,
 * can_apply=false, the UI offers the edit link instead of Apply). The AI
 * can only point at prose that already exists — it never writes new text.
 *
 * Apply is the EXISTING signal-noise/ai-link-apply ability, unchanged:
 * nothing in the splice contract (locate, fingerprint 409, inside-<a> 400,
 * same-host 422) is mention-specific.
 *
 * Verdict + nomination cache in a transient keyed
 * md5(source|target|source_modified|target_modified) — BOTH stamps, because
 * a semantic verdict depends on both contents (the mentions check needed
 * only the source's). Positions and fingerprints are always recomputed
 * from CURRENT content, never cached.
 *
 * Ability: signal-noise/ai-pair-suggest (inc/abilities-ai-health.php).
 * Surface convention follows inc/ai-link-suggest.php.
 *
 * @package SignalNoiseTools
 * @since 8.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Paired with the check's design split: candidates are zero-AI
// (health-link-opportunities.php); this prompt judges one pair and
// nominates the anchor. The nomination is a POINTER into existing prose,
// never new text — the impl below re-validates it before Apply can run.
const SNT_AI_PAIR_SUGGEST_SYSTEM = "You are an editor deciding whether one note (the source) should link to another note (the target) on the same personal site. The two notes cover related subjects but neither mentions the other's title.\n\n" .
	"Input JSON: { source_title, target_title, source_excerpt, target_excerpt, shared_tags } — excerpts are the opening prose of each note.\n\n" .
	"Return ONLY a JSON object: {\"verdict\": \"link\" | \"skip\" | \"unsure\", \"reason\": \"<one sentence>\", \"anchor\": \"<phrase copied verbatim from source_excerpt, or empty>\"}\n\n" .
	"Rules:\n" .
	"- \"link\" only when the source genuinely discusses the target's subject and a link would help the reader.\n" .
	"- \"skip\" when the overlap is superficial (shared vocabulary, not shared subject).\n" .
	"- \"unsure\" when the excerpts are too thin to judge.\n" .
	"- anchor: on \"link\", copy a short phrase (2-8 words) EXACTLY as it appears in source_excerpt where the link belongs — same casing, same punctuation. Never invent or paraphrase. If no phrase can be copied verbatim, do not use \"link\" — return \"skip\" instead.\n" .
	"- Output JSON only. No markdown, no preamble.";

// v8.1.1: 200 → 300; v8.4.1: 300 → 500. The three-field response kept
// truncating live at 300 (one pair failed EVERY retry — the persistent
// "unparseable verdict"). The parser now salvages truncated responses too,
// but headroom keeps reason + anchor intact in the first place.
const SNT_AI_PAIR_SUGGEST_MAX_TOKENS   = 500;
const SNT_AI_PAIR_SOURCE_EXCERPT_CHARS = 1200;
const SNT_AI_PAIR_TARGET_EXCERPT_CHARS = 600;
const SNT_AI_PAIR_ANCHOR_MIN_LENGTH    = 3;

/**
 * Pure impl: AI verdict + validated splice coordinates for one semantic pair.
 *
 * @param int $source_id Post that would carry the link (the newer note).
 * @param int $target_id Post being linked to.
 * @return array{ok:bool,verdict:string,reason:string,anchor:string,can_apply:bool,position:int,context_snippet:string,fingerprint:string,post_id:int,target_id:int,target_url:string}|WP_Error
 *
 * WP_Error codes (reuses the ai-link table where semantics match):
 *   snt_ai_unavailable         (gate)
 *   snt_ai_link_invalid        (422) — source == target
 *   snt_ai_post_not_found      (404) — source or target missing / target unpublished
 *   snt_ai_link_already_linked (409) — source already links the target (stale finding)
 *   snt_ai_runtime_error       (500) — payload encode / unparseable AI verdict
 *
 * @since 8.1.0
 */
function snt_ai_pair_suggest_impl( $source_id, $target_id ) {
	$gate = snt_ai_require_text_generation();
	if ( $gate ) {
		return $gate;
	}

	$source_id = (int) $source_id;
	$target_id = (int) $target_id;
	if ( $source_id === $target_id ) {
		return new WP_Error( 'snt_ai_link_invalid', __( 'Source and target are the same post.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$source = get_post( $source_id );
	$target = get_post( $target_id );
	if ( ! $source || ! $target || 'publish' !== $target->post_status ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Source or target post not found (target must be published).', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$raw = (string) $source->post_content;

	// Stale-finding guard: the link may have been added since the scan.
	if ( sn_health_contains_note_link( $raw, (string) $target->post_name ) ) {
		return new WP_Error( 'snt_ai_link_already_linked', __( 'The source already links to this note. Re-run the scan to refresh.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$stripped = wp_strip_all_tags( strip_shortcodes( $raw ) );

	// Verdict memory: ID-keyed row, BOTH modified stamps compared in the
	// payload — a semantic verdict depends on both contents; either post's
	// edit = stamp mismatch = cache miss. v8.4.5 moved the stamps out of the
	// key: stamp-keyed rows orphaned on every Apply (wp_update_post bumps
	// post_modified), resurrecting judged siblings. Durable store since
	// v8.4.1 (transients died on every purge).
	$cached = snt_ai_verdict_lookup_pair( $source_id, $target_id, (string) $source->post_modified_gmt, (string) $target->post_modified_gmt );
	if ( is_array( $cached ) && isset( $cached['verdict'], $cached['reason'], $cached['anchor'] ) ) {
		$verdict    = (string) $cached['verdict'];
		$reason     = (string) $cached['reason'];
		$nomination = (string) $cached['anchor'];
	} else {
		$payload = array(
			'source_title'   => (string) $source->post_title,
			'target_title'   => (string) $target->post_title,
			'source_excerpt' => substr( $stripped, 0, SNT_AI_PAIR_SOURCE_EXCERPT_CHARS ),
			'target_excerpt' => substr( wp_strip_all_tags( strip_shortcodes( (string) $target->post_content ) ), 0, SNT_AI_PAIR_TARGET_EXCERPT_CHARS ),
			'shared_tags'    => snt_ai_pair_shared_tag_names( $source_id, $target_id ),
		);
		$prompt  = wp_json_encode( $payload );
		if ( false === $prompt ) {
			return new WP_Error( 'snt_ai_runtime_error', __( 'Failed to encode AI payload.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
		}

		$result = snt_ai_generate_with_constraints( $prompt, SNT_AI_PAIR_SUGGEST_SYSTEM, SNT_AI_PAIR_SUGGEST_MAX_TOKENS, 'pair_suggest' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = snt_ai_parse_verdict_json( (string) $result );
		if ( ! is_array( $parsed ) || ! isset( $parsed['verdict'] ) ) {
			return new WP_Error( 'snt_ai_runtime_error', __( 'AI returned an unparseable verdict.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
		}
		$verdict    = in_array( (string) $parsed['verdict'], array( 'link', 'skip', 'unsure' ), true ) ? (string) $parsed['verdict'] : 'unsure';
		$reason     = (string) ( $parsed['reason'] ?? '' );
		$nomination = (string) ( $parsed['anchor'] ?? '' );

		snt_ai_verdict_store_set( snt_ai_pair_verdict_key( $source_id, $target_id ), array(
			'verdict' => $verdict,
			'reason'  => $reason,
			'anchor'  => $nomination,
			'src_id'  => $source_id,
			'tgt_id'  => $target_id,
			'src_mod' => (string) $source->post_modified_gmt,
			'tgt_mod' => (string) $target->post_modified_gmt,
		) );
	}

	// Validate the nomination against CURRENT content. The impl, never the
	// AI, decides applicability; any failure degrades to advice-only.
	$contract = ( 'link' === $verdict ) ? snt_ai_pair_nomination_contract( $raw, $stripped, $nomination ) : null;

	return array(
		'ok'              => true,
		'verdict'         => $verdict,
		'reason'          => $reason,
		'anchor'          => $contract ? $contract['anchor'] : '',
		'can_apply'       => null !== $contract,
		'position'        => $contract ? $contract['position'] : -1,
		'context_snippet' => $contract ? $contract['context_snippet'] : '',
		'fingerprint'     => $contract ? $contract['fingerprint'] : '',
		'post_id'         => $source_id,
		'target_id'       => $target_id,
		'target_url'      => (string) get_permalink( $target ),
	);
}

/**
 * Validate an anchor nomination against CURRENT content and build the
 * splice contract.
 *
 * v8.1.2: extracted from the impl so the SCAN can reuse it — a cached
 * "link" judgment whose nomination no longer validates is advice-only,
 * and advice-only is noise per the owner rule (2026-07-02); the check
 * suppresses such pairs instead of re-nominating them.
 *
 * The chain mirrors apply's own guards: length bounds, present in
 * stripped prose (the phrase AS IT APPEARS — casing may differ from the
 * nomination), contiguous in raw content (drift v4.1.1 lesson), and NOT
 * inside an existing <a> (the v8.1.1 live incident: the AI judges
 * stripped prose, so existing links are invisible to it).
 *
 * @param string $raw        Raw post_content.
 * @param string $stripped   wp_strip_all_tags'd + shortcode-stripped prose.
 * @param string $nomination The AI's anchor nomination.
 * @return array{anchor:string,position:int,context_snippet:string,fingerprint:string}|null
 *         The splice contract, or null when the nomination is unusable.
 *
 * @since 8.1.2
 */
function snt_ai_pair_nomination_contract( $raw, $stripped, $nomination ) {
	$raw        = (string) $raw;
	$stripped   = (string) $stripped;
	$nomination = (string) $nomination;
	if ( '' === $nomination
		|| strlen( $nomination ) < SNT_AI_PAIR_ANCHOR_MIN_LENGTH
		|| strlen( $nomination ) > SNT_AI_LINK_ANCHOR_MAX_LENGTH ) {
		return null;
	}
	$pos = stripos( $stripped, $nomination );
	if ( false === $pos ) {
		return null;
	}
	$mention = substr( $stripped, $pos, strlen( $nomination ) );
	$start   = max( 0, $pos - 80 );
	$ctx     = trim( substr( $stripped, $start, 200 ) );
	$raw_pos = snt_ai_drift_locate_in_raw( $raw, $mention, $ctx );
	if ( -1 === $raw_pos || snt_ai_link_position_inside_anchor( $raw, $raw_pos ) ) {
		return null;
	}
	return array(
		'anchor'          => $mention,
		'position'        => $raw_pos,
		'context_snippet' => $ctx,
		'fingerprint'     => snt_ai_drift_fingerprint( $raw, $mention, $raw_pos ),
	);
}

/**
 * Names of post_tag terms two posts share (payload context for the AI).
 *
 * @param int $a Post ID.
 * @param int $b Post ID.
 * @return string[]
 *
 * @since 8.1.0
 */
function snt_ai_pair_shared_tag_names( $a, $b ) {
	if ( ! function_exists( 'wp_get_post_terms' ) ) {
		return array();
	}
	$ta = wp_get_post_terms( (int) $a, 'post_tag' );
	$tb = wp_get_post_terms( (int) $b, 'post_tag' );
	if ( ! is_array( $ta ) || ! is_array( $tb ) ) {
		return array();
	}
	$ids_b = array();
	foreach ( $tb as $t ) {
		if ( is_object( $t ) && isset( $t->term_id ) ) {
			$ids_b[ (int) $t->term_id ] = true;
		}
	}
	$names = array();
	foreach ( $ta as $t ) {
		if ( is_object( $t ) && isset( $t->term_id, $t->name ) && isset( $ids_b[ (int) $t->term_id ] ) ) {
			$names[] = (string) $t->name;
		}
	}
	return $names;
}
