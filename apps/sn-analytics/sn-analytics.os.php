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
}

$sn_analytics = App::define( APP_ID )
	->title( __( 'S&N Analytics', 'signal-and-noise-tools' ) )
	// The same chart glyph `add_menu_page()` gives the classic menu, so the
	// dock tile the app registers looks like the tile it replaces.
	->icon( 'dashicons-chart-area' )
	->size( 1280, 860 )
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
	->view( __NAMESPACE__ . '\\render_view' )
	// A view, a window, a class, a compare mode, a drill — from a link's
	// `os-arg-*` or the custom-date form's fields. Validated by the page's own
	// resolvers, never against a list kept here.
	->action(
		'go',
		static function ( State $state, Os $os, array $args ) {
			unset( $os );
			if ( ! may_manage() ) {
				return;
			}
			\snt_os_analytics_apply( $state, incoming( $args ) );
			// A notice is a one-shot on the classic page too: it arrives on a
			// `?sn_flash` URL and does not survive the next click.
			$state->set( 'notice', null );
		}
	)
	// THE PAGE HAS NO WRITE. Its one POST form streams a file and stays a real
	// form (see snt_os_analytics_keep_actions()), so this exists to answer
	// rather than to save: a refusal that names what was measured beats a
	// handler that would `exit` in the middle of a dispatch, and beats silence.
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
	// Any other admin screen, as its own shell window: the unconfigured gate's
	// "Configure analytics →" and the Measurement → Analytics links. Same
	// destination the classic link has; a window instead of a navigation.
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
	// The title-bar button. Four of the thirteen views read live data at render
	// time (edge, Sessions, Engagement, Login defense), so a repaint is the
	// window's "run it again"; the notice is a one-shot and goes with it.
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
	// A deep link landing on a window that is already open: the shell writes
	// the new params first, so this is mount's read, again.
	->action(
		'reopen',
		static function ( State $state, Os $os, array $args ) {
			unset( $args );
			if ( ! may_manage() ) {
				return;
			}
			read_params( $state, $os );
		}
	);

return $sn_analytics;
