<?php
/**
 * S&N Dashboard — the tab frame: what every tab view paints around its leaf.
 *
 * The window's tabs are the framework's (`->tab()` per top tab; the main view
 * is the Dashboard tab), so the strip lives in the window chrome and each tab
 * is its own session. A tab view paints: the notice the last write produced,
 * the sub-leaf strip (`<os-tabs os-bind="sub">` — a pick writes `sub` and
 * repaints), and the active leaf through its kit painter. A leaf without a
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
 * The classic leaf, captured — the scaffold for a leaf without a painter.
 *
 * @param string $tab   Top-tab slug.
 * @param string $sub   Leaf slug.
 * @param State  $state Session state.
 * @return string
 */
function captured_leaf( $tab, $sub, State $state ) {
	if ( ! function_exists( 'sn_admin_render_active_tab' ) || ! function_exists( 'snt_os_host_capture' ) ) {
		return '';
	}
	$params = $state->get( 'params' );
	$query  = is_array( $params ) ? $params : array();
	$query['page'] = SNT_OS_DASHBOARD_PAGE;
	$query['tab']  = $tab;
	if ( '' !== $sub ) {
		$query['sub'] = $sub;
	}
	$post = $state->get( 'post' );
	$post = is_array( $post ) ? $post : array();
	if ( array() !== $post ) {
		$state->set( 'post', array() );
	}
	$html = \snt_os_host_capture(
		static function () use ( $tab, $sub ) {
			\sn_admin_render_active_tab( $tab, $sub );
		},
		$query,
		$post
	);
	return '<div class="snt-classic">' . \snt_os_host_rewrite( $html, \snt_os_host_own_pages() ) . '</div>';
}

/**
 * Paint one leaf: its kit painter, else the capture.
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
	return captured_leaf( $tab, $sub, $state );
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
 * A tab's view callable.
 *
 * @param string $tab Top-tab slug.
 * @return callable
 */
function tab_view( $tab ) {
	return static function ( State $state, Os $os ) use ( $tab ) {
		$sub    = active_sub( $tab, $state );
		$leaves = leaves_for( $tab );
		$anchor = (string) $state->get( 'anchor' );
		echo '<div class="snt-app" data-snt-tab="' . \snt_kit_esc( $tab ) . '"' . ( '' !== $anchor ? ' data-snt-anchor="' . \snt_kit_esc( $anchor ) . '"' : '' ) . '>';
		echo notice_html( $state->get( 'notice' ) );
		if ( count( $leaves ) > 1 ) {
			echo \snt_kit_tabs( $sub, $leaves, 'sub', __( 'Sections', 'signal-and-noise-tools' ) );
		}
		echo '<div class="snt-leaf" data-snt-leaf="' . \snt_kit_esc( $sub ) . '">';
		echo paint_leaf( $tab, $sub, $state, $os );
		echo '</div></div>';
	};
}
