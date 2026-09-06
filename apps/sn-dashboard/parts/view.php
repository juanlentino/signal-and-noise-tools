<?php
/**
 * S&N Dashboard host — the view.
 *
 * The whole body of the window, in the order inc/admin-page.php prints it:
 * `<div class="wrap">`, the heading, the subtitle, the notice, the tab strip,
 * and then the dispatcher's own output — captured, not reimplemented.
 *
 * The capture is the point of the host. `sn_admin_render_active_tab()` prints
 * the sub-tab nav, the section wrapper (`.sn-fieldset`, `.sn-section` or
 * `.sn-fieldset--wide`, whichever the leaf's registry flags ask for) and the
 * leaf itself. Every one of ~35 leaves therefore lands in the window with no
 * leaf-level change, and a leaf added tomorrow lands for free.
 *
 * The registry is read on EVERY paint. Not at definition: the framework loads
 * `.os.php` files at `init`, when two of the registry's leaves have not been
 * spliced in yet and the acting user may be nobody at all.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard;

use OpenStation\App\Os;
use OpenStation\App\State;

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The `$_GET` this paint lends the leaf.
 *
 * `page`, `tab` and `sub` are the canonical URL a click on the classic tab
 * strip produces. The `sn_*` params are the ones that ARE state on that page
 * (the Tags merge preview). `new_id` is the one derived key: the classic page
 * extracts it from a `wh_added_…` / `wh_rotated_…` flash so the Webhooks leaf
 * can show a secret exactly once — reproduced here, from the same two
 * prefixes, because a save that stops showing the secret is a save that
 * silently lost something.
 *
 * @param State $state Window state.
 * @return array<string,mixed>
 */
function capture_query( State $state ) {
	$params = $state->get( 'params' );
	$query  = is_array( $params ) ? $params : array();

	$query['page'] = SNT_OS_DASHBOARD_PAGE;
	$query['tab']  = (string) $state->get( 'tab' );
	$sub           = (string) $state->get( 'sub' );
	if ( '' !== $sub ) {
		$query['sub'] = $sub;
	}

	$flash = (string) $state->get( 'flash' );
	if ( 0 === strpos( $flash, 'wh_added_' ) ) {
		$query['new_id'] = substr( $flash, strlen( 'wh_added_' ) );
	} elseif ( 0 === strpos( $flash, 'wh_rotated_' ) ) {
		$query['new_id'] = substr( $flash, strlen( 'wh_rotated_' ) );
	}
	return $query;
}

/**
 * The leaf, captured and rewritten.
 *
 * @param State $state Window state.
 * @return string HTML, already escaped by the leaves that produced it.
 */
function leaf_html( State $state ) {
	if ( ! function_exists( 'sn_admin_render_active_tab' ) || ! function_exists( 'snt_os_host_capture' ) ) {
		return '';
	}
	$tab = (string) $state->get( 'tab' );
	$sub = (string) $state->get( 'sub' );

	$html = \snt_os_host_capture(
		static function () use ( $tab, $sub ) {
			\sn_admin_render_active_tab( $tab, $sub );
		},
		capture_query( $state )
	);
	return \snt_os_host_rewrite( $html, array( SNT_OS_DASHBOARD_PAGE ) );
}

/**
 * Paint the window.
 *
 * @param State $state Window state.
 * @param Os    $os    Host handle.
 * @return void
 */
function render_view( State $state, Os $os ) {
	unset( $os );
	$tab    = (string) $state->get( 'tab' );
	$anchor = (string) $state->get( 'anchor' );

	// `data-snt-anchor` carries the post-save landing. The classic page scrolls
	// by URL fragment, which a window has none of; assets/os-host.js scrolls to
	// it once after the paint and clears the attribute.
	//
	// The value is the ELEMENT ID, not the bare slug state keeps. State holds
	// what `sn_admin_post_redirect_target()` returns ('identity'), and the
	// classic dispatcher turns that into the fragment `#sn-sec-identity`;
	// sn_admin_render_section() emits `id="sn-sec-identity"`. Painting the slug
	// here would hand the client a name no element on the page carries — a
	// scroll that silently lands nowhere.
	if ( '' !== $anchor ) {
		echo '<div class="wrap" data-snt-anchor="' . esc_attr( 'sn-sec-' . $anchor ) . '">';
	} else {
		echo '<div class="wrap">';
	}

	render_heading( $tab );
	render_notice( $state->get( 'notice' ) );
	render_tab_strip( $tab );

	// The captured leaf. Every byte of it was produced by the same render
	// callables the classic page runs, each of which escapes its own output;
	// re-escaping here would print the admin page as source text at the reader.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured output of the estate's own leaf renderers (inc/admin-dispatch.php), escaped at production; the only transform since is snt_os_host_rewrite()'s attribute pass.
	echo leaf_html( $state );

	echo '</div>';
}
