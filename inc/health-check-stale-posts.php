<?php
/**
 * Signal & Noise Tools -- Content Health check: stale posts.
 *
 * Check 4: stale posts -- published posts whose last SUBSTANTIVE change is older
 * than SN_HEALTH_STALE_MONTHS months, excluding those flagged _sn_evergreen. Read-only.
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
function sn_health_check_stale_posts() {
	global $wpdb;
	$findings = array();

	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . SN_HEALTH_STALE_MONTHS . ' months' ) );
	// The effective clock is written out in full at each of the three places it
	// is needed (SELECT / WHERE / ORDER BY) rather than interpolated from a
	// variable: WPCS reads a variable inside prepare() as unprepared SQL, and
	// the WHERE and the ORDER BY must agree exactly or LIMIT 200 truncates on a
	// different ordering than it filtered by.
	//
	// NULLIF guards a meta row that exists but is empty — that is "no answer",
	// not "the epoch", and COALESCE alone would accept '' as a real value.
	//
	// v8.11.0 (B5): posts the editor flagged `_sn_evergreen` are intentionally
	// timeless — "accept as evergreen" made actionable, so they drop out of the scan.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_modified_gmt,
		        pm.meta_value AS prov_gmt,
		        COALESCE( NULLIF( pm.meta_value, '' ), p.post_modified_gmt ) AS effective_gmt
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm
		        ON pm.post_id = p.ID
		       AND pm.meta_key = %s
		 WHERE p.post_status = 'publish'
		   AND p.post_type IN ('post','page')
		   AND COALESCE( NULLIF( pm.meta_value, '' ), p.post_modified_gmt ) < %s
		   AND p.ID NOT IN (
		       SELECT post_id FROM {$wpdb->postmeta}
		       WHERE meta_key = '_sn_evergreen' AND meta_value = '1'
		   )
		 ORDER BY COALESCE( NULLIF( pm.meta_value, '' ), p.post_modified_gmt ) ASC
		 LIMIT 200",
		SN_PROV_LAST_COMMIT_META,
		$cutoff
	), ARRAY_A );

	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$findings[] = array(
				'subject_type'  => 'post',
				'subject_id'    => (int) $r['ID'],
				'subject_url'   => get_permalink( (int) $r['ID'] ),
				'subject_label' => (string) $r['post_title'],
				'edit_url'      => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				// Name the clock that produced the verdict. A reader who sees
				// "last modified" and knows they touched the post yesterday would
				// rightly distrust the finding; "last substantive change" says
				// what was actually measured.
				'note'          => '' !== (string) ( $r['prov_gmt'] ?? '' )
					? sprintf( 'Last substantive change %s (provenance): review for currency.', $r['prov_gmt'] )
					: sprintf( 'Last modified %s (no provenance commit): review for currency.', $r['post_modified_gmt'] ),
			);
		}
	}

	return sn_health_pack_check( sprintf( 'Stale posts (>%d months)', SN_HEALTH_STALE_MONTHS ), $findings, 'Review and either update, archive, or mark "Evergreen" in the post\'s Signal & Noise box to exempt it.' );
}
