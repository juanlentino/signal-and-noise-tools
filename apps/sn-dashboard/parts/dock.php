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
 * Read the open-time params onto the state: `tab`, `sub`, `anchor`.
 *
 * Shared by `mount` and `reopen` — a deep link that lands on a window already
 * open must retarget it, and the shell writes the new params before it
 * dispatches `reopen`, so the two reads are the same read.
 *
 * @param State $state Window state.
 * @param Os    $os    Host handle.
 * @return void
 */
function read_params( State $state, Os $os ) {
	$tab    = current_tab( $os );
	$sub    = \snt_os_host_resolve_sub( $tab, (string) $os->param( 'sub', '' ) );
	// The anchor param IS the element id (a door names `sn-sec-<slug>` or any
	// id); the section_anchor() prefixing is for handler targets, not params.
	$anchor = (string) $os->param( 'anchor', '' );
	$params = is_array( $os->params ) ? \snt_os_host_params( $os->params ) : array();
	$state->set( 'sub', $sub )
		->set( 'anchor', $anchor )
		->set( 'params', $params )
		->set( 'flash', '' )
		->set( 'post', array() )
		->set( 'notice', null );
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
