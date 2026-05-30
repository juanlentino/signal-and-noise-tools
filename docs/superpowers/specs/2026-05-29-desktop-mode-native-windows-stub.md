# Stub spec — Desktop Mode native windows for S&N tools

**Status:** STUB — not scheduled. Captured 2026-05-29 after reviewing the [AllTerrainDeveloper/desktop-mode-official-extensions](https://github.com/AllTerrainDeveloper/desktop-mode-official-extensions) repo (the Desktop Mode developer's own extension collection). Parked behind the v4.6.0 → v5.0.0 prep-major chain. Idea-only; no commitment.

**Why this exists:** capture a newly-discovered desktop-mode PHP API + the strategic idea while fresh, so a future session can evaluate it without re-deriving the contract. Pairs with the existing desktop-mode memory notes ([[reference_desktop_mode_ai_copilot]], [[reference_desktop_mode_plugins_window]], [[feedback_desktop_mode_blocks_browser_automation]], [[feedback_desktop_mode_horizontal_submenu_warning]]).

---

## The discovery (what the repo revealed)

The repo is **18 standalone WordPress plugins**, each a desktop-mode extension. There is **no manifest/registration DSL** — an extension is just a normal plugin that:

1. Declares the dependency via WP 6.5's native header: `Requires Plugins: desktop-mode`.
2. Registers a **native desktop-mode window** through two PHP functions S&N's current integration does NOT use:

```php
// Guarded — no-ops cleanly when desktop-mode is absent.
if ( ! function_exists( 'desktop_mode_register_window' ) ) { return; }

desktop_mode_register_window( 'wpdm-cron-manager', array(
    'title'        => __( 'Cron Jobs', 'textdomain' ),
    'icon'         => 'dashicons-clock',
    'template'     => 'my_render_template_callback', // PHP callback echoing the window's static HTML
    'script'       => 'my-registered-script-handle', // wp_register_script handle
    'width'        => 980,  'height'     => 620,
    'min_width'    => 640,  'min_height' => 420,
    'placement'    => 'taskbar',
    'capabilities' => array( 'manage_options' ),
) ); // returns WP_Error on failure

desktop_mode_register_icon( 'wpdm-cron-manager', array(
    'title' => '…', 'icon' => 'dashicons-clock',
    'window' => 'wpdm-cron-manager', 'position' => 90,
    'capabilities' => array( 'manage_options' ),
) );
```

- Both registered on `add_action( 'init', …, 20 )`, themselves gated behind `add_action( 'plugins_loaded', …, 20 )` + the `function_exists` guard.
- There are filters on the args: `desktop_mode_{name}_window_args` / `_icon_args` / `_template_html`.
- **Asset delivery trick worth stealing:** the window's JS bundle is served via `admin-ajax.php?action=…` with the REST nonce + URLs baked into the response body as `window.{Config} = {...}` — avoiding `wp_localize_script`/`wp_add_inline_script` lifecycle fragility on the lazy-load path. Script dep is `array( 'wp-i18n', 'wp-desktop' )`; UI uses desktop-mode's web components (`<wpd-table>`, `<wpd-select>`, `<wpd-text-field>`, `<wpd-button>`).
- **Graceful degradation:** if desktop-mode is missing, the extension's REST routes + filters keep working; only the window UI no-ops.

This **corrects [[reference_desktop_mode_ai_copilot]]'s implicit assumption** that S&N had no PHP path to a native DM window — it does: `desktop_mode_register_window()` / `desktop_mode_register_icon()`. (Verify against live source before relying on it — see Caveats.)

## The idea

S&N already implements Cron dashboard, Health, Insights, and Block Migrations as **admin-page tabs** (`?page=sn-*`). This same surface could *additionally* present as **desktop-mode native windows** — e.g. an "S&N Cron" / "S&N Health" window with a dashicon on the DM taskbar — reusing the existing REST routes + abilities as the data layer. The cron-manager extension is essentially "S&N's Cron tab, as a DM window": near-identical feature, different shell.

Candidate first window (smallest, highest-fit): **Health scan** or **Cron** — both already have REST + ability surfaces to back a window with zero new data-layer code.

## Acceptance criteria (when/if this gets scheduled)

- New isolated module (e.g. `inc/desktop-mode-windows.php`) — deletable in one file, mirroring how cron-manager isolates `includes/window.php`.
- Every `desktop_mode_register_*` call `function_exists`-guarded; **zero behavior change when desktop-mode is inactive** (S&N must not hard-depend on it — it's an enhancement, not a requirement).
- Reuse existing REST routes/abilities for data; no duplicated business logic.
- Respect [[feedback_no_dashboard_widgets]] (this is a DM window, NOT a wp-admin dashboard widget — distinct surface, should be fine) and [[feedback_no_brutalist_in_admin_ui]] (DM windows use desktop-mode's own web components — match THEIR design language, not S&N brand).
- Match in-page tab COUNT awareness from [[feedback_desktop_mode_horizontal_submenu_warning]].

## Caveats (read before building)

1. **License risk — borrow the PATTERN, not the CODE.** The repo has **no top-level LICENSE** (repo-level license is null). Individual extensions vary: `desktop-mode-cron-manager` is `GPL-2.0-or-later` (compatible), but e.g. `query-monitor` is a *vendored copy* of the third-party Query Monitor plugin (its own bundled LICENSE + `vendor/`). Do not copy files; re-implement the documented API pattern from scratch under S&N's own GPL.
2. **0 stars, last push 2026-05-05, default branch `trunk`, author "Desktop Mode Contributors".** This reads as the developer's personal/working collection, not a stable public SDK. The API names below are observed-from-example, **not from official docs.**
3. **MANDATORY before implementing** (per [[feedback_read_framework_source]] + [[feedback_read_peer_implementation_first]]): fetch the live `WordPress/desktop-mode` plugin source and confirm `desktop_mode_register_window()` / `desktop_mode_register_icon()` exist with these exact signatures + arg keys. This project has repeatedly paid for reasoning from guessed framework behavior (the v3.7.1 `method_exists` AI-client incident; the v4.2.0 login-routing arc). The cron-manager example is a strong lead, not a contract.
4. **Automation/testing:** [[feedback_desktop_mode_blocks_browser_automation]] — DM windows render inside the chrome-extension portal iframe; Claude-in-Chrome can't click/type/screenshot inside it. Plan UI smoke-tests accordingly (test the REST/PHP layer headlessly; manual-verify the window UI).

## Not now

Idea-only. The next scheduled work is **v4.6.0** (plugin prep-minor; WS1–WS7). This is a candidate for a later minor (post-v5.0.0), to be re-evaluated at a brainstorm checkpoint. No version bump, no scheduling commitment from this note.
