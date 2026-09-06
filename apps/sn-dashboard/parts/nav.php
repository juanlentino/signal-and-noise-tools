<?php
/**
 * S&N Dashboard host — the chrome the window paints around the leaf.
 *
 * The page header, the notice and the top-tab strip: the three things
 * inc/admin-page.php prints before it hands over to the dispatcher. They are
 * here rather than in the capture because the capture starts AT the
 * dispatcher — `sn_admin_render_active_tab()` — which is where the classic
 * page's own header ends.
 *
 * The strip is DERIVED on every paint. Hardcoding even one entry would be the
 * v3.8.1 duplicate-nav trap in a new room: the registry gained two spliced
 * leaves (Machine Readers, Search Console) after the fact, and a frozen list
 * would have shown yesterday's IA with no error anywhere.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard;

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The top tabs, straight off the registry, in registry order.
 *
 * @return array<int,array<string,mixed>>
 */
function top_tabs() {
	if ( ! function_exists( 'sn_admin_top_tabs' ) ) {
		return array();
	}
	$tabs = sn_admin_top_tabs();
	return is_array( $tabs ) ? $tabs : array();
}

/**
 * The page heading and subtitle, exactly as inc/admin-page.php prints them.
 *
 * @param string $tab Active top-tab slug.
 * @return void
 */
function render_heading( $tab ) {
	echo '<h1 class="sn-page-h1">Signal &amp; Noise</h1>';
	$subtitle = function_exists( 'sn_admin_page_subtitle_for_tab' ) ? (string) \sn_admin_page_subtitle_for_tab( (string) $tab ) : '';
	if ( '' !== $subtitle ) {
		echo '<p class="sn-page-subtitle">' . esc_html( $subtitle ) . '</p>';
	}
}

/**
 * The post-save notice, in the classic markup.
 *
 * `wp_kses_post` on the body for the same reason the classic page uses it:
 * about fifteen flash codes deliberately ship an `<a>` or a `<code>`, and
 * `esc_html` would print the tags at the reader.
 *
 * @param array{0:string,1:string}|null $notice `[ severity, html ]`.
 * @return void
 */
function render_notice( $notice ) {
	if ( ! is_array( $notice ) || ! isset( $notice[0], $notice[1] ) ) {
		return;
	}
	echo '<div class="notice notice-' . esc_attr( (string) $notice[0] ) . ' is-dismissible"><p>' . wp_kses_post( (string) $notice[1] ) . '</p></div>';
}

/**
 * The top-tab strip. Same classes and same active state as the classic nav;
 * a `go` action instead of an href, because the runtime does NOT
 * preventDefault a click — a link that kept its href would navigate the whole
 * desktop away from the shell.
 *
 * @param string $active Active top-tab slug.
 * @return void
 */
function render_tab_strip( $active ) {
	$tabs = top_tabs();
	if ( array() === $tabs ) {
		return;
	}
	echo '<nav class="nav-tab-wrapper sn-nav-tabs">';
	foreach ( $tabs as $tab ) {
		$slug  = isset( $tab['tab'] ) ? (string) $tab['tab'] : '';
		$label = isset( $tab['label'] ) ? (string) $tab['label'] : '';
		if ( '' === $slug ) {
			continue;
		}
		// Two whole literals rather than one with a stitched-in fragment: an
		// assembled attribute string is exactly the shape PHPCS cannot read as
		// escaped, and the estate already carries one scoped exclusion for it.
		if ( $slug === (string) $active ) {
			echo '<a class="nav-tab nav-tab-active" os-action="go" os-arg-tab="' . esc_attr( $slug ) . '" aria-current="page">' . esc_html( $label ) . '</a>';
		} else {
			echo '<a class="nav-tab" os-action="go" os-arg-tab="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</a>';
		}
	}
	echo '</nav>';
}
