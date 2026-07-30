<?php
/**
 * Signal & Noise Tools — Health check: link opportunities.
 *
 * C2 semantic pairing (approach C of the interlinking arc): pairs of
 * published notes that SHOULD link to each other but don't — no link in
 * either direction AND no literal title mention. Title-mention pairs stay
 * with unlinked_mentions (inc/health-checks.php); the skip rule below
 * mirrors that check's exact trigger so the two checks PARTITION the pair
 * space — one pair can never double-report.
 *
 * v10.23.0 RE-BASE: the candidate pass now reads the ML kernel's stored
 * related artifact (snt_ml_related_for_post — blended TF-IDF cosine + tag
 * overlap + link graph, precomputed at build time and mutation-tested)
 * instead of the v8.1.0 homegrown scorer this file used to carry (its own
 * stopword list, tokenizer, lightweight TF-IDF, and tag-weight arithmetic —
 * a pre-kernel proto-kernel, deleted). While the artifact is unbuilt the
 * advisory stays QUIET (it builds on the next publish or overnight); a
 * post the artifact does not index simply nominates nothing.
 *
 * Zero-AI at scan time, unchanged: AI enters only on the Suggest click
 * (inc/ai-pair-suggest.php). Everything the check earned across
 * v8.1.2..v8.4.5 is preserved verbatim: the judged-noise suppression, the
 * per-source cap with judged-pairs-consume-slots, ID-keyed verdicts that
 * survive Apply, and legacy-verdict migration.
 *
 * ADVISORY tier (sn_health_advisory_checks): candidates are opportunities,
 * not rot — they surface as "N advisories" and never touch finding_total.
 *
 * @package SignalNoiseTools
 * @since 8.1.0 (kernel re-base 10.23.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The one surviving candidate-pass knob: how many rendered pairs each
// source may hold (judged pairs consume slots — the v8.4.4 convergence rule).
define( 'SN_HEALTH_PAIRS_MAX_PER_SOURCE', 3 );

/**
 * Whether a candidate pair has already been JUDGED non-actionable.
 *
 * v8.1.2 (owner rule 2026-07-02: "the ones that don't suggest anything are
 * noise"): the Suggest verdict store doubles as the scan's memory. A stored
 * skip/unsure means the AI already said no; a stored link whose nomination
 * no longer yields a usable splice contract is advice-only — both suppress
 * the pair. No entry (never judged, or either post edited since — the key
 * carries BOTH modified stamps) keeps the pair nominated. v8.4.1: the
 * memory is the DURABLE snt_ai_verdict_store (autoload=no option) — it was
 * transients, and the v10.22.0 auto-purges flush those on every update,
 * which resurrected judged pairs after every release (the owner-reported
 * "persistent entries").
 *
 * @param array $src Source row (ID, post_content, post_modified_gmt).
 * @param array $tgt Target row (ID, post_modified_gmt).
 * @return bool True when the pair should be suppressed.
 *
 * @since 8.1.2
 */
function sn_health_pair_judged_noise( $src, $tgt ) {
	if ( ! function_exists( 'snt_ai_verdict_lookup_pair' ) ) {
		return false;
	}
	// v8.4.5: ID-keyed lookup with the stamps compared in the payload (the
	// lookup also reads + migrates pre-v8.4.5 stamp-keyed rows). Apply
	// restamps its post's rows, so judged siblings survive our own splice;
	// a real edit still re-nominates.
	$cached = snt_ai_verdict_lookup_pair(
		(int) $src['ID'],
		(int) $tgt['ID'],
		(string) ( $src['post_modified_gmt'] ?? '' ),
		(string) ( $tgt['post_modified_gmt'] ?? '' )
	);
	if ( ! is_array( $cached ) || ! isset( $cached['verdict'] ) ) {
		return false;
	}
	if ( 'link' !== (string) $cached['verdict'] ) {
		return true; // Judged skip/unsure: nothing to apply.
	}
	if ( ! function_exists( 'snt_ai_pair_nomination_contract' ) ) {
		return false;
	}
	$raw      = (string) $src['post_content'];
	$stripped = wp_strip_all_tags( strip_shortcodes( $raw ) );
	return null === snt_ai_pair_nomination_contract( $raw, $stripped, (string) ( $cached['anchor'] ?? '' ) );
}

/**
 * Zero-AI link-opportunities check (v8.1.0): published note pairs that
 * should link but don't. One finding per unordered pair; the NEWER note is
 * the subject/source (the newer note references the older; the theme's
 * cited-by footer then serves readers the reverse direction).
 *
 * The pairwise pass is quadratic over the LIMIT-500 corpus like the
 * mentions check; the real site is a few dozen notes.
 *
 * @return array Packed check (sn_health_pack_check shape).
 */
function sn_health_check_link_opportunities() {
	$label    = 'Link opportunities';
	$fix_hint = 'Two notes cover related subjects but neither links the other. Suggest asks AI whether a link helps and nominates an anchor phrase already in your prose; Apply wraps it. Advisory: opportunities, not problems.';

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
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	// One prep pass: stripped prose (the mention partition reads it) and an
	// ID index into the fetched rows. The scoring brain is the kernel's
	// stored artifact now — no tf/df/tags recomputation at scan time.
	if ( ! function_exists( 'snt_ml_related_for_post' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint ); // Defensive; the ML module loads with the plugin.
	}
	$prep     = array();
	$by_id    = array();
	foreach ( $rows as $i => $row ) {
		$prep[ $i ] = array(
			'stripped' => wp_strip_all_tags( strip_shortcodes( (string) $row['post_content'] ) ),
		);
		$by_id[ (int) $row['ID'] ] = $i;
	}

	// Candidate pass: every post's stored related rows (top-10, blended
	// cosine + tag overlap + link graph). BOTH directions are consulted —
	// each side's artifact truncates to top-10 INDEPENDENTLY, so a pair can
	// survive in only ONE post's list (the reviewer's truncation trap); the
	// normalize-and-dedupe below keeps it either way. Rows are date-DESC, so
	// the smaller index is the NEWER note = the reported source.
	$scored    = array();
	$pair_seen = array();
	foreach ( $rows as $i => $walk_src ) {
		$rel = snt_ml_related_for_post( (int) $walk_src['ID'], 10 );
		if ( null === $rel ) {
			// Artifact never built: the advisory stays quiet rather than
			// guessing — it builds on the next publish or overnight.
			return sn_health_pack_check( $label, array(), $fix_hint );
		}
		if ( ! is_array( $rel ) ) {
			continue; // Unindexed post (or error shape): nominates nothing.
		}
		foreach ( $rel as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['post_id'], $r['score'] ) ) {
				continue; // Malformed artifact row: skip, never fabricate.
			}
			$other = $by_id[ (int) $r['post_id'] ] ?? null;
			if ( null === $other || $other === $i ) {
				continue; // Unknown target or self.
			}
			$a = min( $i, $other ); // Newer note — the source.
			$b = max( $i, $other );
			if ( isset( $pair_seen[ $a . ':' . $b ] ) ) {
				continue; // Symmetric row already taken (same stored score).
			}
			$pair_seen[ $a . ':' . $b ] = true;
			$src = $rows[ $a ];
			$tgt = $rows[ $b ];

			// Connected pairs (either direction) have nothing to gain.
			if ( sn_health_contains_note_link( (string) $src['post_content'], (string) $tgt['post_name'] )
				|| sn_health_contains_note_link( (string) $tgt['post_content'], (string) $src['post_name'] ) ) {
				continue;
			}

			// Title-mention pairs belong to unlinked_mentions — mirror that
			// check's EXACT trigger (eligibility + stripped stripos) so the
			// two checks partition the pair space with no gap and no overlap.
			$tgt_title = trim( (string) $tgt['post_title'] );
			if ( sn_health_mention_target_eligible( $tgt_title ) && false !== stripos( $prep[ $a ]['stripped'], $tgt_title ) ) {
				continue;
			}

			$scored[] = array(
				'i'     => $a,
				'j'     => $b,
				'score' => (float) $r['score'],
			);
		}
	}

	// Best first, so the per-source cap keeps the strongest pairs. Ties
	// break on (source, target) index so the ranking never flaps.
	usort( $scored, function ( $a, $b ) {
		if ( $a['score'] === $b['score'] ) {
			return ( $a['i'] <=> $b['i'] ) ?: ( $a['j'] <=> $b['j'] );
		}
		return $b['score'] <=> $a['score'];
	} );

	$findings   = array();
	$per_source = array();
	foreach ( $scored as $pair ) {
		$src = $rows[ $pair['i'] ];
		$tgt = $rows[ $pair['j'] ];
		$sid = (int) $src['ID'];
		if ( ( $per_source[ $sid ] ?? 0 ) >= SN_HEALTH_PAIRS_MAX_PER_SOURCE ) {
			continue;
		}
		$per_source[ $sid ] = ( $per_source[ $sid ] ?? 0 ) + 1;
		// v8.1.2 owner rule: a pair already judged non-actionable is NOISE —
		// it renders nothing. v8.4.4: but it KEEPS its cap slot. Suppression
		// used to run before the cap, so judging the rendered top-3 freed
		// their slots and every re-scan promoted the next-ranked unjudged
		// pairs — Suggest All never converged. With the slot consumed, the
		// cap means "top-N SCORED candidates per source" and one judging
		// pass reaches quiet; an edit to either post changes the verdict
		// key's modified stamps and re-nominates naturally.
		if ( sn_health_pair_judged_noise( $src, $tgt ) ) {
			continue;
		}

		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => $sid,
			'subject_url'   => (string) get_permalink( $sid ),
			'subject_label' => (string) $src['post_title'],
			'edit_url'      => admin_url( 'post.php?post=' . $sid . '&action=edit' ),
			'note'          => sprintf( 'Covers subjects related to "%s" (/notes/%s) without linking to it.', (string) $tgt['post_title'], (string) $tgt['post_name'] ),
			'target_id'     => (int) $tgt['ID'],
			'target_title'  => (string) $tgt['post_title'],
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
