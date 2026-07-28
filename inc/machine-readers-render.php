<?php
/**
 * Signal & Noise Tools — Machine Readers: pure renderers.
 *
 * SCAFFOLD (Session 3 plan, lane 2). Pure string-returning renderers over
 * normalized rows (canned-rows testable, native wp-admin markup, esc_html on
 * every cell even though enums are allowlisted). tests/machine-readers-render.php
 * is RED against this shell on purpose.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Families whose public declarations class them as AI-training crawlers —
 * the static half of the observed-vs-declared compliance read. Observation
 * only; the render NEVER claims verified identity (UAs are self-reported).
 *
 * @return string[]
 */
function snt_mr_ai_training_families() {
	return array( 'openai', 'anthropic', 'google-ai', 'commoncrawl', 'bytedance', 'apple-ai', 'meta-ai', 'mistral', 'cohere', 'allen-ai' );
}

/**
 * Family summary table (hits per family over the window), rows descending.
 *
 * @param array $rows Normalized rows (snt_mr_normalize_rows shape).
 * @param int   $days Window the rows cover.
 * @return string HTML.
 */
function snt_mr_render_family_table( $rows, $days ) {
	return ''; // Session 3 lane 2.
}

/**
 * Surface-class breakdown table (which machine surfaces get read).
 *
 * @param array $rows Normalized rows.
 * @return string HTML.
 */
function snt_mr_render_surface_table( $rows ) {
	return ''; // Session 3 lane 2.
}

/**
 * Observed-vs-declared compliance table: AI-training-class families crossed
 * with their `rights`-surface reads. Labeled observed-vs-declared, never
 * "verified".
 *
 * @param array $rows Normalized rows.
 * @return string HTML.
 */
function snt_mr_render_compliance( $rows ) {
	return ''; // Session 3 lane 2.
}
