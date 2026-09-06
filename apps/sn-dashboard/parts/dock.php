<?php
/**
 * S&N Dashboard host — the dock tile: its badge, its menu, and the params a
 * deep link opens on.
 *
 * All three used to belong to the manual dock item in inc/desktop-mode-dock.php
 * — an entry with the same id, the same shield and an 8-tab submenu built from
 * the same registry. The app now registers that tile itself (one id names one
 * thing), so the badge and the menu move here and read from the SAME two
 * sources they always did: `snt_desktop_dock_badge()` and `sn_admin_top_tabs()`.
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
 * The window's ⋯ menu rows: one `go` per top tab, in registry order — the
 * manual dock item's 8-entry submenu, as actions instead of URLs.
 *
 * NOT `$os->menu()`, though the plan named it: that effect "pop[s] a context
 * menu at the pointer" (Effects::menu), so calling it at mount would open a
 * menu nobody asked for. The window's own menu is declared chrome —
 * `App::window_action()` — which is why this returns declarations rather than
 * performing an effect.
 *
 * Safe to bake at definition time, unlike the tab STRIP: the eight top tabs
 * are a literal array in inc/admin-tabs-data.php, and the two registry
 * callbacks that splice leaves in (Machine Readers, Search Console) splice
 * SUB-tabs under Measurement, never a ninth top tab.
 *
 * @return array<int,array<string,mixed>>
 */
function menu_items() {
	$items = array();
	$order = 10;
	foreach ( top_tabs() as $tab ) {
		$slug = isset( $tab['tab'] ) ? (string) $tab['tab'] : '';
		if ( '' === $slug ) {
			continue;
		}
		$items[] = array(
			'id'     => 'tab-' . $slug,
			'label'  => isset( $tab['label'] ) ? (string) $tab['label'] : $slug,
			'action' => 'go',
			'order'  => $order,
			'args'   => array( 'tab' => $slug ),
		);
		$order += 10;
	}
	return $items;
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
	$anchor = (string) $os->param( 'anchor', '' );
	$state->set( 'sub', $sub )
		->set( 'anchor', '' !== $anchor ? section_anchor( $anchor ) : '' )
		->set( 'params', array() )
		->set( 'flash', '' )
		->set( 'post', array() )
		->set( 'notice', null );
}

/**
 * Mount: land on the requested tab, then tell the shell what the tile says.
 *
 * The menu rows are declared chrome (see `menu_items()`), so mount only has
 * the badge to set.
 *
 * @param State $state Window state.
 * @param Os    $os    Host handle.
 * @return void
 */
function mount( State $state, Os $os ) {
	read_params( $state, $os );
	$os->badge( badge_count() );
}
