<?php
/**
 * Signal & Noise Tools — AI-assisted unlinked-mention linking (v7.4.0).
 *
 * Suggest+Apply impls for the unlinked_mentions Health check
 * (sn_health_check_unlinked_mentions in inc/health-checks.php). Mirrors the
 * drift-phrase machinery exactly and REUSES its primitives:
 * snt_ai_drift_locate_in_raw() resolves the mention's RAW-content offset
 * (the v4.1.1 raw-vs-stripped lesson) and snt_ai_drift_fingerprint() gates
 * Apply against concurrent edits (409 on mismatch).
 *
 * Suggest is deliberately re-derived from (source_id, target_id) alone — it
 * never trusts scan-time data, so a stale finding self-heals into a 409
 * instead of a wrong splice. The AI verdict (link / skip / unsure) caches in
 * a transient keyed md5(source|target|source_post_modified); fingerprints
 * are always recomputed from CURRENT content, never cached.
 *
 * Abilities: signal-noise/ai-link-suggest + signal-noise/ai-link-apply
 * (inc/abilities-ai-health.php). Surface convention follows
 * inc/ai-drift-phrase-suggest.php.
 *
 * @package SignalNoiseTools
 * @since 7.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Paired with SNT_AI_DRIFT_SYSTEM's design split: detection is zero-AI
// (health-checks.php); this prompt only judges whether one located mention
// really refers to the target note.
const SNT_AI_LINK_SUGGEST_SYSTEM = "You are an editor deciding whether a mention of another note's title inside a post should become an internal link to that note.\n\n" .
	"Input JSON: { source_title, target_title, mention, context } — `mention` is the matched text inside the source post; `context` is ~200 chars around it.\n\n" .
	"Return ONLY a JSON object: {\"verdict\": \"link\" | \"skip\" | \"unsure\", \"reason\": \"<one sentence>\"}\n\n" .
	"Rules:\n" .
	"- \"link\" only when the mention clearly refers to the target note itself (its subject, or the essay by that name) and a link would help the reader.\n" .
	"- \"skip\" when the words merely coincide with the title (generic phrase), the mention sits inside quoted third-party text, or a link would read as noise.\n" .
	"- \"unsure\" when the context is too thin to judge.\n" .
	"- Output JSON only. No markdown, no preamble.";

const SNT_AI_LINK_SUGGEST_MAX_TOKENS = 120;
const SNT_AI_LINK_ANCHOR_MAX_LENGTH  = 300;

/**
 * Pure impl: AI verdict + splice coordinates for linking one unlinked mention.
 *
 * @param int $source_id Post that contains the mention.
 * @param int $target_id Post being mentioned.
 * @return array{ok:bool,verdict:string,reason:string,anchor:string,position:int,context_snippet:string,fingerprint:string,post_id:int,target_id:int,target_url:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_unavailable         (gate)
 *   snt_ai_post_not_found      (404) — source or target missing / target unpublished
 *   snt_ai_link_invalid        (422) — source == target
 *   snt_ai_link_already_linked (409) — source already links the target (stale finding)
 *   snt_ai_mention_drifted     (409) — mention no longer present
 *   snt_ai_runtime_error       (500) — payload encode / unparseable AI verdict
 *
 * @since 7.4.0
 */
function snt_ai_link_suggest_impl( $source_id, $target_id ) {
	$gate = snt_ai_require_text_generation();
	if ( $gate ) {
		return $gate;
	}

	$source_id = (int) $source_id;
	$target_id = (int) $target_id;
	if ( $source_id === $target_id ) {
		return new WP_Error( 'snt_ai_link_invalid', __( 'Source and target are the same post.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}

	$source = get_post( $source_id );
	$target = get_post( $target_id );
	if ( ! $source || ! $target || 'publish' !== $target->post_status ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Source or target post not found (target must be published).', 'signal-noise-tools' ), array( 'status' => 404 ) );
	}

	$raw = (string) $source->post_content;

	// Stale-finding guard: the link may have been added since the scan.
	if ( sn_health_contains_note_link( $raw, (string) $target->post_name ) ) {
		return new WP_Error( 'snt_ai_link_already_linked', __( 'The source already links to this note. Re-run the scan to refresh.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}

	// Re-derive the mention from CURRENT content (never trust scan data).
	$stripped = wp_strip_all_tags( strip_shortcodes( $raw ) );
	$title    = trim( (string) $target->post_title );
	$pos      = ( '' !== $title ) ? stripos( $stripped, $title ) : false;
	if ( false === $pos ) {
		return new WP_Error( 'snt_ai_mention_drifted', __( 'Mention no longer present in post content — post was edited since the scan. Re-run the scan to refresh.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}
	$mention = substr( $stripped, $pos, strlen( $title ) );
	$start   = max( 0, $pos - 80 );
	$context = trim( substr( $stripped, $start, 200 ) );

	// RAW-content offset: the stripped offset cannot be used against raw
	// content for any post with block markup (drift v4.1.1 lesson).
	$raw_position = snt_ai_drift_locate_in_raw( $raw, $mention, $context );
	if ( -1 === $raw_position ) {
		// Mention exists in prose but not contiguously in raw content (split
		// by inline markup) — Apply could not splice it safely.
		return new WP_Error( 'snt_ai_mention_drifted', __( 'Mention is split by inline markup — link it manually in the editor.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}

	// Verdict cache: (source, target, source-modified) triple. Content change
	// = new post_modified = new key, so fingerprints stay honest below.
	$cache_key = 'sn_link_verdict_' . md5( $source_id . '|' . $target_id . '|' . (string) $source->post_modified_gmt );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && isset( $cached['verdict'], $cached['reason'] ) ) {
		$verdict = (string) $cached['verdict'];
		$reason  = (string) $cached['reason'];
	} else {
		$payload = array(
			'source_title' => (string) $source->post_title,
			'target_title' => $title,
			'mention'      => $mention,
			'context'      => $context,
		);
		$prompt = wp_json_encode( $payload );
		if ( false === $prompt ) {
			return new WP_Error( 'snt_ai_runtime_error', __( 'Failed to encode AI payload.', 'signal-noise-tools' ), array( 'status' => 500 ) );
		}

		$result = snt_ai_generate_with_constraints( $prompt, SNT_AI_LINK_SUGGEST_SYSTEM, SNT_AI_LINK_SUGGEST_MAX_TOKENS );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Strip optional markdown fences (opener and/or closer, independently).
		$text   = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( (string) $result ) ) );
		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['verdict'] ) ) {
			return new WP_Error( 'snt_ai_runtime_error', __( 'AI returned an unparseable verdict.', 'signal-noise-tools' ), array( 'status' => 500 ) );
		}
		$verdict = in_array( (string) $parsed['verdict'], array( 'link', 'skip', 'unsure' ), true ) ? (string) $parsed['verdict'] : 'unsure';
		$reason  = (string) ( $parsed['reason'] ?? '' );

		set_transient( $cache_key, array( 'verdict' => $verdict, 'reason' => $reason ), 30 * DAY_IN_SECONDS );
	}

	return array(
		'ok'              => true,
		'verdict'         => $verdict,
		'reason'          => $reason,
		'anchor'          => $mention,
		'position'        => $raw_position,
		'context_snippet' => $context,
		'fingerprint'     => snt_ai_drift_fingerprint( $raw, $mention, $raw_position ),
		'post_id'         => $source_id,
		'target_id'       => $target_id,
		'target_url'      => (string) get_permalink( $target ),
	);
}

/**
 * Whether $position in $content sits inside an existing <a>…</a> element.
 *
 * Heuristic on the text BEFORE the position: the last '<a' tag-open (the
 * tag name must END right after — '<a' alone would also match <abbr>,
 * <aside>, <article>, <audio>) vs the last '</a>'. Open after close =
 * inside a link.
 *
 * @param string $content  Raw post_content.
 * @param int    $position Byte offset.
 * @return bool
 *
 * @since 7.4.0
 */
function snt_ai_link_position_inside_anchor( $content, $position ) {
	$before    = substr( (string) $content, 0, max( 0, (int) $position ) );
	$last_open = -1;
	if ( preg_match_all( '#<a[\s>]#i', $before, $m, PREG_OFFSET_CAPTURE ) ) {
		$hit       = end( $m[0] );
		$last_open = (int) $hit[1];
	}
	$close      = strripos( $before, '</a>' );
	$last_close = ( false === $close ) ? -1 : (int) $close;
	return $last_open > $last_close;
}

/**
 * Apply: wrap the mention in <a href="target_url">anchor</a>.
 *
 * Same contract as snt_ai_drift_apply_impl: raw position re-resolved via the
 * context snippet, fingerprint validated at the resolved position (409 on
 * mismatch), write via wp_update_post() with wp_error. Additional guards:
 * the anchor must not already sit inside an <a> (400), and target_url must
 * be same-host (422 — this feature only creates internal links).
 *
 * @param int    $post_id         Source post.
 * @param string $anchor          Mention text exactly as it appears in raw content.
 * @param string $context_snippet ~200 stripped chars around the mention (disambiguates occurrences).
 * @param string $fingerprint     md5 from the matching suggest call.
 * @param string $target_url      Permalink to link to (same-host required).
 * @return array{ok:bool,post_id:int,anchor:string,target_url:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_anchor_invalid      (422) — empty / over-long anchor
 *   snt_ai_link_target_invalid (422) — target_url not same-host
 *   snt_ai_capability          (403)
 *   snt_ai_post_not_found      (404)
 *   snt_ai_apply_conflict      (409) — anchor gone OR fingerprint mismatch
 *   snt_ai_link_already_linked (400) — anchor already inside an <a>
 *   snt_ai_write_failed        (500)
 *
 * @since 7.4.0
 */
function snt_ai_link_apply_impl( $post_id, $anchor, $context_snippet, $fingerprint, $target_url ) {
	$post_id         = (int) $post_id;
	$anchor          = (string) $anchor; // NOT trimmed — must match content bytes.
	$context_snippet = (string) $context_snippet;
	$fingerprint     = (string) $fingerprint;
	$target_url      = (string) $target_url;

	if ( '' === $anchor || strlen( $anchor ) > SNT_AI_LINK_ANCHOR_MAX_LENGTH ) {
		return new WP_Error( 'snt_ai_anchor_invalid', __( 'Anchor is empty or too long.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}

	$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
	$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $target_host || ! $site_host || strtolower( (string) $target_host ) !== strtolower( (string) $site_host ) ) {
		return new WP_Error( 'snt_ai_link_target_invalid', __( 'Target URL must be an internal permalink.', 'signal-noise-tools' ), array( 'status' => 422 ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot edit this post.', 'signal-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;

	$raw_position = snt_ai_drift_locate_in_raw( $current_content, $anchor, $context_snippet );
	if ( -1 === $raw_position ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Mention no longer present in post content. Re-run scan.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}

	$current_fp = snt_ai_drift_fingerprint( $current_content, $anchor, $raw_position );
	if ( $current_fp !== $fingerprint ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Post changed since suggest. Re-run scan to refresh.', 'signal-noise-tools' ), array( 'status' => 409 ) );
	}

	if ( snt_ai_link_position_inside_anchor( $current_content, $raw_position ) ) {
		return new WP_Error( 'snt_ai_link_already_linked', __( 'The mention already sits inside a link.', 'signal-noise-tools' ), array( 'status' => 400 ) );
	}

	$link        = '<a href="' . esc_url( $target_url ) . '">' . $anchor . '</a>';
	$new_content = substr_replace( $current_content, $link, $raw_position, strlen( $anchor ) );

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => $new_content,
	), true );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'snt_ai_write_failed', sprintf( __( 'wp_update_post failed: %s', 'signal-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
	}

	return array(
		'ok'         => true,
		'post_id'    => $post_id,
		'anchor'     => $anchor,
		'target_url' => $target_url,
	);
}
