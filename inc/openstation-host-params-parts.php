<?php
/**
 * Signal & Noise Tools: openstation-host.php, rel tokens, the window params,
 * the notice and toast text, the sub-tab resolver.
 *
 * Split from inc/openstation-host.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * `noopener noreferrer` MERGED into whatever rel the leaf already chose.
 *
 * Replacing it was a real loss, not a cosmetic one: Integrity → Citations
 * prints each inbound row as `rel="noopener nofollow ugc"` around a URL a
 * webmention SENDER supplied, and overwriting that attribute stripped the
 * untrusted-link profile the leaf deliberately set.
 *
 * @param string|null|bool $existing The anchor's current rel.
 * @return string
 */
function snt_os_host_rel( $existing ) {
	$tokens = is_string( $existing ) ? preg_split( '/\s+/', trim( $existing ), -1, PREG_SPLIT_NO_EMPTY ) : array();
	$tokens = is_array( $tokens ) ? $tokens : array();
	$have   = array_map( 'strtolower', $tokens );
	foreach ( array( 'noopener', 'noreferrer' ) as $needed ) {
		if ( ! in_array( $needed, $have, true ) ) {
			$tokens[] = $needed;
		}
	}
	return implode( ' ', $tokens );
}

/**
 * The `sn_*` query params a window carries, filtered and bounded.
 *
 * These ARE state on the classic page — the Tags leaf's merge preview is
 * three of them — so they have to survive a paint. They also ride every
 * dispatch, hence the caps.
 *
 * `_wpnonce` rides too, and only because one own-page GET link is a nonce-gated
 * ACTION rather than navigation: `sn_worker_version_recheck_url()` builds
 * `?sn_worker_recheck=1` and appends the nonce, and
 * `sn_worker_version_recheck_requested()` requires both. Dropping it made
 * "Re-check now" a silent no-op that repainted the same stale version. It is
 * safe to carry because it is only ever a value the window LENDS back as
 * `$_GET`; every write still verifies its own.
 *
 * @param array<string,mixed> $source A `$_GET`-shaped array.
 * @return array<string,string|string[]>
 */
function snt_os_host_params( array $source ) {
	$out = array();
	foreach ( $source as $key => $value ) {
		if ( ! is_string( $key ) || ( '_wpnonce' !== $key && ! preg_match( '/^sn_[a-z0-9_]{1,40}$/', $key ) ) ) {
			continue;
		}
		if ( count( $out ) >= SNT_OS_HOST_PARAM_CAP ) {
			break;
		}
		if ( is_array( $value ) ) {
			$list = array();
			foreach ( array_slice( $value, 0, 100 ) as $item ) {
				if ( is_scalar( $item ) ) {
					$list[] = substr( (string) $item, 0, 200 );
				}
			}
			$out[ $key ] = $list;
			continue;
		}
		if ( is_scalar( $value ) ) {
			$out[ $key ] = substr( (string) $value, 0, 200 );
		}
	}
	return $out;
}

/**
 * A flash code as the classic page's notice: `[ severity, html ]`, or null.
 *
 * Calls the shared registry; an unknown code renders nothing, which is what
 * the classic page does with one.
 *
 * @param string $flash Flash code.
 * @return array{0:string,1:string}|null
 */
function snt_os_host_notice( $flash ) {
	$flash = (string) $flash;
	if ( '' === $flash || ! function_exists( 'sn_admin_flash_to_notice' ) ) {
		return null;
	}
	$notice = sn_admin_flash_to_notice( $flash );
	return is_array( $notice ) && isset( $notice[0], $notice[1] ) ? array( (string) $notice[0], (string) $notice[1] ) : null;
}

/**
 * The same notice as one line of plain text, for `$os->toast()`.
 *
 * A toast has no tone and no markup (Effects::toast's own docblock), so the
 * colour and the `<a>` stay in the in-window notice; the toast is the part a
 * reader sees without looking at the body.
 *
 * @param array{0:string,1:string}|null $notice From `snt_os_host_notice()`.
 * @return string
 */
function snt_os_host_toast_text( $notice ) {
	if ( ! is_array( $notice ) || ! isset( $notice[1] ) ) {
		return '';
	}
	$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $notice[1] ) : (string) $notice[1];
	// 16.1.4: the flash strings carry entities (&rsquo;, &mdash;, &hellip;)
	// for the HTML notice; a toast is text and printed "Zenodo&rsquo;s".
	return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * The sub-tab a (tab, requested sub) pair resolves to.
 *
 * The same two rules as `sn_admin_resolve_active_sub()` — the requested leaf
 * when the tab has it, else the first — read off the same registry accessor.
 * A separate function only because the classic one reads `$_GET`, and the
 * window's sub lives in state.
 *
 * @param string $tab       Top-tab slug.
 * @param string $requested Requested sub-tab slug.
 * @return string '' for a landing tab with no sub-tabs.
 */
function snt_os_host_resolve_sub( $tab, $requested ) {
	if ( ! function_exists( 'sn_admin_get_sub_tabs' ) ) {
		return '';
	}
	$sub_tabs = sn_admin_get_sub_tabs( (string) $tab );
	if ( empty( $sub_tabs ) ) {
		return '';
	}
	$requested = (string) $requested;
	if ( '' !== $requested && isset( $sub_tabs[ $requested ] ) ) {
		return $requested;
	}
	return (string) array_key_first( $sub_tabs );
}
