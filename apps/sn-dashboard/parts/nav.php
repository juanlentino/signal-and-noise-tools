<?php
/**
 * S&N Dashboard native window — the shared top-tab registry.
 *
 * The framework paints the window chrome; the frame paints kit notices and leaves.
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
