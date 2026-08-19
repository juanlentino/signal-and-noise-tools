<?php
/**
 * Signal & Noise Tools — the shell's script registrations and shared data global.
 *
 * Registers every sn-desktop-mode* handle on `init` priority 5 (registration
 * only, never enqueue — the shell enqueues by handle) and localizes
 * window.snDesktopData on admin_enqueue_scripts.
 *
 * THE HOOK IS LOAD-BEARING. The long v9.52.1 note in the loader explains why
 * `init` and not admin_enqueue_scripts:10 — do not move these hooks.
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the two JS files that desktop-mode loads when our commands +
 * widget activate. Registration (not enqueue) — desktop-mode enqueues
 * them on demand by handle.
 *
 * wp-api-fetch dep gives us wp.apiFetch() with auto-nonce handling for
 * the REST endpoints below.
 *
 * sn_desktop_data is localized so the JS can read current version +
 * latest tag without an extra REST call for the "Info" commands.
 */
/*
 * v9.52.1 — THE HOOK. Scripts register on `init` priority 5, widgets and
 * commands on `init` priority 6, exactly as desktop-mode's own
 * docs/examples/register-widget.md prescribed at the time.
 *
 * 2026-08-17 audit vs desktop-mode-official-extensions: the REAL invariant
 * is only "the script handle must already be registered when the widget
 * registration call fires" (my-echo.php documents it; alcazaba-monitor does
 * both in ONE plain init callback). The 5/6 split satisfies that invariant
 * with margin — keep it, but don't cite it as a shell-mandated contract.
 *
 * This is not stylistic. desktop-mode builds its serverWidgets /
 * serverCommands / desktopIcons payload inside desktop_mode_enqueue_assets(),
 * hooked on `admin_enqueue_scripts` at DEFAULT priority 10
 * (includes/render/assets.php), and it reads the registries EAGERLY right
 * there (`$payload[$k] = $builder();`, includes/core/payload.php). WordPress
 * runs equal-priority callbacks in INSERTION order, and `active_plugins` is
 * sorted alphabetically — 'desktop-mode' sorts before 'signal-and-noise-tools'
 * — so desktop-mode's priority-10 callback is always added, and therefore
 * runs, BEFORE any priority-10 callback of ours.
 *
 * Registering from our own admin_enqueue_scripts:10 closure was therefore
 * unwinnable: by the time we called desktop_mode_register_widget(), the
 * payload had already been built from an empty registry. Every widget and
 * every Cmd+K command was silently absent from the picker/palette — for
 * years, and independently of the v9.52.0 mount-callback bug (a widget that
 * never reaches the payload can't mount no matter how correct its callback).
 * The desktop ICONS in this same file always worked precisely because they
 * were already registered on `init`.
 *
 * `init` also covers the chromeless / live-refresh path, which rebuilds the
 * same payload OUTSIDE admin_enqueue_scripts entirely — and where server-sync
 * UNREGISTERS any id missing from the refresh, i.e. a late registry doesn't
 * just fail to add widgets, it can actively remove live ones.
 *
 * (Supersedes the v4.1.6 D-13 note: those three admin_enqueue_scripts actions
 * were merged into one hook for tidiness; the hook itself was the bug.)
 */
add_action( 'init', function() {
	// ── Register the desktop-mode scripts (init:5, per the docs) ──
	// Registration only — never wp_enqueue_script() here. desktop-mode
	// enqueues what it needs on shell pages at admin_enqueue_scripts:20, and
	// server-sync lazy-loads the rest by URL; enqueueing here would load them
	// on every admin page.
	if ( ! snt_os_active() ) {
		return;
	}

	// v10.43.0 — OpenStation rename compat. A tiny prelude that aliases
	// window.desktopModeWidgets ↔ window.openStationWidgets and
	// window.wp.desktop ↔ window.wp.os onto the SAME object. Registered as
	// an explicit dependency of every other sn-desktop-mode* script below,
	// so on the ordinary WP-enqueued boot path the alias is in place first,
	// on either OpenStation line.
	//
	// REJECT #11 MEDIUM correction: that guarantee does NOT hold on every
	// boot path. desktop-mode's own lazy widget/command loader (server-
	// sync.ts / command-sync) injects a widget or command script's
	// <script src="..."> tag directly by URL — it never walks this
	// wp_register_script dependency graph — so under a post-#475
	// mid-session shell activation, a widget file or assets/desktop-mode.js
	// can run BEFORE this prelude ever does. Every one of those consumers
	// (the 8 widget files + desktop-mode.js) now aliases both names ITSELF,
	// so none of them actually depends on this file running first anymore;
	// this registration remains for boot-path print-order tidiness on the
	// path where WP's own enqueue pipeline IS what delivers the script. See
	// assets/desktop-mode-os-compat.js and docs/openstation-compat.md.
	wp_register_script(
		'sn-desktop-mode-os-compat',
		plugins_url( 'assets/desktop-mode-os-compat.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode',
		plugins_url( 'assets/desktop-mode.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget',
		plugins_url( 'assets/desktop-mode-widget.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	// v2.1.0: two new widget scripts — Quick Actions + RSS Subscribers.
	wp_register_script(
		'sn-desktop-mode-widget-actions',
		plugins_url( 'assets/desktop-mode-widget-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget-rss',
		plugins_url( 'assets/desktop-mode-widget-rss.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	// v9.78.0: SN Anchors widget — everything rides the abilities run-path
	// (anchor-status read + anchor-sweep action), so snt-ability-run is the
	// sole real dependency; sn-desktop-mode orders the snDesktopData global
	// it reads for the dashboard link.
	wp_register_script(
		'sn-desktop-mode-widget-machine-readers',
		plugins_url( 'assets/desktop-mode-widget-machine-readers.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'sn-desktop-mode' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget-anchors',
		plugins_url( 'assets/desktop-mode-widget-anchors.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'snt-ability-run', 'sn-desktop-mode' ),
		SNT_VERSION,
		true
	);

	// v11.29.0: the cron widget. Reads window.snDesktopData.cronSummary only —
	// no REST call, no ability run — so its dependencies are exactly the compat
	// prelude and the handle that carries the data global. Deliberately NOT in
	// the analytics loop below, which hands out wp-api-fetch this widget never
	// uses.
	wp_register_script(
		'sn-desktop-mode-widget-cache',
		plugins_url( 'assets/desktop-mode-widget-cache.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'snt-ability-run', 'sn-desktop-mode' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget-cron',
		plugins_url( 'assets/desktop-mode-widget-cron.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-desktop-mode-os-compat', 'sn-desktop-mode' ),
		SNT_VERSION,
		true
	);

	// v9.52.0: three analytics widgets. These read the site-views REST
	// endpoint (below) rather than the ability run-path, so they depend on
	// wp-api-fetch only — no snt-ability-run.
	//
	// v9.52.1: they DO depend on 'sn-desktop-mode', which carries the
	// window.snDesktopData localize the widgets read. Sharing a dependency
	// (wp-api-fetch) never ordered them — the pre-v9.52.1 comment claiming it
	// did was wrong — so name the real edge and let WP guarantee the data
	// global is printed before any widget script runs.
	foreach ( array( 'views', 'health', 'uptime' ) as $sn_widget ) {
		wp_register_script(
			'sn-desktop-mode-widget-' . $sn_widget,
			plugins_url( 'assets/desktop-mode-widget-' . $sn_widget . '.js', SNT_PATH . 'signal-and-noise-tools.php' ),
			array( 'sn-desktop-mode-os-compat', 'wp-api-fetch', 'sn-desktop-mode' ),
			SNT_VERSION,
			true
		);
	}
}, 5 );

/**
 * Localize the shared data global (admin only).
 *
 * Stays on admin_enqueue_scripts — unlike registration, this is not a
 * registry desktop-mode reads at :10, it's per-handle script data WordPress
 * prints when the handle is enqueued (desktop-mode does that at :20 on shell
 * pages). It also reads deploy/health/uptime state, which has no business
 * running on front-end `init`.
 */
add_action( 'admin_enqueue_scripts', function() {
	if ( ! snt_os_active() ) {
		return;
	}

	// Shared data — every SN desktop script reads window.snDesktopData.
	$theme  = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'theme' ) : array();
	$plugin = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'plugin' ) : array();
	// v9.52.0: this hook fires on EVERY wp-admin screen, so it runs for any
	// role that can reach one (a Contributor, or a Subscriber on their own
	// profile). The two operational summaries below are owner data and are
	// gated accordingly — non-admins receive nulls and the widgets render their
	// honest empty state. (The fields above predate this gate and keep their
	// existing exposure; this release does not widen them.)
	$sn_is_owner = current_user_can( 'manage_options' );
	$shared = array(
		'restNamespace' => 'signal-noise/v1',
		'theme'         => $theme,
		'plugin'        => $plugin,
		'cronSummary'   => function_exists( 'snt_cron_summary_for_localize' ) ? snt_cron_summary_for_localize() : array(),
		// v11.29.0: NULL when verification has never run — deliberately not an
		// empty struct, because "never probed" and "every purge succeeded" are
		// different facts and the widget renders them differently.
		'cacheFreshness' => function_exists( 'snt_cf_freshness_summary' ) ? snt_cf_freshness_summary() : null,
		'insightsSummary' => function_exists( 'snt_insights_summary_for_localize' ) ? snt_insights_summary_for_localize() : null,
		// v9.52.0: cheap/durable summaries for the Pulse + Health widgets.
		// Both are a single option read; both return null (never a fabricated
		// zero or a fake pass) when their source is absent, so the widget can
		// render an honest "not configured" / "not scanned yet" state.
		// The views SERIES is deliberately NOT here — see the REST endpoint.
		// v9.53.0: uptimeSummary is GONE. SN Pulse was its only consumer, and
		// Pulse is retired; the new SN Uptime widget fetches live via the
		// uptime-status ability instead. Leaving it here would have run
		// sn_uptime_status_fetch() — a Better Stack API call behind a 90s
		// transient — on EVERY wp-admin page load, for a payload nothing reads.
		// That is precisely the cost this file's data rule exists to prevent.
		'healthSummary' => $sn_is_owner ? snt_health_summary_for_localize() : null,
		// v9.55.0: resolve every link instead of hardcoding a slug. These all
		// pointed at the pre-v3.8.1 legacy slugs (sn-identity, sn-login, …),
		// which stopped being registered when the submenu was cut to 6 top
		// tabs — so every one of them wp_die()'d "Sorry, you are not allowed
		// to access this page." See snt_desktop_admin_url().
		'pages'         => array(
			'dashboard'    => snt_desktop_admin_url( 'sn-theme-options' ),
			'identity'     => snt_desktop_admin_url( 'sn-identity' ),
			'login'        => snt_desktop_admin_url( 'sn-login' ),
			'cloudflare'   => snt_desktop_admin_url( 'sn-cloudflare' ),
			'cron'         => snt_desktop_admin_url( 'sn-cron' ),
			'insights'     => snt_desktop_admin_url( 'sn-insights' ),
			'rss'          => snt_desktop_admin_url( 'sn-rss' ),
			'reading_time' => snt_desktop_admin_url( 'sn-reading-time' ),
			'analytics'    => snt_desktop_admin_url( 'sn-analytics' ),
			'machine_readers' => snt_desktop_admin_url( 'sn-monitoring', 'machine-readers' ),
		),
	);
	// v4.1.1 (D-08): localize once. Both 'sn-desktop-mode' and
	// 'sn-desktop-mode-widget' read from window.snDesktopData (the same global).
	// wp_localize_script outputs a <script> tag with the JSON payload before
	// the target script — emitting it twice doubled the inline payload for no
	// benefit. The widget script enqueues after sn-desktop-mode (it depends
	// transitively on wp-api-fetch), so the global will be set by the time it
	// runs.
	wp_localize_script( 'sn-desktop-mode', 'snDesktopData', $shared );
} );
