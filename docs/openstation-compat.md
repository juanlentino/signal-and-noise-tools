# OpenStation rename compatibility — audit trail

WordPress/openstation PR #475 (merged 2026-08-03, in `trunk`, **not yet in
any tagged release** — the owner runs pre-rename Desktop Mode **v0.9.8**
today) renames the plugin from "Desktop Mode" to "OpenStation":
`desktop_mode_*()` functions/hooks → `openstation_*()` (no underscore
between "open" and "station" — verified, not the mechanically-guessed
`open_station_*`), `DESKTOP_MODE_*` constants → `OPENSTATION_*`,
`Desktop_Mode_*` classes → `Open_Station_*`, JS `wp.desktop` → `wp.os`,
`window.desktopModeWidgets` → `window.openStationWidgets`, CSS
`.desktop-mode-*` → `.os-*`, `--wpd-*`/`--desktop-mode-*` → `--os-ui-*`/`--os-*`.

**No back-compat shim exists upstream.** A fresh GitHub code search of
post-#475 `WordPress/openstation` for any `desktop_mode_*` name returns zero
hits. Exactly one naming family is ever active for a given install, decided
entirely by which release the owner is running.

Compat layer: [inc/openstation-compat.php](../inc/openstation-compat.php).
Every mapping below was verified against real post-#475 source (file:line),
not derived mechanically from the rename table — the source of truth was
fetched from `https://raw.githubusercontent.com/WordPress/openstation/trunk/...`
and cross-checked with `gh api search/code` on 2026-08-04.

## The 9 PHP hooks this plugin consumes

| Old hook (v0.9.8) | New hook (post-#475) | Upstream firing site | Our consumer |
|---|---|---|---|
| `desktop_mode_dock_items` | `openstation_dock_items` | `includes/core/payload.php:213` — `apply_filters( 'openstation_dock_items', $items )` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — the "Signal & Noise" dock entry |
| `desktop_mode_dock_placement` | `openstation_dock_placement` | `includes/core/payload.php:1138` — `apply_filters( 'openstation_dock_placement', 'dock', $menu_slug )` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — suppresses the auto-imported SN dock item |
| `desktop_mode_ai_tools` | `openstation_ai_tools` | `includes/ai-copilot/search.php:1124` — `apply_filters( 'openstation_ai_tools', $tools, $context )` (2nd arg is new; our callback still declares only `$tools`) | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — Anthropic tool-schema normalizer + Copilot prune list |
| `desktop_mode_ai_system_prompt_appendix` | `openstation_ai_system_prompt_appendix` | `includes/ai-copilot/search.php:1594` — `apply_filters( 'openstation_ai_system_prompt_appendix', '', $ctx_for_filter )` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — analytics-vocabulary appendix |
| `desktop_mode_ai_tool_called` | `openstation_ai_tool_called` | `includes/ai-copilot/search.php:1322` / `:1399` / `:1753` — `do_action( 'openstation_ai_tool_called', array( 'tool_name' => …, 'args' => …, 'user_id' => …, 'request_id' => … ) )` | [inc/ai-tool-invocation-log.php](../inc/ai-tool-invocation-log.php) — Copilot tool-invocation log |
| `desktop_mode_agent_completed` | `openstation_agent_completed` | `includes/agents/runner.php:243` — `do_action( 'openstation_agent_completed', (int) $user->ID, $message, $result, (array) $context )` | [inc/mcp/mcp-telemetry-agents.php](../inc/mcp/mcp-telemetry-agents.php) — seam 2, failure-visibility backfill |
| `desktop_mode_agent_tool_result` | `openstation_agent_tool_result` | `includes/agents/runner.php:579` — `apply_filters( 'openstation_agent_tool_result', $output, $slug, $args, $agent_user_id )` | [inc/mcp/mcp-telemetry-agents.php](../inc/mcp/mcp-telemetry-agents.php) — seam 1, success-path telemetry |
| `desktop_mode_living_tree_traffic` | `openstation_living_tree_traffic` | `includes/living-tree/helpers.php:91` — `apply_filters( 'openstation_living_tree_traffic', $views )`, inside `openstation_living_tree_traffic()` at `:76` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — wallpaper wind driven by real 14-day traffic |
| `desktop_mode_plugins_window_icon_url` | `openstation_plugins_window_icon_url` | `includes/plugins-window/rest-fields.php:465`, inside `openstation_plugins_window_field_icon_url()` at `:422` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — our plugin's icon in the shell's Plugins window |

All 9 verified — none flagged unverifiable.

## The direct function calls this plugin makes into upstream

No hook is involved here; these are ordinary PHP function calls this plugin
makes, dispatched through `inc/openstation-compat.php`'s wrappers
(`snt_os_register_command()`, `snt_os_register_widget()`,
`snt_os_register_icon()`, `snt_os_is_enabled()`,
`snt_os_ai_ability_tool_name()`), preferring the post-#475 name when both
exist and falling back to the pre-rename name otherwise.

| Old function | New function | Verified at |
|---|---|---|
| `desktop_mode_register_command()` | `openstation_register_command()` | `includes/commands.php:115` |
| `desktop_mode_register_widget()` | `openstation_register_widget()` | `includes/registries/widgets.php:83` |
| `desktop_mode_register_icon()` | `openstation_register_icon()` | `includes/registries/icons.php:88` |
| `desktop_mode_is_enabled()` | `openstation_is_enabled()` | `includes/helpers.php:58` |
| `desktop_mode_ai_ability_tool_name()` | `openstation_ai_ability_tool_name()` | `includes/ai-copilot/abilities.php:93` |

Constant, for detection: `DESKTOP_MODE_VERSION` (v0.9.8) →
`OPENSTATION_VERSION` (post-#475), defined in the plugin's main file.
`snt_os_is_post_rename()` detects the active family via
`function_exists( 'openstation_register_command' )` rather than the
constant, since the register function is what every consumer already
depends on.

## JS surface

| Old (Desktop Mode) | New (OpenStation) | Verified at | Our consumer |
|---|---|---|---|
| `window.desktopModeWidgets[ id ]` | `window.openStationWidgets[ id ]` | `src/widgets/server-sync.ts:9,36,85,96` — `( window as unknown as WidgetGlobals ).openStationWidgets \|\| {}` | All 9 widget scripts under `assets/desktop-mode-widget*.js` (unchanged — the compat prelude aliases both globals onto one object) |
| `window.wp.desktop` | `window.wp.os` | `src/api/facade.ts:858` — `window.wp.os = api` | `assets/desktop-mode.js` (65 call sites, unchanged — aliased) |
| `desktop-mode.drop.files-detected` (wp.hooks filter) | `os.drop.files-detected` | `src/os-file-drop/hooks.ts:19` — `FILE_DROP_HOOKS.FILES_DETECTED` | `assets/desktop-dropzone.js` — registers under both names directly (a WeakSet guards a hypothetical double-fire) |

The `wp.os` public API object's shape (`registerCommand`, `notify`, `dock`,
`sideDock`, `icons`, `registerWidget`) is unchanged from `wp.desktop` — only
the top-level namespace renamed (`src/api/facade.ts:188-234`).

## CSS

Verified: our plugin's CSS does **not** consume any `--wpd-*` or
`--desktop-mode-*` custom property (grepped `assets/*.css` — zero hits),
confirming the discipline noted in project memory (TRAP 8 — the widget card
deliberately avoids upstream color tokens) still holds.

The one class selector we DO read from upstream — `body.desktop-mode-chromeless`
in `assets/admin.css` (hides our in-page tab nav inside a chromeless shell
window, so the shell's own in-window tab strip is the only nav shown) — IS a
real exposure: it's a body class the shell adds via `admin_body_class`, not a
hook we register on, so it sits outside the dual-registration mechanism
above. Verified real post-#475: `includes/render/body-classes.php`,
`openstation_admin_body_classes()` — `ltrim( $classes . ' os-chromeless' )`,
the `.desktop-mode-*` → `.os-*` CSS rule holding exactly. `assets/admin.css`
now lists both selectors (`body.desktop-mode-chromeless .sn-nav-tabs,
body.os-chromeless .sn-nav-tabs`); exactly one will ever match on a given
install.

## What is verified vs. what cannot be

Every mapping above — 9 hooks, 5 functions, 3 JS surfaces, and the
`.desktop-mode-chromeless`/`.os-chromeless` CSS class — was checked against
real post-#475 `WordPress/openstation` trunk source. Nothing in this audit
is flagged unverified.

What is **structurally impossible** to verify right now: end-to-end
behavior against a real post-#475 **release**. No such release exists —
v0.9.8 is the latest tag and predates the rename entirely. This compat
layer is source-verified against `trunk`, dual-registers defensively, and
every existing test passes unmodified on the v0.9.8 line, but the
post-rename path has never executed against a real WordPress admin. Revisit
this file when a post-#475 OpenStation release ships, to confirm live.
