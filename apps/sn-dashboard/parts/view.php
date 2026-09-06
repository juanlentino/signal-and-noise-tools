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
 * THE POST BAG IS SPENT HERE. One form in the estate (`audit_prune_now`) is
 * handled INSIDE its own render function, out of `$_POST`; the classic page
 * serves it by not redirecting, so the page re-renders with the submission
 * still standing. The `post` action puts those values in state and this paint
 * lends them to the capture — then CLEARS them, in the same dispatch, before
 * the state goes back to the client. A bag that survived would prune again on
 * the next unrelated repaint.
 *
 * @param State $state Window state.
 * @return string HTML, already escaped by the leaves that produced it.
 */
function leaf_html( State $state ) {
	if ( ! function_exists( 'sn_admin_render_active_tab' ) || ! function_exists( 'snt_os_host_capture' ) ) {
		return '';
	}
	$tab  = (string) $state->get( 'tab' );
	$sub  = (string) $state->get( 'sub' );
	$post = $state->get( 'post' );
	$post = is_array( $post ) ? $post : array();
	// SPENT BEFORE it is used, not after: a leaf that throws mid-capture would
	// otherwise leave the bag standing, and the next paint would run its handler
	// a second time -- a prune nobody asked for.
	if ( array() !== $post ) {
		$state->set( 'post', array() );
	}

	$html = \snt_os_host_capture(
		static function () use ( $tab, $sub ) {
			\sn_admin_render_active_tab( $tab, $sub );
		},
		capture_query( $state ),
		$post
	);
	// The own-page slugs are DERIVED (eight top tabs plus eleven legacy ones all
	// render this page); a literal made every leaf-to-leaf link a second window.
	return \snt_os_host_rewrite( $html, \snt_os_host_own_pages() );
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

	// `data-snt-anchor` carries the landing. The classic page scrolls by URL
	// fragment, which a window has none of; assets/os-host.js looks the value up
	// as an ELEMENT ID and scrolls to it once after the paint.
	//
	// Painted UNCHANGED, because state already holds the id. Prefixing here
	// meant the only ids reachable were `sn-sec-*` ones, and the Dashboard's
	// attention strip links `#sn-dash-diagnostics` — which landed nowhere.
	// `section_anchor()` converts the estate's bare slugs at the ONE place they
	// enter state, so exactly one rule applies.
	if ( '' !== $anchor ) {
		echo '<div class="wrap" data-snt-anchor="' . esc_attr( $anchor ) . '">';
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
