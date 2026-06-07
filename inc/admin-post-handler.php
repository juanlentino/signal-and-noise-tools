<?php
/**
 * Signal & Noise — admin form-submission dispatcher.
 *
 * Handles all SN admin POST submissions on admin_init (before any output, so
 * wp_safe_redirect/header work cleanly — Post/Redirect/Get). Validates the
 * shared nonce + capability + page allowlist, dispatches to the matching
 * sn_handle_<action>() in inc/admin-post-actions.php via sn_admin_post_handlers(),
 * then redirects to the canonical top-tab + sub-tab + anchor carrying the
 * resulting ?sn_flash=… code. Extracted from inc/admin-page.php in v4.5.4.
 *
 * Save status survives the redirect via ?sn_flash, which sn_theme_options_page()
 * resolves to an admin notice through inc/admin-flash-messages.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action → handler-callback map. Single source of truth for which form actions
 * the dispatcher accepts; each callback lives in inc/admin-post-actions.php and
 * returns a ?sn_flash=… code.
 *
 * @return array<string,string>
 */
function sn_admin_post_handlers() {
	return array(
		'clear_overrides'            => 'sn_handle_clear_overrides',
		'purge_caches'               => 'sn_handle_purge_caches',
		'full_reset'                 => 'sn_handle_full_reset',
		'save_identity'              => 'sn_handle_save_identity',
		'save_login'                 => 'sn_handle_save_login',
		'pl_save'                    => 'sn_handle_pl_save',
		'pl_test'                    => 'sn_handle_pl_test',
		'cf_save'                    => 'sn_handle_cf_save',
		'cf_purge_now'               => 'sn_handle_cf_purge_now',
		'apply_reading_time_cleanup' => 'sn_handle_apply_reading_time_cleanup',
		'health_scan'                => 'sn_handle_health_scan',
		'webhook_add'                => 'sn_handle_webhook_add',
		'webhook_update'             => 'sn_handle_webhook_update',
		'webhook_delete'             => 'sn_handle_webhook_delete',
		'insights_run'               => 'sn_handle_insights_run',
		'insights_dismiss'           => 'sn_handle_insights_dismiss',
		'insights_snooze'            => 'sn_handle_insights_snooze',
		'insights_mark_done'         => 'sn_handle_insights_mark_done',
		'save_insights_settings'     => 'sn_handle_save_insights_settings',
		'audit_save_retention'       => 'sn_handle_audit_save_retention',
		'pattern_adoption_scan'      => 'sn_handle_pattern_adoption_scan',
		'block_migrations_scan'      => 'sn_handle_block_migrations_scan',
		'monitoring_save'            => 'sn_handle_monitoring_save',
		'perf_save'                  => 'sn_handle_perf_save',
	);
}

add_action( 'admin_init', 'sn_handle_admin_post' );

function sn_handle_admin_post() {
	if ( ! isset( $_POST['sn_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only process for our admin pages — guards against the handler firing for
	// an unrelated $_POST that happens to carry sn_action.
	$current_page = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
	$our_slugs    = array_column( sn_admin_pages(), 'slug' );
	if ( ! in_array( $current_page, $our_slugs, true ) ) {
		return;
	}

	check_admin_referer( 'sn_theme_options_nonce' );

	$action   = sanitize_text_field( wp_unslash( $_POST['sn_action'] ) );
	$handlers = sn_admin_post_handlers();
	if ( ! isset( $handlers[ $action ] ) ) {
		return; // unknown action — same as the old trailing `else { return; }`
	}
	// Handlers receive the RAW $_POST and unslash per-field exactly as their
	// original arms did (see inc/admin-post-actions.php docblock).
	$flash = (string) call_user_func( $handlers[ $action ], $_POST );

	$redirect_args = array(
		'page'     => $current_page,
		'sn_flash' => $flash,
	);

	// v3.8.0+: redirect to canonical top-tab + anchor (instead of legacy tab
	// slug). v3.8.1+: also preserves &sub= so flash notices land on the right
	// sub-tab. The legacy tab slug from the form POST is mapped via
	// sn_admin_legacy_redirect_map().
	$anchor = '';
	if ( isset( $_REQUEST['tab'] ) ) {
		$requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
		$map           = sn_admin_legacy_redirect_map();
		$top_tabs      = array_column( sn_admin_top_tabs(), 'tab' );

		if ( in_array( $requested_tab, $top_tabs, true ) ) {
			// Already a canonical top tab; pass through.
			$redirect_args['tab'] = $requested_tab;
			// v3.8.1+: preserve &sub= from the request (set by sub-tab forms).
			if ( isset( $_REQUEST['sub'] ) ) {
				$redirect_args['sub'] = sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) );
			}
		} elseif ( isset( $map[ $requested_tab ] ) ) {
			// Legacy slug; rewrite to canonical destination.
			$redirect_args['tab'] = $map[ $requested_tab ]['tab'];
			if ( ! empty( $map[ $requested_tab ]['sub'] ) ) {
				$redirect_args['sub'] = $map[ $requested_tab ]['sub'];
			}
			if ( ! empty( $map[ $requested_tab ]['anchor'] ) ) {
				$anchor = '#sn-sec-' . rawurlencode( $map[ $requested_tab ]['anchor'] );
			}
		} else {
			// Unknown slug; fall back to dashboard.
			$redirect_args['tab'] = 'dashboard';
		}
	}

	$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . $anchor;

	// Raw header() because wp_safe_redirect() strips URL fragments. Destination
	// is admin_url() (same-host, trusted) with a sanitized top-tab name from a
	// fixed allowlist — safe. 302 (transient post-save redirect), not 301.
	header( 'Location: ' . $redirect_url, true, 302 );
	exit;
}
