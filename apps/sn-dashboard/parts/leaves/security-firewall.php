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
 * The "Outbound pinning" box (#1599): the SSRF guard's Site Health verdict
 * (inc/ssrf-guard.php, `sn_ssrf_pinning_health()`) painted where the
 * perimeter question is asked. The row stays off the curated Health tab
 * because it never fires on a healthy host; a posture box on a leaf is the
 * 17.4.0 Breached-passwords shape, not a defects row. The verdict on top
 * (a warning when not good, the hint line when good), then the two facts it
 * is derived from: whether the cURL transport is there, and the last
 * request that went out unpinned, from the same option the Site Health row
 * reads. Nothing is computed here. '' when the module is absent.
 *
 * @return string
 */
function firewall_pinning_html() {
	if ( ! function_exists( 'sn_ssrf_pinning_health' ) || ! function_exists( 'sn_ssrf_pinning_available' ) ) {
		return '';
	}
	$last      = get_option( 'sn_ssrf_unpinned_last', null );
	$last      = is_array( $last ) && ! empty( $last['at'] ) ? $last : null;
	$available = (bool) \sn_ssrf_pinning_available();
	$v         = \sn_ssrf_pinning_health( $available, $last );
	$rows      = array(
		array(
			'label' => __( 'cURL transport', 'signal-and-noise-tools' ),
			'value' => $available ? __( 'present, the pin fires on every outbound request', 'signal-and-noise-tools' ) : __( 'absent, so requests fall back to fsockopen unpinned', 'signal-and-noise-tools' ),
			'tone'  => $available ? null : 'warn',
		),
		array(
			'label' => __( 'Last unpinned request', 'signal-and-noise-tools' ),
			'html'  => true,
			'value' => null === $last
				? \snt_kit_esc( __( 'none recorded', 'signal-and-noise-tools' ) )
				: sprintf(
					/* translators: 1: host name, 2: relative time element */
					\snt_kit_esc( __( '%1$s, %2$s', 'signal-and-noise-tools' ) ),
					\snt_kit_esc( (string) ( $last['host'] ?? 'unknown' ) ),
					\snt_kit_relative_time( (int) $last['at'] )
				),
			'tone'  => null === $last ? null : 'warn',
		),
	);
	return \snt_kit_section(
		__( 'Outbound pinning', 'signal-and-noise-tools' ),
		\snt_kit_verdict( is_array( $v ) ? $v : array() ) . \snt_kit_kv( $rows ),
		__( 'Whether outbound requests are bound to the addresses the SSRF guard validated, so a DNS answer that changes between validation and connect cannot be followed.', 'signal-and-noise-tools' )
	);
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
	// #1599: the pinning posture stands alone at full width under the
	// Cloudflare reading, in every state of the monitor.
	return firewall_cloudflare_html() . firewall_pinning_html();
}

/**
 * The Cloudflare reading: the firewall row, the posture under it, or the
 * sentence that says why there is none yet.
 *
 * @return string
 */
function firewall_cloudflare_html() {
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
