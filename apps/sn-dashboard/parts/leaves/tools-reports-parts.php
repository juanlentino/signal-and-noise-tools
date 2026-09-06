<?php
/**
 * S&N Dashboard — Tools → Reports: the three built-in report renderers.
 *
 * Split out of tools-reports.php to keep the leaf file under the house line
 * cap. Each function here mirrors one classic renderer byte-for-byte in
 * substance (same fields read, same sentences, same thresholds, same row
 * caps) while painting kit markup instead of `<table>`/`<details>`/inline
 * `style=`. See the docblock on each function for its classic counterpart
 * and what changed shape.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A threshold ratio as the shortest honest decimal (4.5 stays "4.5", 3.0
 * becomes "3"), mirroring `sn_health_contrast_format_threshold()`.
 *
 * @param float $ratio Threshold.
 * @return string
 */
function tools_reports_threshold_label( $ratio ) {
	return rtrim( rtrim( number_format( (float) $ratio, 1, '.', '' ), '0' ), '.' );
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
		$items .= ' — ' . \snt_kit_esc( __( 'Links out:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( $links ) . $edit . '</li>';
	}
	$out .= '<ul class="snt-plain">' . $items . '</ul>';

	$hidden = $total - count( $rows );
	if ( ! empty( $report['truncated'] ) || $hidden > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: 1: rows shown, 2: true total */
				__( 'Showing %1$d of %2$d isolated notes — the list is capped, not complete.', 'signal-and-noise-tools' ),
				count( $rows ),
				$total
			)
		) . '</p>';
	}
	return $out;
}

/**
 * The motion report, mirroring `sn_health_render_motion_report()`.
 *
 * @param array $report The check's `report` payload.
 * @return string
 */
function tools_reports_render_motion( array $report ) {
	$uncovered   = isset( $report['uncovered'] ) && is_array( $report['uncovered'] ) ? $report['uncovered'] : array();
	$scanned     = (int) ( $report['scanned'] ?? 0 );
	$total       = (int) ( $report['motion_total'] ?? 0 );
	$gated       = (int) ( $report['gated'] ?? 0 );
	$neutralized = (int) ( $report['neutralized'] ?? 0 );

	if ( 0 === $scanned ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( "No front stylesheets were readable, so no motion was scanned. This tier reads the plugin's front-end CSS and the active theme's — the same sheet population as the contrast usage tier.", 'signal-and-noise-tools' ) ) . '</p>';
	}

	$out = '<p class="snt-prose">' . \snt_kit_esc(
		sprintf(
			/* translators: 1: uncovered count, 2: total motion declarations, 3: gated count, 4: neutralized count, 5: stylesheet count */
			__( '%1$d of %2$d declared motions have no reduced-motion counterpart — %3$d gated behind no-preference, %4$d neutralized under reduce, across %5$d stylesheets.', 'signal-and-noise-tools' ),
			count( $uncovered ),
			$total,
			$gated,
			$neutralized,
			$scanned
		)
	) . '</p>';

	if ( empty( $uncovered ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Every declared motion has a reduced-motion counterpart — gated behind no-preference or set to none under reduce. Script-driven motion stays outside this tier, as the coverage line says.', 'signal-and-noise-tools' ) ) . '</p>';
		return $out;
	}

	$visible = array_slice( $uncovered, 0, defined( 'SN_HEALTH_MOTION_MAX_ROWS' ) ? SN_HEALTH_MOTION_MAX_ROWS : 50 );
	$hidden  = count( $uncovered ) - count( $visible );

	$table_rows = array();
	foreach ( $visible as $row ) {
		$table_rows[] = array(
			'sheet'    => (string) ( $row['sheet'] ?? '' ),
			'selector' => (string) ( $row['selector'] ?? '' ),
			'kind'     => (string) ( $row['kind'] ?? '' ),
		);
	}
	$inner = \snt_kit_table(
		array(
			array( 'key' => 'sheet', 'label' => __( 'Stylesheet', 'signal-and-noise-tools' ) ),
			array( 'key' => 'selector', 'label' => __( 'Selector', 'signal-and-noise-tools' ) ),
			array( 'key' => 'kind', 'label' => __( 'Kind', 'signal-and-noise-tools' ) ),
		),
		$table_rows
	);
	if ( $hidden > 0 ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: %d: hidden row count */
				__( '+%d more uncovered declarations — the list is capped, not complete.', 'signal-and-noise-tools' ),
				$hidden
			)
		) . '</p>';
	}
	$out .= \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: %d: uncovered declaration count */
				_n( 'Show the %d uncovered declaration', 'Show the %d uncovered declarations', count( $uncovered ), 'signal-and-noise-tools' ),
				count( $uncovered )
			),
		),
		$inner
	);
	return $out;
}

/**
 * Placement-dependent pairings, mirroring `sn_health_render_contrast_conditional()`.
 *
 * @param array $conditional Rows from the usage scan.
 * @return string Empty when there is nothing to show.
 */
function tools_reports_contrast_conditional( array $conditional ) {
	if ( empty( $conditional ) ) {
		return '';
	}
	$table_rows = array();
	foreach ( array_slice( $conditional, 0, defined( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS' ) ? SN_HEALTH_CONTRAST_USAGE_MAX_ROWS : 25 ) as $row ) {
		$table_rows[] = array(
			'selector' => (string) ( $row['selector'] ?? '' ),
			'pair'     => (string) ( $row['pair'] ?? '' ),
			'ratio'    => number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) . ':1',
			'palette'  => (string) ( $row['palette'] ?? '' ),
		);
	}
	$inner = '<p class="snt-hint">' . \snt_kit_esc( __( 'These components set no background of their own, so where they land decides whether they pass. Not counted above, because a stylesheet cannot say where they land.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_table(
			array(
				array( 'key' => 'selector', 'label' => __( 'Selector', 'signal-and-noise-tools' ) ),
				array( 'key' => 'pair', 'label' => __( 'Would be', 'signal-and-noise-tools' ) ),
				array( 'key' => 'ratio', 'label' => __( 'Ratio', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'palette', 'label' => __( 'Palette', 'signal-and-noise-tools' ) ),
			),
			$table_rows
		);
	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: %d: conditional pairing count */
				__( '%d placement-dependent pairing(s) — pass on the page background, would fail on another surface the design system paints', 'signal-and-noise-tools' ),
				count( $conditional )
			),
		),
		$inner
	);
}

/**
 * The usage tier, mirroring `sn_health_render_contrast_usage()`.
 *
 * @param array $usage The report's `usage` block.
 * @return string
 */
function tools_reports_contrast_usage( array $usage ) {
	$failures = isset( $usage['failures'] ) && is_array( $usage['failures'] ) ? $usage['failures'] : array();
	$palettes = isset( $usage['palettes'] ) && is_array( $usage['palettes'] ) ? $usage['palettes'] : array();
	$scanned  = (int) ( $usage['scanned'] ?? 0 );
	$declared = (int) ( $usage['pairings'] ?? 0 );

	$out = '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'Usage tier — pairings actually declared in stylesheets', 'signal-and-noise-tools' ) ) . '</b></p>';

	if ( 0 === $scanned ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( "No stylesheets were readable, so nothing was scored. This tier reads the plugin's front-end CSS and the active theme's.", 'signal-and-noise-tools' ) ) . '</p>';
		return $out;
	}

	$out .= '<p class="snt-prose">' . \snt_kit_esc(
		empty( $failures )
			? sprintf(
				/* translators: 1: declared pairing count, 2: stylesheet count, 3: palette count */
				__( 'No declared pairing falls below AA: %1$d pairings across %2$d stylesheets, each scored under %3$d palette(s).', 'signal-and-noise-tools' ),
				$declared,
				$scanned,
				count( $palettes )
			)
			: sprintf(
				/* translators: 1: failing count, 2: declared pairing count, 3: stylesheet count, 4: palette count */
				__( '%1$d of %2$d declared pairings fall below body-text AA, across %3$d stylesheets and %4$d palette(s).', 'signal-and-noise-tools' ),
				count( $failures ),
				$declared,
				$scanned,
				count( $palettes )
			)
	) . '</p>';

	if ( ! empty( $palettes ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: %s: comma-separated palette names */
				__( 'Scored under: %s. The served palette is listed first — a pairing that passes at root and fails under a variation is still a live defect for whoever is being shown that variation.', 'signal-and-noise-tools' ),
				implode( ', ', array_map( 'strval', $palettes ) )
			)
		) . '</p>';
	}

	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Reads stylesheet declarations at rest. Hover and focus states, colours inlined in block markup, and the computed cascade are invisible to it — those are measured by hand with tools/contrast-render-scan.mjs, and what it finds is pinned as a test rather than counted here. A clean count here means nothing declared in CSS fails at rest, not that the site passes.', 'signal-and-noise-tools' ) ) . '</p>';

	$out .= tools_reports_contrast_conditional( isset( $usage['conditional'] ) && is_array( $usage['conditional'] ) ? $usage['conditional'] : array() );

	if ( empty( $failures ) ) {
		return $out;
	}

	$visible    = array_slice( $failures, 0, defined( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS' ) ? SN_HEALTH_CONTRAST_USAGE_MAX_ROWS : 25 );
	$hidden     = count( $failures ) - count( $visible );
	$table_rows = array();
	foreach ( $visible as $row ) {
		$selector = (string) ( $row['selector'] ?? '' );
		if ( ! empty( $row['source'] ) ) {
			$selector .= ' (' . (string) $row['source'] . ')';
		}
		$pair = (string) ( $row['pair'] ?? '' );
		if ( ! empty( $row['literal'] ) ) {
			$pair .= ' [' . __( 'hardcoded', 'signal-and-noise-tools' ) . ']';
		}
		if ( empty( $row['anchored'] ) ) {
			$pair .= ' [' . __( 'on page background', 'signal-and-noise-tools' ) . ']';
		}
		$table_rows[] = array(
			'selector' => $selector,
			'pair'     => $pair,
			'ratio'    => number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) . ':1',
			'palette'  => (string) ( $row['palette'] ?? '' ),
		);
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'selector', 'label' => __( 'Selector', 'signal-and-noise-tools' ) ),
			array( 'key' => 'pair', 'label' => __( 'Pairing', 'signal-and-noise-tools' ) ),
			array( 'key' => 'ratio', 'label' => __( 'Ratio', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'palette', 'label' => __( 'Palette', 'signal-and-noise-tools' ) ),
		),
		$table_rows
	);
	if ( $hidden > 0 ) {
		$table .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: %d: hidden row count */
				__( '+%d more failing pairings, sorted worst-first — the tail is the safe end.', 'signal-and-noise-tools' ),
				$hidden
			)
		) . '</p>';
	}
	$out .= \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: %d: failing pairing count */
				_n( 'Show the %d failing pairing', 'Show the %d failing pairings', count( $failures ), 'signal-and-noise-tools' ),
				count( $failures )
			),
		),
		$table
	);
	return $out;
}

/**
 * The contrast report, mirroring `sn_health_render_contrast_report()`: the
 * usage tier leads, the arithmetic pair table sits collapsed beneath it.
 *
 * CHANGED SHAPE, both bounded by `<os-table>`'s data-only cells: (1) the two
 * verdict pills per row (glyph + colour + screen-reader word) collapse to a
 * plain "Pass"/"Fail N:1" string per threshold column — the verdict is still
 * stated in words, only the glyph/colour channel is gone; (2) the palette
 * swatches (an inline `style="background-color:…"` per house rule, re-
 * validated hex) are not painted — no kit component takes an arbitrary
 * colour, and this leaf paints no inline `style=` — so the token legend
 * shows slug + hex as plain chips, naming the same tokens without the
 * swatch. See the leaf's `unported`/`changed` notes.
 *
 * @param array $report The check's `report` payload.
 * @return string
 */
function tools_reports_render_contrast( array $report ) {
	$pairs      = isset( $report['pairs'] ) && is_array( $report['pairs'] ) ? $report['pairs'] : array();
	$tokens     = isset( $report['tokens'] ) && is_array( $report['tokens'] ) ? $report['tokens'] : array();
	$thresholds = isset( $report['thresholds'] ) && is_array( $report['thresholds'] ) ? $report['thresholds'] : array();
	$body_aa    = isset( $thresholds['aa_body'] ) ? (float) $thresholds['aa_body'] : 4.5;
	$large_aa   = isset( $thresholds['aa_large'] ) ? (float) $thresholds['aa_large'] : 3.0;
	$would_fail = (int) ( $report['would_fail_body'] ?? 0 );

	$out = tools_reports_contrast_usage( isset( $report['usage'] ) && is_array( $report['usage'] ) ? $report['usage'] : array() );

	if ( empty( $pairs ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'No theme palette tokens were readable, so no pairs were scored. A theme.json palette (theme or custom origin) is what this check reads.', 'signal-and-noise-tools' ) ) . '</p>';
		return $out;
	}

	$body_label  = tools_reports_threshold_label( $body_aa );
	$large_label = tools_reports_threshold_label( $large_aa );

	$visible = array_slice( $pairs, 0, defined( 'SN_HEALTH_CONTRAST_MAX_ROWS' ) ? SN_HEALTH_CONTRAST_MAX_ROWS : 60 );
	$hidden  = count( $pairs ) - count( $visible );

	$table_rows = array();
	foreach ( $visible as $row ) {
		$table_rows[] = array(
			'pair'  => (string) ( $row['pair'] ?? '' ),
			'ratio' => number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) . ':1',
			// The arithmetic tier's verdict is hedged — "would pass"/"would
			// fail", never bare "Pass"/"Fail" — because the pair may never
			// actually meet on a real page; see sn_health_contrast_verdict_pill_html()'s
			// docblock. The usage tier below is the one that says "fails".
			'body'  => ( ! empty( $row['aa_body'] ) ? __( 'Would pass', 'signal-and-noise-tools' ) : __( 'Would fail', 'signal-and-noise-tools' ) ) . ' ' . $body_label . ':1',
			'large' => ( ! empty( $row['aa_large'] ) ? __( 'Would pass', 'signal-and-noise-tools' ) : __( 'Would fail', 'signal-and-noise-tools' ) ) . ' ' . $large_label . ':1',
		);
	}

	$legend = '';
	foreach ( $tokens as $slug => $hex ) {
		$legend .= \snt_kit_chip( (string) $slug . ' ' . (string) $hex );
	}

	$inner = '<p class="snt-prose">' . \snt_kit_esc(
		sprintf(
			/* translators: 1: failing pair count, 2: total pair count, 3: body AA ratio, 4: token count */
			__( '%1$d of %2$d token pairs would fall below %3$s:1 (body-text AA) if rendered together, across %4$d palette tokens.', 'signal-and-noise-tools' ),
			$would_fail,
			count( $pairs ),
			$body_label,
			count( $tokens )
		)
	) . '</p>' . $legend . \snt_kit_table(
		array(
			array( 'key' => 'pair', 'label' => __( 'Token pair', 'signal-and-noise-tools' ) ),
			array( 'key' => 'ratio', 'label' => __( 'Ratio', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'body', 'label' => __( 'Would pass AA (body)', 'signal-and-noise-tools' ) ),
			array( 'key' => 'large', 'label' => __( 'Would pass AA (large)', 'signal-and-noise-tools' ) ),
		),
		$table_rows
	);

	if ( $hidden > 0 ) {
		$floor  = (float) ( $visible[ count( $visible ) - 1 ]['ratio'] ?? 0 );
		$inner .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: 1: hidden row count, 2: the lowest ratio still shown */
				__( '+%1$d more pairs, every one of them at %2$s:1 or better — the table is sorted worst-first, so the tail is the safe end.', 'signal-and-noise-tools' ),
				$hidden,
				number_format( $floor, 2 )
			)
		) . '</p>';
	}

	$out .= \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: 1: failing pair count, 2: total pair count */
				__( 'Arithmetic tier — %1$d of %2$d token pairs would fail if rendered together (palette property, moves only when a token changes)', 'signal-and-noise-tools' ),
				$would_fail,
				count( $pairs )
			),
		),
		$inner
	);

	return $out;
}
