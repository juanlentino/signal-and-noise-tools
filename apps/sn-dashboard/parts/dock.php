<?php
/**
 * S&N Dashboard host — the dock tile: its badge and the params a deep link
 * opens on.
 *
 * Both used to belong to the manual dock item in inc/desktop-mode-dock.php
 * — an entry with the same id, the same shield and an 8-tab submenu built from
 * the same registry. The app now registers that tile itself (one id names one
 * thing), so the badge moved here and reads from the same source it always
 * did: `snt_desktop_dock_badge()`. The 8-tab menu builder that came with it
 * had no caller and was removed.
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
 * The dock badge: the same update count the manual dock item carried.
 *
 * @return int 0 clears it.
 */
function badge_count() {
	return function_exists( 'snt_desktop_dock_badge' ) ? (int) \snt_desktop_dock_badge() : 0;
}

/**
 * The tab a deep link names, as the classic page reads it: no `tab` is the
 * Dashboard, and a legacy tab or a moved leaf resolves through the same map
 * the 301 uses.
 *
 * @param Os $os Host handle.
 * @return array{0:string,1:string,2:string} Tab, leaf, anchor.
 */
function landing( Os $os ) {
	$tab    = (string) $os->param( 'tab', '' );
	$sub    = (string) $os->param( 'sub', '' );
	$anchor = (string) $os->param( 'anchor', '' );
	$tab    = '' === $tab ? 'dashboard' : $tab;
	$dest   = function_exists( 'sn_admin_canonical_destination' ) ? \sn_admin_canonical_destination( $tab, $sub ) : null;
	if ( is_array( $dest ) ) {
		$tab    = (string) $dest['tab'];
		$sub    = (string) ( $dest['sub'] ?? '' );
		$anchor = ( '' === $anchor && ! empty( $dest['anchor'] ) ) ? 'sn-sec-' . $dest['anchor'] : $anchor;
	}
	return array( $tab, $sub, $anchor );
}

/**
 * Read the open-time params onto the state: `tab`, `sub`, `anchor`.
 *
 * Shared by `mount` and `reopen`. Each tab is its own session and every one
 * of them receives the params, so only the session of the tab the link names
 * lands, and says so with the `snt-land` effect (assets/os-kit.js shows that
 * tab: a server action cannot switch the window's tabs). The others open on
 * their first leaf, or keep the leaf they are on when the window was already
 * open.
 *
 * ONCE PER LINK. A dock or icon click reopens the window with NO params, and
 * the shell then hands back the last ones it had: re-reading them snapped
 * every tab back to the last deep link's leaf (measured 2026-10-12). So a
 * reopen whose params are the ones this session already read changes nothing;
 * a link always differs, because assets/os-settings-tab.js stamps each one.
 *
 * @param State $state  Window state.
 * @param Os    $os     Host handle.
 * @param bool  $reopen True for `reopen`.
 * @return void
 */
function read_params( State $state, Os $os, $reopen = false ) {
	$read = (string) json_encode( is_array( $os->params ) ? $os->params : array() );
	if ( $reopen && $read === (string) $state->get( 'landed' ) ) {
		return;
	}
	$state->set( 'landed', $read );
	$tab = current_tab( $os );
	list( $to, $sub, $anchor ) = landing( $os );
	if ( $to !== $tab ) {
		if ( ! $reopen ) {
			$state->set( 'sub', \snt_os_host_resolve_sub( $tab, '' ) );
		}
		return;
	}
	// The anchor param IS the element id (a door names `sn-sec-<slug>` or any
	// id); the section_anchor() prefixing is for handler targets, not params.
	$state->set( 'sub', \snt_os_host_resolve_sub( $tab, $sub ) )
		->set( 'anchor', $anchor )
		->set( 'params', is_array( $os->params ) ? \snt_os_host_params( $os->params ) : array() )
		->set( 'flash', '' )
		->set( 'post', array() )
		->set( 'notice', null );
	if ( isset( $os->effects ) && method_exists( $os->effects, 'add' ) ) {
		$os->effects->add( 'snt-land' );
	}
}

/**
 * Mount: land on the requested tab, then tell the shell what the tile says.
 *
 * The window's menu is declared chrome (`App::window_action()`), so mount only
 * has the badge to set.
 *
 * @param State $state Window state.
 * @param Os    $os    Host handle.
 * @return void
 */
function mount( State $state, Os $os ) {
	read_params( $state, $os );
	$os->badge( badge_count() );
}
