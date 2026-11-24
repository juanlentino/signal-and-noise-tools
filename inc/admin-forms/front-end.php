<?php
/**
 * Signal & Noise: the front-end settings form, painted on Settings > Front
 * end (inc/admin-forms/settings-front-end.php).
 *
 * Eight settings the companion theme reads through the sn_tf_* callbacks in
 * inc/theme-filters.php: the theme asks apply_filters('sn_x', <default>) and
 * is unchanged when the plugin is absent (the defaults here are the theme's
 * own). The form was the whole of Site > Front-End until 2026-11 (Tools, then
 * Content, before that); labels, ids, hints, bounds and names are the ones it
 * had. What changed is the grouping: three headed boxes by subject.
 *
 * SAVE PATH, unchanged: sn_action=save_theme, sn_handle_save_theme()
 * (inc/admin-post-actions/theme-ai.php). ALL EIGHT FIELDS STAY IN THIS ONE
 * FORM: the handler writes each of them on every save, the six numbers from
 * `?? <default>`, the palette switch from `! empty()` and the alias from
 * `?? 'research'`, so a form that left one out would reset it
 * (tests/lib/whole-forms.php). The boxes are headings inside the form.
 *
 * Added in v4.12.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One bounded number field: label, input, helper. Every argument but the
 * value is a literal written in this file; the label and the helper are
 * HTML (entities, one <code>), so they are echoed as they are.
 *
 * @param string $name        Field name (the handler's key).
 * @param string $label_html  Label, static HTML.
 * @param int    $value       Stored value.
 * @param int    $min         Lower bound.
 * @param int    $max         Upper bound.
 * @param string $helper_html Helper, static HTML.
 * @return void
 */
function sn_admin_front_end_number( $name, $label_html, $value, $min, $max, $helper_html ) {
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_' . esc_attr( $name ) . '">' . $label_html . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal from this file.
	echo '<input type="number" min="' . (int) $min . '" max="' . (int) $max . '" id="sn_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (int) $value ) . '">';
	echo '<p class="sn-field-helper">' . $helper_html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal from this file.
	echo '</div>';
}

/**
 * The form: three boxes (Reading, Command palette, Replies) in one row, then
 * the one Save. The pane is 'wide' (no wrapper card), so each box is its own
 * .sn-fieldset and the save row is a .sn-fieldset-actions, never a bare
 * .sn-savebar: its negative card-bleed margin overflows a bare .sn-section
 * (the v6.43.1 IndexNow regression).
 *
 * @return void
 */
function sn_admin_render_front_end_form() {
	$penab  = (bool) sn_setting( 'theme.palette_enabled', true );
	$ralias = (string) sn_setting( 'theme.note_reply_alias', 'research' );

	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-front-end-form">';
	wp_nonce_field( 'sn_save_theme' );
	echo '<input type="hidden" name="action" value="sn_save_theme">';
	echo '<p class="sn-fieldset-intro">How the site&rsquo;s pages render; the companion theme reads these values. The defaults are the theme&rsquo;s own, so nothing changes until you save here.</p>';
	echo '<div class="sn-2up">';

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Reading</h2>';
	sn_admin_front_end_number( 'theme_related_count', 'Related notes shown', sn_setting( 'theme.related_count', 3 ), 1, 12, 'How many related notes appear under a single note (1 to 12).' );
	sn_admin_front_end_number( 'theme_updated_threshold_days', '&ldquo;Updated&rdquo; badge after (days)', sn_setting( 'theme.updated_threshold_days', 14 ), 1, 90, 'Show the &ldquo;Updated&rdquo; badge when a note was revised this many days after publishing (1 to 90).' );
	sn_admin_front_end_number( 'theme_reading_wpm', 'Reading speed (words/min)', sn_setting( 'theme.reading_wpm', 225 ), 100, 400, 'Words per minute used to estimate reading time (100 to 400).' );
	// Moved to WordPress's Settings > Reading (inc/reading-settings-screen.php).
	echo '<p class="sn-field-helper">Notes per page and JSON feed items are on <a href="' . esc_url( admin_url( 'options-reading.php#sn_reading_notes_per_page' ) ) . '">Settings &rsaquo; Reading</a>, with WordPress&rsquo;s own.</p>';
	echo '</div>';

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Command palette</h2>';
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Reader command palette</label>';
	echo '<label><input type="checkbox" id="sn_theme_palette_enabled" name="theme_palette_enabled" value="1"' . checked( $penab, true, false ) . '> Enable the &#8984;K command palette and its footer trigger</label>';
	echo '<p class="sn-field-helper">Turning this off hides the trigger and skips loading the palette&rsquo;s scripts and styles.</p>';
	echo '</div>';
	sn_admin_front_end_number( 'theme_palette_recent_count', 'Command-palette recent notes', sn_setting( 'theme.palette_recent_count', 8 ), 0, 20, 'Recent notes listed in the &#8984;K reader palette (0 to 20).' );
	echo '</div>';

	// A SELECT, deliberately not a text field: an arbitrary local part mints an
	// address the mailbox does not filter. The list mirrors the five /contact
	// aliases, and both the plugin filter and the theme validate against it.
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Replies</h2>';
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_note_reply_alias">Note reply goes to</label>';
	echo '<select id="sn_theme_note_reply_alias" name="theme_note_reply_alias">';
	foreach ( function_exists( 'sn_note_reply_aliases' ) ? sn_note_reply_aliases() : array( 'research' ) as $a ) {
		echo '<option value="' . esc_attr( $a ) . '"' . selected( $ralias, $a, false ) . '>' . esc_html( $a . '@' ) . '</option>';
	}
	echo '</select>';
	echo '<p class="sn-field-helper">Which existing alias the Reply row on a note writes to. Only aliases the mailbox already filters are offered; a new address would arrive unfiltered.</p>';
	echo '</div>';
	echo '</div>';

	echo '</div>'; // .sn-2up
	echo '<div class="sn-fieldset-actions">';
	echo '<p class="sn-fieldset-actions-hint">Saving a change refreshes the site&rsquo;s cached pages, so the live site shows it the next time a page loads.</p>';
	echo '<button type="submit" class="button button-primary">Save front-end settings</button>';
	echo '</div>';
	echo '</form>';
}
