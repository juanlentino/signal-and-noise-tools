<?php
/**
 * Signal & Noise Tools -- Content Health check: stale posts.
 *
 * Check 4: stale posts -- published posts whose last SUBSTANTIVE change is older
 * than SN_HEALTH_STALE_MONTHS months. Read-only.
 *
 * v11.11.9 -- `_sn_evergreen` is now ADVISORY, not an exemption (owner decision).
 * It used to remove a post from the query entirely, which meant the flag could
 * hide genuine staleness: ticking a box made the row disappear rather than
 * explaining it. Now one query finds every stale post and the flag PARTITIONS
 * the result into two checks:
 *
 *   stale_posts            -- the fault tier. Unflagged, counts as a finding.
 *   stale_posts_evergreen  -- the advisory tier. Flagged, reported and visible,
 *                             but excluded from the defect total by
 *                             sn_health_advisory_checks().
 *
 * The tiering matters: a flagged post is stale by measurement and intentional by
 * declaration, and BOTH facts are true. Counting it as a defect would make Health
 * permanently red for posts the author already ruled on; hiding it entirely was
 * the previous bug. An advisory says the thing without scoring it.
 *
 * v11.11.8 -- the clock changed. This check used to filter on post_modified_gmt,
 * which bumps on ANY save: a block-migration pass, a bulk re-save, a metadata
 * tweak. Provenance only commits when the PROSE changes, so
 * `_sn_prov_last_commit_gmt` measures the thing the check is actually about.
 * The motivating case was block-migration tooling silently resetting the
 * staleness clock across the catalogue.
 *
 * The fallback is deliberate: posts with no provenance commit (Pages, and posts
 * predating the chain) COALESCE back to post_modified_gmt rather than dropping
 * out of the scan. Filtering strictly on the meta would make an un-committed
 * post permanently invisible to the check -- silence read as freshness, which is
 * the failure mode this check exists to prevent.
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

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 4: stale posts (published > 12mo ago, never modified since)
 * ───────────────────────────────────────────────────────────────────── */
/**
 * Every stale post, partitioned by the `_sn_evergreen` declaration.
 *
 * ONE query for both tiers, so the two checks can never disagree about which
 * posts are stale or about which clock said so.
 *
 * @return array{findings:array,evergreen:array}
 */
function sn_health_stale_posts_scan() {
	global $wpdb;
	$findings  = array();
	$evergreen = array();

	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . SN_HEALTH_STALE_MONTHS . ' months' ) );
	// The effective clock is written out in full at each of the three places it
	// is needed (SELECT / WHERE / ORDER BY) rather than interpolated from a
	// variable: WPCS reads a variable inside prepare() as unprepared SQL, and
	// the WHERE and the ORDER BY must agree exactly or LIMIT 200 truncates on a
	// different ordering than it filtered by.
	//
	// NULLIF guards a meta row that exists but is empty -- that is "no answer",
	// not "the epoch", and COALESCE alone would accept '' as a real value.
	//
	// The evergreen flag is now SELECTED, not filtered on (v11.11.9): it labels
	// the row instead of removing it.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_modified_gmt,
		        pm.meta_value AS prov_gmt,
		        ev.meta_value AS evergreen,
		        COALESCE( NULLIF( pm.meta_value, '' ), p.post_modified_gmt ) AS effective_gmt
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm
		        ON pm.post_id = p.ID
		       AND pm.meta_key = %s
		 LEFT JOIN {$wpdb->postmeta} ev
		        ON ev.post_id = p.ID
		       AND ev.meta_key = '_sn_evergreen'
		 WHERE p.post_status = 'publish'
		   AND p.post_type IN ('post','page')
		   AND COALESCE( NULLIF( pm.meta_value, '' ), p.post_modified_gmt ) < %s
		 ORDER BY COALESCE( NULLIF( pm.meta_value, '' ), p.post_modified_gmt ) ASC
		 LIMIT 200",
		SN_PROV_LAST_COMMIT_META,
		$cutoff
	), ARRAY_A );

	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			// Name the clock that produced the verdict. A reader who sees
			// "last modified" and knows they touched the post yesterday would
			// rightly distrust the finding; "last substantive change" says
			// what was actually measured.
			$when = '' !== (string) ( $r['prov_gmt'] ?? '' )
				? sprintf( 'Last substantive change %s (provenance)', $r['prov_gmt'] )
				: sprintf( 'Last modified %s (no provenance commit)', $r['post_modified_gmt'] );
			$is_evergreen = '1' === (string) ( $r['evergreen'] ?? '' );
			$row          = array(
				'subject_type'  => 'post',
				'subject_id'    => (int) $r['ID'],
				'subject_url'   => get_permalink( (int) $r['ID'] ),
				'subject_label' => (string) $r['post_title'],
				'edit_url'      => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				'note'          => $is_evergreen
					? $when . ': marked Evergreen, so this is a note rather than a defect.'
					: $when . ': review for currency.',
			);
			if ( $is_evergreen ) {
				$evergreen[] = $row;
				continue;
			}
			$findings[] = $row;
		}
	}

	return array( 'findings' => $findings, 'evergreen' => $evergreen );
}

/**
 * CHECK 4 (fault tier): stale posts the author has NOT declared timeless.
 *
 * @param array|null $scan Optional pre-computed sn_health_stale_posts_scan().
 * @return array
 */
function sn_health_check_stale_posts( $scan = null ) {
	$scan = is_array( $scan ) ? $scan : sn_health_stale_posts_scan();
	return sn_health_pack_check( sprintf( 'Stale posts (>%d months)', SN_HEALTH_STALE_MONTHS ), $scan['findings'], 'Review and either update, archive, or mark "Evergreen" in the post\'s Signal & Noise box -- which moves it to the advisory list below rather than silencing it.' );
}

/**
 * CHECK (advisory tier): stale posts the author HAS declared timeless.
 *
 * Registered in sn_health_advisory_checks(), so its count reports beside the
 * defect total instead of inside it. The rows are still here to be read: the
 * declaration is a reason, not an erasure, and a post can be both intentionally
 * timeless and quietly out of date.
 *
 * @param array|null $scan Optional pre-computed sn_health_stale_posts_scan().
 * @return array
 */
function sn_health_check_stale_posts_evergreen( $scan = null ) {
	$scan = is_array( $scan ) ? $scan : sn_health_stale_posts_scan();
	return sn_health_pack_check( sprintf( 'Evergreen posts past %d months', SN_HEALTH_STALE_MONTHS ), $scan['evergreen'], 'Advisory only -- these are flagged Evergreen, so they are reported rather than counted. Untick Evergreen if one should be chased.' );
}
