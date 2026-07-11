<?php
/**
 * Signal & Noise — Front-End settings form (Tools tab → Front-End sub-tab).
 *
 * Renders the render-knob form (sn_action=save_theme → sn_handle_save_theme).
 * These values feed the companion theme's front-end filters via the
 * sn_tf_* callbacks in inc/theme-filters.php — the theme reads each as
 * apply_filters('sn_x', <default>) and is unchanged when the plugin is absent
 * (defaults match the theme's own hardcoded defaults). Mirrors the
 * Identity & SEO / Performance forms: native WP styling, .sn-field classes,
 * a single Save button via the shared .sn-savebar.
 *
 * Added in v4.12.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Front-End section body. Used as the sn_admin_render_section()
 * callback for the 'front-end' sub-tab.
 */
function sn_admin_render_front_end_form() {
	$related = (int) sn_setting( 'theme.related_count', 3 );
	$precent = (int) sn_setting( 'theme.palette_recent_count', 8 );
	$penab   = (bool) sn_setting( 'theme.palette_enabled', true );
	$jfeed   = (int) sn_setting( 'theme.json_feed_items', 20 );
	$uthr    = (int) sn_setting( 'theme.updated_threshold_days', 14 );
	$wpm     = (int) sn_setting( 'theme.reading_wpm', 225 );
	$nperp   = (int) sn_setting( 'theme.notes_per_page', 20 );
	$model     = (string) sn_setting( 'theme.ai_model', 'claude-sonnet-5' );
	$alt_model = (string) sn_setting( 'theme.ai_alt_model', 'gemini-2.5-flash-lite' );
	$budget    = (float) sn_setting( 'theme.ai_monthly_budget', 0 );
	$spent     = function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0;

	echo '<form method="post" class="sn-front-end-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="save_theme">';

	// Phase 4b: this leaf is 'wide' (bare .sn-section, no wrapper card), so the
	// form owns its own .sn-fieldset. The field-grid CSS (.sn-front-end-form
	// .sn-fieldset) lays the eight render knobs out in auto-fit columns; the
	// heading + intro span all columns. Mirrors the Identity Phase 4a treatment.
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Front-End</h2>';
	echo '<p class="sn-fieldset-intro">Render knobs the companion theme reads via filters. Defaults match the theme&rsquo;s own hardcoded values, so changes apply only once you save here. Each takes effect on the next front-end request.</p>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_related_count">Related notes shown</label>';
	echo '<input type="number" min="1" max="12" id="sn_theme_related_count" name="theme_related_count" value="' . esc_attr( $related ) . '">';
	echo '<p class="sn-field-helper">How many related notes appear under a single note (1&ndash;12).</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_palette_recent_count">Command-palette recent notes</label>';
	echo '<input type="number" min="0" max="20" id="sn_theme_palette_recent_count" name="theme_palette_recent_count" value="' . esc_attr( $precent ) . '">';
	echo '<p class="sn-field-helper">Recent notes listed in the &#8984;K reader palette (0&ndash;20).</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Reader command palette</label>';
	echo '<label><input type="checkbox" id="sn_theme_palette_enabled" name="theme_palette_enabled" value="1"' . checked( $penab, true, false ) . '> Enable the &#8984;K command palette and its footer trigger</label>';
	echo '<p class="sn-field-helper">Turning this off hides the trigger and skips the palette&rsquo;s JS/CSS entirely.</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_json_feed_items">JSON feed items</label>';
	echo '<input type="number" min="1" max="50" id="sn_theme_json_feed_items" name="theme_json_feed_items" value="' . esc_attr( $jfeed ) . '">';
	echo '<p class="sn-field-helper">Number of notes in the JSON feed (1&ndash;50).</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_updated_threshold_days">&ldquo;Updated&rdquo; badge after (days)</label>';
	echo '<input type="number" min="1" max="90" id="sn_theme_updated_threshold_days" name="theme_updated_threshold_days" value="' . esc_attr( $uthr ) . '">';
	echo '<p class="sn-field-helper">Show the &ldquo;Updated&rdquo; badge when a note was revised this many days after publishing (1&ndash;90).</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_reading_wpm">Reading speed (words/min)</label>';
	echo '<input type="number" min="100" max="400" id="sn_theme_reading_wpm" name="theme_reading_wpm" value="' . esc_attr( $wpm ) . '">';
	echo '<p class="sn-field-helper">Words per minute used to estimate reading time (100&ndash;400).</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_notes_per_page">Notes per page</label>';
	echo '<input type="number" min="1" max="100" id="sn_theme_notes_per_page" name="theme_notes_per_page" value="' . esc_attr( $nperp ) . '">';
	echo '<p class="sn-field-helper">How many notes per page on the <code>/notes</code> index (1&ndash;100). Pagination appears once published notes exceed this.</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="sn_theme_ai_model">AI model</label>';
	echo '<select id="sn_theme_ai_model" name="theme_ai_model">';
	foreach ( sn_theme_ai_models() as $id => $label ) {
		echo '<option value="' . esc_attr( $id ) . '"' . selected( $model, $id, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="sn-field-helper">Model used for AI-assisted prose features (drafts, insights, meta descriptions).</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="sn_theme_ai_alt_model">Vision model (alt text)</label>';
	echo '<select id="sn_theme_ai_alt_model" name="theme_ai_alt_model">';
	foreach ( sn_theme_ai_vision_models() as $id => $label ) {
		echo '<option value="' . esc_attr( $id ) . '"' . selected( $alt_model, $id, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="sn-field-helper">Model used to LOOK at images when suggesting alt text. The <code>snt_ai_alt_text_model</code> filter still overrides this for code-level pins.</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_ai_monthly_budget">Monthly AI budget (USD)</label>';
	echo '<input type="number" min="0" step="0.5" id="sn_theme_ai_monthly_budget" name="theme_ai_monthly_budget" value="' . esc_attr( number_format( $budget, 2, '.', '' ) ) . '">';
	echo '<p class="sn-field-helper">Hard cap on this plugin&rsquo;s own AI spend per calendar month. <strong>0 = no limit.</strong> When reached, AI features pause until the next month or you raise this. This month so far: <strong>$' . esc_html( number_format( $spent, 2 ) ) . '</strong>.</p>';
	echo '</div>';

	// Wide leaf → no wrapper card → the save row must be a card-owned
	// .sn-fieldset-actions INSIDE the .sn-fieldset, NOT a bare .sn-savebar: the
	// savebar's negative card-bleed margin overflows the zero-padding bare
	// .sn-section (the v6.43.1 IndexNow regression). Matches Performance +
	// IndexNow. CSS spans it across the field grid via grid-column: 1 / -1.
	echo '<div class="sn-fieldset-actions">';
	echo '<p class="sn-fieldset-actions-hint">Changes apply on the next front-end request. Live site re-renders automatically.</p>';
	echo '<button type="submit" class="button button-primary">Save front-end settings</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset

	echo '</form>';
}
