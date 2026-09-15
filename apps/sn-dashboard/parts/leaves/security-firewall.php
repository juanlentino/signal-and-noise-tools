<?php
/**
 * S&N Dashboard — Security › Firewall: what Cloudflare stopped before
 * WordPress ran (15.3.0). Paints the monitor's firewall reading and the event
 * log's tops through the same part the Connections leaf used until 15.3.0
 * (connections-cloudflare-monitor.php), from the same stored record; the
 * reading moved to where its question is asked. Refresh posts
 * `cf_monitor_refresh`; painting never fetches.
 *
 * @package SignalNoiseTools
 * @since 15.3.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_security_firewall( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	if ( ! function_exists( __NAMESPACE__ . '\\cloudflare_firewall_html' ) || ! function_exists( __NAMESPACE__ . '\\cloudflare_data' ) ) {
		return \snt_kit_empty( __( 'The Cloudflare monitor is not loaded.', 'signal-and-noise-tools' ) );
	}
	$d      = cloudflare_data();
	$record = cloudflare_monitor_record();
	if ( ! is_array( $record ) || empty( $record['configured'] ) ) {
		$why = is_array( $record )
			? __( 'Not configured: set the Cloudflare token under Connections › Credentials.', 'signal-and-noise-tools' )
			: __( 'The monitor has not run yet. It runs daily; press Refresh to run it now.', 'signal-and-noise-tools' );
		return \snt_kit_section( __( 'Firewall, 24 hours', 'signal-and-noise-tools' ), '<p class="snt-hint">' . \snt_kit_esc( $why ) . '</p>' . cloudflare_monitor_footer_html( $d, $record ), __( 'What Cloudflare stopped before WordPress ran.', 'signal-and-noise-tools' ) );
	}
	return cloudflare_firewall_html( $d );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/firewall'] = __NAMESPACE__ . '\\paint_security_firewall';
		return $painters;
	}
);
