<?php
/**
 * S&N Dashboard — Monitoring → RSS, painted from the kit.
 *
 * The classic leaf (`sn_rss_tracker_render_admin_tab()`, inc/rss-feed-tracker.php:608,
 * hooked to `sn_admin_rss_tab` and wrapped by `sn_admin_render_rss_section()`,
 * inc/admin-render-sections.php:131) is the ONE leaf on this tab that bypasses
 * the shared `sn_action` + `sn_theme_options_nonce` pipeline entirely: its form
 * field is `sn_rss_action`, its nonce action is `SN_RSS_TRACKER_NONCE`
 * (`sn_rss_tracker_action`), and its flash query arg is `sn_rss_ok` — read
 * directly at inc/rss-feed-tracker.php:615, bypassing the shared `?sn_flash=`
 * pipeline. `snt_kit_form()` bakes in the shared field name and nonce, so this
 * leaf paints its own `rss_form()` wrapper carrying the RIGHT field name and
 * nonce action instead; the host's `rss` write pipeline
 * (`snt_os_host_replay_rss()`, inc/openstation-host-pipelines.php) is what
 * already expects `sn_rss_action` on the wire, one for one.
 *
 * Same three actions (save_settings, reset_defaults, purge_log), same six
 * field names, same activity counters, same recent-request table, same flash
 * states; the kit's parts instead of wp-admin's two-column layout.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-rss-parts.php';

/**
 * This leaf's own form: hidden `sn_rss_action` + the RSS module's own nonce —
 * NOT `snt_kit_form()`'s shared `sn_action` + shared nonce, which
 * `sn_rss_tracker_handle_form()` would never recognise.
 *
 * @param string              $sn_rss_action The handler's action name.
 * @param string              $inner         Painted fields.
 * @param array<string,mixed> $opts          submit, confirm, danger.
 * @return string
 */
function rss_form( $sn_rss_action, $inner, array $opts = array() ) {
	$nonce_action = defined( 'SN_RSS_TRACKER_NONCE' ) ? SN_RSS_TRACKER_NONCE : 'sn_rss_tracker_action';
	$hidden       = \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_rss_action', 'value' => (string) $sn_rss_action ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( $nonce_action ) : '' ) );
	return \snt_kit_tag(
		'os-form',
		array(
			'class'             => 'snt-form',
			'os-action'         => 'post',
			'os-arg-pipeline'   => 'rss',
			'submit-label'      => (string) ( $opts['submit'] ?? __( 'Save', 'signal-and-noise-tools' ) ),
			'show-reset'        => 'false',
			'columns'           => '1',
			'os-confirm'        => isset( $opts['confirm'] ) ? (string) $opts['confirm'] : null,
			'os-confirm-title'  => isset( $opts['confirm_title'] ) ? (string) $opts['confirm_title'] : null,
			'os-confirm-label'  => isset( $opts['confirm_label'] ) ? (string) $opts['confirm_label'] : null,
			'os-confirm-danger' => ! empty( $opts['danger'] ),
		),
		$inner . $hidden
	);
}

/**
 * The state, read the way the classic leaf reads it.
 *
 * @return array{settings:array,stats:array,recent:array}
 */
function rss_state() {
	return array(
		'settings' => function_exists( 'sn_rss_tracker_settings' ) ? sn_rss_tracker_settings() : array(),
		'stats'    => function_exists( 'sn_rss_tracker_window_stats_multi' ) ? sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) ) : array(
			'most_recent' => null,
			'windows'     => array(),
		),
		'recent'   => function_exists( 'sn_rss_tracker_recent' ) ? sn_rss_tracker_recent( 20 ) : array(),
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_rss( array $ctx ) {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	if ( ! function_exists( 'sn_rss_tracker_settings' ) ) {
		return \snt_kit_notice( 'warn', '<strong>' . \snt_kit_esc( __( 'RSS feed-request tracker not loaded.', 'signal-and-noise-tools' ) ) . '</strong>' );
	}
	$tab   = (string) ( $ctx['tab'] ?? 'monitoring' );
	$state = rss_state();

	$params = null !== $ctx['state'] ? $ctx['state']->get( 'params' ) : null;
	$params = is_array( $params ) ? $params : array();
	$flash  = isset( $params['sn_rss_ok'] ) ? (string) $params['sn_rss_ok'] : '';

	$out  = rss_flash_html( $flash );
	$out .= \snt_kit_section( __( 'Activity', 'signal-and-noise-tools' ), rss_activity_html( $state['stats'] ) );
	$out .= \snt_kit_section( __( 'Recent requests', 'signal-and-noise-tools' ), rss_recent_table_html( $state['recent'] ) );
	$out .= \snt_kit_section( __( 'Settings', 'signal-and-noise-tools' ), rss_settings_form_html( $state['settings'], $tab ) . rss_reset_form_html() );
	$out .= rss_maintenance_html( $state['settings'] );
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/rss'] = __NAMESPACE__ . '\\paint_monitoring_rss';
		return $painters;
	}
);
