<?php
/**
 * S&N Dashboard — Site → Identity & SEO, painted from the kit.
 *
 * Classic (inc/admin-forms/identity-and-seo.php,
 * `sn_admin_render_identity_and_seo_form()`): dispatcher paints four section
 * tabs (`sn_admin_render_section_tabs()`, anchors `#sn-sec-identity` …
 * `#sn-sec-seo-copy`) then ONE form (`sn_action=save_identity` →
 * `sn_handle_save_identity()` → `sn_settings_save()`). Here: one `<os-form>`
 * wrapping an `<os-tabs>` strip + four `<os-tabpanel>` siblings (client-side
 * swap), same fields, same handler; the window's `anchor` plays the hash.
 * Panels live in site-identity-and-seo-parts.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/site-identity-and-seo-parts.php';

/**
 * The sections, slug => label, from the registry the classic strip reads
 * (`sn_admin_top_tabs()` → site → identity-and-seo → sub_sections).
 *
 * @return array<string,string>
 */
function identity_seo_sections() {
	if ( function_exists( 'sn_admin_top_tabs' ) ) {
		foreach ( (array) \sn_admin_top_tabs() as $top ) {
			if ( 'site' !== (string) ( $top['tab'] ?? '' ) ) {
				continue;
			}
			$out = array();
			foreach ( (array) ( $top['sub_tabs']['identity-and-seo']['sub_sections'] ?? array() ) as $slug => $sub ) {
				$out[ (string) $slug ] = is_array( $sub ) && isset( $sub['label'] ) ? (string) $sub['label'] : (string) $slug;
			}
			if ( array() !== $out ) {
				return $out;
			}
		}
	}
	return array( 'identity' => 'Identity', 'social' => 'Social', 'open-graph' => 'Open Graph', 'seo-copy' => 'SEO Copy' );
}

/**
 * The sub-tab's OWN label (`sn_admin_top_tabs()` → site → identity-and-seo →
 * `label`), the same registry node `identity_seo_sections()` walks — so
 * renaming the sub-tab in the registry renames the strip's accessible name
 * here too, the way it already renames the classic page's.
 *
 * @return string
 */
function identity_seo_strip_label() {
	if ( function_exists( 'sn_admin_top_tabs' ) ) {
		foreach ( (array) \sn_admin_top_tabs() as $top ) {
			if ( 'site' !== (string) ( $top['tab'] ?? '' ) ) {
				continue;
			}
			if ( isset( $top['sub_tabs']['identity-and-seo']['label'] ) ) {
				return (string) $top['sub_tabs']['identity-and-seo']['label'];
			}
		}
	}
	return 'Identity & SEO';
}

/**
 * Which section opens: the one the window's `anchor` (`sn-sec-<slug>`, the
 * classic hash) names, else the first — what assets/admin.js does on load.
 *
 * @param array<string,mixed>  $ctx      Painter context.
 * @param array<string,string> $sections From identity_seo_sections().
 * @return string
 */
function identity_seo_active_section( array $ctx, array $sections ) {
	$state  = $ctx['state'] ?? null;
	$anchor = is_object( $state ) && method_exists( $state, 'get' ) ? (string) $state->get( 'anchor' ) : '';
	$slug   = 0 === strpos( $anchor, 'sn-sec-' ) ? substr( $anchor, strlen( 'sn-sec-' ) ) : '';
	return isset( $sections[ $slug ] ) ? $slug : (string) array_key_first( $sections );
}

/**
 * The section strip: `<os-tabs value label>` + `<os-tab value>` per section.
 * No `os-bind` — the strip toggles its sibling panels' `hidden` itself.
 *
 * @param array<string,string> $sections slug => label.
 * @param string               $active   Open section.
 * @return string
 */
function identity_seo_strip( array $sections, $active ) {
	$tabs = '';
	foreach ( $sections as $slug => $label ) {
		$tabs .= \snt_kit_tag( 'os-tab', array( 'value' => (string) $slug ), \snt_kit_esc( $label ) );
	}
	return \snt_kit_tag(
		'os-tabs',
		array(
			'class' => 'snt-subtabs',
			'value' => (string) $active,
			'label' => sprintf(
				/* translators: %s: the sub-tab's registry label, e.g. "Identity & SEO". */
				__( '%s sections', 'signal-and-noise-tools' ),
				identity_seo_strip_label()
			),
		),
		$tabs
	);
}

/**
 * The leaf: one form, the strip, the four panels, the save-bar hint.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_site_identity_and_seo( array $ctx ) {
	$sections = identity_seo_sections();
	$inner    = identity_seo_strip( $sections, identity_seo_active_section( $ctx, $sections ) );
	foreach ( array_keys( $sections ) as $slug ) {
		$painter = __NAMESPACE__ . '\\identity_seo_' . str_replace( '-', '_', (string) $slug ) . '_panel';
		if ( is_callable( $painter ) ) {
			$inner .= call_user_func( $painter );
		}
	}
	$inner .= '<p slot="footer-leading" class="snt-hint">' . \snt_kit_esc( __( 'Changes apply immediately on Save. Live site re-renders on next request.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_form( 'save_identity', $inner, array( 'submit' => __( 'Save Identity Settings', 'signal-and-noise-tools' ) ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/identity-and-seo'] = __NAMESPACE__ . '\\paint_site_identity_and_seo';
		return $painters;
	}
);
