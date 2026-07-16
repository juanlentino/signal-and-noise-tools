<?php
/**
 * Signal & Noise Tools — WordPress/desktop-mode integration.
 *
 * Makes the SN plugin a first-class participant in desktop-mode (when
 * installed + active). Adds:
 *
 *   1. A dock icon "Signal & Noise" with a submenu of all 8 SN settings
 *      tabs (via the desktop_mode_dock_items filter).
 *   2. Two desktop icons (Dashboard + Identity — the most-frequent
 *      surfaces) via desktop_mode_register_icon().
 *   3. Twenty-nine Cmd+K command-palette commands via
 *      desktop_mode_register_command() — 4 maintenance actions (Abilities
 *      run-path), 6 navigation shortcuts, 2 version/info, 2 cron, 1
 *      insights, 2 audit-log, and 12 display-only theme-ability launchers.
 *   4. Six desktop widgets via desktop_mode_register_widget():
 *      SN Deploy Status, SN Quick Actions, SN RSS Subscribers, and (v9.52.0)
 *      SN Pulse, SN Site Views, SN Health.
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
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
		return;
	}

	wp_register_script(
		'sn-desktop-mode',
		plugins_url( 'assets/desktop-mode.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget',
		plugins_url( 'assets/desktop-mode-widget.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	// v2.1.0: two new widget scripts — Quick Actions + RSS Subscribers.
	wp_register_script(
		'sn-desktop-mode-widget-actions',
		plugins_url( 'assets/desktop-mode-widget-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget-rss',
		plugins_url( 'assets/desktop-mode-widget-rss.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'snt-ability-run' ),
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
	foreach ( array( 'views', 'pulse', 'health' ) as $sn_widget ) {
		wp_register_script(
			'sn-desktop-mode-widget-' . $sn_widget,
			plugins_url( 'assets/desktop-mode-widget-' . $sn_widget . '.js', SNT_PATH . 'signal-and-noise-tools.php' ),
			array( 'wp-api-fetch', 'sn-desktop-mode' ),
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
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
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
		'uptimeSummary' => $sn_is_owner ? snt_uptime_summary_for_localize() : null,
		'healthSummary' => $sn_is_owner ? snt_health_summary_for_localize() : null,
		'pages'         => array(
			'dashboard'    => admin_url( 'admin.php?page=sn-theme-options' ),
			'identity'     => admin_url( 'admin.php?page=sn-identity' ),
			'login'        => admin_url( 'admin.php?page=sn-login' ),
			'cloudflare'   => admin_url( 'admin.php?page=sn-cloudflare' ),
			'cron'         => admin_url( 'admin.php?page=sn-cron' ),
			'insights'     => admin_url( 'admin.php?page=sn-insights' ),
			'rss'          => admin_url( 'admin.php?page=sn-rss' ),
			'reading_time' => admin_url( 'admin.php?page=sn-reading-time' ),
			'analytics'    => admin_url( 'admin.php?page=sn-analytics' ),
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
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
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

		// ─── Theme-ability ⌘K launcher commands (12 total) ─────────────────
		// Pure launcher entries — slug/label/description/icon only. These
		// surface the theme's WP 7.0 abilities in the Command Palette for
		// discoverability. v3.8.0 wires real invocation via the
		// desktop_mode_register_ai_tool() server-side AI tool registry +
		// an Anthropic provider (desktop_mode_register_ai_provider()) so
		// the AI Copilot can dispatch them with structured arguments.
		//
		// Until v3.8.0 lands, these are display-only entries — clicking a
		// command does nothing beyond the desktop-mode default behavior
		// (no JS run() registered). That's intentional: the wrong UX
		// (sequential window.prompt() forms) is worse than no UX.
		array( 'slug' => 'sn-cmd-get-design-tokens',        'label' => 'SN: Show design tokens',          'description' => 'Theme palette + typography + spacing scale.',           'icon' => 'dashicons-art' ),
		array( 'slug' => 'sn-cmd-list-block-patterns',      'label' => 'SN: List block patterns',         'description' => 'All registered patterns with category + keywords.',     'icon' => 'dashicons-screenoptions' ),
		array( 'slug' => 'sn-cmd-get-template-structure',   'label' => 'SN: Inspect active template',     'description' => 'FSE block tree for the current page.',                  'icon' => 'dashicons-layout' ),
		array( 'slug' => 'sn-cmd-theme-version',            'label' => 'SN: Theme version info',          'description' => 'Theme + WP version + block-theme flags.',               'icon' => 'dashicons-info-outline' ),
		array( 'slug' => 'sn-cmd-page-notes-pillars',       'label' => 'SN: List /notes pillars',         'description' => 'Pillar essay metadata for the /notes catalog.',         'icon' => 'dashicons-book' ),
		array( 'slug' => 'sn-cmd-reading-time',             'label' => 'SN: Reading time for slug',       'description' => 'Computed minutes for a given post slug.',               'icon' => 'dashicons-clock' ),
		array( 'slug' => 'sn-cmd-design-summary',           'label' => 'SN: Design-system summary',       'description' => 'Formatted overview optimized for AI prompts.',          'icon' => 'dashicons-edit-page' ),
		array( 'slug' => 'sn-cmd-ai-page-note-summary',     'label' => 'SN: Generate page-note summary',  'description' => 'AI-summarize the current post in /notes catalog voice.', 'icon' => 'dashicons-text' ),
		array( 'slug' => 'sn-cmd-ai-suggest-pattern',       'label' => 'SN: Suggest block pattern',       'description' => 'AI recommends patterns for a draft.',                   'icon' => 'dashicons-screenoptions' ),
		array( 'slug' => 'sn-cmd-ai-brand-validate',        'label' => 'SN: Validate brand alignment',    'description' => 'AI checks if content fits SN voice + palette.',         'icon' => 'dashicons-yes-alt' ),
		array( 'slug' => 'sn-cmd-ai-pattern-content',       'label' => 'SN: Generate pattern content',    'description' => 'Fill a pattern with brand-voiced copy.',                'icon' => 'dashicons-format-aside' ),
		array( 'slug' => 'sn-cmd-ai-rewrite-voice',         'label' => 'SN: Rewrite in brand voice',      'description' => 'Transform external copy into SN voice.',                'icon' => 'dashicons-edit' ),
	);

	foreach ( $commands as $cmd ) {
		desktop_mode_register_command( array(
			'slug'        => $cmd['slug'],
			'label'       => $cmd['label'],
			'description' => $cmd['description'],
			'icon'        => $cmd['icon'],
			'script'      => 'sn-desktop-mode',
		) );
	}

	// ── Sub-block 3: register desktop widgets ──
	// Independent function_exists check — desktop-mode could theoretically
	// ship commands without widgets (defensive, mirrors the pre-v4.1.6 split).
	if ( function_exists( 'desktop_mode_register_widget' ) ) {
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
		// FALSE, so until now the cards were locked to the column. `movable`
		// makes desktop-mode render a thin chrome header (grip + label +
		// remove) and drag initiates ONLY from that chrome, so the buttons
		// inside SN Quick Actions stay clickable; `resizable` adds the 8 grip
		// handles. The column drives geometry while a card is docked — the
		// default_* sizes apply the first time a card floats, and the min_*
		// floor stops a drag collapsing one into an unreadable sliver.
		$sn_drag = array( 'movable' => true, 'resizable' => true );

		desktop_mode_register_widget( 'sn-pulse', array_merge( $sn_drag, array(
			'label'          => 'SN Pulse',
			'description'    => 'Views, uptime and content health in one tile.',
			'icon'           => 'dashicons-heart',
			'script'         => 'sn-desktop-mode-widget-pulse',
			'min_width'      => 220,
			'min_height'     => 120,
			'default_width'  => 300,
			'default_height' => 150,
		) ) );

		desktop_mode_register_widget( 'sn-site-views', array_merge( $sn_drag, array(
			'label'          => 'SN Site Views',
			'description'    => 'A 14-day first-party pageview sparkline.',
			'icon'           => 'dashicons-chart-area',
			'script'         => 'sn-desktop-mode-widget-views',
			// Taller floor than its siblings: the sparkline needs vertical room
			// before it reads as a trend rather than a smudge.
			'min_width'      => 240,
			'min_height'     => 170,
			'default_width'  => 320,
			'default_height' => 220,
		) ) );

		desktop_mode_register_widget( 'sn-deploy-status', array_merge( $sn_drag, array(
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
		desktop_mode_register_widget( 'sn-quick-actions', array_merge( $sn_drag, array(
			'label'          => 'SN Quick Actions',
			'description'    => 'One-click purge, clear overrides, force update-check.',
			'icon'           => 'dashicons-controls-repeat',
			'script'         => 'sn-desktop-mode-widget-actions',
			// Three stacked buttons + the toast slot.
			'min_width'      => 220,
			'min_height'     => 190,
			'default_width'  => 300,
			'default_height' => 240,
		) ) );

		// v2.1.0: RSS Subscribers widget — surfaces RSS feed activity that
		// was previously buried under S&N → RSS tab + a single line on the
		// SN Dashboard tab. At-a-glance subscriber growth on the desktop.
		desktop_mode_register_widget( 'sn-rss-subscribers', array_merge( $sn_drag, array(
			'label'          => 'SN RSS Subscribers',
			'description'    => 'Unique feed subscribers over 24h / 7d / 30d.',
			'icon'           => 'dashicons-rss',
			'script'         => 'sn-desktop-mode-widget-rss',
			'min_width'      => 220,
			'min_height'     => 150,
			'default_width'  => 300,
			'default_height' => 200,
		) ) );

		desktop_mode_register_widget( 'sn-health', array_merge( $sn_drag, array(
			'label'          => 'SN Health',
			'description'    => 'Content-health checks passing, and when last scanned.',
			'icon'           => 'dashicons-shield-alt',
			'script'         => 'sn-desktop-mode-widget-health',
			'min_width'      => 220,
			'min_height'     => 110,
			'default_width'  => 300,
			'default_height' => 150,
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
 *
 * Added in v2.0.1 (post-v1.15.0 desktop-mode bug fix).
 */
add_filter( 'desktop_mode_dock_placement', function( $placement, $menu_slug ) {
	if ( 'sn-theme-options' === $menu_slug ) {
		return 'hidden';
	}
	return $placement;
}, 10, 2 );

add_filter( 'desktop_mode_dock_items', function( $items ) {
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
add_action( 'init', function() {
	if ( ! function_exists( 'desktop_mode_register_icon' ) ) {
		return;
	}

	desktop_mode_register_icon( 'sn-icon-dashboard', array(
		'title' => 'SN Dashboard',
		'icon'  => 'dashicons-shield-alt',
		'url'   => admin_url( 'admin.php?page=sn-theme-options' ),
	) );

	desktop_mode_register_icon( 'sn-icon-identity', array(
		'title' => 'SN Identity',
		'icon'  => 'dashicons-id',
		'url'   => admin_url( 'admin.php?page=sn-identity' ),
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * COMMAND IMPLEMENTATIONS
 *
 * Each operation lives in a pure function returning array (success payload)
 * or WP_Error. Both the legacy REST handler AND the new abilities (v2.5.0+)
 * call these — single source of truth.
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

function snt_cmd_impl_purge_caches( $include_template_overrides = false ) {
	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => (bool) $include_template_overrides ) );
	return array(
		'ok'      => true,
		'message' => 'All caches purged.',
		'data'    => array( 'count' => $count ),
	);
}

function snt_cmd_impl_clear_overrides() {
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	return array(
		'ok'      => true,
		'message' => sprintf( '%d database override%s cleared.', $count, 1 === $count ? '' : 's' ),
		'data'    => array( 'count' => $count ),
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
 * REST ENDPOINTS — signal-noise/v1/cmd/*
 *
 * @deprecated since 2.5.0 — prefer the abilities REST surface at
 * /wp-abilities/v1/signal-noise/<ability>/run. These endpoints stay
 * wired for back-compat with the desktop-mode plugin's own command
 * palette which still calls /cmd/* directly. Each handler now delegates
 * to a snt_cmd_impl_* pure function shared with the new ability execute
 * callbacks (single source of truth).
 *
 * Response shape: { ok: bool, message: string, data?: object }
 * ════════════════════════════════════════════════════════════════════════ */

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
 */
add_filter( 'desktop_mode_plugins_window_icon_url', function( $url, $slug ) {
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
	$canonical_icon_url = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	if ( ! isset( $data['desktop_mode_icon_url'] ) || $data['desktop_mode_icon_url'] !== $canonical_icon_url ) {
		$data['desktop_mode_icon_url'] = $canonical_icon_url;
		$dirty = true;
	}

	if ( $dirty ) {
		$response->set_data( $data );
	}
	return $response;
}, 10, 3 );

/**
 * Inline DOM patch printed to admin_footer.
 *
 * Two upstream bugs in Desktop Mode trunk that have NO server-side filter:
 *
 *   1. "View on WordPress.org" button in the Installed-view detail panel
 *      ([installed-detail.ts:297-301]). Gated purely on `if (slug)` where
 *      slug = dirname(plugin_file) — non-empty for every installed plugin,
 *      so the button shows even for self-hosted plugins. Link 404s for us.
 *
 *   2. Belt + suspenders for the plugin Name. The rest_prepare_plugin
 *      filter above already decodes the Name on the wire, but if Desktop
 *      Mode ever caches a pre-fix response (service worker, in-memory),
 *      the literal `Signal &amp; Noise Tools` could resurface.
 *
 * Why admin_footer + inline instead of wp_enqueue_script:
 *   - wp_enqueue_script depends on Desktop Mode's script lifecycle. Their
 *     custom Plugins window may load JS in a different context than the
 *     standard admin enqueue chain (the v2.1.6 enqueue did not visibly
 *     fire — the button persisted post-install).
 *   - admin_footer prints into the raw <body>, so the script is in the
 *     DOM regardless of how Desktop Mode loads its frontend bundle.
 *   - Inline avoids the assets/ file altogether, dropping the 1 extra
 *     HTTP request + removing a moving part.
 *
 * The script is ~1.5KB minified, self-gates on `Signal &amp; Noise Tools`
 * presence + MutationObserver, and no-ops on any page where its target
 * nodes don't exist.
 *
 * @since 2.1.7 (supersedes the wp_enqueue_script approach from v2.1.6)
 */
add_action( 'admin_print_footer_scripts', function() {
	// Only fire when Desktop Mode is active — no point patching pages
	// it doesn't render.
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
		return;
	}
	?>
<script id="sn-desktop-mode-installed-view-patch">
(function(){
'use strict';
var SLUG = 'signal-and-noise-tools';
var LITERAL = 'Signal &amp; Noise Tools';
var DECODED = 'Signal & Noise Tools';
function patch(root){
if(!root||!root.querySelectorAll)return;
// Hide any wp.org link pointing at our self-hosted slug.
var links = root.querySelectorAll('a[href*="wordpress.org/plugins/'+SLUG+'"], a[href*="wordpress.org/support/plugin/'+SLUG+'"], a[href*="ps.w.org/'+SLUG+'"]');
for(var i=0;i<links.length;i++){
var el = links[i];
if(el.dataset.snHidden==='1')continue;
var host = el.closest('button, .wpd-button, [class*="action"], [class*="cta"], [class*="link"]') || el;
host.style.display='none';
el.dataset.snHidden='1';
}
// Defensive Name decode — only leaf nodes with the exact literal text.
var nodes = root.querySelectorAll('h1,h2,h3,h4,h5,h6,strong,span,div,td,a,p');
for(var j=0;j<nodes.length;j++){
var n = nodes[j];
if(n.children.length===0 && n.textContent===LITERAL){
n.textContent=DECODED;
}
}
}
function init(){
patch(document.body);
new MutationObserver(function(muts){
for(var i=0;i<muts.length;i++){
var added = muts[i].addedNodes;
for(var j=0;j<added.length;j++){
if(added[j].nodeType===1)patch(added[j]);
}
}
}).observe(document.body,{childList:true,subtree:true});
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init,{once:true});}
else{init();}
})();
</script>
	<?php
}, 99 );

/* ─────────────────────────────────────────────────────────────────────
 * v9.52.0 — analytics widget data layer
 *
 * Three widgets (Pulse, Site Views, Health) need three shapes of data.
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

	$total   = sn_health_check_total( $scan );
	$flagged = count( sn_health_flagged_checks( $scan ) );
	$passed  = max( 0, $total - $flagged );

	return array(
		'passed'     => $passed,
		'total'      => $total,
		'all_passed' => 0 === $flagged,
		// sn_health_run_scan() stores scanned_at as time() — an INT timestamp.
		'scanned_at' => (int) ( $scan['scanned_at'] ?? 0 ),
	);
}

/**
 * Uptime summary for the localize payload.
 *
 * NULL when Better Stack is unconfigured (no token) so the Pulse widget
 * omits the row entirely rather than showing a misleading "down".
 *
 * @return array{level:string,status:string}|null
 */
function snt_uptime_summary_for_localize() {
	if ( ! function_exists( 'sn_uptime_status_configured' ) || ! sn_uptime_status_configured() ) {
		return null;
	}
	if ( ! function_exists( 'sn_uptime_status_fetch' ) ) {
		return null;
	}

	// sn_uptime_status_fetch() returns a SNAPSHOT — array{fetched_at:int,
	// rows:array} — or a WP_Error when unconfigured / the API call fails. The
	// monitor rows live under ['rows']; iterating the snapshot itself finds no
	// 'level' key on anything, which would silently default every monitor to
	// 'ok' and paint the tile green straight through an outage. The
	// !is_array() guard also absorbs the WP_Error case (it's an object).
	$snap = sn_uptime_status_fetch();
	if ( ! is_array( $snap ) || empty( $snap['rows'] ) || ! is_array( $snap['rows'] ) ) {
		return null;
	}

	// Worst level wins — one monitor down means the site is not "ok".
	$rank   = array( 'ok' => 0, 'warn' => 1, 'alert' => 2 );
	$worst  = null;
	$status = '';
	foreach ( $snap['rows'] as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$level = (string) ( $row['level'] ?? 'ok' );
		if ( null === $worst || ( $rank[ $level ] ?? 0 ) > ( $rank[ $worst ] ?? 0 ) ) {
			$worst  = $level;
			$status = (string) ( $row['status'] ?? $level );
		}
	}

	if ( null === $worst ) {
		return null;
	}

	return array( 'level' => $worst, 'status' => $status );
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

	$days = array();
	if ( function_exists( 'sn_analytics_daily_series' ) ) {
		$series = sn_analytics_daily_series( $from, $today, 'human', 'day' );
		if ( is_array( $series ) ) {
			foreach ( $series as $row ) {
				$days[] = array(
					'date'  => (string) ( $row['day'] ?? '' ),
					'views' => (int) ( $row['views'] ?? 0 ),
				);
			}
		}
	}

	$total     = 0;
	$delta_pct = null;
	if ( function_exists( 'sn_analytics_range_totals' ) ) {
		$this_window = sn_analytics_range_totals( $from, $today, 'human' );
		$total       = (int) ( $this_window['views'] ?? 0 );

		// Prior 14-day window, for the week-over-week style delta.
		$prior_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$prior_from = gmdate( 'Y-m-d', strtotime( $from . ' -14 days' ) );
		$prior      = sn_analytics_range_totals( $prior_from, $prior_to, 'human' );
		$delta_pct  = snt_desktop_delta_pct( $total, (int) ( $prior['views'] ?? 0 ) );
	}

	$payload = array(
		'days'      => $days,
		'total'     => $total,
		'delta_pct' => $delta_pct,
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
 */
add_filter( 'desktop_mode_living_tree_traffic', function( $views ) {
	if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
		return (int) $views;
	}
	$today  = substr( (string) current_time( 'mysql' ), 0, 10 );
	$from   = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );
	$totals = sn_analytics_range_totals( $from, $today, 'human' );
	return (int) ( $totals['views'] ?? $views );
} );
