<?php
/**
 * S&N Dashboard — Monitoring → RSS, painted from the kit.
 *
 * The classic leaf (`sn_rss_tracker_render_admin_tab()`, in inc/rss-feed-tracker-render-parts.php,
 * hooked to `sn_admin_rss_tab` and wrapped by `sn_admin_render_rss_section()`,
 * inc/admin-render-sections.php) posts its three writes to admin-post.php as
 * `action=sn_rss_<op>` with a nonce minted for that action alone (2026-11-10;
 * before, one `sn_rss_action` field and one shared nonce on admin_init). Its
 * flash is still its own `?sn_rss_ok`, read directly in that function, not
 * the shared `?sn_flash=`. The `action` field routes a window's submission
 * through the host's admin-post replay (inc/openstation-host-pipelines.php),
 * which runs the same `admin_post_sn_rss_<op>` hook. `rss_form()` stays this
 * leaf's own wrapper only for the confirm title and label.
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
require_once __DIR__ . '/monitoring-rss-setup.php'; // the status line and the Setup card

/**
 * This leaf's own form: the `action=sn_rss_<op>` hook and a nonce minted for
 * it alone, the fields snt_kit_form() prints, plus the confirm title and
 * label snt_kit_form() has no option for. No pipeline is declared: an
 * `action` field routes to the host's admin-post replay.
 *
 * @param string              $sn_rss_action The operation (an SN_RSS_TRACKER_ACTION_* value).
 * @param string              $inner         Painted fields.
 * @param array<string,mixed> $opts          submit, confirm, confirm_title, confirm_label, danger, inline (the compact one-button form, `.snt-form--inline`).
 * @return string
 */
function rss_form( $sn_rss_action, $inner, array $opts = array() ) {
	$key    = 'rss_' . (string) $sn_rss_action;
	$hidden = \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'action', 'value' => \snt_kit_hook_action( $key ) ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => \snt_kit_nonce( $key ) ) );
	return \snt_kit_tag(
		'os-form',
		array(
			'class'             => empty( $opts['inline'] ) ? 'snt-form' : 'snt-form snt-form--inline',
			'os-action'         => 'post',
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

	// 2026-10-16 leaf anatomy (docs/kit-leaf-anatomy.md): one row. Activity
	// leads with the status line, the Setup card (Settings and Maintenance,
	// each a fold whose closed line is the setting) under it, beside the
	// Recent requests ledger they are read against (#1573: the ledger
	// measured 507px live, the settings form beside it the same).
	$out  = rss_flash_html( $flash );
	$out .= rss_pair(
		\snt_kit_stack(
			\snt_kit_section( __( 'Activity', 'signal-and-noise-tools' ), rss_status_html( $state['settings'] ) . rss_activity_html( $state['stats'] ) )
			. rss_setup_html( $state['settings'], $tab )
		),
		\snt_kit_section( __( 'Recent requests', 'signal-and-noise-tools' ), rss_recent_table_html( $state['recent'] ) )
	);
	return $out;
}

/**
 * Two boxes on one os-grid row; a side that paints nothing leaves the
 * other at full width (the tags_pair() shape).
 *
 * @param string $left  Painted box or ''.
 * @param string $right Painted box or ''.
 * @return string
 */
function rss_pair( $left, $right ) {
	return \snt_kit_grid( array( $left, $right ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/rss'] = __NAMESPACE__ . '\\paint_monitoring_rss';
		return $painters;
	}
);
