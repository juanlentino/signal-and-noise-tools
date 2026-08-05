<?php
/**
 * Signal & Noise — Identity & SEO admin form (Site tab, default sub-tab).
 *
 * Renders the bundled 4-section form (Identity / Social / Open Graph / SEO Copy)
 * saved by a single "Save Identity Settings" button (sn_action=save_identity →
 * sn_handle_save_identity → sn_settings_save). The 4 sections are emitted via
 * sn_admin_render_section() so the #sn-sec-<slug> anchor wrappers the section
 * tabs target keep working. Extracted verbatim from inc/admin-page.php in v4.5.4.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit the full Identity & SEO form (wrapper + 4 sections + savebar). The
 * caller renders the in-form section tabs (sn_admin_render_section_tabs) before
 * this; assets/admin.js then wires them to show one section at a time.
 */
function sn_admin_render_identity_and_seo_form() {
	echo '<form method="post" class="sn-identity-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="save_identity">';

	sn_admin_render_section( 'identity', function() {
		echo '<h2 class="sn-fieldset-h">Identity</h2>';
		echo '<p class="sn-fieldset-intro">Site-wide name, description, and locale.</p>';

		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="sn_identity_site_name">Site name</label>';
		echo '<input type="text" id="sn_identity_site_name" name="identity_site_name" value="' . esc_attr( sn_setting( 'identity.site_name', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_identity_site_description">Site description</label>';
		echo '<textarea id="sn_identity_site_description" name="identity_site_description" rows="2">' . esc_textarea( (string) sn_setting( 'identity.site_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="sn_identity_person_name">Person name (schema author)</label>';
		echo '<input type="text" id="sn_identity_person_name" name="identity_person_name" value="' . esc_attr( sn_setting( 'identity.person_name', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="sn_identity_job_title">Job title</label>';
		echo '<input type="text" id="sn_identity_job_title" name="identity_job_title" value="' . esc_attr( sn_setting( 'identity.job_title', 'Music Producer' ) ) . '" placeholder="Music Producer">';
		echo '<p class="sn-field-helper">Emitted as <code>jobTitle</code> on the Person schema. Single short phrase.</p>';
		echo '</div>';

		// v6.17.0 (D5): availability line surfaced in the /contact + /services
		// heroes (theme's [sn_availability] shortcode). Empty = hidden.
		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_identity_availability">Availability line</label>';
		echo '<input type="text" id="sn_identity_availability" name="identity_availability" value="' . esc_attr( (string) sn_setting( 'identity.availability', '' ) ) . '" placeholder="Available for select mixing work">';
		echo '<p class="sn-field-helper">A short status line surfaced in the <code>/contact</code> and <code>/services</code> page heroes. Leave empty to hide it.</p>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_identity_knows_about">Knows about</label>';
		$knows_about_value = (array) sn_setting(
			'identity.knows_about',
			array( 'Music Production', 'Audio Engineering', 'Provenance', 'Music Industry' )
		);
		echo '<textarea id="sn_identity_knows_about" name="identity_knows_about" rows="4">' . esc_textarea( implode( "\n", $knows_about_value ) ) . '</textarea>';
		echo '<p class="sn-field-helper">One topic per line. Emitted as the <code>knowsAbout</code> array on the Person schema: domain expertise areas that signal to search engines what this person is about. Leave a line blank to omit the entry.</p>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xs">';
		echo '<label class="sn-field-label" for="sn_identity_locale">Locale</label>';
		echo '<input type="text" id="sn_identity_locale" name="identity_locale" value="' . esc_attr( sn_setting( 'identity.locale', 'en_US' ) ) . '" placeholder="en_US">';
		echo '<p class="sn-field-helper">WP locale code (e.g. <code>en_US</code>). Used for og:locale and schema inLanguage.</p>';
		echo '</div>';
	} );

	sn_admin_render_section( 'social', function() {
		echo '<h2 class="sn-fieldset-h">Social</h2>';
		echo '<p class="sn-fieldset-intro">Twitter / X handle and profile URLs (emitted as schema sameAs).</p>';

		echo '<div class="sn-field sn-field-w-sm">';
		echo '<label class="sn-field-label" for="sn_social_twitter_handle">Twitter / X handle</label>';
		echo '<input type="text" id="sn_social_twitter_handle" name="social_twitter_handle" value="' . esc_attr( sn_setting( 'social.twitter_handle', '' ) ) . '" placeholder="@username">';
		echo '<p class="sn-field-helper">Used as twitter:site and twitter:creator. Include the @ prefix.</p>';
		echo '</div>';

		$same_as = (array) sn_setting( 'social.same_as', array() );
		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">Profile URLs (sameAs)</label>';
		echo '<div class="sn-sameas">';
		// WCAG 4.1.2: each repeating input needs its own accessible name.
		// The visible .sn-field-label applies to the group; aria-label on
		// each row gives screen readers a per-input name. Matches the
		// pattern already in assets/admin.js initAddRowButton() for
		// dynamically-added rows (audit D PA-10).
		foreach ( $same_as as $url ) {
			echo '<input type="url" name="social_same_as[]" value="' . esc_attr( (string) $url ) . '" placeholder="https://..." aria-label="Profile URL">';
		}
		echo '<button type="button" class="sn-add-row-btn" aria-label="Add another profile URL row">Add another profile URL</button>';
		echo '<noscript>';
		echo '<input type="url" name="social_same_as[]" value="" placeholder="https://..." class="sn-sameas-extra" aria-label="Profile URL">';
		echo '</noscript>';
		echo '</div>'; // .sn-sameas
		echo '<p class="sn-field-helper">Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.</p>';
		echo '</div>';
	} );

	sn_admin_render_section( 'open-graph', function() {
		echo '<h2 class="sn-fieldset-h">Open Graph</h2>';
		echo '<p class="sn-fieldset-intro">Fallback OG image and card dimensions for social shares.</p>';

		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label" for="sn_og_default_image_url">Default OG image URL</label>';
		echo '<input type="url" id="sn_og_default_image_url" name="og_default_image_url" value="' . esc_attr( (string) sn_setting( 'og.default_image_url', '' ) ) . '">';
		echo '<p class="sn-field-helper">Fallback image used when no per-post OG card exists.</p>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xs">';
		echo '<label class="sn-field-label" for="sn_og_card_width">Card width (px)</label>';
		echo '<input type="number" min="1" id="sn_og_card_width" name="og_card_width" value="' . esc_attr( (string) sn_setting( 'og.card_width', 1200 ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xs">';
		echo '<label class="sn-field-label" for="sn_og_card_height">Card height (px)</label>';
		echo '<input type="number" min="1" id="sn_og_card_height" name="og_card_height" value="' . esc_attr( (string) sn_setting( 'og.card_height', 630 ) ) . '">';
		echo '</div>';
	} );

	sn_admin_render_section( 'seo-copy', function() {
		echo '<h2 class="sn-fieldset-h">SEO Copy</h2>';
		echo '<p class="sn-fieldset-intro">Per-route title + description for the home, /notes, and /provenance pages.</p>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_home_title">Home title</label>';
		echo '<input type="text" id="sn_seo_home_title" name="seo_home_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.home_title', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_home_description">Home description</label>';
		echo '<textarea id="sn_seo_home_description" name="seo_home_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.home_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_notes_title">/notes title</label>';
		echo '<input type="text" id="sn_seo_notes_title" name="seo_notes_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.notes_title', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_notes_description">/notes description</label>';
		echo '<textarea id="sn_seo_notes_description" name="seo_notes_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.notes_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_provenance_title">/provenance title</label>';
		echo '<input type="text" id="sn_seo_provenance_title" name="seo_provenance_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.provenance_title', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_provenance_description">/provenance description</label>';
		echo '<textarea id="sn_seo_provenance_description" name="seo_provenance_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.provenance_description', '' ) ) . '</textarea>';
		echo '</div>';
	} );

	// Sticky save bar — saves Identity / Social / OG / SEO Copy (the 4 above).
	// Cloudflare's save is separate (its own form on its own sub-tab now).
	echo '<div class="sn-savebar">';
	echo '<p class="sn-savebar-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>';
	echo '<button type="submit" class="button button-primary">Save Identity Settings</button>';
	echo '</div>';
	echo '</form>';
}
