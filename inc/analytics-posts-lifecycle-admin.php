<?php
/**
 * Signal & Noise — A4 "Lifecycle at scale" RENDER layer (freshness arc, v8.11.0).
 *
 * The catalogue-wide companion to the recent-cohort Posts view: a shape census
 * (spike / cooling / evergreen) over every published Note and a leaderboard that
 * floats REFRESH CANDIDATES — cooling posts NOT flagged evergreen (B5) — to the
 * top. Reuses the dashboard's native vocabulary only (.sn-kpi glance, the
 * .wp-list-table chrome, .sn-pill) — no new CSS. Read-only.
 *
 * @package signal-and-noise-tools
 * @since 8.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // snt_an_annotation + snt_an_kpi_row (house style: don't lean on loader order).

const SN_LIFECYCLE_TABLE_LIMIT = 50; // rows rendered (candidates sort first); census counts the full catalogue.

/**
 * The whole "Lifecycle at scale" section: glance + census + candidate leaderboard.
 *
 * @param array|null $lifecycle From sn_analytics_posts_lifecycle().
 */
function snt_analytics_render_lifecycle_section( $lifecycle ) {
	if ( ! is_array( $lifecycle ) || empty( $lifecycle['rows'] ) ) {
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>'
			. esc_html__( 'Lifecycle at scale', 'signal-and-noise-tools' )
			. '</span></h2></div><div class="inside sn-an-panel"><p class="sn-an-empty sn-an-empty--panel">'
			. esc_html__( 'No catalogue data yet — once your published Notes accumulate views, their decay shapes and refresh candidates show up here.', 'signal-and-noise-tools' )
			. '</p></div></div>';
		return;
	}

	$summary = is_array( $lifecycle['summary'] ?? null ) ? $lifecycle['summary'] : array();
	$counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
	$cands   = (int) ( $summary['refresh_candidates'] ?? 0 );

	echo '<div class="postbox sn-overview"><div class="postbox-header"><h2 class="hndle"><span>'
		. esc_html__( 'Lifecycle at scale — your whole catalogue', 'signal-and-noise-tools' )
		. '</span></h2></div><div class="inside inside-flush sn-an-panel">';

	snt_an_annotation( sn_annotation_lifecycle( $summary ) );

	// ── Glance: the actionable number + the shape census, in cloned .sn-kpi cards.
	// v9.40.0 D4: 'sub' cards are colored TEXT descriptors (no real {pct,dir}
	// pair) — 'sub_class' rides the candidate-count-derived class; the three
	// always-flat cards omit it and fall through to the primitive's default.
	$cards = array(
		array(
			'l'         => __( 'Refresh candidates', 'signal-and-noise-tools' ),
			'n'         => number_format_i18n( $cands ),
			'sub'       => __( 'cooling, not evergreen', 'signal-and-noise-tools' ),
			'promoted'  => true,
			'sub_class' => $cands > 0 ? 'sn-delta-down' : 'sn-delta-flat',
		),
		array( 'l' => __( 'Cooling', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $counts['cooling'] ?? 0 ) ), 'sub' => __( 'losing steam', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Evergreen', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $counts['evergreen'] ?? 0 ) ), 'sub' => __( 'sustained tail', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Spike', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $counts['spike'] ?? 0 ) ), 'sub' => __( 'front-loaded', 'signal-and-noise-tools' ) ),
	);
	snt_an_kpi_row( $cards );

	echo '<p class="sn-an-foot">' . esc_html__( 'Shape is each Note\'s early-life share of its lifetime views. A refresh candidate is a cooling Note you haven\'t marked evergreen — the editorial call the data can\'t make.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div></div>';

	snt_analytics_render_lifecycle_table( (array) $lifecycle['rows'], (int) ( $summary['total'] ?? 0 ) );
}

/**
 * The catalogue leaderboard — candidates first, capped for DOM sanity.
 *
 * @param array $rows  Sorted lifecycle rows.
 * @param int   $total Full catalogue count (for the truncation note).
 */
function snt_analytics_render_lifecycle_table( $rows, $total ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>'
		. esc_html__( 'Refresh queue', 'signal-and-noise-tools' )
		. '</span></h2></div><div class="inside sn-an-table-inside">';

	$shown = array_slice( $rows, 0, SN_LIFECYCLE_TABLE_LIMIT );

	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Post', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="manage-column num">' . esc_html__( 'Lifetime', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="manage-column num">' . esc_html__( 'Per day', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="manage-column">' . esc_html__( 'Shape', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="manage-column">' . esc_html__( 'Status', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';

	foreach ( $shown as $r ) {
		$decay = (string) ( $r['decay'] ?? '' );
		echo '<tr>';
		echo '<td class="column-primary" data-colname="Post"><a href="' . esc_url( (string) $r['permalink'] ) . '"><strong>'
			. esc_html( (string) $r['title'] ) . '</strong></a> <span class="sn-an-muted">' . esc_html( (int) $r['age'] . 'd' ) . '</span></td>';
		echo '<td class="num" data-colname="Lifetime">' . esc_html( number_format_i18n( (int) $r['lifetime'] ) ) . '</td>';
		echo '<td class="num" data-colname="Per day">' . esc_html( number_format_i18n( (float) $r['per_day'] ) ) . '</td>';
		echo '<td data-colname="Shape">' . ( '' !== $decay ? esc_html( $decay ) : '<span class="sn-an-muted">&mdash;</span>' ) . '</td>';
		echo '<td data-colname="Status">' . sn_lifecycle_status_pill( $r ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper builds escaped markup.
		echo '</tr>';
	}
	echo '</tbody></table>';

	if ( (int) $total > count( $shown ) ) {
		echo '<p class="sn-an-foot">' . esc_html( sprintf(
			/* translators: 1: rows shown, 2: total posts. */
			__( 'Showing the top %1$d of %2$d posts (refresh candidates first).', 'signal-and-noise-tools' ),
			count( $shown ),
			(int) $total
		) ) . '</p>';
	}
	echo '</div></div>';
}

/**
 * The Status cell: a "Refresh" pill for candidates, an "Evergreen" pill for
 * flagged posts, otherwise a muted dash.
 *
 * @param array $row Lifecycle row.
 * @return string Escaped HTML.
 */
function sn_lifecycle_status_pill( $row ) {
	if ( ! empty( $row['refresh_candidate'] ) ) {
		return '<span class="sn-pill sn-pill--warn">' . esc_html__( 'Refresh', 'signal-and-noise-tools' ) . '</span>';
	}
	if ( ! empty( $row['evergreen'] ) ) {
		return '<span class="sn-pill sn-pill--ok">' . esc_html__( 'Evergreen', 'signal-and-noise-tools' ) . '</span>';
	}
	return '<span class="sn-an-muted" aria-hidden="true">&mdash;</span>';
}
