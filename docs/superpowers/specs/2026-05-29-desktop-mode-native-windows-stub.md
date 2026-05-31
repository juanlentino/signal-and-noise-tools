# Stub spec — Desktop Mode native windows for S&N tools

> ## ✅ VERIFIED AGAINST OFFICIAL SOURCE (2026-05-30)
>
> Prompted by a user challenge ("there's nothing here about those extensions" → [WordPress/desktop-mode](https://github.com/WordPress/desktop-mode)), the API below was checked against the **official** repo. **The API is real, official, and stable** — the original capture (from the AllTerrainDeveloper mirror) was substantively correct. Corrections:
>
> - **`desktop_mode_register_window()` + `desktop_mode_register_icon()` are OFFICIAL, supported, stable APIs.** Confirmed: 71 + 30 code hits in `WordPress/desktop-mode`, and a dedicated contract doc, **[`docs/use-from-a-plugin.md`](https://github.com/WordPress/desktop-mode/blob/trunk/docs/use-from-a-plugin.md)**, which states verbatim: *"This guide is the public extension contract. Everything here is a supported, stable API. If you build against it, we treat breaking it as a regression."* **Stable as of Desktop Mode 0.20.0**; signatures follow semver (arg keys only added, never removed/repurposed within a major).
> - **The extensions live IN the official repo** (`extensions/desktop-mode-cron-manager/`, `…-code-editor/`, `…-phpmyadmin/`, plus a base class `extensions/base/includes/ExtensionWindow.php`). The AllTerrainDeveloper repo is a **mirror/copy** of that `extensions/` tree — not a separate/third-party API. So the earlier "borrow the pattern, not the code; 0-star personal repo" license worry (Caveat 1 below) is **downgraded**: the canonical source is the official GPL-2.0 `WordPress/desktop-mode` repo + its `docs/use-from-a-plugin.md`. Build from THAT, not the mirror.
> - **Doc-name correction:** the official docs are `use-from-a-plugin.md` (the canonical contract), `hooks-reference.md`, `javascript-reference.md`, `api-index.md`, `event-driven-framework.md`, `components-reference.md` — NOT the `native-windows.md`/`extensions.md` names guessed below. (There is also a `native-windows-proposal.md`, but `use-from-a-plugin.md` is the shipped contract.)
> - **Process win:** Caveat 3 ("MANDATORY: verify against live source before building") did its job. The user's challenge triggered exactly that verification. Outcome: the API was *confirmed*, not refuted — but the doc references + license framing were corrected. This is [[feedback_read_framework_source]] working as intended.
>
> **Official minimal example** (`docs/use-from-a-plugin.md` Quick Start — "a complete, working extension; no build step, no JavaScript required"):
> ```php
> // Plugin header: Requires Plugins: desktop-mode
> add_action( 'init', function () {
>     if ( ! function_exists( 'desktop_mode_register_window' ) ) { return; }
>     desktop_mode_register_window( 'my-app', array(
>         'title' => __( 'My App', 'my-app' ), 'icon' => 'dashicons-smiley',
>         'template' => 'my_app_render', 'script' => 'my-app',  // template = PHP callback echoing HTML; script optional
>         'width' => 800, 'height' => 600, 'placement' => 'taskbar',
>         'capabilities' => array( 'read' ),
>     ) );
>     desktop_mode_register_icon( 'my-app', array(
>         'title' => __( 'My App', 'my-app' ), 'icon' => 'dashicons-smiley',
>         'window' => 'my-app', 'capabilities' => array( 'read' ),
>     ) );
> }, 20 );
> ```
> Everything below this box is the ORIGINAL capture (from the mirror) — accurate on the API shape, but read it through the corrections above. Caveat 1 (license) is downgraded; Caveats 2/4 still apply.

**Status:** STUB — not scheduled. Captured 2026-05-29 from the [AllTerrainDeveloper/desktop-mode-official-extensions](https://github.com/AllTerrainDeveloper/desktop-mode-official-extensions) mirror; **API verified against the official [WordPress/desktop-mode](https://github.com/WordPress/desktop-mode) repo 2026-05-30 (see box above).** Parked behind the v4.6.0 → v5.0.0 prep-major chain. Idea-only; no commitment.

> ## ⚠️ MATURITY CAVEAT — Desktop Mode is NOT 1:1 with classic wp-admin (user-observed 2026-05-30)
>
> User feedback after daily use: *"Desktop Mode isn't 1:1 with the classic admin dashboard at all, at least for now."* This is **by design**, not a gap that's about to close — and it directly shapes the cost/benefit of this stub. Desktop Mode has **two tiers**:
>
> 1. **Curated native windows** — bespoke desktop apps hand-built in the repo (`includes/posts-window/`, `pages-window/`, `comments-window/`, `my-wordpress/`, the AI Copilot). Beautiful, `wpd-*`-component-based, feel OS-native. But each is *individually written* — coverage = only what they've explicitly built.
> 2. **Compat fallback** (`includes/plugins-window/`, `includes/compat/`) — everything WITHOUT a native window renders classic wp-admin inside a portal iframe. This is the "annoying / not really desktop-native" tier, and it carries the automation limits already in [[feedback_desktop_mode_blocks_browser_automation]].
>
> The non-1:1-ness traces to Desktop Mode's own design promise (README: *"doesn't change core, and fully reverts on deactivation"*). A true 1:1 surface would require deep coupling to core's admin rendering — the opposite of that promise — so they chose curated native windows + an iframe fallback instead.
>
> **What this means for THIS stub (it's a reason FOR, gated on conditions — not against):**
> - Building S&N's tools as native DM windows (`desktop_mode_register_window()`) is **tier-1 work** — it's the *only* way to lift S&N's Cron/Health/Insights out of the tier-2 iframe fallback and make them first-class desktop apps. So the non-1:1-ness *increases* the potential payoff, not decreases it.
> - **But the gate is real:** only worth the build IF (a) you actually use Desktop Mode daily/regularly (otherwise you're polishing a surface no one opens), AND (b) the `desktop_mode_register_window()` API stays stable (it's marked stable as of DM 0.20.0 per `use-from-a-plugin.md`, but DM is young + evolving fast).
> - **Re-evaluation trigger:** revisit this stub when EITHER you've adopted Desktop Mode as a primary admin surface, OR Desktop Mode ships materially broader native coverage (signalling the project + its API have matured). Until one of those, parked is correct.
>
> Net: the idea got *more* compelling from this observation (native windows are the escape hatch from the iframe friction), but the *timing* stays "wait" — pending adoption + maturity signals.

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
