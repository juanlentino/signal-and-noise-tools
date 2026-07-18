<?php
/**
 * Signal & Noise — Analytics tabular panels: top pages, dimension breakdowns
 * (with optional inline sparklines), the low-engagement table, entry/exit page
 * roles, and the inline micro-sparkline the dimension table embeds. Native
 * wp-admin markup via the panel primitive. Extracted from
 * analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';        // panel chrome + clamp helpers + empty-fold collector
require_once __DIR__ . '/analytics-render-helpers.php'; // snt_analytics_fmt_time + snt_analytics_smooth_path (sparkline)

/**
 * Top-pages panel (path + views + visits + scroll + time).
 *
 * @param array $paths [{path,views,visits,scroll_avg,time_avg}]
 */
function snt_analytics_render_paths_table( $paths ) {
	if ( empty( $paths ) ) {
		snt_an_note_empty( __( 'Top pages', 'signal-and-noise-tools' ), __( 'No page views in this range.', 'signal-and-noise-tools' ) );
		return;
	}
	snt_an_panel_open( __( 'Top pages', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside sn-an-table-inside' ) );
	snt_an_clamp_open( count( $paths ), 10 ); // v8.5.0: full rows in the DOM; 10 visible — the primary table fills its column beside the sources stack (owner: no blank spaces)
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Path', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Visits', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Scroll', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Time', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $paths as $r ) {
		echo '<tr>'
			. '<td class="column-primary" data-colname="Path"><strong>' . esc_html( (string) $r['path'] ) . '</strong></td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>'
			. '<td class="num" data-colname="Scroll">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num" data-colname="Time">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';
	snt_an_clamp_close( count( $paths ), 10 );
	snt_an_panel_close();
}

/**
 * A dimension breakdown panel (value + views + visits), with optional per-row
 * trend sparklines. Pass $series as a value→[{day,views}] map to activate them;
 * omit (or pass an empty array) for the original back-compatible layout.
 *
 * @param string $title
 * @param array  $rows   [{value,views,visits}]
 * @param string $empty  Empty-state copy.
 * @param array  $series Optional value-keyed series map for sparklines.
 * @param string $drill_dim Optional drill dimension for primary-cell links.
 * @param int    $visible   Rows visible while clamped.
 * @param array  $opts   {
 *     Optional seams (v9.68.0 part 4, the Overview landing). Every default
 *     renders byte-identically for existing callers.
 *     @type string     $header_meta Forwarded to snt_an_panel_open (kses'd there).
 *     @type array|null $deltas      Value-keyed {pct,dir,previous?} arrays: a
 *                                   matched row's chip renders inline beside its
 *                                   Views figure via snt_an_delta_badge.
 *     @type string     $prior_note  One muted line after the table ('' = none)
 *                                   — the Overview's once-per-panel prior-window
 *                                   honesty note.
 * }
 */
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill_dim = '', $visible = 5, $opts = array() ) {
	if ( null === $rows ) {
		// v9.68.1: the accessors report a FAILED wpdb read as null ([] stays
		// the honest empty window) — fold with the shared read-failure copy,
		// never the $empty copy (a database failure must not impersonate a
		// quiet range).
		snt_an_note_empty( $title, snt_an_read_failed_copy( $title ) );
		return;
	}
	if ( empty( $rows ) ) {
		snt_an_note_empty( $title, $empty );
		return;
	}
	$panel_args = array( 'inside_class' => 'inside sn-an-table-inside' );
	if ( ! empty( $opts['header_meta'] ) ) {
		$panel_args['header_meta'] = (string) $opts['header_meta'];
	}
	snt_an_panel_open( $title, $panel_args );
	$has_spark = ! empty( $series );
	$deltas    = ( isset( $opts['deltas'] ) && is_array( $opts['deltas'] ) ) ? $opts['deltas'] : null;
	snt_an_clamp_open( count( $rows ), (int) $visible ); // v8.5.0 (content view passes 10 for sources — column balance)
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html( $title ) . '</th>';
	if ( $has_spark ) {
		echo '<th scope="col" class="manage-column">' . esc_html__( 'Trend', 'signal-and-noise-tools' ) . '</th>';
	}
	echo '<th scope="col" class="manage-column num">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th><th scope="col" class="manage-column num">' . esc_html__( 'Visits', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$v = (string) $r['value'];
		echo '<tr><td class="column-primary" data-colname="' . esc_attr( $title ) . '">';
		if ( '' !== $drill_dim ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'sn_drill' => $drill_dim . ':' . $v ) ) ) . '"><strong>' . esc_html( $v ) . '</strong></a>';
		} else {
			echo '<strong>' . esc_html( $v ) . '</strong>';
		}
		echo '</td>';
		if ( $has_spark ) {
			echo '<td>' . snt_analytics_sparkline( $series[ $v ] ?? array() ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped markup: an SVG with esc_attr'd path d + a per-call gradient id minted by the helper.
		}
		echo '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) );
		if ( null !== $deltas && isset( $deltas[ $v ] ) ) {
			snt_an_delta_badge( $deltas[ $v ] ); // inline variant — leading space is the badge's own.
		}
		echo '</td>';
		echo '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	snt_an_clamp_close( count( $rows ), (int) $visible );
	if ( ! empty( $opts['prior_note'] ) ) {
		echo '<p class="sn-an-compare-note sn-an-prior-note">' . esc_html( (string) $opts['prior_note'] ) . '</p>';
	}
	snt_an_panel_close();
}

/**
 * Inline micro-sparkline (returns a string so it can sit in a table cell). A tiny
 * smooth-line SVG mini-area mirroring the Overview chart's treatment via the shared
 * smooth-path helper. A static counter mints a unique gradient id per call so the
 * many sparklines on one page don't collide on a duplicate <linearGradient> id.
 *
 * @param array $series [{day:string, views:int}]
 * @return string HTML
 */
function snt_analytics_sparkline( $series ) {
	if ( empty( $series ) ) {
		return '<span class="sn-an-spark sn-an-spark--empty"></span>';
	}
	static $uid = 0;
	$gid  = 'sn-spark-fill-' . ( ++$uid );
	$n    = count( $series );
	$max  = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) $row['views'] );
	}
	$w    = 72.0;
	$top  = 2.0;
	$base = 16.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( array_values( $series ) as $i => $row ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (int) $row['views'] / $max ) * ( $base - $top ), 2 );
	}
	// A single data point smooths to a bare moveto (invisible). Pad it to a flat
	// full-width line so a one-bucket dimension still shows a mark — the old bar
	// sparkline drew a single full-height bar; don't regress to nothing.
	if ( count( $px ) < 2 ) {
		$px = array( 0.0, $w );
		$py = array( $py[0], $py[0] );
	}
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$last_x = $px[ count( $px ) - 1 ];
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';

	$out  = '<span class="sn-an-spark">';
	$out .= '<svg viewBox="0 0 72 18" preserveAspectRatio="none" aria-hidden="true">';
	$out .= '<defs><linearGradient id="' . esc_attr( $gid ) . '" x1="0" y1="0" x2="0" y2="1">';
	$out .= '<stop offset="0%" stop-color="#2271b1" stop-opacity="0.18"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	$out .= '<path d="' . esc_attr( $area_d ) . '" fill="url(#' . esc_attr( $gid ) . ')" stroke="none"/>';
	$out .= '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#2271b1" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	$out .= '</svg></span>';
	return $out;
}

/**
 * "Pages losing readers" panel: pages with meaningful traffic but weak
 * engagement (low scroll AND low dwell). Data from sn_analytics_low_engagement_paths().
 *
 * @param array $rows [{path,views,scroll_avg,time_avg}]
 */
function snt_analytics_render_lowengage( $rows ) {
	if ( empty( $rows ) ) {
		snt_an_note_empty( __( 'Pages losing readers', 'signal-and-noise-tools' ), __( 'No low-engagement pages in this range — readers are sticking around.', 'signal-and-noise-tools' ) );
		return;
	}
	snt_an_panel_open( __( 'Pages losing readers', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside sn-an-table-inside' ) );
	snt_an_clamp_open( count( $rows ), 5 ); // v8.5.0
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Page', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Scroll', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Time', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>'
			. '<td class="column-primary" data-colname="Page"><strong>' . esc_html( (string) $r['path'] ) . '</strong></td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>'
			. '<td class="num" data-colname="Scroll">' . esc_html( (int) round( (float) $r['scroll_avg'] ) . '%' ) . '</td>'
			. '<td class="num" data-colname="Time">' . esc_html( snt_analytics_fmt_time( (float) $r['time_avg'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';
	snt_an_clamp_close( count( $rows ), 5 );
	snt_an_panel_close();
}

/**
 * Entry/exit pages panel (path · views · visits). $role drives the title and
 * captions: 'entry' = landing pages (arrivals from search/links/direct, merged
 * live + historical); 'exit' = last-page-of-visit, fed nightly since v9.66.0
 * by the session-rollup bridge (sn_session_exit_page_rows — one exit per
 * visit), merged with the older Plausible history. Human-only: no
 * traffic-class control applies here, consistent with the human-only
 * Plausible history.
 *
 * Clones snt_analytics_render_paths_table()'s WP-native markup (.postbox +
 * .inside.sn-an-table-inside + .wp-list-table.widefat.striped). Reuses existing
 * CSS — no new stylesheet rule needed.
 *
 * @param array  $rows        [{path,views,visits}]
 * @param string $role        'entry' | 'exit'.
 * @param string $header_meta Optional small muted note right of the panel
 *                            title (forwarded to snt_an_panel_open; kses'd
 *                            there). '' = omitted — byte-identical output for
 *                            the existing callers. The Overview landing uses
 *                            it to label these tables human-only (v9.68.0).
 * @param array  $opts        {
 *     Optional seams (v9.68.0 part 4) — defaults render byte-identically.
 *     @type array|null $deltas     Path-keyed {pct,dir,previous?} arrays →
 *                                  inline chips beside the Views figures.
 *     @type string     $prior_note One muted line after the table ('' = none).
 * }
 */
function snt_analytics_render_pageroles_table( $rows, $role, $header_meta = '', $opts = array() ) {
	$is_exit = ( 'exit' === $role );
	$title   = $is_exit ? __( 'Exit pages', 'signal-and-noise-tools' ) : __( 'Entry pages', 'signal-and-noise-tools' );
	$caption = $is_exit
		? __( 'Where visits ended — the last page of each visit, rolled up nightly.', 'signal-and-noise-tools' )
		: __( 'Where visits began — arrivals from search, links, or direct.', 'signal-and-noise-tools' );
	$empty   = $is_exit
		? __( 'No exit pages in this range yet.', 'signal-and-noise-tools' )
		: __( 'No entry pages in this range yet.', 'signal-and-noise-tools' );

	if ( null === $rows ) {
		// v9.68.1: null = the accessor's failed-read verdict — the read-failure
		// fold, never the empty-window copy.
		snt_an_note_empty( $title, snt_an_read_failed_copy( $title ) );
		return;
	}
	if ( empty( $rows ) ) {
		snt_an_note_empty( $title, $empty );
		return;
	}
	$panel_args = array( 'inside_class' => 'inside sn-an-table-inside' );
	if ( '' !== (string) $header_meta ) {
		$panel_args['header_meta'] = (string) $header_meta;
	}
	snt_an_panel_open( $title, $panel_args );
	echo '<p class="sn-an-settings-help" style="padding:0 12px">' . esc_html( $caption ) . '</p>';
	$deltas = ( isset( $opts['deltas'] ) && is_array( $opts['deltas'] ) ) ? $opts['deltas'] : null;
	snt_an_clamp_open( count( $rows ), 5 ); // v8.5.0
	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Path', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col" class="manage-column num">' . esc_html__( 'Visits', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$path = (string) $r['path'];
		echo '<tr>'
			. '<td class="column-primary" data-colname="Path"><strong>' . esc_html( $path ) . '</strong></td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) $r['views'] ) );
		if ( null !== $deltas && isset( $deltas[ $path ] ) ) {
			snt_an_delta_badge( $deltas[ $path ] ); // inline variant — leading space is the badge's own.
		}
		echo '</td>'
			. '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';
	snt_an_clamp_close( count( $rows ), 5 );
	if ( ! empty( $opts['prior_note'] ) ) {
		echo '<p class="sn-an-compare-note sn-an-prior-note">' . esc_html( (string) $opts['prior_note'] ) . '</p>';
	}
	snt_an_panel_close();
}
