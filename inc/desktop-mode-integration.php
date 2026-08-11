<?php
/**
 * Signal & Noise Tools — WordPress/desktop-mode (OpenStation) integration.
 *
 * THIS FILE IS THE LOADER. Since v10.87.2 the integration lives in seven
 * modules beside it; this file requires them in the order the hooks demand and
 * carries the architectural notes that span all seven. Nothing else belongs
 * here — a new surface gets a new module, not another 200 lines in this file.
 *
 * Makes the SN plugin a first-class participant in the shell (when installed +
 * active). Adds:
 *
 *   1. A dock icon "Signal & Noise" with a submenu of every SN settings
 *      tab — derived from sn_admin_top_tabs(), never a hardcoded count
 *      (via the dock_items filter).            → desktop-mode-dock.php
 *   2. Two desktop icons (Dashboard + Identity — the most-frequent
 *      surfaces) via register_icon().          → desktop-mode-dock.php
 *   3. The Cmd+K command-palette commands via register_command() —
 *      maintenance actions (Abilities run-path), navigation shortcuts,
 *      version/info, cron, insights, and audit-log. (The display-only
 *      theme-ability launchers were removed in v9.52.3; the registration
 *      loop is the source of truth.)           → desktop-mode-commands.php
 *   4. Eight desktop widgets via register_widget(): SN Site Views, SN
 *      Health, SN Uptime, SN Deploy Status, SN Quick Actions, SN RSS
 *      Subscribers, SN Anchors, SN Machine Readers — one per domain since
 *      v9.53.0 (SN Pulse retired: it duplicated Site Views + Health).
 *                                              → desktop-mode-widgets.php
 *   5. (v9.52.0) The living_tree_traffic filter, so the wallpaper tree's
 *      wind responds to real 14-day traffic.   → desktop-mode-payloads.php
 *
 * EVERY integration is gated on function_exists() (through the snt_os_*
 * shims in inc/openstation-compat.php) — the plugin behaves identically when
 * the shell is inactive or uninstalled. Each module keeps its OWN guard; the
 * loader does not gate, so a module is never silently skipped as a group.
 *
 * ── THE HOOK IS THE WHOLE BALLGAME (v9.52.1) ──────────────────────────────
 * Scripts register on `init` priority 5, widgets and commands on `init`
 * priority 6, exactly as the shell's own docs/examples/register-widget.md
 * prescribes.
 *
 * This is not stylistic. The shell builds its serverWidgets / serverCommands /
 * desktopIcons payload inside its enqueue_assets(), hooked on
 * `admin_enqueue_scripts` at DEFAULT priority 10 (includes/render/assets.php),
 * and it reads the registries EAGERLY right there (`$payload[$k] =
 * $builder();`, includes/core/payload.php). WordPress runs equal-priority
 * callbacks in INSERTION order, and `active_plugins` is sorted alphabetically
 * — 'desktop-mode' sorts before 'signal-and-noise-tools' — so the shell's
 * priority-10 callback is always added, and therefore runs, BEFORE any
 * priority-10 callback of ours.
 *
 * Registering from our own admin_enqueue_scripts:10 closure was therefore
 * unwinnable: by the time we called register_widget(), the payload had already
 * been built from an empty registry. Every widget and every Cmd+K command was
 * silently absent from the picker/palette — for years, and independently of
 * the v9.52.0 mount-callback bug (a widget that never reaches the payload
 * can't mount no matter how correct its callback). The desktop ICONS always
 * worked precisely because they were already registered on `init`.
 *
 * `init` also covers the chromeless / live-refresh path, which rebuilds the
 * same payload OUTSIDE admin_enqueue_scripts entirely — and where server-sync
 * UNREGISTERS any id missing from the refresh, i.e. a late registry doesn't
 * just fail to add widgets, it can actively remove live ones.
 *
 * ── THE WIDGET MOUNT CONTRACT (v9.52.0 — the fix that made widgets work) ──
 * The shell offers two widget paths. Ours is the PHP-declared one:
 * register_widget() publishes label/description/icon server-side, then
 * server-sync loads the widget's script and reads its mount callback from
 * `window.desktopModeWidgets[ id ]` (mount( container, ctx ) → teardown). The
 * OTHER path, wp.desktop.registerWidget( def ), is for pure client-side
 * widgets and hard-validates the def (id + label + description + icon +
 * mount), throwing otherwise. Before v9.52.0 all three widget scripts called
 * the client-side path with `{ id, render }` — wrong path AND wrong shape — so
 * they failed validation and never set the global either. All three were
 * silently dead; the file had no tests to notice.
 * tests/desktop-mode-integration.php now pins the contract for all eight.
 *
 * ── COMMANDS DISPATCH THROUGH ABILITIES ───────────────────────────────────
 * The maintenance commands fire without page navigation via the Abilities
 * run-path (assets/desktop-commands.js run() → sntAbilityRun):
 * purge-all-caches (bare, or {include_template_overrides:true} for the
 * full-reset command), clear-template-overrides, and get-deploy-status
 * {force_refresh:true} for force-check. The legacy signal-noise/v1/cmd/*
 * REST routes were removed in v7.0.0; the deprecated per-command abilities
 * (full-reset, force-check-updates) in v8.0.0. The local sn-cmd-* palette
 * keys keep their names — they are labels, not ability slugs.
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

/*
 * REQUIRE ORDER IS THE REGISTRATION CONTRACT.
 *
 * WordPress runs equal-priority callbacks in INSERTION order, and these
 * modules add their hooks at file scope — so the order below IS the order the
 * shell sees. Two edges matter:
 *
 *   - assets BEFORE commands/widgets: the scripts those two name in their
 *     'script' arg must already be registered handles.
 *   - commands BEFORE widgets: both add an `init` priority-6 callback, and
 *     the commands were registered first before the split. Preserved so the
 *     shell's payload order is byte-identical to pre-v10.87.2.
 *
 * payloads is required before assets because the localize in assets calls
 * snt_health_summary_for_localize(). PHP function declarations at file scope
 * are hoisted per-file, so this is belt-and-braces rather than load-bearing —
 * but it keeps the file order matching the data-flow order.
 */
require_once __DIR__ . '/desktop-mode-payloads.php';
require_once __DIR__ . '/desktop-mode-assets.php';
require_once __DIR__ . '/desktop-mode-commands.php';
require_once __DIR__ . '/desktop-mode-widgets.php';
require_once __DIR__ . '/desktop-mode-dock.php';
require_once __DIR__ . '/desktop-mode-plugins-window.php';
require_once __DIR__ . '/desktop-mode-ai.php';
