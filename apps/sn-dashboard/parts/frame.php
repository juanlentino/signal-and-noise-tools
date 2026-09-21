<?php
/**
 * S&N Dashboard — the tab frame: what every tab view paints around its leaf.
 *
 * The window's tabs are the framework's (`->tab()` per top tab; the main view
 * is the Dashboard tab), so the strip lives in the window chrome and each tab
 * is its own session. A tab view paints: the notice the last write produced,
 * the leaf bar (the native list toolbar's status control, `os-segmented` on
 * a desk and `os-select` on a phone, `os-bind="sub"`: a pick writes `sub`
 * and repaints; 17.4.3), and the active leaf through its kit painter. A leaf without a
 * painter yet paints its classic markup through the capture (the port's
 * scaffolding; tests/openstation-app-dashboard.php counts what is left).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard;

use OpenStation\App\Os;
use OpenStation\App\State;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The leaves of a tab: slug => label, in registry order.
 *
 * @param string $tab Top-tab slug.
 * @return array<string,string>
 */
function leaves_for( $tab ) {
	foreach ( top_tabs() as $entry ) {
		if ( (string) ( $entry['tab'] ?? '' ) !== (string) $tab ) {
			continue;
		}
		$out = array();
		foreach ( (array) ( $entry['sub_tabs'] ?? array() ) as $slug => $leaf ) {
			$out[ (string) $slug ] = is_array( $leaf ) && isset( $leaf['label'] ) ? (string) $leaf['label'] : (string) $slug;
		}
		return $out;
	}
	return array();
}

/**
 * The leaf a tab paints: the state's `sub` when it names one of the tab's
 * leaves, else the first leaf ('' on a tab without leaves, like Dashboard).
 *
 * @param string $tab   Top-tab slug.
 * @param State  $state Session state.
 * @return string
 */
function active_sub( $tab, State $state ) {
	$leaves = leaves_for( $tab );
	if ( empty( $leaves ) ) {
		return '';
	}
	$sub = (string) $state->get( 'sub' );
	return isset( $leaves[ $sub ] ) ? $sub : (string) array_key_first( $leaves );
}

/**
 * Painters, keyed `tab/sub` ('' sub for a landing tab), registered by the
 * files under parts/leaves through this filter.
 *
 * @return array<string,callable>
 */
function painters() {
	return (array) apply_filters( 'snt_os_dashboard_painters', array() );
}

/**
 * Paint one leaf: its kit painter, or an empty state when missing.
 *
 * @param string $tab   Top-tab slug.
 * @param string $sub   Leaf slug.
 * @param State  $state Session state.
 * @param Os     $os    Host.
 * @return string
 */
function paint_leaf( $tab, $sub, State $state, Os $os ) {
	$painters = painters();
	$key      = $tab . '/' . $sub;
	if ( isset( $painters[ $key ] ) && is_callable( $painters[ $key ] ) ) {
		return (string) call_user_func( $painters[ $key ], array( 'tab' => $tab, 'sub' => $sub, 'state' => $state, 'os' => $os ) );
	}
	return \snt_kit_empty( __( 'Section not found', 'signal-and-noise-tools' ) );
}

/**
 * The notice the last write produced, as the kit paints one.
 *
 * @param mixed $notice `[ severity, html ]` or null.
 * @return string
 */
function notice_html( $notice ) {
	if ( ! is_array( $notice ) || ! isset( $notice[0], $notice[1] ) ) {
		return '';
	}
	return \snt_kit_notice( (string) $notice[0], (string) $notice[1], true );
}

/**
 * Tell the host script this paint landed, and where to scroll.
 *
 * `$os->effects->add()` queues a custom effect the runtime re-dispatches on
 * the app root as an `os-app-effect` CustomEvent after the morph (OpenStation
 * App Framework, Experimental, v1.1.6+; docs/app-framework.md "Effects").
 * assets/os-host.js listens for `snt-paint` there; the method_exists guard
 * names that seam.
 *
 * @param Os     $os     Host handle.
 * @param string $anchor Element id to land on, or ''.
 * @return void
 */
function paint_effect( Os $os, $anchor ) {
	if ( isset( $os->effects ) && method_exists( $os->effects, 'add' ) ) {
		$os->effects->add( 'snt-paint', array( 'anchor' => $anchor ) );
	}
}

/**
 * A tab's view callable.
 *
 * @param string $tab Top-tab slug.
 * @return callable
 */
function tab_view( $tab ) {
	return static function ( State $state, Os $os ) use ( $tab ) {
		$sub    = active_sub( $tab, $state );
		$leaves = leaves_for( $tab );
		paint_effect( $os, (string) $state->get( 'anchor' ) );
		// The anchor rides this one paint and no later one: the runtime echoes
		// state after render, so clearing it here keeps a Refresh or an inline
		// post from scrolling to the same section again.
		$state->set( 'anchor', '' );
		echo '<div class="snt-app" data-os-app="sn-dashboard" data-snt-tab="' . \snt_kit_esc( $tab ) . '" data-snt-layout="dashboard">';
		// #1617: the window frame is the kit's <os-app-frame> (Stable at
		// 1.1.10): the toolbar slot stays put and the default slot scrolls
		// (`::part( content )`, styled in sn-dashboard.css). S&N Home owns
		// its own scrolling (the rail and the main), so it is `contained`.
		// The runtime upgrades the tag from the paint; no loadComponents call.
		echo '<os-app-frame' . ( 'dashboard' === $tab ? ' contained' : '' ) . '>';
		// 17.4.3: the leaf bar is the native list toolbar's status control
		// (segmented on a desk, a select on a phone), bound to `sub`, not an
		// os-tabs strip: see snt_kit_tabs().
		if ( count( $leaves ) > 1 ) {
			echo \snt_kit_tabs( $sub, $leaves, 'sub', __( 'Sections', 'signal-and-noise-tools' ), array( 'slot' => 'toolbar' ) );
		}
		echo notice_html( $state->get( 'notice' ) );
		echo '<div class="snt-leaf" data-snt-leaf="' . \snt_kit_esc( $sub ) . '">';
		echo paint_leaf( $tab, $sub, $state, $os );
		echo '</div></os-app-frame></div>';
	};
}
