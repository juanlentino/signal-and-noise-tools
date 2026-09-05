<?php
/**
 * Signal & Noise Tools -- Content Health check: unlinked mentions.
 *
 * Check 7 (v7.4.0): unlinked mentions -- a note mentions another note title without linking it. Zero-AI at scan time; AI Suggest+Apply via inc/ai-link-suggest.php.
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
 * Target eligibility for the unlinked-mentions check. Titles under 12
 * characters or under 2 words false-positive on substring matching
 * ("Now", "Craft" appear in ordinary prose constantly).
 *
 * @param string $title Target post title.
 * @return bool
 *
 * @since 7.4.0
 */
function sn_health_mention_target_eligible( $title ) {
	$title = trim( (string) $title );
	if ( strlen( $title ) < 12 ) {
		return false;
	}
	$words = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $words ) && count( $words ) >= 2;
}

/**
 * Whether $content already links to the note at /notes/$post_name.
 *
 * Boundary-aware: a bare stripos would treat '/notes/craft-two' as a link
 * to 'craft' and silently suppress a real finding. After the slug we
 * require a path / quote / query / fragment terminator or end-of-string.
 * The suggest impl (inc/ai-link-suggest.php) and the theme's cited-by
 * query use the same boundary so the two surfaces never disagree.
 *
 * @param string $content   Raw post_content.
 * @param string $post_name Target slug.
 * @return bool
 *
 * @since 7.4.0
 */
function sn_health_contains_note_link( $content, $post_name ) {
	$post_name = (string) $post_name;
	if ( '' === $post_name ) {
		return false;
	}
	return (bool) preg_match(
		'#/notes/' . preg_quote( $post_name, '#' ) . '(?=[/"\'?\#]|$)#i',
		(string) $content
	);
}

/**
 * Zero-AI unlinked-mentions check (v7.4.0): published notes whose PROSE
 * mentions another note's title without linking to /notes/<post_name>.
 * One finding per (source, target) pair, capped at
 * SN_HEALTH_MENTIONS_MAX_PER_SOURCE pairs per source. AI enters only on
 * the Suggest click (inc/ai-link-suggest.php), never at scan time.
 *
 * The pairwise pass is quadratic over the LIMIT-500 corpus but each
 * source's content is stripped once; the real site is a few dozen notes.
 *
 * @return array Packed check (sn_health_pack_check shape).
 */
function sn_health_check_unlinked_mentions() {
	$label    = 'Unlinked mentions';
	$fix_hint = 'The note mentions another note\'s title without linking to it. Suggest asks AI whether the mention really refers to that note; Apply wraps the mention in a link.';

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_name, post_content, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type = 'post'
		 ORDER BY post_date DESC
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The post query failed, so nothing was scanned. The check retries on the next scan.' );
	}
	if ( count( $rows ) < 2 ) {
		return sn_health_pack_check( $label, array(), $fix_hint, null ); // Fewer than two posts: nothing to cross-link, and that IS an answer.
	}

	$findings = array();
	foreach ( $rows as $source ) {
		// Strip once per source: mentions live in prose — a title inside an
		// href or attribute is markup, not a mention (same rationale as the
		// drift extractor's strip pass).
		$stripped = wp_strip_all_tags( strip_shortcodes( (string) $source['post_content'] ) );
		if ( '' === trim( $stripped ) ) {
			continue;
		}
		$pairs = 0;
		foreach ( $rows as $target ) {
			if ( $pairs >= SN_HEALTH_MENTIONS_MAX_PER_SOURCE ) {
				break;
			}
			if ( (int) $source['ID'] === (int) $target['ID'] ) {
				continue;
			}
			$title = trim( (string) $target['post_title'] );
			if ( ! sn_health_mention_target_eligible( $title ) ) {
				continue;
			}
			$pos = stripos( $stripped, $title );
			if ( false === $pos ) {
				continue;
			}
			if ( sn_health_contains_note_link( (string) $source['post_content'], (string) $target['post_name'] ) ) {
				continue;
			}
			// The mention AS IT APPEARS in the prose (case may differ from the
			// title). Suggest/Apply locate THIS exact string in raw content via
			// snt_ai_drift_locate_in_raw(), so casing must be the content's.
			$mention = substr( $stripped, $pos, strlen( $title ) );
			$start   = max( 0, $pos - 80 );
			$context = trim( substr( $stripped, $start, 200 ) );

			// v8.1.2 owner rule: non-actionable pairs are NOISE, not findings.
			// (a) STRUCTURAL: a mention that cannot be spliced — split by
			// inline markup, or sitting inside an existing <a> to a third
			// note — can only ever produce an advice-only panel. Suppress it
			// without spending an AI call.
			if ( function_exists( 'snt_ai_drift_locate_in_raw' ) && function_exists( 'snt_ai_link_position_inside_anchor' ) ) {
				$raw_pos = snt_ai_drift_locate_in_raw( (string) $source['post_content'], $mention, $context );
				if ( -1 === $raw_pos || snt_ai_link_position_inside_anchor( (string) $source['post_content'], $raw_pos ) ) {
					continue;
				}
			}
			// (b) JUDGED: the Suggest verdict store doubles as the scan's
			// memory — a stored skip/unsure means the AI already said no.
			// The stored stamp gates suppression, so an edit re-nominates
			// naturally. v8.4.1: DURABLE store (autoload=no option), not
			// transients — the v10.22.0 auto-purges flush transients on
			// every update, which resurrected judged pairs.
			// v8.4.4: a judged pair KEEPS its cap slot (renders nothing) —
			// suppression before the cap freed slots and every re-scan
			// promoted the next eligible target, so Suggest All never
			// converged (same treadmill as link_opportunities).
			// v8.4.5: ID-keyed lookup, stamp in the payload — Apply restamps
			// its post's rows so judged siblings survive our own splice.
			if ( function_exists( 'snt_ai_verdict_lookup_link' ) ) {
				$judged = snt_ai_verdict_lookup_link( (int) $source['ID'], (int) $target['ID'], (string) ( $source['post_modified_gmt'] ?? '' ) );
				if ( is_array( $judged ) && isset( $judged['verdict'] ) && 'link' !== (string) $judged['verdict'] ) {
					$pairs++;
					continue;
				}
			}

			$findings[] = array(
				'subject_type'    => 'post',
				'subject_id'      => (int) $source['ID'],
				'subject_url'     => (string) get_permalink( (int) $source['ID'] ),
				'subject_label'   => (string) $source['post_title'],
				'edit_url'        => admin_url( 'post.php?post=' . (int) $source['ID'] . '&action=edit' ),
				'note'            => sprintf( 'Mentions "%s" without linking to /notes/%s.', $title, (string) $target['post_name'] ),
				'target_id'       => (int) $target['ID'],
				'target_title'    => $title,
				'mention'         => $mention,
				'context_snippet' => $context,
			);
			$pairs++;
		}
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
