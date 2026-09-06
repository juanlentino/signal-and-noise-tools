<?php
/**
 * S&N Dashboard — Site → Identity & SEO: the four section panels.
 *
 * One painter per classic section (`sn_admin_render_section( 'identity' | 'social'
 * | 'open-graph' | 'seo-copy', … )` in inc/admin-forms/identity-and-seo.php):
 * the same reads (`sn_setting()` with the same defaults), the same labels,
 * placeholders and helper prose, as `<os-tabpanel>` + `<os-section>` + kit
 * fields. Required by site-identity-and-seo.php, which owns the strip, the
 * form and the registration.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A kit field, plus the classic helper line when it carries inline code
 * (`<os-field-row hint>` is text-only, so a hint with `<code>` is painted as
 * a `.snt-hint` paragraph with `<os-code>` inline).
 *
 * @param string $type      Control type.
 * @param string $name      Field name.
 * @param string $label     Label.
 * @param mixed  $value     Current value.
 * @param array  $opts      snt_kit_field() options.
 * @param string $hint_html Escaped helper HTML, or ''.
 * @return string
 */
function identity_seo_field( $type, $name, $label, $value, array $opts = array(), $hint_html = '' ) {
	return \snt_kit_field( $type, $name, $label, $value, $opts )
		. ( '' !== $hint_html ? '<p class="snt-hint">' . $hint_html . '</p>' : '' );
}

/**
 * `<os-tabpanel for id>` around the section. `for` is the documented prop
 * that wires the panel to its `<os-tabs>` strip; `id` is NOT a scroll anchor
 * here (nothing in the window scrolls to a document fragment — routing is
 * client-side via the strip and, on load, via `identity_seo_active_section()`
 * reading the window's `anchor` state) — it is a stable DOM hook carrying the
 * classic `#sn-sec-<slug>` spelling so a future editor can grep for it.
 *
 * @param string $slug    Section slug.
 * @param string $heading Section heading.
 * @param string $intro   Section intro.
 * @param string $fields  Painted fields.
 * @return string
 */
function identity_seo_panel( $slug, $heading, $intro, $fields ) {
	return \snt_kit_tag( 'os-tabpanel', array( 'for' => (string) $slug, 'id' => 'sn-sec-' . (string) $slug ), \snt_kit_section( $heading, $fields, $intro ) );
}

/** @return string The Identity section. */
function identity_seo_identity_panel() {
	$knows_about = (array) \sn_setting( 'identity.knows_about', array( 'Music Production', 'Audio Engineering', 'Provenance', 'Music Industry' ) );
	$fields      = identity_seo_field( 'text', 'identity_site_name', __( 'Site name', 'signal-and-noise-tools' ), (string) \sn_setting( 'identity.site_name', '' ) )
		. identity_seo_field( 'textarea', 'identity_site_description', __( 'Site description', 'signal-and-noise-tools' ), (string) \sn_setting( 'identity.site_description', '' ), array( 'rows' => 2 ) )
		. identity_seo_field( 'text', 'identity_person_name', __( 'Person name (schema author)', 'signal-and-noise-tools' ), (string) \sn_setting( 'identity.person_name', '' ) )
		. identity_seo_field(
			'text',
			'identity_job_title',
			__( 'Job title', 'signal-and-noise-tools' ),
			(string) \sn_setting( 'identity.job_title', 'Music Producer' ),
			array( 'placeholder' => 'Music Producer' ),
			\snt_kit_esc( __( 'Emitted as ', 'signal-and-noise-tools' ) ) . \snt_kit_code( 'jobTitle', false ) . \snt_kit_esc( __( ' on the Person schema. Single short phrase.', 'signal-and-noise-tools' ) )
		)
		. identity_seo_field(
			'text',
			'identity_availability',
			__( 'Availability line', 'signal-and-noise-tools' ),
			(string) \sn_setting( 'identity.availability', '' ),
			array( 'placeholder' => 'Available for select mixing work' ),
			\snt_kit_esc( __( 'A short status line surfaced in the ', 'signal-and-noise-tools' ) ) . \snt_kit_code( '/contact', false ) . \snt_kit_esc( __( ' and ', 'signal-and-noise-tools' ) ) . \snt_kit_code( '/services', false ) . \snt_kit_esc( __( ' page heroes. Leave empty to hide it.', 'signal-and-noise-tools' ) )
		)
		. identity_seo_field(
			'textarea',
			'identity_knows_about',
			__( 'Knows about', 'signal-and-noise-tools' ),
			implode( "\n", array_map( 'strval', $knows_about ) ),
			array( 'rows' => 4 ),
			\snt_kit_esc( __( 'One topic per line. Emitted as the ', 'signal-and-noise-tools' ) ) . \snt_kit_code( 'knowsAbout', false ) . \snt_kit_esc( __( ' array on the Person schema: domain expertise areas that signal to search engines what this person is about. Leave a line blank to omit the entry.', 'signal-and-noise-tools' ) )
		)
		. identity_seo_field(
			'text',
			'identity_locale',
			__( 'Locale', 'signal-and-noise-tools' ),
			(string) \sn_setting( 'identity.locale', 'en_US' ),
			array( 'placeholder' => 'en_US' ),
			\snt_kit_esc( __( 'WP locale code (e.g. ', 'signal-and-noise-tools' ) ) . \snt_kit_code( 'en_US', false ) . \snt_kit_esc( __( '). Used for og:locale and schema inLanguage.', 'signal-and-noise-tools' ) )
		);
	return identity_seo_panel( 'identity', __( 'Identity', 'signal-and-noise-tools' ), __( 'Site-wide name, description, and locale.', 'signal-and-noise-tools' ), $fields );
}

/**
 * The Social section. The sameAs rows are the stored URLs plus one empty row
 * — the classic `<noscript>` shape — because the "Add another profile URL"
 * button was a client script, and the handler drops empty rows on save.
 * Rows are named `social_same_as[N]`, not `social_same_as[]`: `<os-form>`
 * collects values into a map keyed by the literal name (last one wins), so
 * repeated `[]` names would reach the handler as ONE url; indexed names
 * expand (`parse_str`) to the same `social_same_as` array a real POST carries.
 *
 * ACCEPTED DRIFT: each row is `snt_kit_field()`, which paints a visible
 * "Profile URL" caption per row via `<os-field-row label>`. The classic
 * painted ONE visible group label plus a bare `aria-label="Profile URL"`
 * per `<input>` (added for WCAG 4.1.2 — a repeating input still needs its
 * own accessible name). `os-text-field` does not carry `aria-label` in its
 * documented prop list (only `os-textarea` does), so matching the classic's
 * bare-input shape here would mean using an undocumented attribute on
 * `os-text-field` — left as-is per the port brief's "never invent a kit
 * attribute" rule. Net effect: three stacked "Profile URL" captions instead
 * of one group label; the accessible name per row is preserved (arguably
 * improved, since it is now visible, not just in the accessibility tree).
 *
 * @return string
 */
function identity_seo_social_panel() {
	$rows   = array_values( array_map( 'strval', (array) \sn_setting( 'social.same_as', array() ) ) );
	$rows[] = '';
	$urls   = '';
	foreach ( $rows as $i => $url ) {
		$urls .= \snt_kit_field( 'url', 'social_same_as[' . (int) $i . ']', __( 'Profile URL', 'signal-and-noise-tools' ), $url, array( 'placeholder' => 'https://...' ) );
	}
	$fields = identity_seo_field( 'text', 'social_twitter_handle', __( 'Twitter / X handle', 'signal-and-noise-tools' ), (string) \sn_setting( 'social.twitter_handle', '' ), array( 'placeholder' => '@username', 'hint' => __( 'Used as twitter:site and twitter:creator. Include the @ prefix.', 'signal-and-noise-tools' ) ) )
		. '<div class="snt-field-static">'
		. '<span class="snt-field-static__k">' . \snt_kit_esc( __( 'Profile URLs (sameAs)', 'signal-and-noise-tools' ) ) . '</span>'
		. '<os-stack gap="8">' . $urls . '</os-stack>'
		. '<span class="snt-field-static__hint">' . \snt_kit_esc( __( 'Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.', 'signal-and-noise-tools' ) ) . '</span>'
		. '</div>';
	return identity_seo_panel( 'social', __( 'Social', 'signal-and-noise-tools' ), __( 'Twitter / X handle and profile URLs (emitted as schema sameAs).', 'signal-and-noise-tools' ), $fields );
}

/** @return string The Open Graph section. */
function identity_seo_open_graph_panel() {
	$fields = identity_seo_field( 'url', 'og_default_image_url', __( 'Default OG image URL', 'signal-and-noise-tools' ), (string) \sn_setting( 'og.default_image_url', '' ), array( 'hint' => __( 'Fallback image used when no per-post OG card exists.', 'signal-and-noise-tools' ) ) )
		. identity_seo_field( 'number', 'og_card_width', __( 'Card width (px)', 'signal-and-noise-tools' ), (string) \sn_setting( 'og.card_width', 1200 ), array( 'min' => '1' ) )
		. identity_seo_field( 'number', 'og_card_height', __( 'Card height (px)', 'signal-and-noise-tools' ), (string) \sn_setting( 'og.card_height', 630 ), array( 'min' => '1' ) );
	return identity_seo_panel( 'open-graph', __( 'Open Graph', 'signal-and-noise-tools' ), __( 'Fallback OG image and card dimensions for social shares.', 'signal-and-noise-tools' ), $fields );
}

/** @return string The SEO Copy section. */
function identity_seo_seo_copy_panel() {
	$fields = '';
	foreach ( array( 'home' => __( 'Home', 'signal-and-noise-tools' ), 'notes' => '/notes', 'provenance' => '/provenance' ) as $route => $label ) {
		$fields .= identity_seo_field( 'text', 'seo_' . $route . '_title', $label . ' ' . __( 'title', 'signal-and-noise-tools' ), (string) \sn_setting( 'seo_copy.' . $route . '_title', '' ) )
			. identity_seo_field( 'textarea', 'seo_' . $route . '_description', $label . ' ' . __( 'description', 'signal-and-noise-tools' ), (string) \sn_setting( 'seo_copy.' . $route . '_description', '' ), array( 'rows' => 2 ) );
	}
	return identity_seo_panel( 'seo-copy', __( 'SEO Copy', 'signal-and-noise-tools' ), __( 'Per-route title + description for the home, /notes, and /provenance pages.', 'signal-and-noise-tools' ), $fields );
}
