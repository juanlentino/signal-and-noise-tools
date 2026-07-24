<?php
/**
 * Signal & Noise Tools -- Content Health check: stale posts.
 *
 * Check 4: stale posts -- published posts unedited in the last SN_HEALTH_STALE_MONTHS months, excluding those flagged _sn_evergreen. Read-only.
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
	// v8.11.0 (B5): posts the editor flagged `_sn_evergreen` are intentionally
	// timeless — "accept as evergreen" made actionable, so they drop out of the scan.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_modified_gmt < %s
		   AND ID NOT IN (
		       SELECT post_id FROM {$wpdb->postmeta}
		       WHERE meta_key = '_sn_evergreen' AND meta_value = '1'
		   )
		 ORDER BY post_modified_gmt ASC
		 LIMIT 200",
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
				'note'          => sprintf( 'Last modified %s — review for currency.', $r['post_modified_gmt'] ),
			);
		}
	}

	return sn_health_pack_check( sprintf( 'Stale posts (>%d months)', SN_HEALTH_STALE_MONTHS ), $findings, 'Review and either update, archive, or mark "Evergreen" in the post\'s Signal & Noise box to exempt it.' );
}
