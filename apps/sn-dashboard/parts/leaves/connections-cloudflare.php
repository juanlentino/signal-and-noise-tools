<?php
/**
 * S&N Dashboard — Connections → Cloudflare, painted from the kit.
 *
 * The classic leaf (inc/cloudflare-purge.php, the `sn_admin_cloudflare_tab`
 * closure behind `sn_admin_render_cloudflare_section()`) paints, in the
 * two-column shell: the intro, the Credentials form (`sn_action=cf_save`,
 * fields `sn_cf_token` / `sn_cf_zone`, each locked to a disabled input by its
 * wp-config constant), the folded Post-purge probes table, and in the rail the
 * cache status box, the Purge Everything Now card (`sn_action=cf_purge_now`,
 * disabled until configured) and the Cloudways purge status. Same readings
 * (the token, the zone, SN_CF_LAST_PURGE_OPT, SN_CF_PROBE_LOG_OPT,
 * SNT_CW_LAST_PURGE_OPT), same forms, same handlers; the kit's parts instead
 * of wp-admin's, stacked in the shell's DOM order (main, then rail).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/connections-cloudflare-parts.php';

/**
 * The leaf's readings, taken the way the classic closure takes them.
 *
 * @return array<string,mixed>
 */
function cloudflare_data() {
	$token      = function_exists( 'sn_cf_get_token' ) ? (string) sn_cf_get_token() : '';
	$zone       = function_exists( 'sn_cf_get_zone' ) ? (string) sn_cf_get_zone() : '';
	$last_purge = defined( 'SN_CF_LAST_PURGE_OPT' ) ? get_option( SN_CF_LAST_PURGE_OPT, array() ) : array();
	$probe_log  = defined( 'SN_CF_PROBE_LOG_OPT' ) ? get_option( SN_CF_PROBE_LOG_OPT, array() ) : array();
	$cloudways  = null;
	if ( function_exists( 'sn_cloudways_is_configured' ) && sn_cloudways_is_configured() ) {
		$cloudways = defined( 'SNT_CW_LAST_PURGE_OPT' ) ? get_option( SNT_CW_LAST_PURGE_OPT, array() ) : array();
		$cloudways = is_array( $cloudways ) ? $cloudways : array();
	}
	// The mask never degrades to the raw token: without the masker, four dots.
	$obscured = function_exists( 'sn_mask_secret' ) ? (string) sn_mask_secret( $token ) : ( '' === $token ? '' : '••••' );
	return array(
		'token_obscured'  => $obscured,
		'zone'            => $zone,
		'token_const_set' => defined( 'SN_CLOUDFLARE_API_TOKEN' ),
		'zone_const_set'  => defined( 'SN_CLOUDFLARE_ZONE_ID' ),
		'last_purge'      => is_array( $last_purge ) ? $last_purge : array(),
		'is_configured'   => function_exists( 'sn_cf_is_configured' ) && sn_cf_is_configured(),
		'probe_log'       => is_array( $probe_log ) ? $probe_log : array(),
		'cloudways'       => $cloudways,
	);
}

/**
 * A field the wp-config constant locks: the classic disabled input carries no
 * `name`, so this is `<os-field-row label hint>` around a nameless, disabled
 * `<os-text-field>` (kit-help "Field row" / "Text field").
 *
 * @param string $label Label.
 * @param string $value Shown value.
 * @param string $hint  The lock explanation.
 * @return string
 */
function cloudflare_locked_field( $label, $value, $hint ) {
	return \snt_kit_tag(
		'os-field-row',
		array( 'label' => (string) $label, 'hint' => (string) $hint ),
		\snt_kit_tag( 'os-text-field', array( 'type' => 'text', 'value' => (string) $value, 'disabled' => true ) )
	);
}

/**
 * The Credentials section: the two fields and, unless both are locked, the
 * `cf_save` form around them.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_credentials_html( array $d ) {
	$fields = $d['token_const_set']
		? cloudflare_locked_field( __( 'API token', 'signal-and-noise-tools' ), '' !== $d['token_obscured'] ? $d['token_obscured'] : '••••', __( 'Locked. Set via SN_CLOUDFLARE_API_TOKEN in wp-config.php.', 'signal-and-noise-tools' ) )
		: \snt_kit_field( 'text', 'sn_cf_token', __( 'API token', 'signal-and-noise-tools' ), $d['token_obscured'], array(
			'placeholder' => __( 'Paste a fresh token to update; type ‘clear’ to remove', 'signal-and-noise-tools' ),
			'hint'        => __( 'Cloudflare API token with Cache Purge permission scoped to your zone. Leave the obscured value alone to keep the existing token.', 'signal-and-noise-tools' ),
		) );
	$fields .= $d['zone_const_set']
		? cloudflare_locked_field( __( 'Zone ID', 'signal-and-noise-tools' ), $d['zone'], __( 'Locked. Set via SN_CLOUDFLARE_ZONE_ID in wp-config.php.', 'signal-and-noise-tools' ) )
		: \snt_kit_field( 'text', 'sn_cf_zone', __( 'Zone ID', 'signal-and-noise-tools' ), $d['zone'], array(
			'placeholder' => __( 'Paste zone ID; type ‘clear’ to remove', 'signal-and-noise-tools' ),
			'hint'        => __( '32-char zone ID from Cloudflare dashboard → site overview → API.', 'signal-and-noise-tools' ),
		) );
	$both_locked = $d['token_const_set'] && $d['zone_const_set'];
	$inner       = $both_locked
		? $fields . '<p class="snt-hint">' . \snt_kit_esc( __( 'Both credentials are set in wp-config.php; there is nothing to save here.', 'signal-and-noise-tools' ) ) . '</p>'
		: \snt_kit_form( 'cf_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section(
		__( 'Credentials', 'signal-and-noise-tools' ),
		$inner,
		__( 'API token + zone ID from your Cloudflare dashboard. Both required.', 'signal-and-noise-tools' )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_cloudflare( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$d   = cloudflare_data();
	$out = '<p class="snt-prose">'
		. \snt_kit_esc( __( "Auto-purges Cloudflare's edge cache when content changes. See", 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'docs/CACHING.md', false ) . ' '
		. \snt_kit_esc( __( "for the dashboard-side Cache Rule that turns on HTML caching to begin with: without that, this module purges nothing useful (origin pages aren't cached at the edge).", 'signal-and-noise-tools' ) )
		. '</p>';
	$out .= cloudflare_credentials_html( $d );
	$out .= cloudflare_probes_html( $d );
	$out .= \snt_kit_section(
		__( 'Cache status', 'signal-and-noise-tools' ),
		cloudflare_status_html( $d ) . cloudflare_purge_html( $d ) . cloudflare_cloudways_html( $d['cloudways'] ),
		'',
		array( 'stack' => true )
	);
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/cloudflare'] = __NAMESPACE__ . '\\paint_connections_cloudflare';
		return $painters;
	}
);
