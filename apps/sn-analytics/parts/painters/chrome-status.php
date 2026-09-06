<?php
/**
 * S&N Analytics — chrome/empty (the unconfigured gate) and chrome/error (the
 * AE diagnostic notice), painted from the kit.
 *
 * Classic renderers, both inc/analytics-admin.php:
 *  - snt_analytics_render_empty()  the snt_an_gate() unconfigured state, with
 *    its "Configure analytics →" door — the page's ONLY action while
 *    unconfigured, so it keeps the classic's button-primary weight.
 *  - snt_analytics_render_error()  the AE diagnostic notice (admins only),
 *    always painted, on every view. Split — behaviour-preserving — into
 *    snt_analytics_render_error_data() so this painter reads the SAME
 *    verdict the classic renderer echoes rather than re-deriving it.
 *
 * Same copy, same settings-door URL, same AE error shape; the kit's parts
 * instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * chrome/empty: the snt_an_gate unconfigured state, with the Configure door.
 *
 * @param array<string,mixed> $ctx Frame context (unused — the gate is static copy).
 * @return string
 */
function paint_chrome_empty( array $ctx ) {
	unset( $ctx );
	$html = \snt_kit_empty(
		__( 'Analytics', 'signal-and-noise-tools' ),
		__( "Analytics isn't receiving data yet. Add your Cloudflare read credentials below to connect the dashboard. You can also set SN_CF_ANALYTICS_TOKEN / SN_CF_ACCOUNT_ID in wp-config.php (see Cloudflare Worker setup below).", 'signal-and-noise-tools' )
	);
	$url = function_exists( 'snt_analytics_settings_url' ) ? snt_analytics_settings_url() : '';
	if ( '' !== (string) $url ) {
		// cta_primary: the page's ONLY action while unconfigured keeps the
		// classic's button-primary weight (house convention: routine dormant
		// gates elsewhere stay button-small; this first-run gate does not).
		$html .= \snt_kit_door(
			__( 'Configure analytics →', 'signal-and-noise-tools' ),
			$url,
			array( 'variant' => 'primary' )
		);
	}
	return $html;
}

/**
 * chrome/error: the last AE read error (admins only), from the SAME data the
 * classic renderer prints.
 *
 * @param array<string,mixed> $ctx Frame context (unused — the reader is global-scoped, same as the classic renderer).
 * @return string
 */
function paint_chrome_error( array $ctx ) {
	unset( $ctx );
	if ( ! function_exists( 'snt_analytics_render_error_data' ) ) {
		return '';
	}
	$data = snt_analytics_render_error_data();
	if ( null === $data ) {
		return '';
	}
	$inner = '<b>' . \snt_kit_esc( __( 'Analytics read failed.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( $data['code'] );
	if ( '' !== $data['url'] ) {
		$inner .= ' ' . \snt_kit_esc( __( 'from', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( $data['url'], false );
	}
	if ( '' !== $data['message'] ) {
		$inner .= '<br>' . \snt_kit_esc( $data['message'] );
	}
	// Not dismissible: the classic markup carries no is-dismissible class.
	return \snt_kit_notice( 'error', $inner );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/empty'] = __NAMESPACE__ . '\\paint_chrome_empty';
		$painters['chrome/error'] = __NAMESPACE__ . '\\paint_chrome_error';
		return $painters;
	}
);
