<?php
/**
 * S&N Dashboard, Integrity > Reports: the link-isolation report renderer.
 *
 * Split out of tools-reports.php to keep the leaf file under the house line
 * cap. Since 2026-10-11 the contrast report lives in tools-reports-contrast.php,
 * tools-reports-usage.php and tools-reports-placement.php, and the motion report
 * in tools-reports-motion.php (each split out when its folds came out); this file
 * keeps the link-isolation view, which mirrors the classic renderer in
 * substance (same fields read, same sentences, same row caps) while painting
 * kit markup instead of `<table>`.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The link-isolation report, mirroring `sn_health_render_link_isolation_report()`.
 *
 * CHANGED SHAPE: the classic `<table>` (Note / Links out / Action) becomes a
 * `<ul class="snt-plain">` of rows rather than an `<os-table>`, because the
 * classic Action column paints a live per-row edit link
 * (`get_edit_post_link()`) and `<os-table>` rows are plain scalar data
 * properties with no slot for embeddable markup — the same reasoning the
 * connections-scheduled-content leaf's rows already rely on
 * (`\snt_kit_door()` inside a plain list row). The slug moves into its own
 * `<os-code>` rather than being concatenated into the note string, so a
 * title containing an em dash cannot be confused with a slug.
 *
 * @param array $report The check's `report` payload.
 * @return string
 */
function tools_reports_render_link_isolation( array $report ) {
	$rows    = isset( $report['isolated'] ) && is_array( $report['isolated'] ) ? $report['isolated'] : array();
	$scanned = (int) ( $report['posts_scanned'] ?? 0 );
	$total   = array_key_exists( 'isolated_total', $report ) ? (int) $report['isolated_total'] : count( $rows );

	$out = '<p class="snt-prose">' . \snt_kit_esc(
		sprintf(
			/* translators: 1: isolated note count, 2: notes scanned */
			__( '%1$d of %2$d published notes have no inbound link from any other note.', 'signal-and-noise-tools' ),
			$total,
			$scanned
		)
	) . '</p>';

	if ( empty( $rows ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Every published note is reachable from at least one other note.', 'signal-and-noise-tools' ) ) . '</p>';
		return $out;
	}

	$items = '';
	foreach ( $rows as $row ) {
		$post_id  = (int) ( $row['post_id'] ?? 0 );
		$outbound = (int) ( $row['outbound_count'] ?? 0 );
		$links    = 0 === $outbound
			/* translators: %d: outbound link count (0) */
			? sprintf( __( '%d (both ways)', 'signal-and-noise-tools' ), $outbound )
			: (string) $outbound;

		$edit = '';
		if ( $post_id > 0 && function_exists( 'get_edit_post_link' ) ) {
			$edit_url = get_edit_post_link( $post_id );
			if ( is_string( $edit_url ) && '' !== $edit_url ) {
				$edit = ' ' . \snt_kit_door( __( 'Edit', 'signal-and-noise-tools' ), $edit_url );
			}
		}

		$items .= '<li>' . \snt_kit_esc( (string) ( $row['title'] ?? '' ) );
		if ( ! empty( $row['slug'] ) ) {
			$items .= ' ' . \snt_kit_code( (string) $row['slug'], false );
		}
		$items .= ' · ' . \snt_kit_esc( __( 'Links out:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( $links ) . $edit . '</li>';
	}
	$out .= '<ul class="snt-plain">' . $items . '</ul>';

	$hidden = $total - count( $rows );
	if ( ! empty( $report['truncated'] ) || $hidden > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: 1: rows shown, 2: true total */
				__( 'Showing %1$d of %2$d isolated notes; the list is capped, not complete.', 'signal-and-noise-tools' ),
				count( $rows ),
				$total
			)
		) . '</p>';
	}
	return $out;
}
