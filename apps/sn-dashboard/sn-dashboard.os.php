<?php
/**
 * S&N Dashboard — the classic admin page as an OpenStation window (#1074).
 *
 * PORT = FAITHFUL. This app paints the SAME HTML the Signal & Noise admin
 * page paints, produced by the SAME render callables, for every one of its
 * ~35 leaves; every form saves through the SAME handler table and every
 * notice says the same thing. Nothing is redesigned, dropped or simplified,
 * and the classic page stays exactly where it is — a removal is not a port.
 *
 * FOUR ACTIONS, and each is one seam a document has and a window does not:
 *
 *   go     A tab, a sub-tab, an anchor, and the `sn_*` params that ARE state
 *          on the classic page. Everything the URL used to carry.
 *   post   A form. The classic pipeline minus `header()` + `exit`, which is
 *          the only part a window cannot do: capability, nonce, page,
 *          handler table, flash code, redirect target, anchor.
 *   door   Any other admin screen (`update-core.php`, `post.php`,
 *          `admin-post.php?action=…`) as its own shell window.
 *   refresh The title-bar button: drop the notice, re-read the badge.
 *
 * Framework tabs (`App::tab()`) are deliberately unused: they are baked at
 * definition and cannot be switched by a server action, and a Dashboard card
 * that links to Measurement → Health must switch the tab from the server.
 * So `tab` and `sub` live in STATE and the strip is painted by the view.
 *
 * Spec: docs/proposals/2026-09-06-openstation-hosts.md. The seams shared with
 * the Analytics host live in inc/openstation-host.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard;

use OpenStation\App;
use OpenStation\App\Os;
use OpenStation\App\State;

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

// The host seams. Required here and not only from the plugin's manifest so
// the app file also loads under a standalone host, where nothing else does.
require_once dirname( __DIR__, 2 ) . '/inc/openstation-host.php';
require_once __DIR__ . '/parts/nav.php';
require_once __DIR__ . '/parts/dock.php';
require_once __DIR__ . '/parts/view.php';

const APP_ID = 'sn-dashboard';

/**
 * The `page=` slug this window stands for: the canonical one the in-page tab
 * strip has linked since v1.8.1, and an entry in `sn_admin_post_allowed_pages()`.
 */
const SNT_OS_DASHBOARD_PAGE = 'sn-theme-options';

/**
 * Whether the acting user may drive this window, asked again on every action.
 *
 * `capabilities( 'manage_options' )` already gates the window, the icon and
 * dispatch. This is the second check, for the same reason the classic
 * pipeline re-checks on every POST rather than trusting the menu that showed
 * the link.
 *
 * @return bool
 */
function may_manage() {
	return function_exists( 'current_user_can' ) && \current_user_can( 'manage_options' );
}

/**
 * Merge a dispatch's argument sources into one bag.
 *
 * A link ships `os-arg-*`; a `<form method="get">` ships `values` (FormData).
 * The form's own fields win, because on the classic page its hidden `tab`
 * beats whatever the address bar held.
 *
 * @param array<string,mixed> $args Dispatch args.
 * @return array<string,mixed>
 */
function incoming( array $args ) {
	$values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
	unset( $args['values'] );
	return array_merge( $args, $values );
}

/**
 * What a refused save says. One line, because a toast has no tone and no
 * markup, and because a reader is owed the reason rather than silence.
 *
 * @param string $reason From `snt_os_host_replay()`.
 * @return string
 */
function refusal_text( $reason ) {
	switch ( (string) $reason ) {
		case 'capability':
			return __( 'Nothing was saved: this account cannot manage options.', 'signal-and-noise-tools' );
		case 'nonce':
			return __( 'Nothing was saved: the form expired. Reopen the tab and try again.', 'signal-and-noise-tools' );
		case 'page':
			return __( 'Nothing was saved: this window does not own that page.', 'signal-and-noise-tools' );
		default:
			return __( 'Nothing was saved.', 'signal-and-noise-tools' );
	}
}

$sn_dashboard = App::define( APP_ID )
	->title( __( 'S&N Dashboard', 'signal-and-noise-tools' ) )
	// The shield the dock item and the desktop icon `sn-icon-dashboard`
	// already wear; the megaphone stays with the Signal & Noise app.
	->icon( 'dashicons-shield-alt' )
	->size( 1180, 820 )
	->min_size( 760, 520 )
	->placement( 'dock' )
	->capabilities( 'manage_options' )
	->state(
		array(
			'tab'    => 'dashboard', // Top-tab slug. The URL's ?tab=, in state.
			'sub'    => '',          // Sub-tab slug; '' on a landing tab.
			'anchor' => '',          // A post-save `sn-sec-…` landing, painted as data-snt-anchor.
			'flash'  => '',          // The last flash CODE (the Webhooks leaf reads an id out of it).
			'notice' => null,        // [ severity, html ] — the classic notice, or null.
			'params' => array(),     // The `sn_*` query params that ARE state on the classic page.
		)
	)
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
	// A tab, a sub-tab, an anchor and the `sn_*` params — from a link's
	// `os-arg-*` or a GET form's fields. Validated against the registry
	// through the estate's own resolvers, never against a list kept here.
	->action(
		'go',
		static function ( State $state, Os $os, array $args ) {
			unset( $os );
			if ( ! may_manage() ) {
				return;
			}
			$in          = incoming( $args );
			$destination = \snt_os_host_destination( (string) ( $in['tab'] ?? '' ), (string) ( $in['sub'] ?? '' ) );
			$anchor      = '' !== $destination['anchor'] ? $destination['anchor'] : (string) ( $in['anchor'] ?? '' );
			$state->set( 'tab', $destination['tab'] )
				->set( 'sub', $destination['sub'] )
				->set( 'anchor', $anchor )
				->set( 'params', \snt_os_host_params( $in ) )
				->set( 'flash', '' )
				->set( 'notice', null );
		}
	)
	// One form. Everything `sn_handle_admin_post()` does before its exit.
	->action(
		'post',
		static function ( State $state, Os $os, array $args ) {
			if ( ! may_manage() ) {
				$os->toast( refusal_text( 'capability' ) );
				return;
			}
			$values = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
			$params = $state->get( 'params' );
			$query  = is_array( $params ) ? $params : array();
			$query['tab'] = (string) $state->get( 'tab' );
			$query['sub'] = (string) $state->get( 'sub' );

			$result = \snt_os_host_replay( $values, SNT_OS_DASHBOARD_PAGE, $query );
			if ( empty( $result['ok'] ) ) {
				// The refusal replaces whatever the last save said. Leaving an
				// earlier "Saved." on screen under a save that did not happen
				// is a readout claiming more than was measured.
				$state->set( 'notice', null )->set( 'flash', '' );
				$os->toast( refusal_text( (string) $result['reason'] ) );
				return;
			}

			// The redirect target, applied to state instead of to a Location
			// header: same canonical tab, same sub, same `#sn-sec-…` anchor.
			$target = is_array( $result['target'] ) ? $result['target'] : null;
			if ( null !== $target ) {
				$tab = (string) ( $target['tab'] ?? $state->get( 'tab' ) );
				$state->set( 'tab', $tab )
					->set( 'sub', \snt_os_host_resolve_sub( $tab, (string) ( $target['sub'] ?? '' ) ) )
					->set( 'anchor', (string) ( $target['anchor'] ?? '' ) );
			}
			// The classic redirect drops every query param but page/tab/sub/
			// sn_flash, so a merge preview does not survive a save here either.
			$state->set( 'params', array() )->set( 'flash', (string) $result['flash'] );

			$notice = \snt_os_host_notice( (string) $result['flash'] );
			$state->set( 'notice', $notice );
			if ( null !== $notice ) {
				$os->toast( \snt_os_host_toast_text( $notice ) );
			}
			$os->badge( badge_count() );
			// A save can change what the SERVER registers — a module gated on
			// an option, a page gated on a credential — and the shell only
			// learns that from a fresh payload.
			$os->refresh_menu();
		}
	)
	// Any other admin screen, as its own shell window: the `update-core.php`
	// door, a post editor, an `admin-post.php` action. Same destination the
	// classic link has; a window instead of a navigation.
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
	// The title-bar button: the notice is a one-shot, and the badge may have
	// moved while the window sat open.
	->action(
		'refresh',
		static function ( State $state, Os $os, array $args ) {
			unset( $args );
			if ( ! may_manage() ) {
				return;
			}
			$state->set( 'notice', null )->set( 'flash', '' );
			$os->badge( badge_count() );
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
			$os->badge( badge_count() );
		}
	);

// The ⋯ menu: the dock submenu that inc/desktop-mode-dock.php used to build,
// as one `go` row per top tab.
foreach ( menu_items() as $sn_menu_row ) {
	$sn_dashboard->window_action(
		(string) $sn_menu_row['id'],
		array(
			'label'  => (string) $sn_menu_row['label'],
			'action' => (string) $sn_menu_row['action'],
			'order'  => (int) $sn_menu_row['order'],
			'args'   => (array) $sn_menu_row['args'],
		)
	);
}

return $sn_dashboard;
