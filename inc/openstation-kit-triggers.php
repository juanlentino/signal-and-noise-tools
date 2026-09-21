<?php
/**
 * Signal & Noise Tools — the kit's triggers, painted from PHP.
 *
 * Buttons that dispatch, one-click writes through the replay pipeline, doors
 * to other admin screens, links inside the window (same tab: a `go`
 * dispatch; another tab: data the companion script reads) and external links.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/openstation-host-urls.php'; // 14.7.5: door-or-tab needs the same-origin test

/**
 * `<os-button>` with an action. Options: variant (primary|secondary|ghost|link|danger|holo),
 * confirm, confirm_title, confirm_label, danger, args (name => value, painted as os-arg-*), class, title, disabled.
 *
 * @param string              $label  Button text.
 * @param string              $action Declared action.
 * @param array<string,mixed> $opts   Options.
 * @return string
 */
function snt_kit_button( $label, $action, array $opts = array() ) {
	$attrs = array(
		'class'            => $opts['class'] ?? null,
		'variant'          => (string) ( $opts['variant'] ?? 'secondary' ),
		'os-action'        => (string) $action,
		'os-confirm'       => isset( $opts['confirm'] ) ? (string) $opts['confirm'] : null,
		'os-confirm-title' => isset( $opts['confirm_title'] ) ? (string) $opts['confirm_title'] : null,
		'os-confirm-label' => isset( $opts['confirm_label'] ) ? (string) $opts['confirm_label'] : null,
		'os-confirm-danger' => ! empty( $opts['danger'] ),
		'title'            => $opts['title'] ?? null,
		'disabled'         => ! empty( $opts['disabled'] ),
	);
	foreach ( (array) ( $opts['args'] ?? array() ) as $name => $value ) {
		$attrs[ 'os-arg-' . (string) $name ] = (string) $value;
	}
	$inner = ( ! empty( $opts['raw'] ) || ( isset( $opts['escape'] ) && false === $opts['escape'] ) )
		? (string) $label
		: snt_kit_esc( $label );
	return snt_kit_tag( 'os-button', $attrs, $inner );
}

/**
 * A one-click write: the classic `<button name="action" value="sn_…">` with
 * its own nonce, as a button that posts the same two values through the
 * admin-post pipeline. `$sn_action` is the handler's table key (`full_reset`)
 * or a hook outside the table with its prefix (`sn_prov_runsweep`); the nonce
 * is minted for the hook's action either way.
 *
 * @param string              $label     Text.
 * @param string              $sn_action Handler action.
 * @param array<string,mixed> $opts      As snt_kit_button(), plus `values` (extra fields).
 * @return string
 */
function snt_kit_action_button( $label, $sn_action, array $opts = array() ) {
	$opts['args'] = array_merge(
		array(
			'action'   => snt_kit_hook_action( $sn_action ),
			'nonce'    => snt_kit_nonce( $sn_action ),
			'pipeline' => 'admin-post',
		),
		(array) ( $opts['args'] ?? array() )
	);
	return snt_kit_button( $label, 'post', $opts );
}

/**
 * A hidden poll trigger: the runtime dispatches `$action` every `$ms` ms for
 * as long as this element is painted, reconciles the timers on every paint,
 * and skips a tick while the window is minimized, the tab hidden or a
 * dispatch in flight. Paint it conditionally and the condition is the
 * auto-refresh switch.
 *
 * SEAM: `os-poll`, App Framework view vocabulary, Experimental at OpenStation
 * 1.1.10 (`docs/app-framework.md`, "The view vocabulary";
 * `src/app-runtime/bindings.ts` readPolls(), which DROPS an element under
 * 250 ms and keys a timer by action + interval + args;
 * `src/app-runtime/session.ts` reconcilePolls()). An unknown
 * attribute on a span is inert, so a shell without the seam paints nothing
 * and polls nothing.
 *
 * NEVER the declared `refresh` action: that handler drops the notice and the
 * flash on every dispatch, and the Webhooks leaf keys its show-once secret
 * off the flash. The app declares `poll`, which touches no state (#1607).
 *
 * @param string $action Declared action to dispatch on every tick.
 * @param int    $ms     Interval in milliseconds; floored here at 250, below which the runtime drops the element.
 * @return string
 */
function snt_kit_poll( $action = 'poll', $ms = 30000 ) {
	return snt_kit_tag( 'span', array( 'os-action' => (string) $action, 'os-poll' => (string) max( 250, (int) $ms ), 'hidden' => true ) );
}

/**
 * The watch bar of a leaf whose forms cannot ride a poll: the morph resets
 * every unfocused field to the server value on a tick, and a kit checkbox's
 * `checked` is an attribute the morph syncs focused or not
 * (`src/app-runtime/morph.ts` syncAttributes(), 1.1.10). So the leaf polls
 * only while its forms are folded, and the fold is a `sn_*` param the way
 * every mode on the classic page is a query param: `sn_watch=1` folds the
 * forms and paints the poll; a `go` without it unfolds them and stops.
 *
 * @param string $sub      The leaf, so the `go` lands back on it.
 * @param bool   $watching Whether `sn_watch` is set.
 * @param string $what     What refreshes, as a noun phrase ("the delivery log").
 * @return string
 */
function snt_kit_watch_bar( $sub, $watching, $what ) {
	$button = $watching
		? snt_kit_button( __( 'Stop watching', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => (string) $sub ) ) )
		: snt_kit_button( __( 'Watch live', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => (string) $sub, 'sn_watch' => '1' ) ) );
	$text   = $watching
		/* translators: %s: what refreshes, e.g. "the delivery log" */
		? sprintf( __( '%s refreshes every 30 seconds. The forms are folded while it does; stop watching to edit.', 'signal-and-noise-tools' ), (string) $what )
		/* translators: %s: what refreshes, e.g. "the delivery log" */
		: sprintf( __( 'Watch %s refresh every 30 seconds; the forms fold while it does.', 'signal-and-noise-tools' ), (string) $what );
	return '<div class="snt-watch">' . ( $watching ? snt_kit_poll() : '' ) . '<span class="snt-hint">' . snt_kit_esc( ucfirst( $text ) ) . '</span>' . $button . '</div>';
}

/**
 * Whether a leaf's `state('params')` asks to watch (`snt_kit_watch_bar()`).
 *
 * @param array<string,mixed> $ctx Leaf context (tab, sub, state, os).
 * @return bool
 */
function snt_kit_watching( array $ctx ) {
	$state  = $ctx['state'] ?? null;
	$params = ( is_object( $state ) && method_exists( $state, 'get' ) ) ? (array) $state->get( 'params' ) : array();
	return ! empty( $params['sn_watch'] );
}

/**
 * A door to another admin screen: opens the URL in a shell window.
 *
 * @param string              $label Text.
 * @param string              $url   Admin URL.
 * @param array<string,mixed> $opts  As snt_kit_button(); variant defaults to link.
 * @return string
 */
function snt_kit_door( $label, $url, array $opts = array() ) {
	$opts['variant'] = $opts['variant'] ?? 'link';
	$opts['args']    = array( 'url' => (string) $url );
	return snt_kit_button( $label, 'door', $opts );
}

/**
 * A link INSIDE the window: to a leaf on the current tab it is a `go`
 * dispatch; to another tab it carries data the companion script reads
 * (activate the strip's tab, then `go` on that tab's session).
 *
 * @param string              $label  Text.
 * @param array<string,mixed> $target tab, sub, anchor; `current` = the painting tab.
 * @param array<string,mixed> $opts   As snt_kit_button(); variant defaults to link.
 * @return string
 */
function snt_kit_go( $label, array $target, array $opts = array() ) {
	$tab     = (string) ( $target['tab'] ?? '' );
	$current = (string) ( $target['current'] ?? $tab );
	$opts['variant'] = $opts['variant'] ?? 'link';
	if ( '' === $tab || $tab === $current ) {
		$opts['args'] = array_filter( array( 'sub' => (string) ( $target['sub'] ?? '' ), 'anchor' => (string) ( $target['anchor'] ?? '' ) ), 'strlen' );
		return snt_kit_button( $label, 'go', $opts );
	}
	return snt_kit_tag(
		'os-button',
		array(
			'class'           => trim( 'snt-go ' . (string) ( $opts['class'] ?? '' ) ),
			'variant'         => (string) $opts['variant'],
			'data-snt-tab'    => $tab,
			'data-snt-sub'    => (string) ( $target['sub'] ?? '' ),
			'data-snt-anchor' => (string) ( $target['anchor'] ?? '' ),
			'title'           => $opts['title'] ?? null,
		),
		( ! empty( $opts['raw'] ) || ( isset( $opts['escape'] ) && false === $opts['escape'] ) ) ? (string) $label : snt_kit_esc( $label )
	);
}

/**
 * A link out of the leaf. Same origin: a door, so it opens as a window
 * (14.7.5: a `target="_blank"` to this site relaunched the installed PWA).
 * Another origin: a new tab, because a window must never navigate the
 * desktop and most sites refuse to be framed.
 *
 * @param string $label Text.
 * @param string $href  URL.
 * @return string
 */
function snt_kit_link( $label, $href ) {
	$href = (string) $href;
	if ( function_exists( 'snt_os_host_is_same_origin_url' ) && function_exists( 'snt_os_host_absolute_url' ) ) {
		$absolute = snt_os_host_absolute_url( $href );
		if ( '' !== $absolute && snt_os_host_is_same_origin_url( $absolute ) && ! ( function_exists( 'snt_os_host_is_download_url' ) && snt_os_host_is_download_url( $absolute ) ) ) {
			return snt_kit_door( $label, $absolute, array( 'class' => 'snt-link' ) );
		}
	}
	return snt_kit_tag( 'a', array( 'class' => 'snt-link', 'href' => $href, 'target' => '_blank', 'rel' => 'noopener noreferrer' ), snt_kit_esc( $label ) );
}
