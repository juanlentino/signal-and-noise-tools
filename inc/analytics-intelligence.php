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
 * Render the digest section (read + forms). Filled in Task 2.
 *
 * @param bool $ai_ready Whether the AI client is configured.
 * @return void
 */
function snt_intelligence_render_digest( $ai_ready ) {}
