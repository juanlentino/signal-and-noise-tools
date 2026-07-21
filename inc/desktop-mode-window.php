<?php
/**
 * Signal & Noise Tools — Desktop Mode native monitoring window.
 *
 * The first TIER-1 surface for S&N inside Desktop Mode: a native "S&N
 * Monitor" window (rendered in the parent DOM, not the iframe compat
 * fallback) with four read-mostly panes — Analytics (main tab), Health,
 * Uptime, Deploy. Built against the UPSTREAM WordPress/desktop-mode
 * contract (trunk @ 0.9.5): desktop_mode_register_window() +
 * desktop_mode_register_window_tab() from includes/registries/, per
 * docs/use-from-a-plugin.md + docs/examples/native-windows.md. The shell
 * auto-wraps the window body in <wpd-tabs>/<wpd-tabpanel> once a tab is
 * registered — templates here only declare pane mount points, and
 * assets/desktop-window.js lights them up on open.
 *
 * Data reuses the widget plumbing wholesale — no new queries:
 *   - Analytics: GET signal-noise/v1/desktop/site-views (fetch-on-open,
 *     same endpoint + cache the SN Site Views widget rides).
 *   - Health:    window.snDesktopData.healthSummary (owner-gated localize).
 *   - Uptime:    the uptime-status ability via sntAbilityRun (fetch-on-open;
 *     Better Stack tiers cache server-side).
 *   - Deploy:    window.snDesktopData.theme / .plugin.
 *
 * Registration is a pure no-op when Desktop Mode is absent, and every
 * pane keeps the widgets' honesty rule: null data renders an honest
 * empty state, never a fabricated zero.
 *
 * @since 9.76.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Window + dock/tile id. One constant-ish accessor so tests, the JS mount
 * contract (window.desktopModeNativeWindows['sn-monitor']) and the icon
 * link can never drift apart.
 *
 * @since 9.76.0
 * @return string
 */
function snt_desktop_window_id() {
	return 'sn-monitor';
}

/**
 * Args for desktop_mode_register_window() — pure, testable.
 *
 * capabilities: manage_options mirrors the healthSummary localize gate and
 * the site-views REST permission_callback — the window shows owner data
 * only, so it registers for owners only (DM fails closed on a cap miss).
 *
 * @since 9.76.0
 * @return array
 */
function snt_desktop_window_args() {
	return array(
		'title'          => __( 'S&N Monitor', 'signal-and-noise-tools' ),
		'icon'           => function_exists( 'plugins_url' ) && defined( 'SNT_PATH' )
			? plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' )
			: 'dashicons-chart-area',
		'template'       => 'snt_desktop_window_template_analytics',
		'script'         => 'sn-desktop-window',
		'style'          => 'sn-desktop-window',
		'width'          => 640,
		'height'         => 480,
		'min_width'      => 320,
		'min_height'     => 280,
		'placement'      => 'dock',
		'capabilities'   => array( 'manage_options' ),
		'main_tab_label' => 'Analytics',
		// wp.desktop.getWindowConfig('sn-monitor') — delivered on BOTH the
		// eager and the lazy (mid-session activation) load paths, which
		// wp_localize_script is not. apiFetch resolves root + nonce itself,
		// so only the namespace rides here.
		'config'         => array( 'restNamespace' => 'signal-noise/v1' ),
	);
}

/**
 * The three secondary tabs. 'main' is reserved upstream for the window's
 * own template (Analytics). Pure, testable.
 *
 * @since 9.76.0
 * @return array[]
 */
function snt_desktop_window_tabs() {
	return array(
		array(
			'value'    => 'health',
			'label'    => __( 'Health', 'signal-and-noise-tools' ),
			'position' => 10,
			'template' => 'snt_desktop_window_template_health',
		),
		array(
			'value'    => 'uptime',
			'label'    => __( 'Uptime', 'signal-and-noise-tools' ),
			'position' => 20,
			'template' => 'snt_desktop_window_template_uptime',
		),
		array(
			'value'    => 'deploy',
			'label'    => __( 'Deploy', 'signal-and-noise-tools' ),
			'position' => 30,
			'template' => 'snt_desktop_window_template_deploy',
		),
	);
}

/**
 * Pane templates. The shell clones these into the window body BEFORE the
 * render callback runs, so the JS can rely on every mount point existing.
 * Static scaffolding only — data arrives client-side.
 *
 * @since 9.76.0
 */
function snt_desktop_window_template_analytics() {
	?>
	<div class="sn-monitor-pane sn-monitor-analytics">
		<div class="sn-monitor-stat-row">
			<div class="sn-monitor-stat"><span class="sn-monitor-stat-n" data-role="views-total">—</span><span class="sn-monitor-stat-l"><?php esc_html_e( 'views · 14d', 'signal-and-noise-tools' ); ?></span></div>
			<div class="sn-monitor-stat"><span class="sn-monitor-stat-n" data-role="views-visits">—</span><span class="sn-monitor-stat-l"><?php esc_html_e( 'visits · 14d', 'signal-and-noise-tools' ); ?></span></div>
			<div class="sn-monitor-stat"><span class="sn-monitor-stat-n" data-role="views-delta">—</span><span class="sn-monitor-stat-l"><?php esc_html_e( 'vs prior 14d', 'signal-and-noise-tools' ); ?></span></div>
		</div>
		<div class="sn-monitor-series" data-role="views-series" aria-label="<?php esc_attr_e( 'Daily views, last 14 days', 'signal-and-noise-tools' ); ?>"></div>
		<p class="sn-monitor-note" data-role="views-note"><?php esc_html_e( 'Loading first-party analytics…', 'signal-and-noise-tools' ); ?></p>
	</div>
	<?php
}

function snt_desktop_window_template_health() {
	?>
	<div class="sn-monitor-pane sn-monitor-health">
		<p class="sn-monitor-headline" data-role="health-headline">—</p>
		<ul class="sn-monitor-list" data-role="health-list"></ul>
		<p class="sn-monitor-note" data-role="health-note"></p>
	</div>
	<?php
}

function snt_desktop_window_template_uptime() {
	?>
	<div class="sn-monitor-pane sn-monitor-uptime">
		<div class="sn-monitor-rows" data-role="uptime-rows"></div>
		<p class="sn-monitor-note" data-role="uptime-note"><?php esc_html_e( 'Checking monitors…', 'signal-and-noise-tools' ); ?></p>
	</div>
	<?php
}

function snt_desktop_window_template_deploy() {
	?>
	<div class="sn-monitor-pane sn-monitor-deploy">
		<div class="sn-monitor-rows" data-role="deploy-cards"></div>
		<p class="sn-monitor-note" data-role="deploy-note"></p>
	</div>
	<?php
}

/**
 * Register the window, its tabs, and the desktop tile. No-op without
 * Desktop Mode; idempotent (init can re-enter under test harnesses).
 *
 * @since 9.76.0
 * @return bool Whether registration ran.
 */
function snt_desktop_window_register() {
	static $done = false;
	if ( ! function_exists( 'desktop_mode_register_window' ) || ! function_exists( 'desktop_mode_register_window_tab' ) ) {
		return false;
	}
	if ( $done ) {
		return true;
	}
	$done = true;

	desktop_mode_register_window( snt_desktop_window_id(), snt_desktop_window_args() );
	foreach ( snt_desktop_window_tabs() as $tab ) {
		desktop_mode_register_window_tab( snt_desktop_window_id(), $tab );
	}
	if ( function_exists( 'desktop_mode_register_icon' ) ) {
		desktop_mode_register_icon( snt_desktop_window_id(), array(
			'title'    => __( 'S&N Monitor', 'signal-and-noise-tools' ),
			'icon'     => snt_desktop_window_args()['icon'],
			'window'   => snt_desktop_window_id(),
			'position' => 30,
		) );
	}
	return true;
}
// init:7 — after the integration's command/widget registration slot (init:6),
// before desktop-mode reads its registries at init:10.
add_action( 'init', 'snt_desktop_window_register', 7 );

/**
 * Register (never enqueue) the window's script + style handles — upstream's
 * desktop_mode_enqueue_native_window_scripts() enqueues them on shell pages
 * and lazy-injects on mid-session activation; our job is only to make the
 * handles resolvable.
 *
 * Deps: wp-api-fetch (site-views REST), snt-ability-run (the sanctioned
 * abilities transport, for uptime-status), sn-desktop-mode (carries the
 * snDesktopData localize the Health/Deploy panes read).
 *
 * @since 9.76.0
 */
function snt_desktop_window_register_assets() {
	wp_register_script(
		'sn-desktop-window',
		plugins_url( 'assets/desktop-window.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'snt-ability-run', 'sn-desktop-mode' ),
		SNT_VERSION,
		true
	);
	wp_register_style(
		'sn-desktop-window',
		plugins_url( 'assets/desktop-window.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'snt_desktop_window_register_assets', 5 );
