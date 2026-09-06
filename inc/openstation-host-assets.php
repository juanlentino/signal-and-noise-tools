<?php
/**
 * Signal & Noise Tools — the HOST's asset seam: the admin styles and scripts a
 * host window must carry, and the filter that puts them on it.
 *
 * WHY A THIRD FILE. inc/openstation-host.php is the paint,
 * inc/openstation-host-pipelines.php is the write; this is neither. It answers
 * one question — "which handles does a window need, and are they REGISTERED" —
 * and its failure mode is unlike either of theirs: nothing throws, nothing
 * refuses, a leaf simply paints unstyled or stops live-refreshing and no
 * readout anywhere says so. Two of those shipped (Machine Readers' sheet, the
 * Heartbeat client) precisely because the list lived next to code about
 * something else.
 *
 * Spec: docs/proposals/2026-09-06-openstation-hosts.md.
 *
 * @package SignalNoiseTools
 * @since 13.104.0
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The handles a host window needs, registered the way their own pages
 * register them.
 *
 * WHY A LIST AND NOT A HOOK. Every one of these is enqueued today by a guard
 * on `sn_admin_page_hooks()` — the classic hook suffixes — and the desktop
 * page is not one of them, so on the desktop NONE of them load. The window
 * cannot borrow that guard either: two of the eight are gated a second time
 * on the active tab/sub, which a window state answers and a hook suffix does
 * not. So the handles are named, and each is registered from the SAME source
 * and the SAME data builder its own page uses — never a copied URL, never a
 * copied localize literal.
 *
 * PER WINDOW, because the two hosts are two pages. `sn-analytics` carries what
 * `toplevel_page_sn-analytics` carries and nothing else: admin.css, the
 * analytics token layer and sheet, the trend brush, the confirm modal, the
 * repeatable rows, the uptime panel. The five leaf-owned handles the Dashboard
 * needs (cron, provenance, the audit sheet, Machine Readers, the Heartbeat
 * client) belong to leaves that page does not have, and a window that carried
 * them would be enqueueing scripts for markup it never paints.
 *
 * Registration, not enqueue: the shell enqueues a window's `scripts` and
 * `styles` when the window first opens, so a desktop page nobody opens this
 * window on pays nothing.
 *
 * @param string $id App id (`sn-dashboard` or `sn-analytics`).
 * @return array{styles:string[],scripts:string[]}
 */
function snt_os_host_asset_handles( $id = 'sn-dashboard' ) {
	if ( 'sn-analytics' === (string) $id ) {
		return array(
			'styles'  => array( 'sn-admin', 'snt-analytics-tokens', 'sn-analytics-admin', 'sn-uptime-status' ),
			'scripts' => array( 'sn-admin', 'snt-confirm', 'sn-analytics-brush', 'sn-resume-admin', 'sn-uptime-status', 'snt-os-host' ),
		);
	}
	return array(
		'styles'  => array( 'sn-admin', 'snt-analytics-tokens', 'sn-analytics-admin', 'sn-uptime-status', 'sn-provenance-admin', 'snt-audit-log', 'sn-machine-readers' ),
		'scripts' => array( 'sn-admin', 'snt-confirm', 'sn-analytics-brush', 'sn-resume-admin', 'sn-freshness-dot', 'snt-health-suggest-actions', 'sn-uptime-status', 'sn-cron-dashboard', 'sn-provenance-admin', 'sn-admin-heartbeat', 'snt-os-host' ),
	);
}

/**
 * Register every handle EITHER host window can carry, that is not registered
 * yet.
 *
 * The union, not the per-window list: registration mints no request (the shell
 * enqueues only what the window declares), and one registrar per handle is the
 * point — a second, window-scoped registration path would be a second place
 * for a dependency or a localize payload to drift.
 *
 * Idempotent by `wp_style_is` / `wp_script_is`: on a classic admin page these
 * are already registered by their own enqueues and this adds nothing.
 *
 * @return void
 */
function snt_os_host_register_assets() {
	if ( ! function_exists( 'wp_register_style' ) || ! defined( 'SNT_URL' ) || ! defined( 'SNT_VERSION' ) ) {
		return;
	}
	$plugin_file = defined( 'SNT_PATH' ) ? SNT_PATH . 'signal-and-noise-tools.php' : __FILE__;

	if ( ! wp_style_is( 'sn-admin', 'registered' ) ) {
		wp_register_style( 'sn-admin', SNT_URL . 'assets/admin.css', array(), SNT_VERSION );
	}
	if ( ! wp_style_is( 'snt-analytics-tokens', 'registered' ) ) {
		wp_register_style( 'snt-analytics-tokens', SNT_URL . 'assets/analytics/analytics-tokens.css', array(), SNT_VERSION );
	}
	if ( ! wp_style_is( 'sn-analytics-admin', 'registered' ) ) {
		wp_register_style( 'sn-analytics-admin', SNT_URL . 'assets/analytics/analytics-admin.css', array( 'sn-admin', 'snt-analytics-tokens' ), SNT_VERSION );
	}
	if ( ! wp_style_is( 'sn-uptime-status', 'registered' ) ) {
		wp_register_style( 'sn-uptime-status', SNT_URL . 'assets/uptime-status.css', array(), SNT_VERSION );
	}

	// The two shared utilities below are registered by their OWN registrars on
	// `admin_enqueue_scripts`; the window-args filter runs at `init`, earlier.
	// Calling the registrar (never re-declaring the handle) keeps one source.
	if ( function_exists( 'snt_register_status_script' ) && ! wp_script_is( 'snt-status', 'registered' ) ) {
		snt_register_status_script();
	}
	if ( function_exists( 'snt_ability_run_client_register' ) ) {
		snt_ability_run_client_register();
	}

	if ( ! wp_script_is( 'sn-admin', 'registered' ) ) {
		wp_register_script( 'sn-admin', SNT_URL . 'assets/admin.js', array(), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'snt-confirm', 'registered' ) ) {
		wp_register_script( 'snt-confirm', SNT_URL . 'assets/snt-confirm.js', array( 'wp-i18n' ), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'sn-analytics-brush', 'registered' ) ) {
		wp_register_script( 'sn-analytics-brush', SNT_URL . 'assets/analytics/analytics-brush.js', array(), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'sn-resume-admin', 'registered' ) ) {
		wp_register_script( 'sn-resume-admin', SNT_URL . 'assets/resume-admin.js', array(), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'sn-freshness-dot', 'registered' ) ) {
		wp_register_script( 'sn-freshness-dot', plugins_url( 'assets/freshness-dot.js', $plugin_file ), array(), SNT_VERSION, true );
		// The SAME payload snt_freshness_enqueue() attaches, from the SAME
		// builder: a copied route list would go stale the first time the
		// front-end routes moved.
		if ( function_exists( 'snt_freshness_routes' ) && defined( 'SNT_FRESHNESS_CARD_ID' ) ) {
			wp_localize_script(
				'sn-freshness-dot',
				'sntFreshness',
				array(
					'routes' => array_map( static function ( $path ) {
						return home_url( $path );
					}, snt_freshness_routes() ),
					'cardId' => SNT_FRESHNESS_CARD_ID,
				)
			);
		}
	}
	if ( ! wp_script_is( 'snt-health-suggest-actions', 'registered' ) ) {
		wp_register_script( 'snt-health-suggest-actions', plugins_url( 'assets/health-suggest-actions.js', $plugin_file ), array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ), SNT_VERSION, true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'snt-health-suggest-actions', 'signal-and-noise-tools' );
		}
	}
	if ( ! wp_script_is( 'sn-uptime-status', 'registered' ) ) {
		wp_register_script( 'sn-uptime-status', SNT_URL . 'assets/uptime-status.js', array( 'snt-ability-run' ), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'snt-os-host', 'registered' ) ) {
		wp_register_script( 'snt-os-host', SNT_URL . 'assets/os-host.js', array( 'sn-admin' ), SNT_VERSION, true );
	}
	// Five leaves register their own assets from their own enqueue callbacks
	// (Connections -> Cron, Integrity -> Provenance, Security -> Audit log,
	// Measurement -> Machine Readers, and the Heartbeat client Cron + Webhooks
	// live-refresh through), gated on the classic hook suffixes the desktop page
	// never carries. Each exposes its registrar; calling it keeps one source of
	// strings, paths and DEPENDENCIES -- sn-admin-heartbeat without its `jquery`
	// and `heartbeat` deps is a handle WordPress silently drops.
	foreach ( array( 'snt_cron_dashboard_register_script', 'sn_prov_admin_register_assets', 'snt_audit_log_register_style', 'snt_mr_admin_register_style', 'snt_admin_heartbeat_register_script' ) as $registrar ) {
		if ( function_exists( $registrar ) ) {
			$registrar();
		}
	}
}

/**
 * Ride the host windows with the admin assets their leaves expect.
 *
 * A sibling of `snt_os_app_window_args()` on the same filter rather than a
 * branch inside it: the Signal & Noise app's window has nothing to do with
 * this one, and one function answering for two windows is how a change to
 * either becomes a change to both.
 *
 * @param array<string,mixed> $window_args `openstation_register_window()` args.
 * @param string              $id          App id.
 * @return array<string,mixed>
 */
function snt_os_host_window_args( $window_args, $id ) {
	if ( ! is_array( $window_args ) || ! in_array( (string) $id, array( 'sn-dashboard', 'sn-analytics' ), true ) ) {
		return $window_args;
	}
	snt_os_host_register_assets();
	$handles = snt_os_host_asset_handles( (string) $id );
	foreach ( array( 'styles', 'scripts' ) as $bucket ) {
		$existing = isset( $window_args[ $bucket ] ) ? (array) $window_args[ $bucket ] : array();
		foreach ( $handles[ $bucket ] as $handle ) {
			if ( ! in_array( $handle, $existing, true ) ) {
				$existing[] = $handle;
			}
		}
		$window_args[ $bucket ] = $existing;
	}
	return $window_args;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'openstation_app_window_args', 'snt_os_host_window_args', 10, 2 );
}
