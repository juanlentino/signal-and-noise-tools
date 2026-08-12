<?php
/**
 * Signal & Noise Tools -- Health tab: the contrast report renderer.
 *
 * IA increment H2 (2026-08-12): a mechanical extraction from
 * inc/health-render-reports.php, moved so the motion renderer (H3) does not
 * push that file past the house line cap. Every function here is byte-for-byte
 * the v10.90.0-era renderer; the reports file keeps the registry, the section
 * chrome, the degrading fallback, and link isolation.
 *
 * INLINE STYLE, DELIBERATE AND BOUNDED: the colour swatches carry
 * `style="background-color:#rrggbb"` because the value IS the data -- a
 * palette hex cannot live in a stylesheet without the stylesheet knowing the
 * site's palette. Every hex is re-validated against /^#[0-9a-f]{6}$/ at the
 * render site before it reaches the attribute. All LAYOUT stays in
 * assets/admin.css, per the house rule.
 *
 * @package SignalNoiseTools
 * @since 10.83.0 (functions), extracted in the IA H2 increment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Row cap for the contrast pair table. The table is sorted worst-first, so a
 * cap truncates the SAFE tail: everything hidden scores at least as well as
 * the last row shown, and the remainder line says so.
 */
if ( ! defined( 'SN_HEALTH_CONTRAST_MAX_ROWS' ) ) {
	define( 'SN_HEALTH_CONTRAST_MAX_ROWS', 60 );
}

/**
 * A palette swatch, or an empty string when the hex is not a normalized
 * '#rrggbb'. Re-validating here (rather than trusting the producer) keeps the
 * only inline-style attribute on the page provably a colour literal.
 *
 * @param string $hex Candidate colour.
 * @return string Swatch markup, escaped.
 * @since 10.83.0
 */
function sn_health_contrast_swatch_html( $hex ) {
	$hex = strtolower( trim( (string) $hex ) );
	if ( ! preg_match( '/^#[0-9a-f]{6}$/', $hex ) ) {
		return '';
	}
	return '<span class="sn-swatch" style="background-color:' . esc_attr( $hex ) . '" aria-hidden="true"></span>';
}

/**
 * One AA verdict pill.
 *
 * THE VERDICT MUST NOT LIVE IN THE COLOUR ALONE. The first cut of this table
 * distinguished pass from fail with .sn-pill--ok vs .sn-pill--warn and nothing
 * else — WCAG 1.4.1 (Use of Color), shipped inside the contrast report, which
 * would be a self-undermining surface. So the pill carries a glyph for sighted
 * readers who cannot separate the hues, and .screen-reader-text words for
 * anyone not seeing it at all. The colour is now the third channel, not the
 * only one.
 *
 * @param bool   $passes    Whether the pair clears this threshold.
 * @param string $threshold Rendered threshold, e.g. '4.5'.
 * @param string $scope     'body' or 'large'.
 * @return string Escaped markup.
 * @since 10.83.0
 */
function sn_health_contrast_verdict_pill_html( $passes, $threshold, $scope ) {
	$glyph = $passes ? '✓' : '✕';
	$word  = $passes
		? esc_html__( 'would pass', 'signal-and-noise-tools' )
		: esc_html__( 'would fail', 'signal-and-noise-tools' );

	return '<span class="sn-pill ' . ( $passes ? 'sn-pill--ok' : 'sn-pill--warn' ) . '">'
		. '<span aria-hidden="true">' . esc_html( $glyph ) . '</span> '
		. '<span class="screen-reader-text">' . $word . ' </span>'
		. esc_html( $scope . ' ' . $threshold . ':1' )
		. '</span>';
}

/**
 * Render a threshold as the shortest honest decimal: 4.5 stays "4.5", 3.0
 * becomes "3". A trailing ".0" in a ratio reads as false precision.
 *
 * @param float $ratio Threshold.
 * @return string
 * @since 10.83.0
 */
function sn_health_contrast_format_threshold( $ratio ) {
	return rtrim( rtrim( number_format( (float) $ratio, 1, '.', '' ), '0' ), '.' );
}

/**
 * The contrast report: the usage tier leads the card, and the arithmetic pair
 * table sits COLLAPSED beneath it (owner decision, 2026-08-11, made after the
 * arithmetic count misled as a headline three separate times).
 *
 * The arithmetic verdict wording is "would fail", never "fails". The check
 * scores every unordered token PAIR arithmetically; which pairs a reader
 * actually meets on screen is the usage tier's question, where the wording
 * flips to "fails" because something on the page is wearing them. The
 * arithmetic count is not a defect count and will NEVER drop when defects are
 * fixed — it is a property of the palette, not of the site. Its two real jobs
 * survive the collapse: the count stays visible in the <summary> as a
 * palette-drift tripwire (any movement means a token or variation changed),
 * and the expanded table remains the risk pool the usage tier draws from.
 *
 * @see sn_health_render_contrast_usage() for the usage tier and its own limits.
 *
 * @param array $report The check's `report` payload.
 * @since 10.83.0
 */
function sn_health_render_contrast_report( $report ) {
	$pairs      = isset( $report['pairs'] ) && is_array( $report['pairs'] ) ? $report['pairs'] : array();
	$tokens     = isset( $report['tokens'] ) && is_array( $report['tokens'] ) ? $report['tokens'] : array();
	$thresholds = isset( $report['thresholds'] ) && is_array( $report['thresholds'] ) ? $report['thresholds'] : array();
	$body_aa    = isset( $thresholds['aa_body'] ) ? (float) $thresholds['aa_body'] : 4.5;
	$large_aa   = isset( $thresholds['aa_large'] ) ? (float) $thresholds['aa_large'] : 3.0;
	$would_fail = (int) ( $report['would_fail_body'] ?? 0 );

	sn_health_render_contrast_usage( isset( $report['usage'] ) && is_array( $report['usage'] ) ? $report['usage'] : array() );

	if ( empty( $pairs ) ) {
		echo '<p class="sn-field-helper">No theme palette tokens were readable, so no pairs were scored. A theme.json palette (theme or custom origin) is what this check reads.</p>';
		return;
	}

	// The whole arithmetic tier collapses; the tripwire count lives in the
	// summary so drift is visible without expanding.
	echo '<details class="sn-health-contrast-arithmetic sn-disclosure">';
	echo '<summary>';
	printf(
		/* translators: 1: failing pair count, 2: total pair count */
		esc_html__( 'Arithmetic tier — %1$d of %2$d token pairs would fail if rendered together (palette property, moves only when a token changes)', 'signal-and-noise-tools' ),
		(int) $would_fail,
		count( $pairs )
	);
	echo '</summary>';

	// Headline: the one number, stated as a proportion so a big raw count on a
	// large palette does not read as a big problem.
	echo '<p class="sn-health-report__headline">';
	printf(
		/* translators: 1: failing pair count, 2: total pair count, 3: body AA ratio, 4: token count */
		esc_html__( '%1$d of %2$d token pairs would fall below %3$s:1 (body-text AA) if rendered together, across %4$d palette tokens.', 'signal-and-noise-tools' ),
		(int) $would_fail,
		count( $pairs ),
		esc_html( sn_health_contrast_format_threshold( $body_aa ) ),
		count( $tokens )
	);
	echo '</p>';

	// Token legend: slugs, not hexes, are what the templates actually name.
	if ( ! empty( $tokens ) ) {
		echo '<p class="sn-health-report__tokens">';
		foreach ( $tokens as $slug => $hex ) {
			echo '<span class="sn-badge sn-badge--token">';
			echo sn_health_contrast_swatch_html( $hex ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper validates the hex and esc_attr()s it.
			echo esc_html( (string) $slug );
			echo '</span>';
		}
		echo '</p>';
	}

	$visible = array_slice( $pairs, 0, SN_HEALTH_CONTRAST_MAX_ROWS );
	$hidden  = count( $pairs ) - count( $visible );

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col" class="snt-col-55">' . esc_html__( 'Token pair', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Ratio', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Would pass AA', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	$body_label  = sn_health_contrast_format_threshold( $body_aa );
	$large_label = sn_health_contrast_format_threshold( $large_aa );

	foreach ( $visible as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) ( $row['pair'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) ) . ':1</td>';
		echo '<td>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every part.
		echo sn_health_contrast_verdict_pill_html( ! empty( $row['aa_body'] ), $body_label, 'body' );
		echo ' ';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every part.
		echo sn_health_contrast_verdict_pill_html( ! empty( $row['aa_large'] ), $large_label, 'large' );
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';

	if ( $hidden > 0 ) {
		$floor = (float) ( $visible[ count( $visible ) - 1 ]['ratio'] ?? 0 );
		echo '<p class="sn-field-helper">';
		printf(
			/* translators: 1: hidden row count, 2: the lowest ratio still shown */
			esc_html__( '+%1$d more pairs, every one of them at %2$s:1 or better — the table is sorted worst-first, so the tail is the safe end.', 'signal-and-noise-tools' ),
			(int) $hidden,
			esc_html( number_format( $floor, 2 ) )
		);
		echo '</p>';
	}

	echo '</details>';
}

/**
 * The usage tier — the card's LEADING block since the arithmetic table
 * collapsed (owner decision, 2026-08-11).
 *
 * THE WORDING FLIPS HERE, and that is the point of the whole block. In the
 * collapsed arithmetic table below, a red row is "would fail" — a pairing
 * nobody may ever meet. Here it is "fails": the pairing is declared in a
 * stylesheet, so something on the page is wearing it.
 *
 * BECAUSE THIS IS NOW THE HEADLINE, its limits have to be stated where the
 * headline is read, not only in the card's coverage sentence. A stylesheet scan
 * reads DECLARATIONS, not computed styles, so three things are invisible to it:
 * non-resting states (docs/r3-prep.md §3C's own example is a :hover link that
 * measured 3.29:1), colours inlined in block markup, and the computed cascade.
 * This scan is the part answerable without a browser, and it found four real
 * defects on its own.
 *
 * WHAT CLOSES THOSE THREE, decided 2026-08-12: not a fourth panel tier.
 * tools/contrast-render-scan.mjs already reads computed styles and forces
 * :hover / :focus-visible, which is §3C's own criterion — but it is a HAND-RUN
 * laptop instrument, and the workflow is find-then-pin: run it, pin what it
 * finds in tests/*.php (as v10.90.1 did with the 3.29:1 hover). A recurring
 * rendered census and a Health-panel ingest were scoped and DECLINED
 * (docs/proposals/render-scan-deterministic.md, Increments 1-6). So the limits
 * line below must not defer to a coming tier — there isn't one. It names the
 * instrument instead, because telling a reader to wait for a surface nobody
 * will build is worse than telling them nothing.
 *
 * So "0 failing" here means "nothing declared in a stylesheet fails at rest",
 * which is a real and previously unavailable fact, and is NOT "the site passes".
 * The limits line under the headline says so in the panel itself.
 *
 * @param array $usage The report's `usage` block.
 * @since 10.90.0
 */
function sn_health_render_contrast_usage( $usage ) {
	$failures = isset( $usage['failures'] ) && is_array( $usage['failures'] ) ? $usage['failures'] : array();
	$palettes = isset( $usage['palettes'] ) && is_array( $usage['palettes'] ) ? $usage['palettes'] : array();
	$scanned  = (int) ( $usage['scanned'] ?? 0 );
	$declared = (int) ( $usage['pairings'] ?? 0 );

	echo '<h4 class="sn-health-report__subhead">' . esc_html__( 'Usage tier — pairings actually declared in stylesheets', 'signal-and-noise-tools' ) . '</h4>';

	if ( 0 === $scanned ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'No stylesheets were readable, so nothing was scored. This tier reads the plugin\'s front-end CSS and the active theme\'s.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	echo '<p class="sn-health-report__headline">';
	if ( empty( $failures ) ) {
		printf(
			/* translators: 1: declared pairing count, 2: stylesheet count, 3: palette count */
			esc_html__( 'No declared pairing falls below AA: %1$d pairings across %2$d stylesheets, each scored under %3$d palette(s).', 'signal-and-noise-tools' ),
			(int) $declared,
			(int) $scanned,
			count( $palettes )
		);
	} else {
		printf(
			/* translators: 1: failing count, 2: declared pairing count, 3: stylesheet count, 4: palette count */
			esc_html__( '%1$d of %2$d declared pairings fall below body-text AA, across %3$d stylesheets and %4$d palette(s).', 'signal-and-noise-tools' ),
			count( $failures ),
			(int) $declared,
			(int) $scanned,
			count( $palettes )
		);
	}
	echo '</p>';

	if ( ! empty( $palettes ) ) {
		echo '<p class="sn-field-helper">';
		printf(
			/* translators: %s: comma-separated palette names */
			esc_html__( 'Scored under: %s. The served palette is listed first — a pairing that passes at root and fails under a variation is still a live defect for whoever is being shown that variation.', 'signal-and-noise-tools' ),
			esc_html( implode( ', ', array_map( 'strval', $palettes ) ) )
		);
		echo '</p>';
	}

	// The limits belong beside the headline, not only in the card's coverage
	// sentence — a reader who trusts a "0 failing" headline is exactly the
	// reader who did not scroll up to read the caveat.
	echo '<p class="sn-field-helper">';
	esc_html_e( 'Reads stylesheet declarations at rest. Hover and focus states, colours inlined in block markup, and the computed cascade are invisible to it — those are measured by hand with tools/contrast-render-scan.mjs, and what it finds is pinned as a test rather than counted here. A clean count here means nothing declared in CSS fails at rest, not that the site passes.', 'signal-and-noise-tools' );
	echo '</p>';

	sn_health_render_contrast_conditional( isset( $usage['conditional'] ) && is_array( $usage['conditional'] ) ? $usage['conditional'] : array() );

	if ( empty( $failures ) ) {
		return;
	}

	$visible = array_slice( $failures, 0, SN_HEALTH_CONTRAST_USAGE_MAX_ROWS );
	$hidden  = count( $failures ) - count( $visible );

	// IA increment H1: the row TABLE folds; the headline, palette line, and
	// limits sentence above stay open — the honesty layer is not collapsible.
	// The summary re-states the count so a closed fold can never hide THAT
	// there is something inside, only the row-by-row evidence.
	echo '<details class="sn-health-contrast-usage sn-disclosure"><summary>';
	printf(
		/* translators: %d: failing pairing count. */
		esc_html( _n( 'Show the %d failing pairing', 'Show the %d failing pairings', count( $failures ), 'signal-and-noise-tools' ) ),
		count( $failures )
	);
	echo '</summary>';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Selector', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Pairing', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Ratio', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Palette', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $visible as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) ( $row['selector'] ?? '' ) ) . '</code>';
		echo '<br><span class="sn-field-helper">' . esc_html( (string) ( $row['source'] ?? '' ) ) . '</span></td>';
		echo '<td><code>' . esc_html( (string) ( $row['pair'] ?? '' ) ) . '</code>';
		if ( ! empty( $row['literal'] ) ) {
			// A hardcoded hex renders identically under every variation, so it is
			// a fidelity problem as well as a contrast one. Say which it is.
			echo ' <span class="sn-badge">' . esc_html__( 'hardcoded', 'signal-and-noise-tools' ) . '</span>';
		}
		if ( empty( $row['anchored'] ) ) {
			echo ' <span class="sn-badge">' . esc_html__( 'on page background', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . esc_html( number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) ) . ':1</td>';
		echo '<td>' . esc_html( (string) ( $row['palette'] ?? '' ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';

	if ( $hidden > 0 ) {
		echo '<p class="sn-field-helper">';
		printf(
			/* translators: %d: hidden row count */
			esc_html__( '+%d more failing pairings, sorted worst-first — the tail is the safe end.', 'signal-and-noise-tools' ),
			(int) $hidden
		);
		echo '</p>';
	}
	echo '</details>';
}

/**
 * Components that clear the page background but would fail on another surface
 * this design system actually paints.
 *
 * DELIBERATELY NOT IN THE HEADLINE COUNT. A component with no background of its
 * own has a contrast that depends on where it is placed, and no stylesheet scan
 * can say where that is — counting these as live defects would repeat, one tier
 * down, exactly the overclaim that made the arithmetic table misleading.
 *
 * They are still worth showing: the provenance chip's `muted` state passes on
 * white at 4.83:1 and fails on the served asphalt at 3.66:1, which is the shape
 * of thing that ships unnoticed for months. So: collapsed, counted, and worded
 * as a conditional.
 *
 * @param array $conditional Rows from the usage scan.
 * @since 10.90.0
 */
function sn_health_render_contrast_conditional( $conditional ) {
	if ( empty( $conditional ) ) {
		return;
	}

	echo '<details class="sn-health-contrast-conditional sn-disclosure">';
	echo '<summary>';
	printf(
		/* translators: %d: conditional pairing count */
		esc_html__( '%d placement-dependent pairing(s) — pass on the page background, would fail on another surface the design system paints', 'signal-and-noise-tools' ),
		count( $conditional )
	);
	echo '</summary>';

	echo '<p class="sn-field-helper">' . esc_html__( 'These components set no background of their own, so where they land decides whether they pass. Not counted above, because a stylesheet cannot say where they land.', 'signal-and-noise-tools' ) . '</p>';

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Selector', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Would be', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Ratio', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Palette', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( array_slice( $conditional, 0, SN_HEALTH_CONTRAST_USAGE_MAX_ROWS ) as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) ( $row['selector'] ?? '' ) ) . '</code>';
		echo '<br><span class="sn-field-helper">' . esc_html( (string) ( $row['source'] ?? '' ) ) . '</span></td>';
		echo '<td><code>' . esc_html( (string) ( $row['pair'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) ) . ':1</td>';
		echo '<td>' . esc_html( (string) ( $row['palette'] ?? '' ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
	echo '</details>';
}
