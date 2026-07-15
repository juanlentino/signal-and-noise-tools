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
		snt_an_gate(
			__( 'Lifecycle at scale', 'signal-and-noise-tools' ),
			__( 'No catalogue data yet — once your published Notes accumulate views, their decay shapes and refresh candidates show up here.', 'signal-and-noise-tools' )
		);
		return;
	}

	$summary = is_array( $lifecycle['summary'] ?? null ) ? $lifecycle['summary'] : array();
	$counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
	$cands   = (int) ( $summary['refresh_candidates'] ?? 0 );

	snt_an_panel_open(
		__( 'Lifecycle at scale — your whole catalogue', 'signal-and-noise-tools' ),
		array(
			'panel_class'  => 'sn-overview',
			'inside_class' => 'inside inside-flush sn-an-panel',
		)
	);

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
	snt_an_panel_close();

	snt_analytics_render_lifecycle_table( (array) $lifecycle['rows'], (int) ( $summary['total'] ?? 0 ) );
}

/**
 * The catalogue leaderboard — candidates first, capped for DOM sanity. Routes
 * through the shared snt_an_kv_table() column-spec mode (D5 §4,
 * inc/analytics-panels.php, holdout retirement v9.43.x): Post carries
 * caller-built link+strong+age markup, Shape carries the decay text or the
 * muted &mdash; fallback, and Status carries sn_lifecycle_status_pill()'s
 * return value — all three as html=true cells (the caller already
 * escapes/builds them, same contract sn_lifecycle_status_pill() already had).
 * The row cap + truncation-footer TEXT stay here (kv_table has no concept of
 * either); $opts['footer'] is only the seam that lands the caller-built <p>
 * inside the same panel kv_table now owns opening/closing.
 *
 * @param array $rows  Sorted lifecycle rows.
 * @param int   $total Full catalogue count (for the truncation note).
 */
function snt_analytics_render_lifecycle_table( $rows, $total ) {
	$shown = array_slice( $rows, 0, SN_LIFECYCLE_TABLE_LIMIT );

	$cols = array(
		array( 'label' => __( 'Post', 'signal-and-noise-tools' ), 'html' => true ),
		array( 'label' => __( 'Lifetime', 'signal-and-noise-tools' ), 'class' => 'num' ),
		array( 'label' => __( 'Per day', 'signal-and-noise-tools' ), 'class' => 'num' ),
		array( 'label' => __( 'Shape', 'signal-and-noise-tools' ), 'html' => true ),
		array( 'label' => __( 'Status', 'signal-and-noise-tools' ), 'html' => true ),
	);

	$kv_rows = array();
	foreach ( $shown as $r ) {
		$decay     = (string) ( $r['decay'] ?? '' );
		$kv_rows[] = array(
			'<a href="' . esc_url( (string) $r['permalink'] ) . '"><strong>' . esc_html( (string) $r['title'] ) . '</strong></a> <span class="sn-an-muted">' . esc_html( (int) $r['age'] . 'd' ) . '</span>',
			number_format_i18n( (int) $r['lifetime'] ),
			number_format_i18n( (float) $r['per_day'] ),
			'' !== $decay ? esc_html( $decay ) : '<span class="sn-an-muted">&mdash;</span>',
			sn_lifecycle_status_pill( $r ),
		);
	}

	$footer = '';
	if ( (int) $total > count( $shown ) ) {
		$footer = '<p class="sn-an-foot">' . esc_html( sprintf(
			/* translators: 1: rows shown, 2: total posts. */
			__( 'Showing the top %1$d of %2$d posts (refresh candidates first).', 'signal-and-noise-tools' ),
			count( $shown ),
			(int) $total
		) ) . '</p>';
	}

	snt_an_kv_table(
		__( 'Refresh queue', 'signal-and-noise-tools' ),
		$kv_rows,
		$cols,
		array(
			'data_colname' => true,
			'footer'       => $footer,
		)
	);
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
