<?php
/**
 * S&N Analytics — the classic Analytics screen as an OpenStation window (#1075).
 *
 * PORT = FAITHFUL. This app paints the SAME HTML the S&N Analytics admin page
 * paints, produced by the SAME renderer — `snt_analytics_render_dashboard()` —
 * for all thirteen views, with the same tab strip, the same range control, the
 * same class and compare pills, the same drill-downs and the same export.
 * Nothing is redesigned, dropped or simplified, and the classic page stays
 * exactly where it is: a removal is not a port.
 *
 * THE PAGE HAS NO WRITES. Every control on it is a GET link or a GET form, and
 * the nine `sn_*` params are its whole state — which is why this window has one
 * navigation action where the Dashboard host has a write pipeline:
 *
 *   go      A view, a window, a class, a compare mode, a drill. Everything the
 *           URL used to carry, applied through the page's own resolvers.
 *   post    The refusal seam. The page's ONE POST form is the CSV/JSON export,
 *           and it stays a real form (a download must be a navigation), so
 *           nothing should ever reach this — and if something does, it says so
 *           instead of running a handler that ends in `exit`.
 *   door    Any other admin screen — the "Configure analytics →" gate, the
 *           Measurement → Analytics settings — as its own shell window.
 *   refresh The title-bar button: drop the notice, repaint.
 *
 * Framework tabs (`App::tab()`) are deliberately unused, for the same reason as
 * the Dashboard host: they are baked at definition and cannot be switched by a
 * server action, while the Overview's doorway links switch the view from the
 * server on every click.
 *
 * Spec: docs/proposals/2026-09-06-openstation-hosts.md. The seams shared with
 * the Dashboard host live in inc/openstation-host.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Analytics;

use OpenStation\App;
use OpenStation\App\Os;
use OpenStation\App\State;

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

// The host seams. Required here and not only from the plugin's manifest so the
// app file also loads under a standalone host, where nothing else does.
require_once dirname( __DIR__, 2 ) . '/inc/openstation-host.php';
require_once __DIR__ . '/parts/state.php';
require_once __DIR__ . '/parts/view.php';
require_once __DIR__ . '/parts/frame.php';
// Every piece painter registers itself through `snt_os_analytics_painters`;
// one file per piece under parts/painters (chrome-*.php, view-*.php).
foreach ( (array) glob( __DIR__ . '/parts/painters/*.php' ) as $sn_analytics_painter_file ) {
	require_once $sn_analytics_painter_file;
}

const APP_ID = 'sn-analytics';

/**
 * The `page=` slug this window stands for.
 *
 * Read off `SNT_ANALYTICS_PAGE_SLUG` — the page's own one-literal-one-place
 * constant — rather than repeated here, so a link the rewrite has to recognise
 * as OURS follows the page. The fallback is for a standalone host, where the
 * estate is not loaded at all.
 *
 * @return string
 */
function page_slug() {
	return defined( 'SNT_ANALYTICS_PAGE_SLUG' ) ? (string) \SNT_ANALYTICS_PAGE_SLUG : 'sn-analytics';
}

/**
 * Whether the acting user may drive this window, asked again on every action.
 *
 * `capabilities( 'manage_options' )` already gates the window, its dock tile
 * and dispatch. This is the second check, for the same reason
 * `snt_analytics_dashboard_page()` re-checks rather than trusting the menu that
 * showed the link: menu visibility is not access control.
 *
 * @return bool
 */
function may_manage() {
	return function_exists( 'current_user_can' ) && \current_user_can( 'manage_options' );
}

/**
 * Merge a dispatch's argument sources into one bag.
 *
 * A rewritten link ships `os-arg-*`; the custom-date `<form method="get">`
 * ships its fields as `values` (FormData). The form's own fields win, because
 * on the classic page its hidden `sn_view` beats whatever the address bar held.
 * Expanded on the way out for the same reason the Dashboard host expands: the
 * runtime's keys are literal `name` attributes, and PHP's request parsing never
 * ran.
 *
 * @param array<string,mixed> $args Dispatch args.
 * @return array<string,mixed>
 */
function incoming( array $args ) {
	$values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
	unset( $args['values'] );
	return \snt_os_host_expand( array_merge( $args, $values ) );
}

/**
 * Read the open-time params onto the state.
 *
 * Shared by `mount` and `reopen` — a deep link that lands on an open window
 * must retarget it, and the shell writes the new params before it dispatches
 * `reopen`, so the two reads are the same read. The params are the classic
 * URL's own names (`sn_view`, `sn_range`, …): one vocabulary for a link, a
 * form and a deep link, so no surface has to guess which spelling to use.
 *
 * @param State $state Window state.
 * @param Os    $os    Host handle.
 * @return void
 */
function read_params( State $state, Os $os ) {
	$args = array();
	foreach ( array_keys( \snt_os_analytics_get( $state ) ) as $key ) {
		if ( 'page' === $key ) {
			continue;
		}
		$value = $os->param( $key, null );
		if ( null !== $value ) {
			$args[ $key ] = $value;
		}
	}
	// Wholesale, never merged: a deep link that named no view must not inherit
	// the drill the last one left behind.
	foreach ( \snt_os_analytics_defaults() as $key => $value ) {
		$state->set( $key, $value );
	}
	\snt_os_analytics_apply( $state, $args );
}

/**
 * Mount: land on the requested view.
 *
 * @param State $state Window state.
 * @param Os    $os    Host handle.
 * @return void
 */
function mount( State $state, Os $os ) {
	read_params( $state, $os );
	$state->set( 'view', view_slug( $os, $state ) );
}


/**
 * A control's pick, as the classic link would have carried it: the current
 * query with ONE parameter replaced by the classic rules -- a range or class
 * pick rebuilds the window arguments (`snt_analytics_window_args()`), a
 * compare pick drops the key for `off`, anything else sets `sn_<key>`.
 *
 * @param State  $state Session state.
 * @param string $key   range|class|compare|drill|event_prop|lg_range.
 * @param string $value The pick.
 * @return array<string,string> The next query.
 */
function picked( State $state, $key, $value ) {
	$get   = \snt_os_analytics_get( $state );
	$key   = (string) $key;
	$value = (string) $value;
	unset( $get['page'] );
	if ( 'range' === $key || 'class' === $key ) {
		$range = 'range' === $key ? $value : (string) $get['sn_range'];
		$class = 'class' === $key ? $value : (string) $get['sn_class'];
		unset( $get['sn_range'], $get['sn_class'], $get['sn_from'], $get['sn_to'] );
		$window = function_exists( 'snt_analytics_window_args' )
			? snt_analytics_window_args( $range, $class, (string) $state->get( 'from' ), (string) $state->get( 'to' ) )
			: array( 'sn_range' => $range, 'sn_class' => $class );
		return array_merge( $get, $window );
	}
	if ( 'compare' === $key ) {
		unset( $get['sn_compare'] );
		return 'off' === $value ? $get : array_merge( $get, array( 'sn_compare' => $value ) );
	}
	$get[ 'sn_' . preg_replace( '/[^a-z_]/', '', $key ) ] = $value;
	return $get;
}

$sn_analytics = App::define( APP_ID )
	->title( __( 'S&N Analytics', 'signal-and-noise-tools' ) )
	->icon( 'dashicons-chart-area' )
	->size( 1280, 860 )
	->min_size( 800, 560 )
	->placement( 'dock' )
	->capabilities( 'manage_options' )
	->state( \snt_os_analytics_defaults() )
	->title_bar_button(
		'refresh',
		array(
			'label'  => __( 'Refresh', 'signal-and-noise-tools' ),
			'icon'   => 'reload',
			'action' => 'refresh',
		)
	)
	->mount( __NAMESPACE__ . '\\mount' )
	->view( tab_view( 'overview' ) )
	->action(
		'go',
		static function ( State $state, Os $os, array $args ) {
			if ( ! may_manage() ) {
				return;
			}
			$in = isset( $args['key'] ) && is_scalar( $args['key'] ) && array_key_exists( 'value', $args ) && is_scalar( $args['value'] )
				? picked( $state, (string) $args['key'], (string) $args['value'] )
				: incoming( $args );
			\snt_os_analytics_apply( $state, $in );
			$state->set( 'view', view_slug( $os, $state ) )->set( 'notice', null );
		}
	)
	->action(
		'post',
		static function ( State $state, Os $os, array $args ) {
			unset( $state );
			if ( ! may_manage() ) {
				$os->toast( __( 'Nothing was saved: this account cannot manage options.', 'signal-and-noise-tools' ) );
				return;
			}
			$values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
			$action = \snt_os_host_last( \snt_os_host_expand( $values )['sn_action'] ?? '' );
			if ( in_array( $action, \snt_os_analytics_keep_actions(), true ) ) {
				$os->toast(
					sprintf(
						/* translators: %s: the sn_action the form submitted. */
						__( 'Nothing was saved: %s streams a file, so it downloads in a new tab instead.', 'signal-and-noise-tools' ),
						$action
					)
				);
				return;
			}
			$os->toast(
				'' === $action
					? __( 'Nothing was saved: the form carried no action, and this window has no form that saves.', 'signal-and-noise-tools' )
					: sprintf(
						/* translators: %s: the sn_action the form submitted. */
						__( 'Nothing was saved: this window paints a read-only report and handles no action named %s.', 'signal-and-noise-tools' ),
						$action
					)
			);
		}
	)
	->action(
		'door',
		static function ( State $state, Os $os, array $args ) {
			unset( $state );
			if ( ! may_manage() ) {
				return;
			}
			$url = (string) ( $args['url'] ?? '' );
			if ( '' !== $url && \snt_os_host_is_admin_url( $url ) ) {
				$os->open_url( $url );
			}
		}
	)
	->action(
		'refresh',
		static function ( State $state, Os $os, array $args ) {
			unset( $os, $args );
			if ( ! may_manage() ) {
				return;
			}
			$state->set( 'notice', null );
		}
	)
	->action(
		'reopen',
		static function ( State $state, Os $os, array $args ) {
			unset( $args );
			if ( ! may_manage() ) {
				return;
			}
			read_params( $state, $os );
			$state->set( 'view', view_slug( $os, $state ) );
		}
	);

// The framework's tabs: one per view after Overview (the main view), in the
// registry's order. Each is its own session painted by the same frame.
$sn_analytics_position = 10;
$sn_analytics_views    = function_exists( 'snt_analytics_views' ) ? (array) snt_analytics_views() : array();
foreach ( $sn_analytics_views as $sn_analytics_slug => $sn_analytics_label ) {
	$sn_analytics_slug = (string) $sn_analytics_slug;
	if ( '' === $sn_analytics_slug || 'overview' === $sn_analytics_slug ) {
		continue;
	}
	$sn_analytics->tab(
		$sn_analytics_slug,
		array(
			'label'    => (string) $sn_analytics_label,
			'position' => $sn_analytics_position,
			'view'     => tab_view( $sn_analytics_slug ),
		)
	);
	$sn_analytics_position += 10;
}
return $sn_analytics;
