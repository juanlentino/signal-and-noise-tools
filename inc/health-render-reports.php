<?php
/**
 * Signal & Noise Tools -- Health tab: report-only check payloads.
 *
 * The gap this closes: contrast_tokens (v10.82.0) ships a full pair table and
 * a coverage sentence, raises zero findings by design, and therefore appeared
 * on the Health tab as a single green chip in the passing strip. Its entire
 * output was invisible in admin -- readable only through the ability layer.
 * Report-only checks now have a section of their own, between the findings
 * (which demand action) and the passing disclosure (which asks for nothing).
 *
 * DISPATCH IS A REGISTRY, NOT A CHAIN OF ifs. A report with no bespoke
 * renderer still renders: the fallback prints its coverage sentence and says
 * plainly that the detail is unrendered. A future report-only check is
 * therefore visible the day it ships, degraded but never absent -- which is
 * the failure mode being fixed here, so it must not be reintroduced one tier
 * down.
 *
 * INLINE STYLE, DELIBERATE AND BOUNDED: the colour swatches carry
 * `style="background-color:#rrggbb"` because the value IS the data -- a
 * palette hex cannot live in a stylesheet without the stylesheet knowing the
 * site's palette. Every hex is re-validated against /^#[0-9a-f]{6}$/ at the
 * render site before it reaches the attribute. All LAYOUT stays in
 * assets/admin.css, per the house rule.
 *
 * @package SignalNoiseTools
 * @since 10.83.0
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
 * check key => renderer callable, taking ( array $report, array $check ).
 * Filterable so a module can own its own report without editing this file.
 *
 * @return array<string,callable>
 * @since 10.83.0
 */
function sn_health_report_renderers() {
	$renderers = array(
		'contrast_tokens' => 'sn_health_render_contrast_report',
		// Link isolation (ML pipeline #8, inc/ml-link-isolation.php) shipped
		// deliberately without a surface. This is its first one. The renderer
		// consumes only the PUBLISHED ENVELOPE SHAPE — it never calls
		// snt_ml_link_isolation() — so the two land in either order without
		// coupling: whichever branch packs the check, the surface is already
		// here, and if the check never arrives this entry is simply unused.
		'link_isolation'  => 'sn_health_render_link_isolation_report',
	);
	if ( function_exists( 'apply_filters' ) ) {
		$renderers = (array) apply_filters( 'sn_health_report_renderers', $renderers );
	}
	return $renderers;
}

/**
 * Render the Reports section: one card per report-only check.
 *
 * @param array<string,array> $reports key => check envelope (sn_health_report_checks()).
 * @since 10.83.0
 */
function sn_health_render_reports_section( $reports ) {
	$reports = (array) $reports;
	if ( empty( $reports ) ) {
		return;
	}

	echo '<h2 class="sn-section-h">Reports</h2>';
	echo '<p class="sn-health-reports__intro">Checks that measure and publish rather than flag. Nothing here is a defect list — read the coverage line before reading the numbers.</p>';
	echo '<div class="sn-health-reports">';

	$renderers = sn_health_report_renderers();
	foreach ( $reports as $key => $check ) {
		$report = isset( $check['report'] ) && is_array( $check['report'] ) ? $check['report'] : array();

		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
		echo esc_html( (string) ( $check['label'] ?? $key ) );
		// Base .sn-pill is the NEUTRAL gray chip — the correct semantic here.
		// A report is neither ok nor warn; colouring it green would restate the
		// exact overclaim this section exists to refuse.
		echo '<span class="sn-pill">report</span>';
		echo '</h2>';

		if ( ! empty( $report['coverage'] ) ) {
			echo '<p class="sn-health-report__coverage"><strong>What this covers:</strong> ' . esc_html( (string) $report['coverage'] ) . '</p>';
		}

		if ( isset( $renderers[ $key ] ) && is_callable( $renderers[ $key ] ) ) {
			call_user_func( $renderers[ $key ], $report, $check );
		} else {
			echo '<p class="sn-field-helper">This report has no detail view yet — its payload is available through the health-scan ability.</p>';
		}

		echo '</div>'; // .sn-fieldset
	}

	echo '</div>'; // .sn-health-reports
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
 * The contrast report: a headline count, the token legend, and the worst-first
 * pair table with an AA verdict per threshold.
 *
 * The verdict wording is "would fail", never "fails". The check scores every
 * unordered token PAIR arithmetically; which pairs a reader actually meets on
 * screen needs computed styles from a real render, and that tier does not
 * exist yet. The report's own coverage sentence says so above this table, and
 * the column header repeats it so a reader who skipped the prose still cannot
 * read a red row as a live defect.
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

	if ( empty( $pairs ) ) {
		echo '<p class="sn-field-helper">No theme palette tokens were readable, so no pairs were scored. A theme.json palette (theme or custom origin) is what this check reads.</p>';
		return;
	}

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
}

/**
 * The link-isolation report: which published notes nothing links to.
 *
 * Envelope (inc/ml-link-isolation.php, ML pipeline #8):
 *   {isolated[], isolated_count, isolated_total, posts_scanned, truncated}
 *
 * ISOLATED_TOTAL IS NEVER DROPPED. The producer caps `isolated` at a limit and
 * publishes the true total beside it precisely so a capped list cannot read as
 * "that is all there is" — rendering the rows without the total would throw
 * away the one field that keeps the surface honest, and would do it silently.
 * So the total leads the headline, and when `truncated` is set the remainder is
 * stated explicitly rather than left to be inferred from a row count.
 *
 * Deliberately NOT a findings table: an isolated note is an editorial
 * observation about graph topology, not a defect. It sits with the reports.
 *
 * @param array $report The check's `report` payload.
 * @since 10.83.0
 */
function sn_health_render_link_isolation_report( $report ) {
	$rows    = isset( $report['isolated'] ) && is_array( $report['isolated'] ) ? $report['isolated'] : array();
	$scanned = (int) ( $report['posts_scanned'] ?? 0 );
	// The TRUE total, always — falling back to the row count only when the
	// producer genuinely omitted it (an older envelope), never silently
	// preferring the shorter number.
	$total = array_key_exists( 'isolated_total', $report ) ? (int) $report['isolated_total'] : count( $rows );

	echo '<p class="sn-health-report__headline">';
	// esc_html( sprintf( ... ) ), not printf( esc_html__( ... ), ... ): the
	// latter escapes the TEMPLATE and leaves the interpolated values raw, which
	// is what PHPCS's EscapeOutput sniff is pointing at. These two happen to be
	// ints, so nothing was exploitable — but "safe because of what I know about
	// today's callers" is exactly the argument that stops being true later.
	echo esc_html(
		sprintf(
			/* translators: 1: isolated note count, 2: notes scanned */
			__( '%1$d of %2$d published notes have no inbound link from any other note.', 'signal-and-noise-tools' ),
			(int) $total,
			(int) $scanned
		)
	);
	echo '</p>';

	if ( empty( $rows ) ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'Every published note is reachable from at least one other note.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col" class="snt-col-55">' . esc_html__( 'Note', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Links out', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Action', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$post_id  = (int) ( $row['post_id'] ?? 0 );
		$outbound = (int) ( $row['outbound_count'] ?? 0 );
		echo '<tr>';
		echo '<td>' . esc_html( (string) ( $row['title'] ?? '' ) );
		if ( ! empty( $row['slug'] ) ) {
			echo '<br><small><code>' . esc_html( (string) $row['slug'] ) . '</code></small>';
		}
		echo '</td>';
		// A note isolated in BOTH directions is more stranded than a dead end
		// that still links out — the producer sorts on exactly that, so say it.
		echo '<td>' . esc_html( (string) $outbound );
		if ( 0 === $outbound ) {
			echo ' <span class="sn-badge">' . esc_html__( 'both ways', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</td>';
		echo '<td>';
		if ( $post_id > 0 && function_exists( 'get_edit_post_link' ) ) {
			$edit = get_edit_post_link( $post_id );
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '" class="button button-small">' . esc_html__( 'Edit', 'signal-and-noise-tools' ) . '</a>';
			}
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';

	$hidden = $total - count( $rows );
	if ( ! empty( $report['truncated'] ) || $hidden > 0 ) {
		echo '<p class="sn-field-helper">';
		echo esc_html(
			sprintf(
				/* translators: 1: rows shown, 2: true total */
				__( 'Showing %1$d of %2$d isolated notes — the list is capped, not complete.', 'signal-and-noise-tools' ),
				count( $rows ),
				(int) $total
			)
		);
		echo '</p>';
	}
}
