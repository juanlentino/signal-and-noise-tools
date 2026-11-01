<?php
/**
 * Signal & Noise Tools: Analytics panel primitive, the range pills and the k/v table.
 *
 * Split from inc/analytics-panels.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * THE range-pill control (v9.42.2): one control-group for "windowed range, N
 * discrete int values" — extracted from login-defense's hand-rolled clone
 * (inc/login-defense-analytics.php), the last hand-rolled control left after D5
 * (D5 §2's own note: a range-pill primitive was never in scope for D4/D5).
 *
 * Renders ONLY the .sn-control-group (role="group" + aria-label) and its
 * button-group pills — NOT the surrounding .sn-toolbar; a toolbar may host
 * other controls alongside this one, so that wrapper stays the caller's, same
 * as the row/group primitives (snt_an_kpi_row, snt_an_trend_svg) leave their
 * outer chrome to the caller (the postbox primitives own theirs).
 * Deliberately narrower than snt_analytics_render_controls() (D3): no
 * calendar/custom/365/all/class-segmented options — login AE retains a fixed
 * ~90d and is not class-segmented, so those would render empty or false. This
 * primitive covers exactly the shape login-defense needs; not a generalized
 * range-control replacement.
 *
 * @param string $param        Query-string param each pill toggles (e.g. 'sn_lg_range').
 * @param array  $allowed      Ordered list of allowed int values.
 * @param int    $active_value The currently active value — its pill gets the
 *                              ' active' class + aria-pressed="true".
 * @param array  $opts         {
 *     @type string $base       URL base for esc_url( add_query_arg( array( $param => $v ), $base ) ).
 *                               Default '' (add_query_arg's own current-URL fallback).
 *     @type string $label      The .sn-control-label text. Default __( 'Range', 'signal-and-noise-tools' ).
 *     @type string $aria_label The group's aria-label. Default __( 'Date range', 'signal-and-noise-tools' ).
 * }
 * @since 9.42.2
 */
function snt_an_range_pills( $param, $allowed, $active_value, $opts = array() ) {
	$param = (string) $param;
	$base  = (string) ( $opts['base'] ?? '' );
	$label = (string) ( $opts['label'] ?? __( 'Range', 'signal-and-noise-tools' ) );
	$aria  = (string) ( $opts['aria_label'] ?? __( 'Date range', 'signal-and-noise-tools' ) );

	echo '<div class="sn-control-group" role="group" aria-label="' . esc_attr( $aria ) . '">';
	echo '<span class="sn-control-label">' . esc_html( $label ) . '</span>';
	echo '<span class="button-group">';
	foreach ( (array) $allowed as $v ) {
		$is_active = ( $v === $active_value );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( add_query_arg( array( $param => $v ), $base ) ) . '">'
			. esc_html( (int) $v . 'd' ) . '</a>';
	}
	echo '</span></div>';
}

/**
 * THE k/v table (D5 §4): one postbox table for "primary label + N numeric
 * columns" rows — the ranked/dimensional-breakdown shape shared by the edge
 * dim tables and login-defense's attacker top-tables. Domain-agnostic like
 * snt_an_trend_svg(): it never formats or translates a value itself — every
 * cell arrives as a PRE-FORMATTED string (number_format_i18n(), snt_edge_fmt_bytes(),
 * translated column labels, …), assembled by the caller.
 *
 * TWO column forms, both accepted by $cols (mode is auto-detected from
 * $cols[0]'s shape — never mix the two within one call):
 *
 * 1. STRING-LIST mode (unchanged since 9.41.0 — byte-identical, never touch
 *    this branch's output): a flat, ordered list of column-header strings.
 *    $cols[0] is the primary row-label column (bold, class="column-primary");
 *    every column after it is numeric (class="num", the house right-align
 *    idiom). $rows is a list of rows, each itself a plain list of cell
 *    strings aligned 1:1 with $cols. Adopters: snt_edge_render_dim()
 *    (inc/edge-admin.php) and sn_login_defense_render_top_table()
 *    (inc/login-defense-analytics.php) — both stay on this form, unchanged.
 *
 * 2. COLUMN-SPEC mode (9.43.x, holdout retirement): $cols[0] is an array, so
 *    every column must be array{ label: string (pre-translated by the
 *    caller), class?: string, html?: bool }. class is the raw CSS class
 *    token appended to "manage-column" on <th> and used verbatim on <td>
 *    (e.g. 'num'); omit/'' for a classless column (<th class="manage-column">,
 *    <td> with NO class attribute at all — the Shape/Status idiom below).
 *    Column 0 is ALWAYS forced to class="column-primary" regardless of what
 *    its spec says — primary handling stays the helper's, per the doc
 *    contract, so a caller never needs to (and can't) override it. html=true
 *    means the row's cell value for that column is emitted RAW — the CALLER
 *    already escaped/built it (e.g. "<a href=…><strong>Title</strong></a>
 *    <span class=…>3d</span>", or a status-pill helper's return value,
 *    mirroring how sn_lifecycle_status_pill()'s markup is consumed today at
 *    inc/analytics-posts-lifecycle-admin.php). html=false/omitted routes the
 *    value through esc_html() — for column 0 specifically, the primitive also
 *    keeps its own legacy <strong>…</strong> auto-wrap in that case (the
 *    string-list mode's exact primary-cell shape). $rows is a list of rows,
 *    each itself a plain list of cell VALUES aligned 1:1 with $cols (not
 *    pre-wrapped in <strong> — the primitive owns that for html=false primary
 *    cells; every other combination is exactly what's passed).
 *    Retired holdouts: snt_analytics_render_posts_leaderboard()
 *    (inc/analytics-posts-admin.php) and snt_analytics_render_lifecycle_table()
 *    (inc/analytics-posts-lifecycle-admin.php) — both were "don't decompose
 *    onto this simple label+numeric-columns shape" holdouts noted below until
 *    this mode gave them a seam for their link+strong+age primary cell and
 *    their Shape/Status glyph-or-pill cells.
 *
 * @param string $title Panel title AND the empty-fold key (snt_an_note_empty).
 * @param array  $rows  List of rows; each row is a list of cell values, 1:1
 *                       with $cols (pre-formatted strings in both modes;
 *                       pre-built markup only where html=true in spec mode).
 * @param array  $cols  String-list (legacy) or column-spec (array-shaped
 *                       $cols[0]) form — see above.
 * @param array  $opts  {
 *     @type string $empty        Diagnostic why-text for the empty fold
 *                                 (forwarded verbatim to snt_an_note_empty()).
 *     @type string $header_meta  Forwarded to snt_an_panel_open() (small muted
 *                                 note right of the panel title). Unused by
 *                                 today's two string-list adopters; kept as a
 *                                 passthrough seam since the primitive already
 *                                 supports it.
 *     @type bool   $data_colname Emit data-colname="<label>" on EVERY <td>,
 *                                 the primary cell included (#1228: the
 *                                 primary-exclusion this docblock used to
 *                                 describe was a .wp-list-table-era concern —
 *                                 core paints the label over the cell's own
 *                                 text under that class (#1021) — but these
 *                                 tables no longer wear .wp-list-table, so the
 *                                 render never carried the exclusion; the
 *                                 attribute is inert today and kept as honest
 *                                 column metadata). Default false. Both string-list
 *                                 adopters keep their pre-adoption default
 *                                 (edge: true, login-defense: false); both
 *                                 spec-mode adopters (posts leaderboard,
 *                                 lifecycle refresh queue) pass true — they
 *                                 always carried per-cell data-colname before
 *                                 migrating here.
 *     @type string $footer       PRE-BUILT HTML appended right after
 *                                 </table>, still INSIDE the panel, before it
 *                                 closes (the lifecycle refresh-queue's
 *                                 clamp/truncation <p class="sn-an-foot">…
 *                                 lives here — the caller still owns the
 *                                 count/text, this is only the seam that lets
 *                                 it land in the same .inside as the table
 *                                 now that the primitive owns open+close).
 *                                 Default '' = omitted, byte-identical to
 *                                 every adopter before this option existed.
 *     @type int    $clamp        Rows visible before "View all N" (the paths
 *                                 and dimension tables' row clamp, around the
 *                                 table inside the panel). Default 0 = no clamp,
 *                                 byte-identical to every adopter before it.
 * }
 * @since 9.41.0 THE k/v table, string-list mode only.
 * @since 9.43.x Column-spec mode + $opts['footer'] — the posts leaderboard
 *               and lifecycle refresh-queue holdouts noted above adopt here;
 *               string-list mode and its two existing adopters are unchanged.
 */
function snt_an_kv_table( $title, $rows, $cols, $opts = array() ) {
	if ( empty( $rows ) ) {
		snt_an_note_empty( $title, (string) ( $opts['empty'] ?? '' ) );
		return;
	}

	$cols      = array_values( (array) $cols );
	$spec_mode = isset( $cols[0] ) && is_array( $cols[0] );

	// Normalize both accepted $cols shapes into one internal column-spec list
	// so the th/td render loop below never branches on mode again. Column 0
	// is always forced to class="column-primary" — spec mode's own doc
	// contract ("primary handling stays the helper's") and string-list mode's
	// original unconditional behavior agree on this, so one line covers both.
	$columns = array();
	foreach ( $cols as $i => $col ) {
		if ( $spec_mode ) {
			$col       = is_array( $col ) ? $col : array();
			$columns[] = array(
				'label' => (string) ( $col['label'] ?? '' ),
				'class' => ( 0 === $i ) ? 'column-primary' : (string) ( $col['class'] ?? '' ),
				'html'  => ! empty( $col['html'] ),
			);
		} else {
			$columns[] = array(
				'label' => (string) $col,
				'class' => ( 0 === $i ) ? 'column-primary' : 'num',
				'html'  => false,
			);
		}
	}

	$with_colname = ! empty( $opts['data_colname'] );

	$panel_args = array( 'inside_class' => 'inside sn-an-table-inside' );
	if ( ! empty( $opts['header_meta'] ) ) {
		$panel_args['header_meta'] = $opts['header_meta'];
	}
	snt_an_panel_open( $title, $panel_args );
	$clamp = (int) ( $opts['clamp'] ?? 0 );
	if ( $clamp > 0 ) {
		snt_an_clamp_open( count( $rows ), $clamp );
	}

	echo '<table class="widefat striped"><thead><tr>';
	foreach ( $columns as $col ) {
		$th_class = 'manage-column' . ( '' !== $col['class'] ? ' ' . $col['class'] : '' );
		echo '<th scope="col" class="' . esc_attr( $th_class ) . '">' . esc_html( $col['label'] ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$row = array_values( (array) $row );
		echo '<tr>';
		foreach ( $columns as $i => $col ) {
			$value = (string) ( $row[ $i ] ?? '' );
			echo '<td' . ( '' !== $col['class'] ? ' class="' . esc_attr( $col['class'] ) . '"' : '' )
				. ( $with_colname ? ' data-colname="' . esc_attr( $col['label'] ) . '"' : '' ) . '>';
			if ( $col['html'] ) {
				echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- spec-mode html=true columns carry caller-escaped/built markup by contract (see the docblock above); mirrors sn_lifecycle_status_pill()'s existing consumption at inc/analytics-posts-lifecycle-admin.php.
			} elseif ( 0 === $i ) {
				echo '<strong>' . esc_html( $value ) . '</strong>';
			} else {
				echo esc_html( $value );
			}
			echo '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	if ( $clamp > 0 ) {
		snt_an_clamp_close( count( $rows ), $clamp );
	}
	if ( ! empty( $opts['footer'] ) ) {
		echo (string) $opts['footer']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- opts.footer is PRE-BUILT HTML assembled from esc_html()'d fragments at the caller (the primitive's wrap_attrs/header_meta passthrough-seam pattern, inc/analytics-panels.php).
	}
	snt_an_panel_close();
}
