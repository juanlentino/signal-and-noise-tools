<?php
/**
 * Signal & Noise Tools — Desktop Mode "S&N Workbench" native window.
 *
 * The suggest→preview→apply triage queue as a TIER-1 desktop app. This
 * replaces the short-lived v9.76.0 monitor window (a readout the six
 * desktop widgets already covered — it failed the "a new element must do
 * something existing chrome can't" test). The workbench earns the window
 * form with INTERACTION the iframe admin genuinely lacks: every pending
 * suggestion from the content scanners in one queue, previewed and
 * applied inline through the existing ability contracts.
 *
 * Registered against the upstream WordPress/desktop-mode contract
 * (trunk @ 0.9.5): desktop_mode_register_window() +
 * desktop_mode_register_window_tab() + desktop_mode_register_icon(),
 * per docs/use-from-a-plugin.md. Pure no-op when Desktop Mode is absent.
 *
 * Panes and their (pre-existing) ability contracts — nothing new
 * server-side, the window is a client of the same surface the admin
 * tabs and MCP already use:
 *   Migrations (main) — block-migrations-scan → candidates;
 *     block-migrations-suggest {post_id, block_fingerprint,
 *     migration_type} → replacement markup; block-migrations-apply
 *     {post_id, block_fingerprint, replacement_markup, migration_type}.
 *   Patterns — pattern-adoption-scan / -suggest / -apply, same shape
 *     family with pattern_type.
 *   Dismissals — the canonical signal-noise/dismiss-candidate
 *     dispatcher {surface, post_id, block_fingerprint, candidate_type}.
 *
 * @since 9.77.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Window + tile id — one accessor so tests, the JS mount contract
 * (window.desktopModeNativeWindows['sn-workbench']) and the icon link
 * can never drift apart.
 *
 * @since 9.77.0
 * @return string
 */
function snt_desktop_workbench_id() {
	return 'sn-workbench';
}

/**
 * Args for desktop_mode_register_window() — pure, testable.
 *
 * capabilities: manage_options — every queue action mutates owner
 * content, and the scan abilities are owner-gated server-side too.
 *
 * @since 9.77.0
 * @return array
 */
function snt_desktop_workbench_args() {
	return array(
		'title'          => __( 'S&N Workbench', 'signal-and-noise-tools' ),
		'icon'           => function_exists( 'plugins_url' ) && defined( 'SNT_PATH' )
			? plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' )
			: 'dashicons-hammer',
		'template'       => 'snt_desktop_workbench_template_migrations',
		'script'         => 'sn-desktop-workbench',
		'style'          => 'sn-desktop-workbench',
		'width'          => 680,
		'height'         => 520,
		'min_width'      => 360,
		'min_height'     => 300,
		'placement'      => 'dock',
		'capabilities'   => array( 'manage_options' ),
		'main_tab_label' => 'Migrations',
		'config'         => array( 'restNamespace' => 'signal-noise/v1' ),
	);
}

/**
 * Secondary tabs ('main' is reserved upstream for the Migrations pane).
 *
 * @since 9.77.0
 * @return array[]
 */
function snt_desktop_workbench_tabs() {
	return array(
		array(
			'value'    => 'patterns',
			'label'    => __( 'Patterns', 'signal-and-noise-tools' ),
			'position' => 10,
			'template' => 'snt_desktop_workbench_template_patterns',
		),
	);
}

/**
 * Pane templates — static scaffolding the shell clones before the render
 * callback runs; assets/desktop-workbench.js lights the mounts up.
 * Both panes share one queue markup shape so the JS renders them with a
 * single engine.
 *
 * @since 9.77.0
 */
function snt_desktop_workbench_template_migrations() {
	?>
	<div class="sn-wb-pane" data-surface="block-migrations">
		<div class="sn-wb-toolbar">
			<span class="sn-wb-count" data-role="mig-count">—</span>
			<button type="button" class="sn-wb-refresh" data-role="mig-refresh"><?php esc_html_e( 'Re-scan', 'signal-and-noise-tools' ); ?></button>
		</div>
		<ul class="sn-wb-list" data-role="mig-list"></ul>
		<p class="sn-wb-note" data-role="mig-note"><?php esc_html_e( 'Loading candidates…', 'signal-and-noise-tools' ); ?></p>
	</div>
	<?php
}

function snt_desktop_workbench_template_patterns() {
	?>
	<div class="sn-wb-pane" data-surface="pattern-adoption">
		<div class="sn-wb-toolbar">
			<span class="sn-wb-count" data-role="pat-count">—</span>
			<button type="button" class="sn-wb-refresh" data-role="pat-refresh"><?php esc_html_e( 'Re-scan', 'signal-and-noise-tools' ); ?></button>
		</div>
		<ul class="sn-wb-list" data-role="pat-list"></ul>
		<p class="sn-wb-note" data-role="pat-note"><?php esc_html_e( 'Loading candidates…', 'signal-and-noise-tools' ); ?></p>
	</div>
	<?php
}

/**
 * Register the window, its tab, and the desktop tile. No-op without
 * Desktop Mode; idempotent.
 *
 * @since 9.77.0
 * @return bool Whether registration ran.
 */
function snt_desktop_workbench_register() {
	static $done = false;
	if ( ! function_exists( 'desktop_mode_register_window' ) || ! function_exists( 'desktop_mode_register_window_tab' ) ) {
		return false;
	}
	if ( $done ) {
		return true;
	}
	$done = true;

	desktop_mode_register_window( snt_desktop_workbench_id(), snt_desktop_workbench_args() );
	foreach ( snt_desktop_workbench_tabs() as $tab ) {
		desktop_mode_register_window_tab( snt_desktop_workbench_id(), $tab );
	}
	if ( function_exists( 'desktop_mode_register_icon' ) ) {
		desktop_mode_register_icon( snt_desktop_workbench_id(), array(
			'title'    => __( 'S&N Workbench', 'signal-and-noise-tools' ),
			'icon'     => snt_desktop_workbench_args()['icon'],
			'window'   => snt_desktop_workbench_id(),
			'position' => 30,
		) );
	}
	return true;
}
// init:7 — after the integration's command/widget slot (init:6), before
// desktop-mode reads its registries at init:10.
add_action( 'init', 'snt_desktop_workbench_register', 7 );

/**
 * Register (never enqueue) the window's handles — upstream's
 * desktop_mode_enqueue_native_window_scripts() ships them on shell pages
 * and lazy-injects on mid-session activation.
 *
 * Deps: snt-ability-run is the whole transport (scan/suggest/apply/
 * dismiss all ride the abilities run-path with annotation-derived verbs).
 *
 * @since 9.77.0
 */
function snt_desktop_workbench_register_assets() {
	wp_register_script(
		'sn-desktop-workbench',
		plugins_url( 'assets/desktop-workbench.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'snt-ability-run' ),
		SNT_VERSION,
		true
	);
	wp_register_style(
		'sn-desktop-workbench',
		plugins_url( 'assets/desktop-workbench.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'snt_desktop_workbench_register_assets', 5 );
