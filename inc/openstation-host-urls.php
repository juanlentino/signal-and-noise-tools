<?php
/**
 * Signal & Noise Tools — the OpenStation host's URL helpers.
 *
 * Split out of inc/openstation-host.php in 14.7.5 so the kit's link helper
 * (snt_kit_link) can decide "door or tab" without loading the host: the
 * decision is the same-origin test below, and a same-origin `target="_blank"`
 * relaunches the installed PWA. Pure functions over admin_url(); no hooks.
 *
 * @package SignalNoiseTools
 * @since 14.7.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's `wp-admin/` base, as `[ host, path ]`, or null when WordPress is
 * not loaded (a standalone host, a suite).
 *
 * @return array{host:string,path:string}|null
 */
if ( ! function_exists( 'snt_os_host_admin_base' ) ) {
	function snt_os_host_admin_base() {
		if ( ! function_exists( 'admin_url' ) ) {
			return null;
		}
		$base = (string) admin_url( '/' );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		if ( '' === $host || '' === $path ) {
			return null;
		}
		return array(
			'host' => strtolower( $host ),
			'path' => $path,
		);
	}
}

/**
 * Whether a URL is an admin URL of THIS site.
 *
 * Host AND path prefix, never a bare `strpos` on the whole URL: `http` vs
 * `https` and a port would each make an identical screen look foreign.
 *
 * @param string $url Absolute URL.
 * @return bool
 */
if ( ! function_exists( 'snt_os_host_is_admin_url' ) ) {
	function snt_os_host_is_admin_url( $url ) {
		$base = snt_os_host_admin_base();
		if ( null === $base ) {
			return false;
		}
		$url    = (string) $url;
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return $host === $base['host'] && 0 === strpos( $path, $base['path'] );
	}
}

/**
 * Whether a URL is on THIS site at all: the same host as wp-admin, any path.
 *
 * 14.7.5: the distinction that decides between a window and a tab. Inside
 * the installed PWA a `target="_blank"` navigation to any same-origin URL
 * is inside the app's scope, so the browser LAUNCHES THE APP AGAIN instead
 * of opening a tab: "View the note", "Verification docket", every front-end
 * link in a window started a second OpenStation. Same-origin opens as a
 * window (the shell iframes the front end the way it iframes previews);
 * only another origin earns a tab.
 *
 * @param string $url Absolute URL.
 * @return bool
 */
if ( ! function_exists( 'snt_os_host_is_same_origin_url' ) ) {
	function snt_os_host_is_same_origin_url( $url ) {
		$base = snt_os_host_admin_base();
		if ( null === $base ) {
			return false;
		}
		$url    = (string) $url;
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $base['host'];
	}
}

/**
 * Resolve an href the way a browser sitting on an admin page would.
 *
 * A bare `admin.php?page=…` on an admin screen is relative to `wp-admin/`,
 * not to the site root — the one resolution that decides whether such a link
 * becomes a window action or is left to navigate the whole desktop away.
 *
 * @param string $href Raw href.
 * @return string Absolute URL, or '' when it cannot be resolved.
 */
if ( ! function_exists( 'snt_os_host_absolute_url' ) ) {
	function snt_os_host_absolute_url( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			return $href;
		}
		$base = snt_os_host_admin_base();
		if ( null === $base ) {
			return '';
		}
		$origin = (string) preg_replace( '#' . preg_quote( $base['path'], '#' ) . '$#', '', (string) admin_url( '/' ) );
		if ( 0 === strpos( $href, '//' ) ) {
			return $href;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}
		return rtrim( (string) admin_url( '/' ), '/' ) . '/' . $href;
	}
}

/**
 * A same-origin URL that must stay a navigation: a file the browser saves
 * (the RSL licence XML, a CSV export). An iframe window cannot show it and
 * a download does not relaunch the PWA.
 *
 * @param string $url Absolute URL.
 * @return bool
 */
if ( ! function_exists( 'snt_os_host_is_download_url' ) ) {
	function snt_os_host_is_download_url( $url ) {
		$path = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		return (bool) preg_match( '/\.(xml|csv|json|txt|zip|pdf)$/', $path );
	}
}
