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
require_once __DIR__ . '/parts/frame.php';
// Every leaf painter registers itself through `snt_os_dashboard_painters`;
// one file per leaf, named `<tab>-<sub>.php` (the Dashboard tab's is `dashboard.php`).
foreach ( (array) glob( __DIR__ . '/parts/leaves/*.php' ) as $sn_dashboard_leaf_file ) {
	require_once $sn_dashboard_leaf_file;
}

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
 * EXPANDED on the way out, for the same reason the replay expands: a GET form's
 * keys are literal `name` attributes, so the Tags merge picker arrives as
 * `sn_tag_from[]` and `snt_os_host_params()`'s allowlist drops it unexpanded —
 * which is how "Preview merge" landed on an empty confirm panel.
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
 * What a refused save says. One line, because a toast has no tone and no
 * markup, and because a reader is owed the reason rather than silence.
 *
 * EVERY LINE NAMES WHAT WAS MEASURED. The first cut said "the form expired.
 * Reopen the tab and try again." to every refusal, including eight forms whose
 * nonce was never the shared one and which reopening could never fix. An
 * expiry was not measured; a token that did not verify against the action it
 * was checked against was, and that is what this says.
 *
 * @param string $reason From `snt_os_host_replay()`.
 * @param string $detail The particular the gate closed on (an action, a nonce action, a die's own words).
 * @return string
 */
function refusal_text( $reason, $detail = '' ) {
	$detail = (string) $detail;
	switch ( (string) $reason ) {
		case 'capability':
			return __( 'Nothing was saved: this account cannot manage options.', 'signal-and-noise-tools' );
		case 'nonce':
			return sprintf(
				/* translators: %s: the nonce action the submitted token was verified against. */
				__( 'Nothing was saved: the security token did not verify against %s.', 'signal-and-noise-tools' ),
				'' !== $detail ? $detail : \SNT_OS_HOST_NONCE
			);
		case 'page':
			return __( 'Nothing was saved: this window does not own that page.', 'signal-and-noise-tools' );
		case 'died':
			// The handler's own words. A window that paraphrased them would be
			// reporting a cause it did not measure.
			return '' !== $detail ? $detail : __( 'Nothing was saved: the action refused, without saying why.', 'signal-and-noise-tools' );
		case 'unknown':
			if ( '' === $detail ) {
				return __( 'Nothing was saved: the form carried no action.', 'signal-and-noise-tools' );
			}
			return sprintf(
				/* translators: %s: the action name the form submitted. */
				__( 'Nothing was saved: no pipeline in this window handles the action %s.', 'signal-and-noise-tools' ),
				$detail
			);
		default:
			return __( 'Nothing was saved.', 'signal-and-noise-tools' );
	}
}

/**
 * A section slug as the element id the page actually carries.
 *
 * State holds an ELEMENT ID, because that is what assets/os-host.js looks up
 * and what a leaf's own fragment link already is (`#sn-dash-diagnostics`).
 * The estate's resolvers speak in bare slugs — `sn_admin_post_redirect_target()`
 * returns 'identity' and `sn_admin_render_section()` emits
 * `id="sn-sec-identity"` — so every slug that arrives from one of them is
 * converted here, once, and nothing else prefixes anything.
 *
 * @param string $slug A `sn_admin_post_redirect_target()` anchor slug.
 * @return string The element id, or ''.
 */
function section_anchor( $slug ) {
	$slug = (string) $slug;
	return '' !== $slug ? 'sn-sec-' . $slug : '';
}


/**
 * The tab this dispatch came from: the framework's view slug, `main` being
 * the Dashboard tab.
 *
 * @param Os $os Host.
 * @return string
 */
function current_tab( Os $os ) {
	$view = (string) $os->view;
	return '' === $view || 'main' === $view ? 'dashboard' : $view;
}

/**
 * What a write submitted: an `<os-form>`'s collected values, or a one-click
 * button's `action` + `nonce` arguments as the two fields the classic form
 * would have carried.
 *
 * @param array<string,mixed> $args Dispatch arguments.
 * @return array<string,mixed>
 */
function posted_values( array $args ) {
	if ( isset( $args['values'] ) && is_array( $args['values'] ) ) {
		return $args['values'];
	}
	if ( isset( $args['action'] ) && is_scalar( $args['action'] ) ) {
		$values = array( 'sn_action' => (string) $args['action'] );
		if ( isset( $args['nonce'] ) && is_scalar( $args['nonce'] ) ) {
			$values['_wpnonce'] = (string) $args['nonce'];
		}
		return $values;
	}
	return array();
}

$sn_dashboard = App::define( APP_ID )
	->title( __( 'S&N Dashboard', 'signal-and-noise-tools' ) )
	->icon( 'dashicons-shield-alt' )
	->size( 1180, 820 )
	->min_size( 760, 520 )
	->placement( 'dock' )
	->capabilities( 'manage_options' )
	// One session per tab (the framework's tabs), each with this shape. The
	// tab itself is `$os->view`; only the leaf, the anchor and what the last
	// write produced are state.
	->state(
		array(
			'sub'    => '',          // Leaf slug; '' on a landing tab.
			'anchor' => '',          // The ELEMENT ID to scroll to, painted as data-snt-anchor.
			'flash'  => '',          // The last flash CODE (the Webhooks leaf reads an id out of it).
			'notice' => null,        // [ severity, html ] — the classic notice, or null.
			'params' => array(),     // The `sn_*` query params that ARE state on the classic page.
			'post'   => array(),     // ONE paint's $_POST, for the form its own leaf handles.
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
	->view( tab_view( 'dashboard' ) )
	->action(
		'go',
		static function ( State $state, Os $os, array $args ) {
			if ( ! may_manage() ) {
				return;
			}
			$in  = incoming( $args );
			$tab = current_tab( $os );
			$state->set( 'sub', \snt_os_host_resolve_sub( $tab, (string) ( $in['sub'] ?? '' ) ) )
				->set( 'anchor', '' !== (string) ( $in['anchor'] ?? '' ) ? (string) $in['anchor'] : '' )
				->set( 'params', \snt_os_host_params( $in ) )
				->set( 'flash', '' )
				->set( 'post', array() )
				->set( 'notice', null );
		}
	)
	->action(
		'post',
		static function ( State $state, Os $os, array $args ) {
			if ( ! may_manage() ) {
				$os->toast( refusal_text( 'capability' ) );
				return;
			}
			$values = posted_values( $args );
			$pipeline = isset( $args['pipeline'] ) && is_scalar( $args['pipeline'] ) ? (string) $args['pipeline'] : '';
			$tab      = current_tab( $os );
			$params   = $state->get( 'params' );
			$query    = is_array( $params ) ? $params : array();
			$query['tab'] = $tab;
			$query['sub'] = active_sub( $tab, $state );
			$result = \snt_os_host_replay( $values, SNT_OS_DASHBOARD_PAGE, $query, $pipeline );
			if ( empty( $result['ok'] ) ) {
				$reason = (string) $result['reason'];
				$detail = (string) $result['detail'];
				$died = 'died' === $reason && '' !== $detail;
				$state->set( 'notice', $died ? array( 'error', $detail ) : null )
					->set( 'flash', '' )
					->set( 'post', array() );
				$os->toast( refusal_text( $reason, $detail ) );
				return;
			}
			if ( 'inline' === (string) $result['pipeline'] ) {
				$state->set( 'post', (array) $result['post'] )
					->set( 'notice', null )
					->set( 'flash', '' );
				$os->badge( badge_count() );
				return;
			}
			$target = is_array( $result['target'] ) ? $result['target'] : null;
			if ( null !== $target && (string) ( $target['tab'] ?? $tab ) === $tab ) {
				$state->set( 'sub', \snt_os_host_resolve_sub( $tab, (string) ( $target['sub'] ?? '' ) ) )
					->set( 'anchor', section_anchor( (string) ( $target['anchor'] ?? '' ) ) );
			}
			$state->set( 'params', (array) $result['params'] )
				->set( 'flash', (string) $result['flash'] )
				->set( 'post', array() );
			$notice = \snt_os_host_notice( (string) $result['flash'] );
			$state->set( 'notice', $notice );
			if ( null !== $notice ) {
				$os->toast( \snt_os_host_toast_text( $notice ) );
			}
			$os->badge( badge_count() );
			$os->refresh_menu();
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
			unset( $args );
			if ( ! may_manage() ) {
				return;
			}
			$state->set( 'notice', null )->set( 'flash', '' );
			$os->badge( badge_count() );
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
			$os->badge( badge_count() );
		}
	);

// The framework's tabs: one per top tab after the Dashboard (the main view),
// in registry order. Each is its own session painted by the same frame.
$sn_dashboard_position = 10;
foreach ( top_tabs() as $sn_dashboard_tab ) {
	$sn_dashboard_slug = (string) ( $sn_dashboard_tab['tab'] ?? '' );
	if ( '' === $sn_dashboard_slug || 'dashboard' === $sn_dashboard_slug ) {
		continue;
	}
	$sn_dashboard->tab(
		$sn_dashboard_slug,
		array(
			'label'    => (string) ( $sn_dashboard_tab['label'] ?? $sn_dashboard_slug ),
			'position' => $sn_dashboard_position,
			'view'     => tab_view( $sn_dashboard_slug ),
		)
	);
	$sn_dashboard_position += 10;
}
return $sn_dashboard;
