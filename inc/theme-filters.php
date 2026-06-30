<?php
/**
 * Signal & Noise — theme-filter callbacks (the cross-package contract).
 *
 * Supplies the configured sn_settings['theme'] values to the companion theme's
 * (and this plugin's) front-end filters. Named functions — not closures — so
 * the standalone CLI tests can call them directly. Each:
 *   - clamps/casts on the way out (defense-in-depth against a hand-edited
 *     option), matching the bounds the save handler enforces;
 *   - falls back to the THEME-supplied default ($d) when the setting is unset,
 *     so the two packages' defaults stay reconciled and the theme renders
 *     identically whether or not this plugin is active.
 *
 * The theme calls apply_filters('sn_x', <its-own-default>); when this plugin is
 * absent the filter is a no-op and the theme uses that default. When present,
 * these callbacks return the configured (or clamped/validated) value instead.
 *
 * Added in v4.12.0 (settings-hygiene batch A).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param int $d Theme-supplied default.
 * @return int Related-notes count (1–12).
 */
function sn_tf_related_count( $d ) {
	return max( 1, min( 12, (int) sn_setting( 'theme.related_count', $d ) ) );
}

/**
 * @param int $d Theme-supplied default.
 * @return int Command-palette recent-notes count (0–20).
 */
function sn_tf_palette_recent_count( $d ) {
	return max( 0, min( 20, (int) sn_setting( 'theme.palette_recent_count', $d ) ) );
}

/**
 * @param bool $d Theme-supplied default.
 * @return bool Whether the reader command palette is enabled.
 */
function sn_tf_palette_enabled( $d ) {
	return (bool) sn_setting( 'theme.palette_enabled', $d );
}

/**
 * @param int $d Theme-supplied default.
 * @return int JSON-feed item count (1–50).
 */
function sn_tf_json_feed_items( $d ) {
	return max( 1, min( 50, (int) sn_setting( 'theme.json_feed_items', $d ) ) );
}

/**
 * @param int $d Theme-supplied default.
 * @return int "Updated" badge threshold in days (1–90).
 */
function sn_tf_updated_threshold( $d ) {
	return max( 1, min( 90, (int) sn_setting( 'theme.updated_threshold_days', $d ) ) );
}

/**
 * @param int $d Theme-supplied default.
 * @return int Reading-time words per minute (100–400).
 */
function sn_tf_reading_wpm( $d ) {
	return max( 100, min( 400, (int) sn_setting( 'theme.reading_wpm', $d ) ) );
}

/**
 * Filter: /notes index page size. The theme applies apply_filters('sn_notes_per_page', 20);
 * we supply the configured value, clamped [1,100] (defense-in-depth vs a tampered option).
 */
function sn_tf_notes_per_page( $default ) {
	return max( 1, min( 100, (int) sn_setting( 'theme.notes_per_page', $default ) ) );
}

/**
 * Validate the configured AI model against the allowlist; fall back to the
 * supplied default when the stored id is off-list (a hand-edited option, or a
 * model that was removed from sn_theme_ai_models() after being configured).
 *
 * v6.52.0: feature-aware. The owner's text-model dropdown choice must NOT
 * clobber the per-feature routes, e.g. the alt-text route pins a Gemini vision
 * model. Both this filter and that route hook snt_ai_model_preference at
 * priority 10, so registration order alone can't guarantee the route wins;
 * instead this callback passes the 'alt-text' feature straight through. Every
 * other feature applies the owner's configured (allowlisted) model. Registered
 * with accepted_args = 4 so $feature is received.
 *
 * @param string $d       Incoming model id (the running filter value / default).
 * @param string $prompt  Unused; present for the 4-arg filter signature.
 * @param string $system  Unused; present for the 4-arg filter signature.
 * @param string $feature The SN AI feature key (e.g. 'alt-text', 'generic').
 * @return string An allowlisted model id, or $d unchanged for feature routes.
 */
function sn_tf_ai_model( $d, $prompt = '', $system = '', $feature = '' ) {
	// Per-feature routes (alt-text → Gemini vision) own their model; never let
	// the text-model dropdown choice override them.
	if ( 'alt-text' === $feature ) {
		return (string) $d;
	}
	$id = (string) sn_setting( 'theme.ai_model', $d );
	return in_array( $id, array_keys( sn_theme_ai_models() ), true ) ? $id : (string) $d;
}

// Register against the theme/plugin filters. Skipped under the CLI test harness
// (which exercises the callbacks directly and does not stub add_filter).
if ( ! defined( 'SN_THEME_FILTERS_TEST' ) || ! SN_THEME_FILTERS_TEST ) {
	add_filter( 'sn_related_count', 'sn_tf_related_count' );
	add_filter( 'sn_palette_recent_count', 'sn_tf_palette_recent_count' );
	add_filter( 'sn_palette_enabled', 'sn_tf_palette_enabled' );
	add_filter( 'sn_json_feed_items', 'sn_tf_json_feed_items' );
	add_filter( 'sn_updated_date_threshold_days', 'sn_tf_updated_threshold' );
	add_filter( 'sn_reading_time_wpm', 'sn_tf_reading_wpm' );
	add_filter( 'sn_notes_per_page', 'sn_tf_notes_per_page' );
	add_filter( 'snt_ai_model_preference', 'sn_tf_ai_model', 10, 4 );
}
