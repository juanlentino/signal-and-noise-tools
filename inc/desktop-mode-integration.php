<?php
/**
 * Signal & Noise Tools — WordPress/desktop-mode integration.
 *
 * Makes the SN plugin a first-class participant in desktop-mode (when
 * installed + active). Adds:
 *
 *   1. A dock icon "Signal & Noise" with a submenu of every SN settings
 *      tab — derived from sn_admin_top_tabs(), never a hardcoded count
 *      (via the desktop_mode_dock_items filter).
 *   2. Two desktop icons (Dashboard + Identity — the most-frequent
 *      surfaces) via desktop_mode_register_icon().
 *   3. The Cmd+K command-palette commands via
 *      desktop_mode_register_command() — maintenance actions (Abilities
 *      run-path), navigation shortcuts, version/info, cron, insights, and
 *      audit-log. (The display-only theme-ability launchers were removed
 *      in v9.52.3; the registration loop below is the source of truth.)
 *   4. Six desktop widgets via desktop_mode_register_widget():
 *      SN Site Views, SN Health, SN Uptime, SN Deploy Status, SN Quick
 *      Actions, SN RSS Subscribers — one per domain since v9.53.0 (SN Pulse
 *      retired: it duplicated Site Views + Health).
 *   5. (v9.52.0) The desktop_mode_living_tree_traffic filter, so the
 *      wallpaper tree's wind responds to real 14-day traffic.
 *
 * EVERY integration is gated on function_exists() — the plugin behaves
 * identically when desktop-mode is inactive or uninstalled.
 *
 * THE WIDGET MOUNT CONTRACT (v9.52.0 — the fix that made widgets work):
 * desktop-mode offers two widget paths. Ours is the PHP-declared one:
 * desktop_mode_register_widget() publishes label/description/icon
 * server-side, then desktop-mode's server-sync loads the widget's script and
 * reads its mount callback from `window.desktopModeWidgets[ id ]`
 * (mount( container, ctx ) → teardown). The OTHER path,
 * wp.desktop.registerWidget( def ), is for pure client-side widgets and
 * hard-validates the def (id + label + description + icon + mount), throwing
 * otherwise. Before v9.52.0 all three widget scripts called the client-side
 * path with `{ id, render }` — wrong path AND wrong shape — so they failed
 * validation and never set the global either. All three were silently dead;
 * the file had no tests to notice. tests/desktop-mode-integration.php now
 * pins the contract for all six.
 *
 * The maintenance commands fire without page navigation via the Abilities
 * run-path (assets/desktop-commands.js run() → sntAbilityRun): purge-all-caches
 * (bare, or {include_template_overrides:true} for the full-reset command),
 * clear-template-overrides, and get-deploy-status {force_refresh:true} for
 * force-check. The legacy signal-noise/v1/cmd/* REST routes were removed in
 * v7.0.0; the deprecated per-command abilities (full-reset, force-check-updates)
 * in v8.0.0. The local sn-cmd-* palette keys keep their names — they are
 * labels, not ability slugs.
 *
 * All the dispatched abilities require manage_options. WP REST API handles
 * _wpnonce verification automatically when JS uses wp.apiFetch (which our
 * scripts do via the wp-api-fetch dependency).
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
 * docs/examples/register-widget.md prescribes.
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

/**
 * Register Command Palette commands + desktop widgets (init:6).
 *
 * MUST be `init` — see the long note above the script-registration block.
 * desktop-mode reads both registries eagerly at admin_enqueue_scripts:10 and
 * always beats a same-priority callback of ours.
 */
add_action( 'init', function() {
	if ( ! snt_os_active() ) {
		return;
	}

	$commands = array(
		// Maintenance (REST → toast).
		array( 'slug' => 'sn-cmd-force-check',     'label' => 'SN: Force-check updates',       'description' => 'Clear all GitHub + WordPress update transients.',           'icon' => 'dashicons-update' ),
		array( 'slug' => 'sn-cmd-purge-caches',    'label' => 'SN: Purge all caches',          'description' => 'Object cache + Breeze + Varnish + Cloudflare.',           'icon' => 'dashicons-trash' ),
		array( 'slug' => 'sn-cmd-clear-overrides', 'label' => 'SN: Clear template overrides',  'description' => 'Remove wp_template / wp_template_part / wp_navigation DB rows.', 'icon' => 'dashicons-editor-removeformatting' ),
		// The JS run() dispatches purge-all-caches {include_template_overrides:true} (the full-reset ability was removed in v8.0.0).
		array( 'slug' => 'sn-cmd-full-reset',      'label' => 'SN: Full reset',                'description' => 'Clear overrides AND purge every cache.',                  'icon' => 'dashicons-controls-repeat' ),

		// Navigation (window.location).
		array( 'slug' => 'sn-cmd-nav-dashboard',    'label' => 'SN: Open Dashboard',    'description' => 'Site state, recent deploys, maintenance actions.', 'icon' => 'dashicons-dashboard' ),
		array( 'slug' => 'sn-cmd-nav-identity',     'label' => 'SN: Open Identity',     'description' => 'Site name, social profiles, OG cards, SEO copy.',  'icon' => 'dashicons-id' ),
		array( 'slug' => 'sn-cmd-nav-login',        'label' => 'SN: Open Login',        'description' => 'Custom login URL + emergency unlock.',             'icon' => 'dashicons-lock' ),
		array( 'slug' => 'sn-cmd-nav-cloudflare',   'label' => 'SN: Open Cloudflare',   'description' => 'CF API token + zone + auto-purge config.',         'icon' => 'dashicons-cloud' ),
		array( 'slug' => 'sn-cmd-nav-rss',          'label' => 'SN: Open RSS',          'description' => 'Subscriber tracking + recent feed requests.',      'icon' => 'dashicons-rss' ),
		array( 'slug' => 'sn-cmd-nav-reading-time', 'label' => 'SN: Open Reading Time', 'description' => 'Legacy reading-time-string cleanup tool.',         'icon' => 'dashicons-clock' ),

		// Info (read from localized data → toast).
		array( 'slug' => 'sn-cmd-version-theme',  'label' => 'SN: Theme version',  'description' => 'Show current theme version + GitHub-latest comparison.',  'icon' => 'dashicons-admin-appearance' ),
		array( 'slug' => 'sn-cmd-version-plugin', 'label' => 'SN: Plugin version', 'description' => 'Show current plugin version + GitHub-latest comparison.', 'icon' => 'dashicons-admin-plugins' ),

		// Cron Dashboard (v3.0.0).
		array( 'slug' => 'sn-cmd-cron-health', 'label' => 'SN: Cron health overview',    'description' => 'Toast a summary of scheduled events + navigate to the Cron tab.',     'icon' => 'dashicons-clock' ),
		array( 'slug' => 'sn-cmd-cron-list',   'label' => 'SN: Open Cron tab',           'description' => 'Navigate directly to the SN Cron tab in wp-admin.',                  'icon' => 'dashicons-list-view' ),

		// Insights (v3.6.0).
		array( 'slug' => 'sn-cmd-insights',    'label' => 'SN: Open Insights tab',       'description' => 'Navigate to the AI-powered Insights tab in wp-admin.',               'icon' => 'dashicons-lightbulb' ),

		// Audit log (v3.8.3).
		array( 'slug' => 'sn-cmd-audit-summary',       'label' => 'SN: Audit log summary',        'description' => 'Toast last-24h totals, 7-day trend, unique IPs, LLA lockout count.', 'icon' => 'dashicons-shield-alt' ),
		array( 'slug' => 'sn-cmd-audit-recent-logins', 'label' => 'SN: Recent successful logins', 'description' => 'Toast last 10 successful login timestamps + usernames.',              'icon' => 'dashicons-admin-users' ),

		// v9.78.0: the mirror-map gap — every one-shot ability gets a ⌘K entry
		// (glance = widget/badge, one-shot = command, review/config = iframe).
		array( 'slug' => 'sn-cmd-health-scan',    'label' => 'SN: Run health scan',      'description' => 'Run the full check suite now instead of waiting on the 24h cache.',        'icon' => 'dashicons-heart' ),
		array( 'slug' => 'sn-cmd-insights-scan',  'label' => 'SN: Run insights scan',    'description' => 'Refresh the AI insights corpus scan now.',                                  'icon' => 'dashicons-lightbulb' ),
		array( 'slug' => 'sn-cmd-narration',      'label' => 'SN: Run narration',        'description' => 'Regenerate the analytics narration now.',                                   'icon' => 'dashicons-format-chat' ),
		array( 'slug' => 'sn-cmd-prune-tags',     'label' => 'SN: Prune unused tags',    'description' => 'Delete tags with zero published posts.',                                    'icon' => 'dashicons-tag' ),
		array( 'slug' => 'sn-cmd-anchor-sweep',   'label' => 'SN: Sweep anchors',        'description' => 'Ask the provenance Worker to upgrade pending Bitcoin anchors now.',         'icon' => 'dashicons-admin-links' ),

		// v9.52.3: the 12 theme-ability ⌘K launcher commands are GONE.
		//
		// They were registered but never wired — no JS run() — so each rendered a
		// real, clickable palette entry that did nothing. They were parked as
		// display-only pending desktop_mode_register_ai_tool(), the server-side AI
		// tool registry the v3.8.0 plan targeted. desktop-mode REMOVED that API in
		// 0.9.4 and replaced it with WordPress Abilities, so the thing they waited
		// for is never coming.
		//
		// Nothing is lost: the replacement is already live and strictly better.
		// desktop-mode's AI Copilot offers EVERY read-only ability
		// (meta.annotations.readonly) as a tool automatically — no opt-in, the
		// ability's own permission_callback still gates execution — and it can
		// dispatch them with STRUCTURED ARGUMENTS, which is exactly what a bare
		// launcher label never could. That's why 7 of these (get-design-tokens,
		// list-block-patterns, get-active-template-structure, get-theme-version,
		// get-page-notes-pillars, get-reading-time-for-slug,
		// get-design-system-summary) already answer through Ask AI today, and why
		// reading-time — whose required slug argument is the reason it was left
		// display-only ("sequential window.prompt() forms are worse than no UX")
		// — now works there.
		//
		// The 5 ai-* abilities are write-path and correctly excluded from the
		// Copilot (a search turn can be driven by attacker-controlled content), but
		// remain available over the MCP write door.
		//
		// EVERY ABILITY IS UNTOUCHED. This removes 12 inert labels, not capability.
		// tests/desktop-mode-integration.php now fails the build if any registered
		// command lacks a JS run().
	);

	foreach ( $commands as $cmd ) {
		snt_os_register_command( array(
			'slug'        => $cmd['slug'],
			'label'       => $cmd['label'],
			'description' => $cmd['description'],
			'icon'        => $cmd['icon'],
			'script'      => 'sn-desktop-mode',
		) );
	}

	// ── Sub-block 3: register desktop widgets ──
	// Independent availability check — desktop-mode/OpenStation could
	// theoretically ship commands without widgets (defensive, mirrors the
	// pre-v4.1.6 split).
	if ( snt_os_register_widget_available() ) {
		// v9.52.0: every entry carries description + icon. desktop-mode's
		// server-sync copies both straight onto the widget def and its picker
		// lists them under the label; without them the picker showed an empty
		// blurb and the generic fallback dashicon.
		//
		// v9.52.1: the 'sort' key these entries used to pass was DEAD — it is
		// absent from desktop_mode_register_widget()'s $defaults and from the
		// stored $entry in BOTH v0.8.9 and v0.9.5, so wp_parse_args() kept it
		// and the registry then dropped it on the floor. Widget order is simply
		// REGISTRATION order (`seed.push( def )`, src/widgets/registry.ts), so
		// the intended order is expressed by registering in it: the Pulse
		// command-center read first, then Site Views, then the three older
		// utility cards, then Health.
		// v9.52.2: every card is movable + resizable — drag it out of the
		// right-side column and place it anywhere on the desktop. Both default
		// FALSE, so until v9.52.2 the cards were locked to the column. `movable`
		// makes desktop-mode render a thin chrome header (grip + label +
		// remove) and drag initiates ONLY from that chrome, so the buttons
		// inside SN Quick Actions stay clickable; `resizable` adds the 8 grip
		// handles. The column drives geometry while a card is docked — the
		// default_* sizes apply the first time a card floats, and the min_*
		// floor stops a drag collapsing one into an unreadable sliver.
		//
		// v9.53.0: ONE WIDGET PER DOMAIN. SN Pulse is retired — it carried
		// views + a delta (Site Views' job) and the health ratio (Health's job),
		// so on a desktop with all cards enabled the same numbers rendered
		// twice. The one row it alone carried, uptime, is now SN Uptime. Each
		// surviving card goes deep instead of three cards going shallow.
		// desktop_mode_register_widget() has NO sort arg (absent from $defaults
		// and the stored $entry in both v0.8.9 and v0.9.5) — order is
		// REGISTRATION order (seed.push, src/widgets/registry.ts). Hence:
		// traffic, then site condition, then ops.
		$sn_drag = array( 'movable' => true, 'resizable' => true );

		snt_os_register_widget( 'sn-site-views', array_merge( $sn_drag, array(
			'label'          => 'SN Site Views',
			'description'    => 'First-party traffic: 14-day sparkline, bot share, top page, forecast.',
			'icon'           => 'dashicons-chart-area',
			'script'         => 'sn-desktop-mode-widget-views',
			// Taller floor than its siblings: the sparkline needs vertical room
			// before it reads as a trend rather than a smudge, and v9.53.0 adds
			// the visits/bot/top-path rows plus the forecast beneath it.
			'min_width'      => 240,
			'min_height'     => 220,
			'default_width'  => 330,
			'default_height' => 300,
		) ) );

		snt_os_register_widget( 'sn-health', array_merge( $sn_drag, array(
			'label'          => 'SN Health',
			'description'    => 'Content-health checks passing — and which ones are not.',
			'icon'           => 'dashicons-shield-alt',
			'script'         => 'sn-desktop-mode-widget-health',
			'min_width'      => 220,
			'min_height'     => 130,
			'default_width'  => 300,
			'default_height' => 190,
		) ) );

		// v9.53.0: new. Was one row inside Pulse; uptime deserves its own card
		// once it can show 30d availability + response time.
		snt_os_register_widget( 'sn-uptime', array_merge( $sn_drag, array(
			'label'          => 'SN Uptime',
			'description'    => 'Monitor status, 30-day availability and response time.',
			'icon'           => 'dashicons-chart-bar',
			'script'         => 'sn-desktop-mode-widget-uptime',
			'min_width'      => 220,
			'min_height'     => 120,
			'default_width'  => 300,
			'default_height' => 180,
		) ) );

		snt_os_register_widget( 'sn-deploy-status', array_merge( $sn_drag, array(
			'label'          => 'SN Deploy Status',
			'description'    => 'Theme + plugin version and last deploy time.',
			'icon'           => 'dashicons-update',
			'script'         => 'sn-desktop-mode-widget',
			'min_width'      => 220,
			'min_height'     => 140,
			'default_width'  => 300,
			'default_height' => 190,
		) ) );

		// v2.1.0: Quick Actions widget — replaces the 3-click path of
		// S&N → Dashboard → Maintenance with single-click access from desktop.
		snt_os_register_widget( 'sn-quick-actions', array_merge( $sn_drag, array(
			'label'          => 'SN Quick Actions',
			'description'    => 'One-click purge, clear overrides, force update-check.',
			'icon'           => 'dashicons-controls-repeat',
			'script'         => 'sn-desktop-mode-widget-actions',
			'min_width'      => 220,
			'min_height'     => 190,
			'default_width'  => 300,
			'default_height' => 240,
		) ) );

		// v2.1.0: RSS Subscribers widget — surfaces RSS feed activity that
		// was previously buried under S&N → RSS tab + a single line on the
		// SN Dashboard tab. At-a-glance subscriber growth on the desktop.
		snt_os_register_widget( 'sn-rss-subscribers', array_merge( $sn_drag, array(
			'label'          => 'SN RSS Subscribers',
			'description'    => 'Unique feed subscribers over 24h / 7d / 30d.',
			'icon'           => 'dashicons-rss',
			'script'         => 'sn-desktop-mode-widget-rss',
			'min_width'      => 220,
			'min_height'     => 150,
			'default_width'  => 300,
			'default_height' => 200,
		) ) );

		// v9.78.0: SN Anchors — the one glanceable that had no mirror.
		// Pending Notes with their live in-flight Bitcoin tx (N/6, captured
		// by the worker's pending callbacks) + a Sweep action; idles at an
		// honest "N notes anchored". Fetch-on-render via the anchor-status
		// ability — the aggregate walks every Note's chain meta, which must
		// never ride a page-load localize.
		snt_os_register_widget( 'sn-anchors', array_merge( $sn_drag, array(
			'label'          => 'SN Anchors',
			'description'    => 'Provenance anchor status: pending Bitcoin confirmations + on-demand sweep.',
			'icon'           => 'dashicons-admin-links',
			'script'         => 'sn-desktop-mode-widget-anchors',
			'min_width'      => 220,
			'min_height'     => 150,
			'default_width'  => 300,
			'default_height' => 220,
		) ) );

		// v10.1.0: the machine half of the audience. Human readership is
		// sn-site-views' job (beacons); this reads the edge sensor, and the two
		// are never summed.
		snt_os_register_widget( 'sn-machine-readers', array_merge( $sn_drag, array(
			'label'          => 'SN Machine Readers',
			'description'    => 'AI crawler readership: top families, declared AI-training reads, sensor state.',
			'icon'           => 'dashicons-visibility',
			'script'         => 'sn-desktop-mode-widget-machine-readers',
			'min_width'      => 220,
			'min_height'     => 170,
			'default_width'  => 300,
			'default_height' => 260,
		) ) );
	}
}, 6 );

/**
 * Dock item — single entry "Signal & Noise" with submenu of all 8 tabs.
 *
 * Filter shape per desktop-mode docs/getting-started.md:
 *   slug, title, icon (dashicons-*), url, badge?, submenu? (array of items
 *   with the same shape, recursively)
 */
/**
 * Suppress desktop-mode's automatic dock import of our menu page.
 *
 * Per WordPress/desktop-mode core/payload.php, every entry registered via
 * add_menu_page() / add_submenu_page() is auto-imported as a dock item
 * by default. Our admin-page.php registers "Signal & Noise" as a top-
 * level menu, so without this filter we end up with TWO dock entries:
 *
 *   1. Auto-imported "Signal & Noise" from add_menu_page (generic icon
 *      because desktop-mode falls back when the menu doesn't specify a
 *      dashicon explicitly — looks like a megaphone glyph on small
 *      screens, which is what surfaced the bug).
 *   2. Our explicit "Signal & Noise" with shield icon registered in the
 *      desktop_mode_dock_items filter below (richer: 8-tab submenu +
 *      update-available badge).
 *
 * Returning 'hidden' for the SN menu slug suppresses the auto-import.
 * Our explicit entry remains. Single dock item, shield icon, full
 * submenu.
 *
 * Verified against WordPress/desktop-mode includes/core/payload.php:
 *   apply_filters( 'desktop_mode_dock_placement', 'dock', $menu_slug );
 * Post-#475 OpenStation renames this to `openstation_dock_placement`
 * (includes/core/payload.php:1137, same 2-arg shape) — dual-registered via
 * snt_os_compat_add_filter(), idempotent (pure function of $menu_slug), no
 * double-fire guard needed.
 *
 * Added in v2.0.1 (post-v1.15.0 desktop-mode bug fix).
 */
snt_os_compat_add_filter( 'desktop_mode_dock_placement', 'openstation_dock_placement', function( $placement, $menu_slug ) {
	if ( 'sn-theme-options' === $menu_slug ) {
		return 'hidden';
	}
	return $placement;
}, 10, 2 );

// Post-#475 OpenStation renames this to `openstation_dock_items`
// (includes/core/payload.php:212) — dual-registered, idempotent (rebuilds
// $items from sn_admin_top_tabs() every call), no double-fire guard needed.
snt_os_compat_add_filter( 'desktop_mode_dock_items', 'openstation_dock_items', function( $items ) {
	if ( ! is_array( $items ) ) {
		$items = array();
	}

	/**
	 * v2.1.0 dock fix:
	 *   - Key is 'id' not 'slug' (the desktop-mode docs/hooks-reference.md
	 *     says 'slug' but the actual code at includes/core/payload.php:163
	 *     uses 'id'. Verified against test fixture at tests/phpunit/tests/
	 *     desktopModeBuildDockItems.php:394 which uses 'id' => 'replaced').
	 *     Wrong key meant item.id was undefined in JS, crashing dock.ts:1711
	 *     with TypeError on every click of the SN tile — silent breakage
	 *     since v1.15.0, only surfaced post-Phase-13 when our auto-import
	 *     suppression removed the parallel working entry.
	 *   - Submenu entries only honor 'title' + 'url' per src/dock.ts:89
	 *     SubmenuItem type — 'icon' and 'slug' on submenu items are
	 *     silently dropped. Removed the noise.
	 *   - Icon is dashicons-megaphone (matches the icon passed to
	 *     add_menu_page() in admin-page.php:121, which is what was
	 *     rendering on the auto-imported entry before suppression).
	 *
	 * Click behavior (verified via src/dock.ts:911-913 + 1703-1765):
	 *   - Single click on parent tile → window opens to item.url
	 *   - Submenu rides into the opened window as an in-window tab strip
	 *     (the "submenu chevron" on the dock tile is documented future work)
	 */
	// v3.8.4: derive submenu from sn_admin_top_tabs() instead of hardcoding
	// the legacy 8-entry list. Was a single-source-of-truth violation: when
	// v3.8.1 reduced the wp-admin sidebar submenu to 6 entries to match the
	// new in-page tab IA, THIS filter was missed — so desktop-mode portal
	// continued rendering the OLD 8 entries as a horizontal top-nav row.
	// That re-created the "duplicate nav appearance" that v3.8.1 was meant
	// to fix (see memory feedback_desktop_mode_horizontal_submenu_warning).
	$dock_submenu = array();
	foreach ( sn_admin_top_tabs() as $top_tab ) {
		// Direct-to-canonical URLs (no redirect round-trip): page=sn-theme-options&tab=<top>.
		// Dashboard tab omits the &tab= param since it's the default.
		$url = 'dashboard' === $top_tab['tab']
			? admin_url( 'admin.php?page=sn-theme-options' )
			: admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $top_tab['tab'] ) );
		$dock_submenu[] = array(
			'title' => $top_tab['label'],
			'url'   => $url,
		);
	}

	$items[] = array(
		'id'      => 'signal-noise',
		'title'   => 'Signal & Noise',
		'icon'    => 'dashicons-megaphone',
		'url'     => admin_url( 'admin.php?page=sn-theme-options' ),
		'badge'   => snt_desktop_dock_badge(),
		'submenu' => $dock_submenu,
	);

	return $items;
} );

/**
 * Badge count for the dock — total "update available" count for theme +
 * plugin. 0 = no badge (desktop-mode convention).
 */
function snt_desktop_dock_badge() {
	$badge = 0;
	if ( function_exists( 'snt_deploy_status_for' ) ) {
		if ( 'available' === ( snt_deploy_status_for( 'theme' )['state']  ?? '' ) ) { $badge++; }
		if ( 'available' === ( snt_deploy_status_for( 'plugin' )['state'] ?? '' ) ) { $badge++; }
	}
	return $badge;
}

/**
 * Desktop icons — Dashboard + Identity (the two most-frequent surfaces).
 */
/**
 * Resolve any SN admin page slug — current OR retired — to a URL that actually
 * loads.
 *
 * WHY THIS EXISTS (v9.55.0, owner-found by clicking)
 *
 * Opening most SN windows in Desktop Mode showed WP core's "Sorry, you are not
 * allowed to access this page." EIGHT of our NINE admin links were dead.
 *
 * v3.8.1 cut the wp-admin submenu from the 12 legacy slugs to 6 top tabs
 * (inc/admin-menu.php registers add_submenu_page over sn_admin_top_tabs()).
 * The icons and the Cmd+K nav map kept hardcoding the RETIRED slugs —
 * sn-identity, sn-login, sn-cron, sn-rss, sn-insights, sn-analytics,
 * sn-cloudflare, sn-reading-time. admin.php looks each up in
 * $_registered_pages, doesn't find it, and wp_die()s. The message is WP CORE's
 * — not desktop-mode's, not ours — which is exactly why no surface here ever
 * noticed, and why CI stayed green for releases on end.
 *
 * The legacy redirect could not rescue them, though it looks like it should:
 * sn_admin_maybe_redirect_legacy() is called from INSIDE sn_theme_options_page(),
 * the render callback of a page that no longer exists. A legacy URL only ever
 * 301s if its slug is still registered. These are not. The rescue lived in the
 * room that burned down.
 *
 * So route through the same canonical resolver the redirect itself uses. It
 * always lands on the registered parent (page=sn-theme-options&tab=…), so a
 * future IA change cannot re-rot these links: they follow the tab data.
 *
 * @since 9.55.0
 * @param string $slug Any SN admin page slug, current or retired.
 * @return string An admin URL whose `page=` is always a registered page.
 */
function snt_desktop_admin_url( $slug, $sub = '' ) {
	// SPECIAL CASE, and the one the resolver alone gets wrong: the analytics
	// page is registered with add_dashboard_page() — i.e. under index.php, NOT
	// the SN menu — so its real home is `index.php?page=sn-analytics`. It is not
	// an SN tab at all, and sn_admin_page_tab_for_slug() has no entry for it, so
	// it would fall through to the 'dashboard' default and land the user on the
	// SN Dashboard: a link that loads perfectly and goes to the wrong place.
	// (The old hardcoded `admin.php?page=sn-analytics` was dead for the opposite
	// reason — right slug, wrong parent.)
	if ( 'sn-analytics' === $slug ) {
		return admin_url( 'index.php?page=sn-analytics' );
	}

	// Guarded because this file is loaded on every admin request and the tab
	// data lives in a sibling module; a missing resolver must degrade to the
	// one slug that is always registered, never to a fatal.
	if ( ! function_exists( 'sn_admin_page_tab_for_slug' ) || ! function_exists( 'sn_admin_canonical_destination' ) ) {
		return admin_url( 'admin.php?page=sn-theme-options' );
	}

	$tab  = sn_admin_page_tab_for_slug( $slug );
	// null = the tab is already canonical; otherwise it maps a legacy tab to
	// its post-v3.8 home (and may carry a sub-leaf or an anchor).
	$dest = sn_admin_canonical_destination( $tab );

	$url = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $dest ? $dest['tab'] : $tab ) );
	if ( $dest && ! empty( $dest['sub'] ) ) {
		$url .= '&sub=' . rawurlencode( $dest['sub'] );
	}
	if ( $dest && ! empty( $dest['anchor'] ) ) {
		$url .= '#sn-sec-' . rawurlencode( $dest['anchor'] );
	}
	// v10.46.0: an explicit leaf, for callers that want a sub-tab the slug
	// resolver cannot express. Passing a query string as $slug (the previous
	// Machine Readers bug) matches no slug, so the resolver fell through to
	// 'dashboard' — a link that loads perfectly and goes to the wrong place,
	// the exact failure the sn-analytics special case above was written for.
	if ( '' !== $sub && ! ( $dest && ! empty( $dest['sub'] ) ) ) {
		$url .= '&sub=' . rawurlencode( $sub );
	}
	return $url;
}

add_action( 'init', function() {
	if ( ! snt_os_register_icon_available() ) {
		return;
	}

	snt_os_register_icon( 'sn-icon-dashboard', array(
		'title' => 'SN Dashboard',
		'icon'  => 'dashicons-shield-alt',
		'url'   => admin_url( 'admin.php?page=sn-theme-options' ),
	) );

	snt_os_register_icon( 'sn-icon-identity', array(
		'title' => 'SN Identity',
		'icon'  => 'dashicons-id',
		'url'   => snt_desktop_admin_url( 'sn-identity' ),
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * COMMAND IMPLEMENTATIONS
 *
 * Pure functions returning array (success payload) or WP_Error. Only the
 * impls with live callers remain: snt_cmd_impl_force_check (dashboard
 * button + ability) and snt_cmd_impl_rss_stats (abilities-content). The
 * purge-caches / clear-overrides siblings were deleted in v9.75.0 — their
 * ability execute callbacks apply the sn_*_result filters directly, and
 * the legacy /cmd/* REST routes that once shared them left in v7.0.0.
 *
 * @since v2.5.0 — extracted from snt_desktop_cmd_handler for the
 * abilities-first refactor.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Force-check: clear all "is there a new version?" caches. Single source of
 * truth — the admin dashboard's force-check button handler also calls this.
 *
 * v4.1.1 (D-01): The GHA runs cache (deploy history) is intentionally NOT
 * cleared here. Clearing it would force a 60/h GitHub API request without
 * answering the question the user actually asked. ETag-based conditional
 * requests in snt_gh_recent_runs() handle that cache's freshness without
 * quota cost.
 */
function snt_cmd_impl_force_check() {
	delete_site_transient( 'sn_gh_latest_theme' );
	delete_site_transient( 'sn_gh_latest_plugin' );
	delete_site_transient( 'update_themes' );
	delete_site_transient( 'update_plugins' );
	return array(
		'ok'      => true,
		'message' => 'Update caches cleared. Next page-load fetches fresh data from GitHub.',
	);
}

function snt_cmd_impl_rss_stats() {
	if ( ! function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
		return new WP_Error(
			'snt_rss_unavailable',
			'RSS tracker module not loaded.',
			array( 'status' => 503 )
		);
	}
	$stats    = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
	$last_rel = '';
	if ( ! empty( $stats['most_recent'] ) ) {
		$t = strtotime( $stats['most_recent'] );
		if ( $t ) {
			$last_rel = human_time_diff( $t, time() ) . ' ago';
		}
	}
	return array(
		'ok'   => true,
		'data' => array(
			'last_request'          => $stats['most_recent'] ?? null,
			'last_request_relative' => $last_rel,
			'windows'               => $stats['windows'] ?? array(),
		),
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * v2.1.3 — Desktop Mode Plugins-window fixes
 *
 * The v2.1.2 brand-assets work (icons + banners via plugins_api + the
 * update_plugins transient) only covers WP core surfaces. Desktop Mode
 * ships its own custom Plugins window (a REST-fed TypeScript frontend
 * under includes/plugins-window/* + src/plugins-window/*) that does NOT
 * consult either of those data sources. Two surgical filters land the
 * icon + decode the plugin name for that surface only.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Provide our plugin's icon to Desktop Mode's custom Plugins window.
 *
 * Desktop Mode derives the icon URL by hardcoding
 *   https://ps.w.org/{dirname(plugin_file)}/assets/icon.svg
 * at includes/plugins-window/rest-fields.php:404-445 — works for the
 * wordpress.org plugin directory, 404s for self-hosted plugins like ours.
 * The update_plugins site_transient icons array (which we populate in
 * inc/wp-update-integration.php) is never read by this code path.
 *
 * The 'desktop_mode_plugins_window_icon_url' filter is exposed at the
 * same line; we return our own SVG so the icon column on Desktop Mode's
 * Plugins panel renders correctly. SVG renders crisp at any DPR — no
 * separate PNG fallback needed.
 *
 * Note on the JS fallback chain: src/plugins-window/icon-fallback.ts
 * only walks SVG → 256.png → 128.png when the URL matches the
 * ps.w.org/<slug>/assets/icon.svg shape. Custom URLs get one shot and
 * then resolve to the dashicons-admin-plugins placeholder. Our SVG is
 * served from the same WP origin as the admin UI, so CSP + mixed-content
 * checks pass and the single shot is enough.
 *
 * Verified against WordPress/desktop-mode
 * (includes/plugins-window/rest-fields.php trunk @ 2026-05-18).
 * Post-#475 OpenStation renames this to `openstation_plugins_window_icon_url`
 * (includes/plugins-window/rest-fields.php:465) — dual-registered via
 * snt_os_compat_add_filter(), idempotent (returns the same canonical URL for
 * the same $slug every call), no double-fire guard needed.
 */
snt_os_compat_add_filter( 'desktop_mode_plugins_window_icon_url', 'openstation_plugins_window_icon_url', function( $url, $slug ) {
	if ( defined( 'SN_GH_PLUGIN_SLUG' ) && SN_GH_PLUGIN_SLUG === $slug ) {
		return plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	}
	return $url;
}, 10, 2 );

/**
 * Decode HTML entities in our plugin's Name on the REST response.
 *
 * Desktop Mode's installed Plugins view calls Core's REST endpoint
 *   GET /wp/v2/plugins?context=view
 * which runs WP_REST_Plugins_Controller::prepare_item_for_response()
 * (wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php
 * lines 578-620). That method calls _get_plugin_data_markup_translate()
 * which unconditionally `wp_kses`'s the Name header (plugin.php line 188)
 * even when called with $markup=false — so the JSON response always
 * carries the entity-encoded form `"name": "Signal &amp; Noise Tools"`.
 *
 * Desktop Mode's frontend then sets `title.textContent = row.name`
 * (src/plugins-window/installed-view.ts + installed-detail.ts), and
 * textContent renders entities literally. The Browse view at card.ts
 * decodes via decodeEntities() — Installed/Detail views forgot to.
 *
 * v2.1.3 attempted this fix via the `all_plugins` filter — wrong layer:
 * `all_plugins` only fires from wp-admin/plugins.php's UI layer, NOT
 * from the REST controller. The REST controller is the ONLY data path
 * Desktop Mode uses for the Installed view.
 *
 * Correct layer: `rest_prepare_plugin` at line 619 of the REST
 * controller, the last writable layer before JSON serialization.
 * Scoped strictly to SN_GH_PLUGIN_BASENAME ($item['_file']) so other
 * plugins' Name strings are never touched.
 *
 * Verified against WordPress/WordPress @ tag 6.9.4:
 *   wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php
 *   lines 578-620 + wp-admin/includes/plugin.php line 188.
 *
 * @since 2.1.6 (supersedes the all_plugins approach from v2.1.3)
 */
add_filter( 'rest_prepare_plugin', function( $response, $item, $request ) {
	if ( ! defined( 'SN_GH_PLUGIN_BASENAME' ) ) {
		return $response;
	}
	if ( ! is_array( $item ) || empty( $item['_file'] ) || SN_GH_PLUGIN_BASENAME !== $item['_file'] ) {
		return $response;
	}

	$data = $response->get_data();
	$dirty = false;

	// Decode the Name field — primary fix.
	if ( isset( $data['name'] ) && false !== strpos( $data['name'], '&amp;' ) ) {
		$data['name'] = html_entity_decode( $data['name'], ENT_QUOTES, 'UTF-8' );
		$dirty = true;
	}

	// Author field also runs through wp_kses in the same function.
	if ( isset( $data['author'] ) && false !== strpos( $data['author'], '&amp;' ) ) {
		$data['author'] = html_entity_decode( $data['author'], ENT_QUOTES, 'UTF-8' );
		$dirty = true;
	}

	// Icon URL — ALWAYS override, never just-when-empty (v2.1.7 fix).
	// Desktop Mode's get_callback may have already populated
	// desktop_mode_icon_url with the ps.w.org URL that 404s for self-
	// hosted plugins; in that case the field is non-empty but wrong, and
	// an `if ( empty(...) )` guard lets it pass through. Self-hosted
	// plugins know their own canonical icon URL — overwrite
	// unconditionally for our basename. Safe scope: gated on
	// $item['_file'] === SN_GH_PLUGIN_BASENAME at the top of this filter.
	//
	// v10.43.0 REJECT #11 LOW: dual-write BOTH REST field keys. Post-#475
	// OpenStation renames the field ITSELF from 'desktop_mode_icon_url' to
	// 'openstation_icon_url' (rest-fields.php) — a different seam from the
	// 'desktop_mode_plugins_window_icon_url' FILTER dual-registered above,
	// which supplies the field's VALUE via get_callback but cannot rename
	// the JSON KEY the response actually carries. Writing only the old key
	// left this belt's "ALWAYS override" promise doing nothing on a
	// post-#475 response, which carries the new key instead. Exactly one
	// key is ever present per install; writing both is a no-op for the
	// absent one.
	$canonical_icon_url = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	if ( ! isset( $data['desktop_mode_icon_url'] ) || $data['desktop_mode_icon_url'] !== $canonical_icon_url ) {
		$data['desktop_mode_icon_url'] = $canonical_icon_url;
		$dirty = true;
	}
	if ( ! isset( $data['openstation_icon_url'] ) || $data['openstation_icon_url'] !== $canonical_icon_url ) {
		$data['openstation_icon_url'] = $canonical_icon_url;
		$dirty = true;
	}

	if ( $dirty ) {
		$response->set_data( $data );
	}
	return $response;
}, 10, 3 );

/**
 * Inline DOM patch — REMOVED. Both halves proved unreachable behind an
 * OPEN SHADOW ROOT; kept as history, not as a "future work" TODO.
 *
 * v2.1.7 shipped an `admin_print_footer_scripts` script that ran a
 * `document.body`-scoped `querySelectorAll()` (+ a `document.body`-scoped
 * `MutationObserver`) to (1) hide a dead "View on WordPress.org" button and
 * (2) defensively re-decode the plugin Name if it ever resurfaced HTML-
 * entity-encoded in the DOM. v10.43.0 (f2faa4b) "fixed" half (1)'s selector
 * from a dead `a[href*="wordpress.org…"]` to `wpd-button, os-button` — still
 * dead, and adversarial review (REJECT #12) proved why: the Installed-view
 * detail panel — button, Name cell, everything — is appended into an OPEN
 * shadow root (`attachShadow({mode:'open'})`, WordPress/openstation
 * `src/ui/core/component.ts:88`) by `wpd-table.ts:1404-1433`. Upstream's own
 * `installed-detail.ts:63-69` documents that document-level DOM access does
 * not pierce this boundary. A query rooted at `document.body` cannot
 * traverse into shadow content, full stop — the tag name it queried for was
 * never the bug. And a `MutationObserver` attached to `document.body` never
 * observes mutations that happen INSIDE a shadow root either, so replacing
 * the selector could never have worked no matter how it was spelled.
 *
 * Half (2), the Name-decode, targeted the exact same table — same shadow
 * root, same unreachability — so it was never the working fix either. The
 * WIRE-level decode, `rest_prepare_plugin` above, always was: it edits the
 * REST payload before Desktop Mode ever renders it, so there is nothing left
 * for a DOM patch to defend. With half (1) gone, `patch()` had no reachable
 * target left in this codebase — nothing else called it — so it is removed
 * wholesale along with the `MutationObserver`, rather than kept running
 * against text nodes it can never see.
 *
 * Reaching either target FOR REAL would need: a shadow-root hop at every
 * custom-element boundary the panel nests through (`el.shadowRoot &&
 * el.shadowRoot.querySelectorAll(...)`, recursively — there is no "pierce
 * all shadow roots" selector); a `MutationObserver` attached to EACH shadow
 * root individually, since one at `document.body` cannot see across the
 * boundary; and re-scoping the button's label match to ONLY this plugin's
 * own detail panel — `installed-detail.ts:333` renders the identical
 * "WordPress.org" label for every wp.org-hosted plugin's legitimate link, so
 * a bare label match, if it could somehow reach in, would hide those too.
 * None of that scaffolding exists today. Building it is future work, not
 * this fix.
 *
 * The 404 itself is accepted as cosmetic: it is upstream's own fallback
 * behavior for a self-hosted, non-wp.org-listed plugin (see the
 * `rest_prepare_plugin` filter's docblock above for why our own icon-url
 * override defeats upstream's empty-icon guard), reachable only from inside
 * this plugin's own detail panel, and no document-scoped patch — this one or
 * any future one — was ever going to hide it without the shadow-root
 * scaffolding described above. Filed upstream rather than left as folklore:
 * WordPress/openstation#492 tracks the missing-slug guard fix; if upstream
 * ships it, the 404 (and this whole accepted-cosmetic note) disappears on
 * its own, no plugin-side work required.
 *
 * @since 2.1.6 wp_enqueue_script approach.
 * @since 2.1.7 superseded by the inline admin_footer version (this comment).
 * @since 10.43.0 (f2faa4b) button-hider selector "fixed" — still dead.
 * @since 10.43.1 removed. REJECT #12: both halves unreachable behind an open
 *                shadow root; see this docblock and CHANGELOG.md.
 */
/* ─────────────────────────────────────────────────────────────────────
 * v9.52.0 — analytics widget data layer
 *
 * The widgets need three shapes of data.
 * The split follows the plugin-wide "keep it off the request path"
 * discipline:
 *
 *   - CHEAP + DURABLE (health scan, uptime last-good) → localized into
 *     window.snDesktopData. Each is one non-autoloaded option read, and
 *     the payload rides a <script> tag we already emit.
 *   - The 14-DAY VIEW SERIES → REST, fetched on render. It is NOT
 *     expensive in the Analytics-Engine sense (inc/analytics-read.php
 *     reads the durable wp_sn_analytics_daily rollup table via $wpdb;
 *     the AE path is sn_analytics_query() in inc/analytics-api.php and
 *     is never touched here). It stays out of the localize because it
 *     costs TWO aggregate SQL queries — a SUM over the window and a
 *     GROUP BY series — and localizing would spend them on EVERY
 *     wp-admin page load, for a widget the user may not have enabled.
 *     Fetch-on-render spends them only when a widget actually mounts.
 * ───────────────────────────────────────────────────────────────────── */

/**
 * Percentage change between two windows.
 *
 * Returns null rather than INF/NAN when the prior window is zero: there is
 * no meaningful "percent up from nothing", and a JSON-encoded INF is not
 * valid JSON. The widget renders a bare total when delta_pct is null.
 *
 * @param int|float $current Current-window total.
 * @param int|float $prior   Prior-window total.
 * @return float|null Signed percentage, or null when incomputable.
 */
function snt_desktop_delta_pct( $current, $prior ) {
	if ( ! is_numeric( $current ) || ! is_numeric( $prior ) || (float) $prior === 0.0 ) {
		return null;
	}
	return round( ( ( (float) $current - (float) $prior ) / (float) $prior ) * 100, 1 );
}

/**
 * Content-health summary for the localize payload.
 *
 * DERIVES pass/fail through the existing single-source-of-truth helpers in
 * inc/health-summary.php rather than re-deriving it here. A scan's `checks`
 * is a MAP of key => { count, findings, label, fix_hint } — there is no
 * "passed" flag in the model; "passed" means a check with zero findings, and
 * advisory-tier checks (external_links, link_opportunities) carry findings by
 * nature and must never read as failures. sn_health_flagged_checks() already
 * encodes both rules, so use it.
 *
 * NULL when no scan has ever run — deliberately not a synthetic "0/0 passed",
 * which would render as a green all-clear and tell the owner the opposite of
 * the truth. (Same silent-wrong-answer class as the v10.42.2 reading-time
 * "5 min" fallback.)
 *
 * @return array{passed:int,total:int,all_passed:bool,scanned_at:int}|null
 */
function snt_health_summary_for_localize() {
	if ( ! function_exists( 'sn_health_last_scan' ) || ! function_exists( 'sn_health_check_total' ) || ! function_exists( 'sn_health_flagged_checks' ) ) {
		return null;
	}
	$scan = sn_health_last_scan();
	if ( ! is_array( $scan ) || empty( $scan['checks'] ) || ! is_array( $scan['checks'] ) ) {
		return null;
	}

	$total       = sn_health_check_total( $scan );
	$flagged_map = sn_health_flagged_checks( $scan );
	$flagged_n   = count( $flagged_map );
	$passed      = max( 0, $total - $flagged_n );

	// v9.53.0: WHICH checks failed, not just how many. sn_health_flagged_checks()
	// already returns them count-desc and already excludes the advisory tier, so
	// reuse its ranking rather than re-deriving one. Cap at 4: the card is a
	// glance, and "+N more" is honest about the tail without pretending the
	// widget is the Health tab.
	$flagged = array();
	foreach ( $flagged_map as $key => $check ) {
		$flagged[] = array(
			'key'   => (string) $key,
			'label' => (string) ( $check['label'] ?? $key ),
			'count' => (int) ( $check['count'] ?? 0 ),
		);
	}
	$shown = array_slice( $flagged, 0, 4 );

	return array(
		'passed'         => $passed,
		'total'          => $total,
		'all_passed'     => 0 === $flagged_n,
		// sn_health_run_scan() stores scanned_at as time() — an INT timestamp.
		'scanned_at'     => (int) ( $scan['scanned_at'] ?? 0 ),
		'flagged'        => $shown,
		'flagged_more'   => max( 0, count( $flagged ) - count( $shown ) ),
		// Advisories are reported SEPARATELY and never as faults: external_links
		// and link_opportunities carry findings by nature (see
		// sn_health_advisory_checks()), so folding them into the fault total
		// would render a healthy site as permanently alarming.
		'findings_total' => function_exists( 'sn_health_finding_total' ) ? (int) sn_health_finding_total( $scan ) : 0,
		'advisory_total' => function_exists( 'sn_health_advisory_total' ) ? (int) sn_health_advisory_total( $scan ) : 0,
	);
}

/**
 * The 14-day view series behind the Site Views + Pulse widgets.
 *
 * Transient-cached for 15 minutes: several widgets can mount in the same
 * shell and each calls this endpoint once, and the underlying rollup only
 * changes when the rollup cron runs.
 *
 * Fail-soft shape: an empty rollup (fresh install, table not yet created,
 * or simply no traffic in the window) returns days:[] / total:0 /
 * delta_pct:null — an honest empty state, never a warning or a hang.
 *
 * @return WP_REST_Response
 */
function snt_desktop_site_views_payload() {
	$today = substr( (string) current_time( 'mysql' ), 0, 10 );
	$from  = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );

	// Date-stamped key: a flat key cached at 23:58 would keep serving the
	// PREVIOUS day's 14-day window for up to 15 minutes after local midnight.
	// Stamping the local day makes the rollover exact and self-expiring.
	$cache_key = 'sn_desktop_site_views_' . $today;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return new WP_REST_Response( $cached, 200 );
	}

	// v9.53.0 — THE FIT WINDOW. The forecast engine suppresses below
	// SN_ANALYTICS_FORECAST_MIN_POINTS (21), so fitting on the 14-day DISPLAY
	// window would return null every single time and the forecast would never
	// once render. sn_analytics_signal_forecasts() already solves this the same
	// way: "trailing fit history ending $to, decoupled from the display range".
	// So fetch ONE longer series and slice it — the last 14 days draw the
	// sparkline, the whole 60 feed the fit. One query, not two.
	$fit_from = gmdate( 'Y-m-d', strtotime( $today . ' -59 days' ) );

	$fit_series = array();
	if ( function_exists( 'sn_analytics_daily_series' ) ) {
		$raw = sn_analytics_daily_series( $fit_from, $today, 'human', 'day' );
		if ( is_array( $raw ) ) {
			$fit_series = $raw;
		}
	}

	// The sparkline shows only the display window.
	$days = array();
	foreach ( $fit_series as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( '' !== $day && $day >= $from ) {
			$days[] = array(
				'date'  => $day,
				'views' => (int) ( $row['views'] ?? 0 ),
			);
		}
	}

	$total     = 0;
	$visits    = 0;
	$delta_pct = null;
	if ( function_exists( 'sn_analytics_range_totals' ) ) {
		$this_window = sn_analytics_range_totals( $from, $today, 'human' );
		$total       = (int) ( $this_window['views'] ?? 0 );
		$visits      = (int) ( $this_window['visits'] ?? 0 );

		// Prior 14-day window, for the week-over-week style delta.
		$prior_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$prior_from = gmdate( 'Y-m-d', strtotime( $from . ' -14 days' ) );
		$prior      = sn_analytics_range_totals( $prior_from, $prior_to, 'human' );
		$delta_pct  = snt_desktop_delta_pct( $total, (int) ( $prior['views'] ?? 0 ) );
	}

	// Bot share across the DISPLAY window, weighted by volume — a plain average
	// of daily bot_pct would let a 3-view day count as much as a 300-view one.
	// null (not 0) when there is nothing to divide by: "no data" is not "0% bots".
	$bot_pct = null;
	if ( function_exists( 'sn_analytics_class_series' ) ) {
		$classes = sn_analytics_class_series( $from, $today, 'day' );
		if ( is_array( $classes ) && $classes ) {
			$tot = 0;
			$bot = 0;
			foreach ( $classes as $row ) {
				$tot += (int) ( $row['total'] ?? 0 );
				$bot += (int) ( $row['bot'] ?? 0 );
			}
			if ( $tot > 0 ) {
				$bot_pct = (int) round( ( $bot / $tot ) * 100 );
			}
		}
	}

	$top_path = null;
	if ( function_exists( 'sn_analytics_top_paths' ) ) {
		$top = sn_analytics_top_paths( $from, $today, 'human', 1 );
		if ( is_array( $top ) && isset( $top[0]['path'] ) ) {
			$top_path = array(
				'path'  => (string) $top[0]['path'],
				'views' => (int) ( $top[0]['views'] ?? 0 ),
			);
		}
	}

	// v9.57.0: top sources — the one thing the desktop had NO surface for. The
	// retired sn-analytics-hud existed largely to show this; the rest of what it
	// showed (views/visits) this tile already covered, better. Three rows, not
	// five: a tile is a glance, and the full list is one click away on the
	// analytics page.
	//
	// Row shape is the accessor's OWN: `value` / `views` / `visits` / `hosts`
	// (inc/analytics-sources.php), sorted by views DESC. NOT `source` — that was
	// an invented key that cost a release (v9.56.0). We surface `value` + `visits`
	// to sit beside the tile's existing visits framing.
	$top_sources = array();
	if ( function_exists( 'sn_analytics_top_sources' ) ) {
		$srcs = sn_analytics_top_sources( $from, $today, 'human', 3 );
		if ( is_array( $srcs ) ) {
			foreach ( $srcs as $src ) {
				if ( ! is_array( $src ) || ! isset( $src['value'] ) ) {
					continue;
				}
				$top_sources[] = array(
					'value'  => (string) $src['value'],
					'visits' => (int) ( $src['visits'] ?? 0 ),
				);
			}
		}
	}

	// The forecast, or nothing. sn_analytics_forecast_of() already encodes the
	// honesty gates — under 21 points → null, zero median level → null, and
	// `confidence` is the backtest's MEASURED interval coverage, not a vibe.
	// We pass its verdict through UNCHANGED and never synthesise a fallback:
	// a point without an interval, or a number invented from thin history, is
	// the dishonest version of this feature.
	$forecast = null;
	if ( function_exists( 'sn_analytics_forecast_of' ) && $fit_series ) {
		$signal = sn_analytics_forecast_of( 'site_views', 'Site views', $fit_series, $fit_from, $today );
		if ( is_array( $signal ) && isset( $signal['value'], $signal['interval']['low'], $signal['interval']['high'] ) ) {
			$forecast = array(
				'value'      => $signal['value'],
				'interval'   => array(
					'low'  => $signal['interval']['low'],
					'high' => $signal['interval']['high'],
				),
				'confidence' => (string) ( $signal['confidence'] ?? 'low' ),
				'direction'  => (string) ( $signal['direction'] ?? 'flat' ),
				// Mirrors the engine's SN_ANALYTICS_FORECAST_HORIZON default, which
				// is what forecast_of() used above (we pass no $opts override).
				'horizon'    => 7,
			);
		}
	}

	$payload = array(
		'days'        => $days,
		'total'       => $total,
		'visits'      => $visits,
		'delta_pct'   => $delta_pct,
		'bot_pct'     => $bot_pct,
		'top_path'    => $top_path,
		'top_sources' => $top_sources,
		'forecast'    => $forecast,
	);

	set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
	return new WP_REST_Response( $payload, 200 );
}

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/desktop/site-views', array(
		'methods'             => 'GET',
		'callback'            => 'snt_desktop_site_views_payload',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );

	register_rest_route( 'signal-noise/v1', '/desktop/machine-readers', array(
		'methods'             => 'GET',
		'callback'            => 'snt_desktop_machine_readers_payload',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
} );

/**
 * Living-tree traffic.
 *
 * desktop-mode's wallpaper renders a tree whose wind responds to site
 * traffic, and its docs invite analytics plugins to supply the real
 * number via this filter. Feed it our 14-day first-party human view
 * total so the desktop breathes with the actual site.
 *
 * Cast to int: desktop-mode types the filtered value as int.
 *
 * Post-#475 OpenStation renames this to `openstation_living_tree_traffic`
 * (includes/living-tree/helpers.php:91) — dual-registered via
 * snt_os_compat_add_filter(), idempotent (pure function of the current
 * analytics totals), no double-fire guard needed.
 */
snt_os_compat_add_filter( 'desktop_mode_living_tree_traffic', 'openstation_living_tree_traffic', function( $views ) {
	if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
		return (int) $views;
	}
	$today  = substr( (string) current_time( 'mysql' ), 0, 10 );
	$from   = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );
	$totals = sn_analytics_range_totals( $from, $today, 'human' );
	return (int) ( $totals['views'] ?? $views );
} );

/**
 * v9.52.5 — Repair the AI Copilot's tool schemas at the boundary.
 *
 * THE BUG (owner-reported, live): clicking Ask AI returned
 *
 *     Bad Request (400) - tools.12.custom.input_schema.type: Input should be 'object'
 *
 * and the Copilot was DEAD — not degraded. One malformed tool fails the whole
 * request, so every SN ability took the assistant down with it.
 *
 * CAUSE. desktop-mode 0.9.4 made the Copilot's tools WordPress Abilities and
 * offers EVERY read-only ability on the site as a tool automatically — no
 * opt-in (includes/ai-copilot/abilities.php). Its converter then passes the
 * ability's input_schema through RAW as the tool's `parameters`
 * (includes/ai-copilot/search.php:743). Our abilities deliberately declare a
 * ['object','null'] union — that IS their GET/null run-path — and Anthropic
 * requires input_schema.type to be the literal string "object".
 * desktop-mode's own abilities all use a plain 'object', so their Copilot
 * never trips over it; only a third-party union-typed ability breaks it. We
 * were enrolled into a contract we never agreed to, and only a live click
 * surfaced it.
 *
 * WHY NOT "JUST FIX THE SCHEMAS". The union is load-bearing: MCP and the REST
 * GET path rely on being callable with null input. The schemas are correct;
 * the BOUNDARY was missing.
 *
 * THE FIX. We already own the normalizer — sn_mcp_normalize_schema()
 * (inc/mcp/mcp-tools.php), which does exactly this for our own MCP door and
 * whose comment predicted this error verbatim: "strict MCP hosts (e.g. the
 * Anthropic tool-schema validator that a client forwards to) reject". It was
 * simply never wired into a path nobody knew existed. desktop_mode_ai_tools
 * exists to "transform the full tool list just before it goes to the provider"
 * — the right seam.
 *
 * Applied to EVERY tool, not just ours: a top-level union is always invalid for
 * the provider, so this is strictly a repair, and it keeps Ask AI alive if
 * another plugin registers the same shape. Nested property unions are left
 * alone — the provider only constrains the top level, and rewriting them would
 * silently narrow an ability's real contract.
 *
 * Upstream: desktop-mode's converter arguably ought to normalize here itself;
 * any plugin with a union-typed read-only ability kills its Copilot.
 *
 * Post-#475 OpenStation renames this to `openstation_ai_tools`
 * (includes/ai-copilot/search.php:1124 — the real signature there is
 * `apply_filters( 'openstation_ai_tools', $tools, $context )`, a second
 * $context arg this callback never declared and doesn't need) —
 * dual-registered via snt_os_compat_add_filter(), idempotent (the
 * normalizer + prune both compute the same output from the same input every
 * call — sn_mcp_normalize_schema() is proven idempotent below in tests), no
 * double-fire guard needed.
 */
snt_os_compat_add_filter( 'desktop_mode_ai_tools', 'openstation_ai_tools', function( $tools ) {
	if ( ! is_array( $tools ) || ! function_exists( 'sn_mcp_normalize_schema' ) ) {
		return $tools;
	}

	// v9.59.0: PRUNE before normalize. Every read-only ability is auto-enrolled
	// as a Copilot tool with no opt-in, and its name + description + input_schema
	// is serialized into EVERY Ask AI turn, before the user's question is read —
	// rent paid forever, invoked or not. snt_dm_ai_pruned_abilities() lists the
	// ones that cannot earn it (see that function). Pruning removes a tool from
	// the COPILOT LIST ONLY; the ability stays registered, REST/MCP-callable and
	// usable by the UI + the scan→suggest→apply pipeline. Reversible in one line.
	//
	// MATCH ON THE STRIPPED NAME. desktop-mode strips the namespace and
	// underscores the slug (signal-noise/export-audit-log → export_audit_log) via
	// desktop_mode_ai_ability_tool_name() BEFORE this filter sees the tool. We
	// call that same function to compute our targets, so our match can never drift
	// from desktop-mode's transform; if it is unavailable we skip pruning rather
	// than guess. Caveat, documented in snt_dm_ai_pruned_abilities(): the
	// namespace is gone at this seam, so a third-party tool with an identical
	// stripped name would also drop — the names are SN-specific and the risk is
	// theoretical, and there is no namespaced seam to prune at instead.
	if ( function_exists( 'openstation_ai_ability_tool_name' ) || function_exists( 'desktop_mode_ai_ability_tool_name' ) ) {
		$prune = array();
		foreach ( snt_dm_ai_pruned_abilities() as $ability ) {
			$prune[ (string) snt_os_ai_ability_tool_name( $ability ) ] = true;
		}
		if ( $prune ) {
			$tools = array_values( array_filter( $tools, static function ( $tool ) use ( $prune ) {
				$name = ( is_array( $tool ) && isset( $tool['name'] ) ) ? (string) $tool['name'] : '';
				return '' === $name || ! isset( $prune[ $name ] );
			} ) );
		}
	}

	foreach ( $tools as $i => $tool ) {
		// Skip anything without an array `parameters` — never fabricate a schema
		// for a tool that declares none. (This once claimed "command tools carry
		// no parameters". They do: search.php builds every command tool a full
		// object schema with a required `args` string. They're conformant already,
		// so normalizing them is a no-op — but the stated reason was wrong, and a
		// wrong reason is how the next person justifies the next skip.)
		if ( ! is_array( $tool ) || ! isset( $tool['parameters'] ) || ! is_array( $tool['parameters'] ) ) {
			continue;
		}
		// v9.53.1 — NO SKIP. Normalize unconditionally.
		//
		// There used to be an "already conformant, touch nothing" guard here. It
		// was the bug, twice. It asked "is this one of the wrong shapes I know
		// about?" and skipped everything else — so each shape we hadn't met yet
		// sailed through untouched and the provider's 400 simply moved to the
		// next tool:
		//
		//   tools.12 …type: Input should be 'object'                    (v9.52.5)
		//   tools.29 …does not support oneOf/allOf/anyOf at the top level (v9.53.0)
		//   tools.30 …properties: Input should be an object              (v9.53.1)
		//
		// Each time, the normalizer already handled the shape — it just never
		// ran, because the guard had judged the schema fine by the only criteria
		// it knew. Enumerating what's broken cannot work: the list of
		// unsupported constructs belongs to the provider, not to us, and we
		// learn it one 400 at a time.
		//
		// So: always normalize. sn_mcp_normalize_schema() is idempotent — a
		// conformant schema goes in and comes out identical — and it costs a few
		// array ops on a payload we already build per request. That is cheaper
		// than being wrong a fourth time.
		//
		// v9.53.2 — the same lesson, one level up: normalizing unconditionally
		// only helps for tools we SEE. At the default priority 10 we saw only the
		// tools that existed at priority 10, and this filter's own docblock
		// invites others to inject tools ("injecting synthetic command tools").
		// Anything hooked later landed downstream of us. Hence PHP_INT_MAX below:
		// we cannot enumerate who else hooks this or when, so we simply run last.
		$tools[ $i ]['parameters'] = sn_mcp_normalize_schema( $tool['parameters'] );
	}

	return $tools;
}, PHP_INT_MAX );

/**
 * Abilities dropped from the Copilot's per-turn tool list (v9.59.0).
 *
 * These three are read-only (so desktop-mode auto-enrols them as Copilot tools),
 * but a conversational turn can never use them:
 *   - signal-noise/pattern-adoption-suggest and signal-noise/block-migrations-suggest
 *     each require a scan-generated block FINGERPRINT as input. The model cannot
 *     produce a valid fingerprint from natural language, so it can never call them
 *     correctly — they are pure per-turn rent.
 *   - signal-noise/export-audit-log is an export/download action (a CSV/JSON blob).
 *     A chat turn should not emit a download, and signal-noise/get-audit-log already
 *     answers the readable "what's in the audit log" question — so it is redundant
 *     for the Copilot and kept only for the wp-admin export button.
 *
 * Pruning removes them from the COPILOT's list only. Every one stays registered,
 * REST-callable, MCP-exposed, and driven by the wp-admin UI + the
 * scan→suggest→apply pipeline. To restore one, delete its line.
 *
 * OURS ONLY, with a seam caveat: the caller matches on the STRIPPED tool name
 * desktop-mode produces, because the namespace is gone before any plugin can
 * filter the tool list (desktop_mode_ai_search_ability_names() exposes no filter).
 * A third-party ability whose slug stripped to one of these exact names would also
 * be dropped. The names are SN-specific and the risk is theoretical; this is the
 * best available match point.
 *
 * @since 9.59.0
 * @return string[] Full SN ability names to drop from the Copilot tool list.
 */
function snt_dm_ai_pruned_abilities() {
	return array(
		'signal-noise/pattern-adoption-suggest',
		'signal-noise/export-audit-log',
		'signal-noise/block-migrations-suggest',
	);
}

/**
 * Teach the Copilot the analytics vocabulary its own tools return but never
 * define (v9.59.0).
 *
 * desktop_mode_ai_system_prompt_appendix is Stable and STACKED across plugins
 * (desktop-mode concatenates every plugin's appendix), so we append and keep to
 * OUR nouns — the exact terms get-analytics-summary / get-analytics-events emit.
 * EVERY WORD IS RENT: it ships on every Ask AI turn, forever, so there is no
 * brand voice and no history here, only the definitions the tool outputs need. A
 * test caps it at 600 chars.
 *
 * The definitions are the REAL ones (inc/analytics-rollup.php:34-42):
 *   - visits = count(DISTINCT visitor-day hash) — approximate unique visitors,
 *     NOT sessions. Telling the model "visits = sessions" would make it
 *     confidently wrong, so it is stated explicitly.
 *   - time_avg is mean dwell in MILLISECONDS; scroll_avg is a 0-100 percent.
 *   - views is sample-corrected, visits is a raw distinct count, so their ratio
 *     is an estimate.
 *
 * Post-#475 OpenStation renames this to `openstation_ai_system_prompt_appendix`
 * (includes/ai-copilot/search.php:1594) — dual-registered via
 * snt_os_compat_add_filter(). NOT treated as a bare idempotent transform: the
 * real callsite fires this filter TWICE per real request in the ordinary
 * case (the primary /ai/search run AND the follow-up composed-reply leg,
 * each starting from a FRESH $appendix), and blind dual-registration would
 * risk our vocabulary text landing twice in a hypothetical future where both
 * hook names fire for the SAME event. The marker check below makes the
 * append itself idempotent by CONTENT rather than by a once-per-request
 * flag — a flag would incorrectly suppress the second legitimate call.
 *
 * v10.43.0 REJECT #11 LOW: registered with accepted_args=2. The real
 * post-#475 call site passes a 2nd arg — search.php:1594's
 * apply_filters( 'openstation_ai_system_prompt_appendix', '', $ctx_for_filter ) —
 * that this callback doesn't use today. Cheap future-proofing: if the
 * vocabulary text ever needs to branch on $ctx_for_filter, that only means
 * widening the closure's signature, not touching the registration.
 *
 * @since 9.59.0
 */
snt_os_compat_add_filter( 'desktop_mode_ai_system_prompt_appendix', 'openstation_ai_system_prompt_appendix', function ( $appendix ) {
	$appendix = (string) $appendix;
	$marker   = 'Signal & Noise analytics vocabulary.';
	if ( false !== strpos( $appendix, $marker ) ) {
		return $appendix; // Already present — avoid compounding under a hypothetical double-fire.
	}
	return trim( $appendix . "\n" . implode( ' ', array(
		$marker,
		'Traffic is classed human, suspect, or bot; every reported figure is human-only unless a class is named.',
		'"views" is sample-corrected pageviews; "visits" is approximate unique visitors (visitor-day hashes), NOT sessions — treat views and visits as estimates, not an exact ratio.',
		'scroll_avg is mean scroll depth (0-100%); time_avg is mean dwell time in MILLISECONDS.',
		'A null metric means never measured, not zero; a real zero is reported as 0.',
	) ) );
}, 10, 2 );

/**
 * Payload for the SN Machine Readers tile (v10.1.0).
 *
 * Shapes the same aggregates the Machine Readers tab renders into the small
 * set a glance needs: total, the top three families, declared AI-training
 * reads (and how many of those touched the rights files), plus the sensor's
 * version and crawler-list verdict so the reader knows whether to trust the
 * numbers. A failed/unconfigured read returns ok:false with the reason — the
 * tile says so rather than painting a zero, because "no data" is not "no
 * crawlers".
 *
 * @return array
 */
function snt_desktop_machine_readers_payload() {
	// v10.2.0: delegates to the ONE builder (inc/machine-readers-api.php) that
	// the get-machine-readers-summary ability also calls, so the tile and the
	// ability can never drift.
	if ( ! function_exists( 'snt_mr_summary_payload' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable', 'days' => 30 );
	}
	return snt_mr_summary_payload( 30 );
}
