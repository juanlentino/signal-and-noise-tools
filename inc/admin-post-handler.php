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
		'cf_save'                    => 'sn_handle_cf_save',
		'cf_purge_now'               => 'sn_handle_cf_purge_now',
		'health_scan'                => 'sn_handle_health_scan',
		'remote_toggle'              => 'sn_handle_remote_toggle',
		'webhook_add'                => 'sn_handle_webhook_add',
		'webhook_update'             => 'sn_handle_webhook_update',
		'webhook_delete'             => 'sn_handle_webhook_delete',
			// v8.10.0 Redirects arc (handler bodies in inc/redirects-admin.php).
			'redirect_add'               => 'sn_handle_redirect_add',
			'redirect_update'            => 'sn_handle_redirect_update',
			'redirect_delete'            => 'sn_handle_redirect_delete',
			'redirect_404_delete'        => 'sn_handle_redirect_404_delete',
			'redirect_404_clear'         => 'sn_handle_redirect_404_clear',
			'redirect_404_clear_probes'  => 'sn_handle_redirect_404_clear_probes',  // v10.47.0: dismiss scanner noise, keep real broken links
		'insights_run'               => 'sn_handle_insights_run',
		'insights_dismiss'           => 'sn_handle_insights_dismiss',
		'insights_snooze'            => 'sn_handle_insights_snooze',
		'insights_mark_done'         => 'sn_handle_insights_mark_done',
		'save_insights_settings'     => 'sn_handle_save_insights_settings',
		'audit_save_retention'       => 'sn_handle_audit_save_retention',
		'security_digest_save'       => 'sn_handle_security_digest_save',
		'now_save'                   => 'sn_handle_now_save',
		'uses_save'                  => 'sn_handle_uses_save',
		'resume_save'                => 'sn_handle_resume_save',
		'pattern_adoption_scan'      => 'sn_handle_pattern_adoption_scan',
		'block_migrations_scan'      => 'sn_handle_block_migrations_scan',
		'monitoring_save'            => 'sn_handle_monitoring_save',
		'perf_save'                  => 'sn_handle_perf_save',
		'save_theme'                 => 'sn_handle_save_theme',
		'ai_settings_save'           => 'sn_handle_ai_settings_save',        // v10.46.0: split out of save_theme with the AI tab
		'analytics_collector_save'   => 'sn_handle_analytics_collector_save', // v10.46.0: collector endpoint moved off Content → RSS
		'music_save'                 => 'sn_handle_music_save',
		'music_sync'                 => 'sn_handle_music_sync',
		'tag_merge'                  => 'sn_handle_tag_merge',
		'tag_ai_suggest'             => 'sn_handle_tag_ai_suggest',
		'tag_ai_apply'               => 'sn_handle_tag_ai_apply',
		'tag_prune_unused'           => 'sn_handle_tag_prune_unused',
		'indexnow_save'              => 'sn_handle_indexnow_save',
		'indexnow_regenerate'        => 'sn_handle_indexnow_regenerate',
		'indexnow_ping_now'          => 'sn_handle_indexnow_ping_now',
		'analytics_save'             => 'sn_handle_analytics_save',
		'analytics_exclude_save'     => 'sn_handle_analytics_exclude_save',
		'analytics_test'             => 'sn_handle_analytics_test',
		'analytics_tuning_save'      => 'sn_handle_analytics_tuning_save',
		'analytics_funnels_save'     => 'sn_handle_analytics_funnels_save',
		'analytics_export'           => 'sn_handle_analytics_export',
		// Scheduled-content ops (Task 8). Handler bodies live in
		// inc/schedule-admin.php to keep the subsystem cohesive.
		'schedule_run_now'           => 'sn_handle_schedule_run_now',
		'schedule_repurge'           => 'sn_handle_schedule_repurge',
		'schedule_swap_run_now'      => 'sn_handle_schedule_swap_run_now',
		// R9 (v9.51.0, lane SEC-C): MCP write-door credential binding
		// (handler body in inc/admin-post-actions.php).
		'bind_mcp_rw_credential'     => 'sn_handle_bind_mcp_rw_credential',
		// v9.85.0 (Session 3): Machine Readers sensor settings (worker URL +
		// write-only read token under the machine_readers subtree).
		'machine_readers_save'       => 'sn_handle_machine_readers_save',
	);
}

add_action( 'admin_init', 'sn_handle_admin_post' );

/**
 * Pages on which an SN admin POST is accepted: the canonical + legacy slugs
 * (sn_admin_pages) UNION the current registry top-tab slugs (sn_admin_top_tabs),
 * so a tab added in a later phase is allowed automatically — no second list to
 * forget (the v3.0.2 / slug-allowlist trap). admin refactor Phase 1.
 *
 * @return string[]
 */
function sn_admin_post_allowed_pages() {
	$legacy   = function_exists( 'sn_admin_pages' ) ? array_column( sn_admin_pages(), 'slug' ) : array();
	$registry = function_exists( 'sn_admin_top_tabs' ) ? array_column( sn_admin_top_tabs(), 'slug' ) : array();
	// v9.2.0: sn-analytics is a Dashboard submenu (index.php via add_dashboard_page),
	// not an SN top-tab, so it is in neither source list — but it carries POST forms
	// (the analytics CSV export). Its redirect goes back to index.php (below).
	$dashboard = array( 'sn-analytics' );
	return array_values( array_unique( array_merge( $legacy, $registry, $dashboard ) ) );
}

/**
 * Redirect URL for a POST that arrived on an SN Dashboard-submenu page (currently
 * only sn-analytics, registered under index.php). Returns null for the normal
 * admin.php pages so the caller falls through to the standard top-tab/sub
 * redirect. Pure (no header/exit) so it is unit-testable.
 *
 * @param string $current_page The ?page slug the POST arrived on.
 * @param string $flash        The flash code the handler produced.
 * @return string|null
 */
function sn_admin_post_dashboard_redirect_url( $current_page, $flash ) {
	if ( 'sn-analytics' !== $current_page ) {
		return null;
	}
	return add_query_arg(
		array(
			'page'     => 'sn-analytics',
			'sn_view'  => 'overview', // the default landing tab (v9.68.0; 'content' before).
			'sn_flash' => $flash,
		),
		admin_url( 'index.php' )
	);
}

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
	$our_slugs    = sn_admin_post_allowed_pages();
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
	//
	// nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable -- $action only SELECTS from the fixed sn_admin_post_handlers() registry behind the isset() above; the callable is never attacker-supplied, and the dispatcher has already enforced the nonce (and capability, per-handler where required).
	$flash = (string) call_user_func( $handlers[ $action ], $_POST );

	// v9.2.0: Dashboard-submenu pages (sn-analytics) redirect back to index.php
	// with the view preserved — the admin.php + top-tab scheme below does not
	// apply to them (the page is registered under index.php, not admin.php).
	$dashboard_url = sn_admin_post_dashboard_redirect_url( $current_page, $flash );
	if ( null !== $dashboard_url ) {
		header( 'Location: ' . $dashboard_url, true, 302 );
		exit;
	}

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
		$requested_sub = isset( $_REQUEST['sub'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) ) : '';

		// v6.18.0: GET 301 + POST PRG share one resolver. A moved leaf (or legacy
		// slug) is rewritten to its canonical home; a current top tab passes through
		// with its sub preserved; an unknown tab falls back to dashboard.
		$target               = sn_admin_post_redirect_target( $requested_tab, $requested_sub );
		$redirect_args['tab'] = $target['tab'];
		if ( ! empty( $target['sub'] ) ) {
			$redirect_args['sub'] = $target['sub'];
		}
		if ( ! empty( $target['anchor'] ) ) {
			$anchor = '#sn-sec-' . rawurlencode( $target['anchor'] );
		}
	}

	$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . $anchor;

	// Raw header() because wp_safe_redirect() strips URL fragments. Destination
	// is admin_url() (same-host, trusted) with a sanitized top-tab name from a
	// fixed allowlist — safe. 302 (transient post-save redirect), not 301.
	header( 'Location: ' . $redirect_url, true, 302 );
	exit;
}
