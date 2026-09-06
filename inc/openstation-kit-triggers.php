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
	return snt_kit_tag( 'os-button', $attrs, snt_kit_esc( $label ) );
}

/**
 * A one-click write: the classic `<button name="sn_action" value="…">` inside
 * a nonce'd form, as a button that posts the same two values.
 *
 * @param string              $label     Text.
 * @param string              $sn_action Handler action.
 * @param array<string,mixed> $opts      As snt_kit_button(), plus `values` (extra fields).
 * @return string
 */
function snt_kit_action_button( $label, $sn_action, array $opts = array() ) {
	$opts['args'] = array_merge( array( 'action' => (string) $sn_action, 'nonce' => snt_kit_nonce() ), (array) ( $opts['args'] ?? array() ) );
	return snt_kit_button( $label, 'post', $opts );
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
		snt_kit_esc( $label )
	);
}

/**
 * An external link, opened in a new tab (a window must never navigate the desktop).
 *
 * @param string $label Text.
 * @param string $href  URL.
 * @return string
 */
function snt_kit_link( $label, $href ) {
	return snt_kit_tag( 'a', array( 'class' => 'snt-link', 'href' => (string) $href, 'target' => '_blank', 'rel' => 'noopener noreferrer' ), snt_kit_esc( $label ) );
}
