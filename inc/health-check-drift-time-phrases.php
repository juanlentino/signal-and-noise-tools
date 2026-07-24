<?php
/**
 * Signal & Noise Tools -- Content Health check: drift time phrases.
 *
 * Check 5: drifting time-relative phrases whose meaning decays. AI verdict via SNT_AI_DRIFT_SYSTEM; Suggest+Apply via inc/ai-drift-phrase-suggest.php.
 *
 * Split VERBATIM out of inc/health-checks.php in v9.81.0 (mirroring the
 * analytics-render-*.php split); every function name is unchanged. Loaded
 * by the inc/health-checks.php orchestrator, which owns the shared
 * constants and sn_health_pack_check().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pattern set for time-relative phrases that decay deterministically.
 *
 * Each regex captures the offending phrase as $matches[0]. Adding a new
 * pattern: append to the array (order doesn't matter for matching, only
 * for FIFO order in results — they get sorted by position downstream).
 *
 * Patterns are intentionally permissive — false positives are fine
 * because the AI evaluator (Task B) is the second filter. Missing a
 * real candidate is the more expensive failure mode.
 *
 * @since 3.7.0
 * @return array<string> regex patterns (Perl-compatible, case-insensitive enabled at call site).
 */
function sn_health_drift_time_patterns() {
	// Source-of-truth list. KEEP IN SYNC WITH the SQL REGEXP in
	// sn_health_check_drift_time_phrases() (which is a pre-filter that
	// must mirror these patterns).
	return array(
		'/\bas of \d{4}\b/i',
		'/\bthis (year|month|week)\b/i',
		'/\b(last|next) (year|month|week)\b/i',
		'/\bcurrently\b/i',
		'/\brecently\b/i',
		'/\bjust (released|launched|announced|shipped|published)\b/i',
		'/\bthe latest\b/i',
		'/\bnow (available|free|paid|in beta|in alpha)\b/i',
		'/\b(today|yesterday|tomorrow)\b/i',
	);
}

/**
 * Extract time-relative phrase candidates from post content.
 *
 * Returns an array of dicts: { phrase, context_snippet, position }.
 * - phrase: the matched substring as-is from the source
 * - context_snippet: ~200 chars around the phrase (for AI evaluation)
 * - position: byte offset in the post_content (sort stable)
 *
 * Strips shortcodes and HTML before scanning to avoid matching inside
 * attributes (e.g., href="...recently...").
 *
 * @since 3.7.0
 * @param string $content Raw post_content.
 * @return array
 */
function sn_health_extract_time_phrase_candidates( $content ) {
	$text = (string) $content;
	if ( '' === trim( $text ) ) {
		return array();
	}

	if ( function_exists( 'strip_shortcodes' ) ) {
		$text = strip_shortcodes( $text );
	}
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( $text );
	} else {
		$text = strip_tags( $text );
	}

	$out = array();
	foreach ( sn_health_drift_time_patterns() as $pattern ) {
		if ( preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$phrase  = $hit[0];
				$pos     = (int) $hit[1];
				$start   = max( 0, $pos - 80 );
				$len     = min( 200, strlen( $text ) - $start );
				$snippet = trim( substr( $text, $start, $len ) );
				$out[]   = array(
					'phrase'          => $phrase,
					'context_snippet' => $snippet,
					'position'        => $pos,
				);
			}
		}
	}

	usort( $out, function( $a, $b ) { return $a['position'] - $b['position']; } );

	return $out;
}

/**
 * Health check #5: time-relative drift detection.
 *
 * Hybrid algorithm: regex pre-filter eliminates posts with no candidate
 * phrases (free, fast); then a single AI call per remaining post evaluates
 * each candidate in context and returns a verdict (stale / ok / unsure).
 * Only "stale" verdicts become Health-tab findings.
 *
 * v3.7.0: detection only — findings deep-link to the editor.
 * v4.0.0: AI Suggest+Apply layer added (inc/ai-drift-phrase-suggest.php).
 * v4.1.1: raw-content position resolver — Apply now works for Gutenberg
 *         posts (B-01 fix; pre-v4.1.1 the apply step silently failed with
 *         409 on any post with block markup before the target phrase).
 *
 * Gracefully degrades when AI is unavailable (returns empty findings,
 * doesn't crash the scan).
 *
 * @since 3.7.0
 * @return array { count, findings, label, fix_hint }
 */
function sn_health_check_drift_time_phrases() {
	$label    = 'Time-relative drift';
	$fix_hint = 'Open each post and replace dated phrasing with absolute references (years, dates) or remove time-relative language entirely.';

	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return sn_health_pack_check( $label, array(), 'AI provider not configured — skipping drift detection. Configure Settings → Connectors + Settings → AI to enable.' );
	}

	global $wpdb;
	// KEEP IN SYNC WITH sn_health_drift_time_patterns().
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '(as of [0-9]{4}|this (year|month|week)|currently|recently|just (released|launched|announced|shipped|published)|the latest|now (available|free|paid|in beta|in alpha)|today|yesterday|tomorrow|last (year|month|week)|next (year|month|week))'
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$findings = array();
	foreach ( $rows as $r ) {
		$candidates = sn_health_extract_time_phrase_candidates( (string) $r['post_content'] );
		if ( count( $candidates ) > SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST ) {
			// v4.1.1 (B-10): cap is the named constant SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST
			// (defined at file scope) so a max_tokens budget tweak only needs the
			// constant value changed, not a grep-and-replace for the literal 25.
			$candidates = array_slice( $candidates, 0, SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST );
		}
		if ( empty( $candidates ) ) {
			continue;  // Regex pre-filter — no AI call needed.
		}

		// Build a compact AI prompt with candidates + post metadata.
		$payload = array(
			'post_id'       => (int) $r['ID'],
			'last_modified' => substr( (string) $r['post_modified_gmt'], 0, 10 ),
			'now'           => gmdate( 'Y-m-d' ),
			'candidates'    => array_map( function( $c ) {
				return array(
					'phrase'  => $c['phrase'],
					'context' => $c['context_snippet'],
				);
			}, $candidates ),
		);
		$prompt = wp_json_encode( $payload );
		if ( false === $prompt ) { continue; }

		// v4.0.1: cache AI verdicts per (post_id, post_modified, prompt_version).
		// Verdicts are deterministic from (post_content, post_modified_gmt, system_prompt),
		// so unchanged posts skip the AI call on subsequent Run scans.
		$cache_key      = 'sn_drift_verdicts_' . (int) $r['ID'];
		$post_modified  = (string) $r['post_modified_gmt'];
		$prompt_version = md5( SNT_AI_DRIFT_SYSTEM );
		$cached         = get_transient( $cache_key );

		if ( is_array( $cached )
			&& isset( $cached['post_modified'], $cached['prompt_version'], $cached['verdicts'] )
			&& $cached['post_modified']  === $post_modified
			&& $cached['prompt_version'] === $prompt_version ) {
			$verdicts = $cached['verdicts'];
		} else {
			$raw = snt_ai_generate_with_constraints( $prompt, SNT_AI_DRIFT_SYSTEM, 600, 'drift_detect' );
			if ( is_wp_error( $raw ) || ! is_string( $raw ) ) {
				continue;  // Soft fail — skip this post.
			}

			// Strip optional markdown fences (opener and/or closer, independently).
			$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw ) ) );
			$verdicts = json_decode( $text, true );
			if ( ! is_array( $verdicts ) ) {
				continue;  // Malformed — skip this post.
			}

			set_transient( $cache_key, array(
				'post_modified'  => $post_modified,
				'prompt_version' => $prompt_version,
				'verdicts'       => $verdicts,
			), 30 * DAY_IN_SECONDS );
		}

		foreach ( $verdicts as $v ) {
			if ( ! is_array( $v ) ) { continue; }
			if ( ( $v['verdict'] ?? '' ) !== 'stale' ) { continue; }
			$phrase = isset( $v['phrase'] ) ? (string) $v['phrase'] : '';
			$reason = isset( $v['reason'] ) ? (string) $v['reason'] : '';
			if ( '' === $phrase ) { continue; }

			// Look up the candidate's position + context_snippet for this phrase.
			// The $candidates array (built before the AI call) has them; find by phrase match.
			$position = 0;
			$context  = '';
			foreach ( $candidates as $cand ) {
				if ( $cand['phrase'] === $phrase ) {
					$position = (int) $cand['position'];
					$context  = (string) $cand['context_snippet'];
					break;
				}
			}

			$findings[] = array(
				'subject_type'    => 'post',
				'subject_id'      => (int) $r['ID'],
				'subject_url'     => get_permalink( (int) $r['ID'] ),
				'subject_label'   => (string) $r['post_title'],
				'edit_url'        => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				'note'            => sprintf( '"%s" — %s', $phrase, $reason ),
				'phrase'          => $phrase,
				'position'        => $position,
				'context_snippet' => $context,
			);
		}
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
