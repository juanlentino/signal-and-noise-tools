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

// IA increment H2: the contrast renderer (swatch, verdict pill, threshold
// format, report + usage + conditional) moved to its own module so the motion
// renderer does not push this file past the house line cap. Required from
// here — not only from the plugin loader — so every existing load site of
// this file (tests included) keeps getting the whole renderer set.
require_once __DIR__ . '/health-render-contrast.php';
require_once __DIR__ . '/health-render-motion.php';

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
		// IA H3: the motion report's first detail view. The degrading fallback
		// below stays for any OTHER unknown report — that path is the contract.
		'motion_scan'     => 'sn_health_render_motion_report',
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
