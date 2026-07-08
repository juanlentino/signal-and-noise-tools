<?php
/**
 * Signal & Noise Tools — Analytics → Intelligence tab (slice a).
 *
 * The interpretation home on the Analytics dashboard. Slice (a) hosts the
 * weekly-digest READ (from the narrator's 7-day cache), a Refresh/Generate
 * action, and the digest-automation toggle — relocated from the Insights page.
 * The digest data layer (inc/insights-narration.php) is unchanged; this file
 * only renders. Slices (b) Recommendations and (c) Ask-your-analytics extend
 * this view later.
 *
 * @package SignalNoiseTools
 * @since 9.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Intelligence view body. Called by the analytics dispatcher's
 * `case 'intelligence'`. Owns-chrome: no pageview header ran above it.
 *
 * @return void
 */
function snt_analytics_render_intelligence_view() {
	$ai_ready = function_exists( 'snt_ai_is_available' ) && snt_ai_is_available();
	snt_intelligence_render_digest( $ai_ready );
}

/**
 * Render the digest section: the weekly-digest read from the narrator's 7-day
 * cache (headline + prose + highlights + generated-timestamp), or an empty
 * state when nothing is cached. Read-only — never triggers a generation; the
 * Refresh/Generate action (Task 3) does that. Cookieless: only renders the
 * prose the narrator already produced under its aggregate-only guardrail.
 *
 * @param bool $ai_ready Whether the AI client is configured.
 * @return void
 */
function snt_intelligence_render_digest( $ai_ready ) {
	$narration = function_exists( 'snt_narration_last' ) ? snt_narration_last() : null;

	snt_an_panel_open( 'This week' );

	if ( is_array( $narration ) ) {
		echo '<p class="sn-an-lede">' . esc_html( (string) $narration['headline'] ) . '</p>';
		foreach ( (array) $narration['paragraphs'] as $p ) {
			echo '<p>' . esc_html( (string) $p ) . '</p>';
		}
		if ( ! empty( $narration['highlights'] ) ) {
			echo '<p class="sn-an-chips">';
			foreach ( (array) $narration['highlights'] as $h ) {
				echo '<span class="sn-an-chip">' . esc_html( (string) $h ) . '</span> ';
			}
			echo '</p>';
		}
		$elapsed = function_exists( 'snt_health_format_elapsed' ) ? snt_health_format_elapsed( (int) $narration['elapsed_ms'] ) : ( (int) $narration['elapsed_ms'] . 'ms' );
		echo '<p class="sn-an-meta">Generated ' . esc_html( human_time_diff( (int) $narration['generated_at'] ) ) . ' ago &middot; in ' . esc_html( $elapsed ) . '.</p>';
	} else {
		echo '<p class="sn-an-meta">No digest yet. Generate one below (~$0.01, cached 7 days).</p>';
	}

	snt_an_panel_close();
}
