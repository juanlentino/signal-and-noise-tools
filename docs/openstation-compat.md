# OpenStation rename compatibility — audit trail

WordPress/openstation PR #475 (merged 2026-08-03) renames the plugin from
"Desktop Mode" to "OpenStation": `desktop_mode_*()` functions/hooks →
`openstation_*()` (no underscore between "open" and "station" — verified,
not the mechanically-guessed `open_station_*`), `DESKTOP_MODE_*` constants →
`OPENSTATION_*`, `Desktop_Mode_*` classes → `Open_Station_*`, JS
`wp.desktop` → `wp.os`, `window.desktopModeWidgets` →
`window.openStationWidgets`, CSS `.desktop-mode-*` → `.os-*`,
`--wpd-*`/`--desktop-mode-*` → `--os-ui-*`/`--os-*`.

**This file cites no upstream line numbers, by design.** It used to, pinned to
one tag, and they were wrong on the next release — silently, because a stale
line number still points at real code. Citations are now the file plus the call
expression, both of which survive a release and are what you would grep for
anyway. `tests/openstation-compat.php` fails the build if a `file.php:NNN`
citation reappears here.

<!-- openstation-verified: v1.1.6 2026-09-04 -->

**Last verified against `v1.1.6`** (2026-09-04). The machine-readable stamp
directly above is what `tests/openstation-compat.php` reads; the sentence you are
reading must agree with it, and the test fails if they drift. Before the stamp
existed this claim was prose only, and it sat at `v1.1.2` through three
releases with nothing able to notice. See
[Re-verifying after an upstream release](#re-verifying-after-an-upstream-release)
for the instrument.

**Verify against the TAG, never the default branch.** GitHub code search reads
`trunk`. Asking it whether a seam exists answers a question about unreleased
code and will happily confirm a name that is not in the release the site runs.
Fetch each file at `?ref=vX.Y.Z`.

### What v1.1.6 changed for us: nothing, and here is the evidence

38 commits and 300 files since v1.1.5, including the App Framework rebuilds of
Station Home, Preferences and Trash — each described upstream as *"the legacy
window deleted whole"* — plus a mobile layer, multisite site-scoped desktops and
a PWA shell. Every seam this plugin uses survives that:

| Seam | At `v1.1.6` |
|---|---|
| `window.openStationWidgets[ id ]` | unchanged — `includes/registries/widgets.php` is not in the v1.1.5..v1.1.6 diff |
| `openstation_register_widget`, `openstation_register_icon` | present |
| `openstation_register_command` | present |
| `openstation_register_station_home_card` | present — **survived the Station Home rebuild** |
| `openstation_is_enabled` | present |
| `openstation_pwa_apple_touch_icon_url()` | present — the 180x180 tile iOS uses for a home-screen install, printed into `admin_head` by `includes/pwa.php`. It has NO filter, so we reach it through core's `get_site_icon_url` instead, scoped to admin + size 180. See `inc/openstation-pwa-icons.php` (#1022). |
| `openstation_pwa_manifest` | present — documented **Stable** in the upstream hook reference (`docs/hooks-reference.md`). We filter `icons` ONLY: the Site-Icon path declared `192x192` on a 300x300 RGBA file, and iOS composites that alpha to black behind a mark measuring luminance 23/255. See `inc/openstation-pwa-icons.php` (#1017). |
| `openstation_ai_ability_tool_name`, `openstation_ai_tools` | present |

Two seam files did change, and neither is behavioural for us:

- **`includes/core/payload.php`** — multisite only (`self_admin_url()` added to
  the same-host set; two network menus given filenames). The TRAP 1 mechanism —
  the payload reading the registries eagerly at `admin_enqueue_scripts:10` — is
  untouched, so `init:5` / `init:6` registration remains required.
- **`includes/commands.php`** — a documentation comment, nothing else (311 lines
  before and after). The example gained
  `}, 5 ); // Before the shell harvests the payload at priority 10.` Upstream now
  documents the exact trap that left every widget and command silently absent
  for years.

**PR #717 is IN this release.** Verified by containment rather than by reading
the notes: comparing #717's merge commit to the `v1.1.6` tag returns
`status=ahead, behind_by=0`. The `scriptDeps` loader no longer re-executes
`wp-hooks` under script concatenation, so boot-time subscribers are not deafened.

**Still unverified, and only a human can close it:** the acceptance test is that
windows reveal *without* the DevTools console paste. That needs the site updated
from wp-admin and someone looking at it. Nothing here may be read as claiming it
passed.

### Upstream now normalizes AI tool schemas — keep ours anyway

`openstation_ai_normalize_tool_schema()` (`includes/ai-copilot/search.php`) is
live at v1.1.6 and applied to the **whole tool list after** the
`openstation_ai_tools` filter, which is the design PR #366 proposed. It covers
every class our own filter does: a union top-level `type`, top-level
`oneOf`/`allOf`/`anyOf`, an empty `properties` needing `{}` rather than `[]`, and
the WP-only `sanitize_callback` / `validate_callback` / `arg_options` keys
stripped at every depth. Upstream's comment states it is idempotent, so ours
running first is harmless.

**Our normalizer stays.** It is redundant only against v1.1.6 and newer, and it
is the single thing standing between a union-typed ability and a Copilot that is
dead rather than degraded on any older install. The PRUNE half of our filter is
ours regardless of upstream. Remove it only once no install we care about runs
below v1.1.6, and never as a side effect of an upgrade.

### New upstream abilities (v1.1.6)

`includes/ai-copilot/abilities-debugging.php` registers four read-only
abilities — `list_log_issues`, `get_log_issue`, `read_source_excerpt`,
`get_site_context`. Being `readonly` they auto-enrol into the Copilot with no
opt-out, which is the TRAP 3 mechanism working as designed.

They are not ours, but they change what an assistant on this site can reach, so
they are recorded here. Gating is layered: `openstation_ai_debug_can_use()`
requires `manage_options` (`manage_network_options` on a network) **and** the
`developerModeEnabled` preference, so they are inert on a site with developer
mode off. `read_source_excerpt` adds four more guards — the file must be named
in the current log, resolve through `realpath`, sit inside `ABSPATH` or
`WP_CONTENT_DIR`, and match a source-extension allowlist.

**No back-compat shim exists upstream.** A code search of post-#475
`WordPress/openstation` for any `desktop_mode_*` name returns zero hits.
Exactly one naming family is ever active for a given install, decided
entirely by which release the owner is running.

Compat layer: [inc/openstation-compat.php](../inc/openstation-compat.php).

## Release timeline

| Tag | Date | Rename status |
|---|---|---|
| v0.9.8 | 2026-07-31 | **Pre-rename** — last release on the `desktop_mode_*` family |
| v1.0.0 | 2026-08-07 | First tagged post-rename release |
| v1.0.1 | 2026-08-11 | Post-rename |
| v1.1.0 | 2026-08-14 | Post-rename — first post-rename release verified here (17 names) |
| v1.1.1 | 2026-08-19 | Post-rename |
| v1.1.2 | 2026-08-21 | Post-rename — verified here at the time (19 names) |
| v1.1.3 | 2026-08-24 | Post-rename — deferred the palette's Gutenberg runtime to first ⌘K and broke plugin-contributed commands. Was in production while the fix (#683) sat merged-but-untagged |
| v1.1.4 | 2026-08-28 | **The release that shipped #683**, so the ⌘K break ends here. Established by containment: #683's commit `199a0851` is an ancestor of `v1.1.4` (`behind_by=0`), not by reading the notes |
| v1.1.5 | 2026-08-29 | Post-rename. Its `scriptDeps` loader re-executed `wp-hooks` under script concatenation, deafening boot-time subscribers (#715); the fix, #717, was merged and untagged for the whole life of this release |
| **v1.1.6** | **2026-09-04** | Post-rename — **the release this file is verified against.** Carries #717 (containment-checked). 38 commits / 300 files, including App Framework rebuilds of Station Home, Preferences and Trash, a mobile layer, multisite site-scoped desktops and a PWA shell — and **not one of our seams moved** |

An earlier revision of this file said the rename was "in trunk, **not yet in
any tagged release**", and that end-to-end verification was "structurally
impossible" because no post-rename release existed. Both statements were true
when written and are **now false** — v1.0.0 shipped the rename a week later.
See [What is verified vs. what is not](#what-is-verified-vs-what-is-not) for
what the honest remaining gap actually is.

Note that the rename is **incomplete upstream at the packaging level**: as of
v1.1.0 the main plugin file is still `desktop-mode.php`, and the i18n text
domain is still `'desktop-mode'`. This is why `snt_os_is_post_rename()`
detects the active family via `function_exists( 'openstation_register_command' )`
rather than by filename, constant, or text domain — the register function is
what every consumer already depends on, and it is the thing that actually
renamed.

## The 11 PHP hooks this plugin consumes

Note on the two WP Explorer rows (v12.4.0): the pre-rename v0.9.8 shell
predates the WP Explorer feature entirely, so their old-family names exist
nowhere upstream and can never fire. They are dual-registered anyway —
one pattern, no special cases — and both callbacks are idempotent by
construction (id/handle dedupe), so no seen-once guard applies.

| Old hook (v0.9.8) | New hook (v1.0.0+) | Upstream firing site | Our consumer |
|---|---|---|---|
| `desktop_mode_my_wordpress_entities` (never existed — see note) | `openstation_my_wordpress_entities` | `includes/my-wordpress/window.php` — `apply_filters( 'openstation_my_wordpress_entities', $entities )`, frozen at `init` 99 | [inc/desktop-mode-explorer.php](../inc/desktop-mode-explorer.php) — the Notes + Discography Explorer sections |
| `desktop_mode_my_wordpress_window_args` (never existed — see note) | `openstation_my_wordpress_window_args` | `includes/my-wordpress/window.php` — `apply_filters( 'openstation_my_wordpress_window_args', $window_args )` | [inc/desktop-mode-explorer.php](../inc/desktop-mode-explorer.php) — rides the window's `scripts` companion list |
| `desktop_mode_dock_items` | `openstation_dock_items` | `includes/core/payload.php` — `apply_filters( 'openstation_dock_items', $items )` | [inc/desktop-mode-dock.php](../inc/desktop-mode-dock.php) — the "Signal & Noise" dock entry |
| `desktop_mode_dock_placement` | `openstation_dock_placement` | `includes/core/payload.php` — `apply_filters( 'openstation_dock_placement', 'dock', $menu_slug )`, inside `openstation_dock_placement()` | [inc/desktop-mode-dock.php](../inc/desktop-mode-dock.php) — suppresses the auto-imported SN dock item |
| `desktop_mode_ai_tools` | `openstation_ai_tools` | `includes/ai-copilot/search.php` — `apply_filters( 'openstation_ai_tools', $tools, $context )` (2nd arg is post-rename; our callback still declares only `$tools`) | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — Anthropic tool-schema normalizer + Copilot prune list |
| `desktop_mode_ai_system_prompt_appendix` | `openstation_ai_system_prompt_appendix` | `includes/ai-copilot/search.php` — `apply_filters( 'openstation_ai_system_prompt_appendix', '', $ctx_for_filter )` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — analytics-vocabulary appendix |
| `desktop_mode_ai_tool_called` | `openstation_ai_tool_called` | `includes/ai-copilot/search.php` — `do_action( 'openstation_ai_tool_called', array( 'tool_name' => …, 'args' => …, 'user_id' => …, 'request_id' => … ) )` | [inc/ai-tool-invocation-log.php](../inc/ai-tool-invocation-log.php) — Copilot tool-invocation log |
| `desktop_mode_agent_completed` | `openstation_agent_completed` | `includes/agents/runner.php` — `do_action( 'openstation_agent_completed', (int) $user->ID, $message, $result, (array) $context )` | [inc/mcp/mcp-telemetry-agents.php](../inc/mcp/mcp-telemetry-agents.php) — seam 2, failure-visibility backfill |
| `desktop_mode_agent_tool_result` | `openstation_agent_tool_result` | `includes/agents/runner.php` — `apply_filters( 'openstation_agent_tool_result', $output, $slug, $args, $agent_user_id )` | [inc/mcp/mcp-telemetry-agents.php](../inc/mcp/mcp-telemetry-agents.php) — seam 1, success-path telemetry |
| `desktop_mode_living_tree_traffic` | `openstation_living_tree_traffic` | `includes/living-tree/helpers.php` — `apply_filters( 'openstation_living_tree_traffic', $views )`, inside `openstation_living_tree_traffic()` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — wallpaper wind driven by real 14-day traffic |
| `desktop_mode_plugins_window_icon_url` | `openstation_plugins_window_icon_url` | `includes/plugins-window/rest-fields.php`, inside `openstation_plugins_window_field_icon_url()` | [inc/desktop-mode-integration.php](../inc/desktop-mode-integration.php) — our plugin's icon in the shell's Plugins window |

All present at v1.1.2 — none removed, none renamed, none flagged. Re-verified
by both-directions membership check on 2026-08-21: 19 of 19 names resolve to a
real seam upstream (8 functions, 10 hooks, 1 REST field), not merely a matching
string
unverifiable.

**`openstation_ai_tools` is a multi-line `apply_filters(` call.** A
single-line `grep "apply_filters( 'openstation_ai_tools'"` reports it
**missing** and will convince you upstream deleted it. Use the `perl -0777`
sweep in [Re-verifying after an upstream release](#re-verifying-after-an-upstream-release).

## The direct function calls this plugin makes into upstream

No hook is involved here; these are ordinary PHP function calls this plugin
makes, dispatched through [inc/openstation-compat.php](../inc/openstation-compat.php)'s
wrappers (`snt_os_register_command()`, `snt_os_register_widget()`,
`snt_os_register_icon()`, `snt_os_is_enabled()`,
`snt_os_ai_ability_tool_name()`), preferring the post-rename name when both
exist and falling back to the pre-rename name otherwise.

| Old function | New function | Verified @ v1.1.0 |
|---|---|---|
| `desktop_mode_register_command()` | `openstation_register_command()` | `includes/commands.php` |
| `desktop_mode_register_widget()` | `openstation_register_widget()` | `includes/registries/widgets.php` |
| `desktop_mode_register_icon()` | `openstation_register_icon()` | `includes/registries/icons.php` |
| `desktop_mode_is_enabled()` | `openstation_is_enabled()` | `includes/helpers.php` |
| `desktop_mode_ai_ability_tool_name()` | `openstation_ai_ability_tool_name()` | `includes/ai-copilot/abilities.php` |
| *(none — postdates the rename)* | `openstation_register_station_home_card()` | `includes/station-home/cards.php` |
| *(not consumed pre-rename)* | `openstation_get_os_settings()` | `includes/os-settings.php` |
| *(not consumed pre-rename)* | `openstation_save_os_settings()` | `includes/os-settings.php` |

All five renamed functions still accept exactly the argument shapes we pass at
v1.1.0 (re-checked against each function's `$defaults` array, not just its
signature).

**The sixth row has no old name, and that is the point.** Station Home
shipped in upstream v1.1.2 (PR #625), *after* the rename, so
`openstation_register_station_home_card()` never had a `desktop_mode_*`
twin. `snt_os_register_station_home_card()` therefore checks ONE name where
every other wrapper in the compat layer checks two — a deliberate
asymmetry, not a missed case.

**Rows seven and eight (v13.105.1) are called directly, not wrapped.**
`inc/desktop-mode-nav-ids.php` carries a user's placement preference from the
auto-imported menu ids (`toplevel_page_sn-theme-options`,
`toplevel_page_sn-analytics`) to the app ids (`sn-dashboard`, `sn-analytics`)
once per site, on `admin_init` at priority 20 behind the option
`snt_os_nav_id_migration`. It reads through the getter (a fully shaped,
sanitized array) and writes through the saver (sanitize-and-REPLACE, the
same path a save from OS Settings takes), and does nothing while either
function is absent. Both had `desktop_mode_*` twins before the rename, but
the app entries the carry targets only exist on 1.1.6+, so there is nothing
to fall back to; checking one name is correct here.

(A seventh single-name wrapper, `snt_os_register_desktop_theme()`, existed
v13.7.0–v13.7.5: the "Signal & Noise" desktop-theme arc, dropped whole by
owner decision 2026-08-27 after six field iterations — the shell's brand
carries 219 color-bearing tokens and a ~60-token reskin loses by coverage.
See CHANGELOG 13.8.0. Do not re-propose without a full-coverage plan.)

Worth recording alongside it: the v1.1.2 verification pass on 2026-08-21
reported 19/19 seams clean, and it was correct — it checks NAMES. Station Home
landed in that same release and claimed `index.php` by pathname, which took the
plugin's Analytics screen off its own URL without renaming anything at all. The
compat instrument answers "did the names move?", never "did upstream grow a new
claim on a surface we use". Reported upstream as
[#650](https://github.com/WordPress/openstation/issues/650); the screen moved to
its own top-level menu in plugin v12.10.0.

Constant, for detection only: `DESKTOP_MODE_VERSION` (v0.9.8) →
`OPENSTATION_VERSION` (v1.0.0+, `desktop-mode.php` — note the pre-rename
*filename*, see above). We do not read it; `snt_os_is_post_rename()` uses
`function_exists()` instead.

## JS surface

| Old (Desktop Mode) | New (OpenStation) | Verified @ v1.1.0 | Our consumer |
|---|---|---|---|
| `window.desktopModeWidgets[ id ]` | `window.openStationWidgets[ id ]` | `src/widgets/server-sync.ts:85` — `( window as unknown as WidgetGlobals ).openStationWidgets \|\| {}` | All 9 widget scripts under `assets/desktop-mode-widget*.js` (each self-aliases both globals onto one object) |
| `window.wp.desktop` | `window.wp.os` | `src/api/facade.ts:879` — `window.wp.os = api` | `assets/desktop-mode.js` (65 call sites, unchanged — self-aliased at the gate) |
| `desktop-mode.drop.files-detected` (wp.hooks filter) | `os.drop.files-detected` | `src/hooks.ts:1445` — `FILE_DROP_FILES_DETECTED` | `assets/desktop-dropzone.js` — registers under both names directly (a WeakSet guards a hypothetical double-fire) |

The `wp.os` public API object's shape (`registerCommand`, `notify`, `dock`,
`sideDock`, `icons`, `registerWidget`) is unchanged from `wp.desktop` — only
the top-level namespace renamed.

### The early partial `wp.os` shim, and why aliasing by reference is safe

Upstream installs `window.wp.os` **twice**, in two stages
(`src/desktop.ts:356`, then `src/api/facade.ts:879`):

1. An early shim carrying only `whenReady` / `ready` / `isReady`, installed
   before bootstrap so a consumer racing `init()` does not blow up on
   `wp.os.whenReady is not a function`.
2. The full API, merged onto **that same object** via `Object.assign` —
   deliberately *not* a reassignment, because reassigning would sever the
   shim's closure binding to its callback queue.

That second property is what makes our by-reference alias
(`window.wp.desktop = window.wp.os` in
[assets/desktop-mode-os-compat.js](../assets/desktop-mode-os-compat.js)) sound:
the object we alias is the object that later grows the full API, so the alias
is never left pointing at a stale husk.

The shim is **not new in v1.1.0** — it is present in v1.0.1 too. Our gate at
[assets/desktop-mode.js:34-38](../assets/desktop-mode.js) accepts either
global, self-aliases locally, and then checks
`typeof window.wp.desktop.registerCommand !== 'function'` — which is
precisely the check that distinguishes the partial shim from the full API, so
a consumer that runs mid-bootstrap bails cleanly instead of registering into a
void.

## CSS

Our plugin's CSS does **not** consume any `--wpd-*` or `--desktop-mode-*`
custom property (grepped `assets/*.css` — zero hits), confirming the
discipline noted in project memory (TRAP 8 — the widget card deliberately
avoids upstream color tokens) still holds.

The one class selector we DO read from upstream — `body.desktop-mode-chromeless`
in `assets/admin.css` (hides our in-page tab nav inside a chromeless shell
window, so the shell's own in-window tab strip is the only nav shown) — IS a
real exposure: it's a body class the shell adds via `admin_body_class`, not a
hook we register on, so it sits outside the dual-registration mechanism above.
Verified @ v1.1.0: `includes/render/body-classes.php`,
`openstation_admin_body_classes()` — `ltrim( $classes . ' os-chromeless' )` at
, the `.desktop-mode-*` → `.os-*` CSS rule holding exactly.
`assets/admin.css` lists both selectors (`body.desktop-mode-chromeless .sn-nav-tabs,
body.os-chromeless .sn-nav-tabs`); exactly one will ever match on a given
install.

## v1.1.0 delta — four changes assessed, zero code changes required

Reviewed 2026-08-14 against the v1.1.0 release notes and a
`git diff v1.0.1 v1.1.0` restricted to the files this plugin consumes. Four
changes looked capable of breaking us. None do.

**PR #545 — "Consolidate navigation into a single dock."** The highest-risk
change. `openstation_build_menu_payload()` now drops any dock item whose
`placement` is `'hidden'`, and partitions the survivors on a new per-item
`isCore` flag. Our injected item
([inc/desktop-mode-dock.php](../inc/desktop-mode-dock.php)) supplies
neither key. It survives because upstream reads both defensively —
`'hidden' !== ( $item['placement'] ?? 'dock' )` keeps it, and
`empty( $item['isCore'] )` files it into the plugin group, which is where a
plugin's item belongs. The same PR also adds `selfLabel`, and
`dockOrder`/`placeable` on native windows; all three are additive and we
register no native windows.

**`wp.os.sideDock` is `null` under the new default layout.** PR #545 makes
`unified` the default (`includes/os-settings.php` —
`'desktopLayout' => 'unified'`), and only the `classic` layout mounts a side
rail. Our attention badge calls `sideDock?.setBadge?.()` at
[assets/desktop-mode.js:436](../assets/desktop-mode.js) — optional-chained, so
it no-ops rather than throwing, and the SN tile sits on the primary rail in
both layouts, so the badge still renders. Benign; the surrounding comment's
"three rails" framing remains accurate because `classic` still exists.

**PR #574 — "Drop the SSE transport and answer over a single request."**
Churned ~365/269 lines in `includes/ai-copilot/search.php`, which is where
three of our nine seams live. Filtering that diff for our seam names shows it
touches **none** of them — what was deleted is the progress-message
machinery (`openstation_ai_progress_message()`) that only existed to narrate a
stream. Our integration hooks filters and actions, never the transport, so the
transport swap is invisible to us. `request_id` retains its per-run semantics
(a UUID correlating the whole run, reused across the agent iteration loop),
which is the assumption the double-fire guard's family-awareness rests on —
see [REJECT #11](#review-round--reject-11) below.

**PR #549 — "AI: add a filter for the model config sent to the provider."**
New seam, `openstation_ai_model_config`. Not consumed, and nothing requires
us to. Noted here because it is a cleaner hook than the `http_request_args`
route currently used to reach Anthropic-specific request fields, should that
layer ever be revisited.

## Re-verifying after an upstream release

Do this **instead of** re-reading the citation tables above. Line numbers are
a snapshot; membership is the contract.

```bash
git clone --depth 1 --branch vX.Y.Z https://github.com/WordPress/openstation.git
```

Then, from the clone, assert that every upstream name this plugin references
still exists — a count of `0` on any row is the finding:

```bash
for n in openstation_agent_completed openstation_agent_runner_generate openstation_agent_tool_result openstation_ai_ability_tool_name openstation_ai_system_prompt_appendix openstation_ai_tool_called openstation_ai_tools openstation_dock_items openstation_dock_placement openstation_icon_url openstation_is_enabled openstation_living_tree_traffic openstation_plugins_window_icon_url openstation_register_command openstation_register_icon openstation_register_widget openstation_resolve_script_payload; do printf '%4s  %s\n' "$(grep -rho "\b$n\b" includes/ --include='*.php' | wc -l | tr -d ' ')" "$n"; done
```

Regenerate that name list from our own source rather than pasting it, so a
newly-added consumer is covered automatically:

```bash
grep -rhoE "openstation_[a-z_]+" inc/ assets/ --include='*.php' --include='*.js' | sort -u
```

Multi-line `apply_filters(` / `do_action(` calls need a paragraph-mode sweep;
a single-line grep will report a live hook as missing:

```bash
perl -0777 -ne 'while (/(apply_filters|do_action)\s*\(\s*.(openstation_[a-z_]+)./gs) { print "$2 ($1)\n" }' $(grep -rl openstation_ includes/ --include='*.php') | sort -u
```

### Name membership is not enough — probe the runtime too

Every sweep above answers "does the name still exist". All of them passed clean
against v1.1.3 while Cmd+K commands were completely dead, because the break was
behavioural: upstream deferred the `core/commands` runtime to first ⌘K, so a
contributor registering at boot wrote into a store that did not exist yet.
Nothing in this repo could detect that — `tests/desktop-mode-integration.php`
asserts every registered command has a JS `run()`, which stayed true and green.

So after any upgrade, open a wp-admin screen and run this in the console:

```javascript
performance.getEntriesByType('resource').filter(r=>/desktop-mode\.js|snt-ability-run|command-palette/.test(r.name)).forEach(r=>console.log('LOADED:',r.name.split('/').pop()));
const cmds = wp.data.select('core/commands').getCommands?.() || [];
console.log('SN commands in store:', cmds.map(c=>c.name).filter(n=>/signal-noise/.test(n)));
cmds.find(c=>c.name==='signal-noise/get-deploy-status')?.callback({close:()=>console.log('close() called')});
```

Read it as: three scripts LOADED, 19 SN commands in the store, and
`close() called` with no `[SN] ability error:` means **our side is healthy** and
any palette failure is upstream. Then actually pick a command in the palette —
that is the only step that exercises the shell/iframe round trip, and it is the
half that broke at v1.1.3.

Do not chase these first; all three were wrong on 2026-08-26. A core-store vs
OpenStation-registry split (both registries were dead). The silent bail guards in
`assets/desktop-mode.js` (`registerCommand` was a function; both guards cleared).
An ad blocker (`ERR_BLOCKED_BY_CLIENT` was a red herring — disabling it changed
nothing). Note also that `snDesktopData` being present does **not** prove the
script ran: `wp_localize_script` prints it inline before the `<script src>`.

**Adopting #683 will change this probe's own passing shape — read that as
healthy, not as a regression.** The fix does not restore the current mechanism;
it replaces it. Per upstream's description, a palette contributor is *dequeued
from the boot document* and *hoisted to the shell's deferred manifest*, so it
executes during the replay, after the `core/commands` store exists. This plugin
has exactly one convicted contributor — and it is **not** the one most of this
file is about. Keep the two palette surfaces apart:

| Surface | Registers via | Touched by #683? |
|---|---|---|
| `inc/desktop-mode-commands.php` — 21 fixed `sn-cmd-*` on `init:6` | `snt_os_register_command()` → OpenStation's own registry | **No.** #683 trims script assets; this never declares `wp-commands` |
| `inc/command-palette.php` → `assets/command-palette.js` | JS `dispatch('core/commands').registerCommand()` | **Yes.** Its dep array names `wp-commands` in `inc/command-palette.php` (`wp_register_script( 'snt-command-palette', … )`), and the walk spares only Core packages |

Concretely, after the upgrade:

- Windows will no longer load `snt-command-palette` at all. The `LOADED:` line
  dropping that script *in a window* is the fix working, not the break returning.
  Judge health by the store contents and the round trip, not by the resource list.
- Block-editor screens are exempt upstream, so the chain still loads there.
- **The escape-hatch filter is not needed here, and that is now settled rather
  than assumed.** `openstation_command_palette_contributor_owns_screen`
  (upstream `includes/render/chromeless-trim.php`, applied at the tail of
  `openstation_command_palette_owns_screen()` as
  `apply_filters( 'openstation_command_palette_contributor_owns_screen', $owns, $handle, $owner, $page )`)
  exists for a contributor that must stay in a
  window to register *screen-specific* commands. Ours registers two dynamic
  families — `signal-noise/goto-<tab>` from `sn_admin_top_tabs()`, and
  `signal-noise/edit-note-<id>` for the 5 most-recent Notes — and **both are pure
  navigation**: each callback is `navigateTo( url, args.close )`. A navigation
  target is globally meaningful by definition, so registering it once on the
  shell is correct, not a leak. Hoisting also collapses the recent-Notes
  `apiFetch` from once-per-window to once, which is strictly better.
- Default routing already sends us down that path: `owns_screen()` keeps a
  contributor in the window only when the URI is under
  `/wp-content/plugins/<owner>/` or `$_GET['page']` *starts with* the plugin's
  directory slug. Our slug is `signal-and-noise-tools`; our pages are
  `page=sn-*`. No prefix match → not owned → hoisted → the fixed path.

So the release that carries #683 is itself a seam event: it is the fourth
consecutive upgrade to change this seam. Re-run the probe against it, and expect
to rewrite the two bullets above once it is measured rather than predicted.

Finally, diff only the files we actually consume — it is a far smaller read
than the release notes, and it catches silent shape changes the notes omit:

```bash
git diff --stat v1.0.1 v1.1.0 -- includes/core/payload.php includes/ai-copilot/search.php includes/agents/runner.php includes/living-tree/helpers.php includes/plugins-window/rest-fields.php includes/registries/ includes/commands.php includes/helpers.php includes/render/body-classes.php
```

## What is verified vs. what is not

Every mapping above — 9 hooks, 5 functions, 3 JS surfaces, and the
`.desktop-mode-chromeless`/`.os-chromeless` CSS class — is checked against
real `WordPress/openstation` source at tag **v1.1.0**. The full plugin suite
passes unmodified against it (exit `0`, zero `FAIL`, 17,522 assertions,
2026-08-14).

**The site runs v1.1.0 in production** (owner-confirmed 2026-08-14). The
post-rename path is therefore the live path, not a hypothetical one, and it
carries daily traffic without incident. Two earlier claims in this file —
that the post-rename path "has never executed against a real WordPress admin"
and that the site "has not been upgraded off the pre-rename line" — were
wrong and are withdrawn.

That said, **"in production without complaints" verifies exactly the surfaces
a human would notice, and no others.** Split honestly:

| Seam | Status |
|---|---|
| Dock item, desktop icons, widgets, chromeless nav, dropzone | **Field-verified live** 2026-08-14 |
| **Cmd+K commands + the `wp.desktop` alias** | **Three breakages, three upgrades** — v1.1.0 load order (fixed v11.7.1), v1.1.0 `label` requirement (fixed v11.7.2), v1.1.3 deferred palette runtime (fix merged UPSTREAM in #683, **unreleased** — nothing to change here, and nothing fixed here yet) — see below. **Assume this seam is broken after every OpenStation upgrade until proven otherwise.** |
| Copilot tool-invocation log (`sn_ai_tool_invocations`) | **Verified live** 2026-08-14 — delta exactly `+1` |
| Agent telemetry (`{prefix}sn_tool_call`) | **Unreachable, not unverified** — agents disabled by owner decision 2026-08-07, so the producer cannot fire |
| Living-tree traffic | **Unverifiable by observation** — falls back to a plausible default rather than an error |

**The visible/silent split still holds as a principle** — a seam that fails
without a symptom cannot be vouched for by daily use — but note which way it
cut here. The *silent* sink (§H) passed; the seam that broke was a **visible**
one that nobody happened to look at, because Cmd+K is not on the daily path.
"Fails visibly" only helps when someone exercises it.

### Cmd+K commands are dead on v1.1.0 — `defer` broke the load order

`window.wp.desktop` is never set, so `assets/desktop-mode.js` bails at its gate
and none of its 23 commands register. Confirmed against the live palette ("No
commands matching `sn-cmd`", negative-controlled: "post" returns many).

Cause: OpenStation's `desktop.min.js` — which installs `wp.os` — is loaded with
**`defer`** (DOM index 56), while our alias prelude (63) and `desktop-mode.js`
(89) are not. Deferred scripts execute after every non-deferred script, so the
shell that *appears* first *runs* last, and both our scripts execute while
`window.wp.os` still does not exist.

**The durable lesson: `wp_register_script` dependency edges order the printed
markup, not the execution.** Once a dependency is deferred and its dependent is
not, the edge is silently inverted at runtime. REJECT #11 correctly identified
this hazard but bound it to the lazy-loader path; it is live on the ordinary
page-load path. Any future "runs after X" reasoning in this file must check
X's *loading strategy*, not just its dependency edge.

**Fixed in v11.7.1** — a failed gate now schedules one retry
(`wp.os.whenReady()`, else `DOMContentLoaded`, else `setTimeout`) instead of
returning. Pinned by `tests/desktop-mode-boot-order.php`, which **executes**
the asset rather than grepping it; the old assertion checked that the
self-alias *string* was present, and it was, for the entire outage.

### …and a second, independent v1.1.0 break underneath it (fixed v11.7.2)

Making the file run revealed that **`registerCommand()` now validates and
throws on a missing `label`** — `RegistrationError: [openstation] Command
registration rejected — fields: label (missing)`. All 22 of our calls passed
only `{ slug, aiCallable, run }`; the label had always come from the PHP-side
registration. The throw fired on the **first** call and aborted the rest of
the IIFE, so every `run` callback went unattached. Labels now mirror the PHP
ones exactly.

**Two corrections to how the first outage was reported**, kept because the
mistake is more instructive than the fix:

- **"No commands matching `sn-cmd`" was an invalid oracle.** The palette
  searches command **labels**, not slugs. Ours are `SN: …`, so that query
  could never match — broken or healthy. It was a *negative control I never
  ran*: I confirmed the palette worked by searching `post`, which proved the
  palette was alive but not that my query shape could ever match our commands.
- **The failure's shape was misdescribed.** The commands were never missing
  from the palette — the palette is fed by the **PHP** `serverCommands`
  payload, which was never broken. They were **listed but inert**: visible,
  selectable, and silently doing nothing. That is worse than absence, because
  absence is legible and an inert control looks healthy.

The `defer` root cause and the v11.7.1 fix stand on independent evidence:
`window.wp.desktop` was `undefined` after full load, and the only assignment
to it sits below the first gate and above everything else.

Full root-cause, blast radius, and proposed fix:
[docs/ops/openstation-1-1-0-runtime-verification.md](ops/openstation-1-1-0-runtime-verification.md).

The inversion is worth stating plainly: the seams most likely to be quietly
broken are precisely the ones production use cannot vouch for, because their
failure mode is *absence of a row* — and absence is what a never-fired hook
and a genuinely quiet week look like alike. See
[[realtime-zero-vs-null]]-style reasoning: never-measured and measured-zero
are different answers, and this sink cannot tell you which it is.

**If that ever matters**, §H and §I of
[docs/ops/openstation-1-1-0-runtime-verification.md](ops/openstation-1-1-0-runtime-verification.md)
are the two sections still worth running — each is a single `wp eval` counter
read before and after one deliberate invocation. The rest of that checklist is
superseded by the field evidence above, and its v0.9.8 baseline pass is no
longer available.

## Review round — REJECT #11

*Historical record of the original ship's adversarial review (2026-08-04,
against trunk). Retained as-is; the reasoning below still describes why the
current code is shaped the way it is.*

Adversarial review of the initial ship REJECTed on one HIGH, one MEDIUM, and
three LOWs. All four findings were fixed in-worktree, watched-RED first for
every behavioral change.

**HIGH — the double-fire guard dropped legitimate identical-repeat events,
today.** `snt_os_compat_seen_once()` was a plain per-request boolean keyed
on a call's full identity hash — it suppressed the SECOND of ANY two calls
sharing that key, whether or not they were actually the same event. That
is not a hypothetical shim scenario: `openstation_agent_tool_result` passes
no `call_id` in its payload, so two identical tool calls with byte-identical
output within one agent run are indistinguishable by payload; a Copilot
`$request_id` is per-RUN (reused across the iteration loop), so a same-tool
same-args repeat within one turn hashes identically too. The old guard
silently dropped the second row on the v0.9.8 line — single hook family, zero
transition shims anywhere in play — corrupting the exact telemetry the MCP
consolidation program's retirement decisions read. Fixed by making the guard
**family-aware**: `snt_os_compat_seen_once()` now counts firings per
`(key, hook family)`, family derived from `current_filter()`'s prefix
(`desktop_mode_` vs `openstation_`, via `snt_os_compat_current_family()`), and
suppresses a firing only when the OTHER family has fired MORE times for that
key than THIS family has. A same-family repeat's own count only ever grows
when it records, so same-family firings never trip that condition — both
proceed. A true both-families transition shim would still fire each event once
per family and collapse to exactly one recorded row, which was always the
guard's actual job. Two inline comments claiming the guard "only matters if a
future release ever ships both as a transition shim" were FALSE and were
corrected.

**MEDIUM — the lazy widget/command loader bypasses the alias prelude
entirely.** `openstation_resolve_script_payload()` (v1.1.0:
`includes/core/payload.php`) resolves only a script handle's own
`src` and never walks its declared `wp_register_script` deps; upstream's
server-sync/command-sync inject one bare `<script src="...">` tag per URL. So
`sn-desktop-mode-os-compat`'s dependency edges guarantee the alias runs first
only on the ordinary WP-enqueued boot path — never on this lazy-load path. A
post-rename mid-session shell activation could load a widget file or
`assets/desktop-mode.js` before the prelude ever ran, leaving
`window.openStationWidgets` (the name upstream actually reads) empty even
though the widget wrote to `window.desktopModeWidgets` —
`widget-missing-mount`, and every Cmd+K command dead until reload. Fixed by
making every consumer **self-sufficient** instead of order-dependent: all 8
`assets/desktop-mode-widget*.js` files' single-line
`window.desktopModeWidgets = window.desktopModeWidgets || {}` prologue became
a 3-statement merge-and-alias onto ONE object under both names (survivor =
`openStationWidgets`, the name upstream itself reads post-rename; a
both-populated-and-differing race copies the loser's keys in first, so no
already-mounted widget is lost); `assets/desktop-mode.js`'s gate now accepts
either `window.wp.desktop` or `window.wp.os` and self-aliases
`window.wp.desktop` locally, so its 65 existing `window.wp.desktop.*` call
sites keep working unchanged either way. The `sn-desktop-mode-os-compat`
prelude registration is KEPT — it still runs first on the ordinary boot path,
print-order tidiness — but its docblock's claim that ordering is "always in
place first, on either OpenStation line" was overbroad, and its claim that
"`src/widgets/server-sync.ts` awaits our script's `<script>` load" was
outright FALSE (server-sync awaits the WIDGET's own URL load; upstream has no
awareness this compat file exists at all). Both corrected.

**LOW — the REST icon-URL belt only ever wrote the pre-rename field key.**
`inc/desktop-mode-integration.php`'s `rest_prepare_plugin` belt (v2.1.7,
"ALWAYS override") wrote `desktop_mode_icon_url` only. Post-rename OpenStation
renames the REST field's JSON *key itself* to `openstation_icon_url` — a
different seam from the already-dual-registered
`desktop_mode_plugins_window_icon_url` / `openstation_plugins_window_icon_url`
*filter*, which supplies the field's *value* via `get_callback` but cannot
rename the key the response carries. The belt now dual-writes both keys.
(Still correct at v1.1.0: the field is registered as `openstation_icon_url` at
`includes/plugins-window/rest-fields.php`.)

**LOW — two `payload.php` line citations were off by one.** Corrected at the
time against trunk. Both have since moved again with v1.1.0 and are recorded
at their current values in the table above — which is the whole reason the
[re-verification recipe](#re-verifying-after-an-upstream-release) exists.

**LOW — the appendix filter under-declared its real arg count.** The
post-rename call site is
`apply_filters( 'openstation_ai_system_prompt_appendix', '', $ctx_for_filter )`
— two args — but the callback was registered with the default
`accepted_args=1`. Cheap future-proofing: now registered with
`accepted_args=2`, so a future need to read `$ctx_for_filter` only means
widening the closure's own signature, not touching the registration.

Every fix above was RED-pinned (a failing assertion against the pre-fix code,
confirmed to fail for the right reason) before the corresponding code change
landed. Full account, including exact RED output, per-file assertion deltas,
and final sweep/phpstan/phpcs numbers: see the `[10.43.0]` review-round entry
in [CHANGELOG.md](../CHANGELOG.md).


## v13.98.0 — the Explorer folder became an app

OpenStation 1.1.6 rebuilt WP Explorer on its App Framework (`apps/my-wordpress/`). Two filters the v12.4.0 integration used are no longer read: `openstation_my_wordpress_entities` is documented as **inert** (it still runs; no window consumes its list) and `openstation_my_wordpress_window_args` went with the legacy window. The rebuilt Explorer offers `openstation_my_wordpress_app_sections`, but a section there is a whole post type (`kind` post | media | user | agent) with no query scoping, so a category-scoped Notes section and an option-backed Discography cannot be expressed.

The plugin now ships its own app instead: `apps/signal-noise/signal-noise.os.php`, registered through `openstation_apps_directories` (the framework's documented path for third-party apps). It shipped painted by server views; #1049 rebuilt it as a **client view** the way WP Explorer is built -- `App::client()` names `apps/signal-noise/signal-noise-client.js`, plain JS queued through `window.openStationAppsPending` (the runtime's documented no-build path for a plugin outside OpenStation's repo), `App::data()` is the payload, and selection / search / filtering / the view switch are `local` reducers that never make a request. The client script rides the window as `openstation-app-<id>-client` when the framework can map its path to a URL; the same symlink trap that loses the stylesheet loses the script, so `snt_os_app_window_args` appends its own `snt-os-app-signal-noise-client` handle only when the framework's is absent. `inc/openstation-app.php` holds the loader, the section registry (`snt_os_app_sections`, the extension point -- see the contract in that file's header) and the provenance summary. `inc/desktop-mode-explorer.php` stays for the `sn_provenance` REST field; its two filters are harmless where they are inert.

Seams the app module consumes, all Experimental at 1.1.6: `openstation_apps_directories` (filter, where the framework loads `.os.php` files from), `openstation_app_window_args` (filter, the registration args of an app window just before `openstation_register_window()` runs -- our stylesheet fallback rides it), `openstation_apps_style_handle()` (the handle the framework registers an app stylesheet under; we test for its presence in `styles`), and `openstation_apps_path_to_url()` (the framework-side path-to-URL mapping whose `''` for a path outside wp-content is the reason the fallback exists).

Verified against the 1.1.6 sources: `includes/framework/wordpress.php` (`openstation_apps_directories`, `openstation_app_window_args`, `openstation_apps_style_handle`, `openstation_apps_path_to_url`, `openstation_register_window`), `includes/framework/app/class-registry.php` (`load_dir()` picks up `*.os.php` one folder deep), `docs/app-framework.md` ("Where apps live", "Third-party client views"), `docs/hooks-reference.md` (the inert note).

Phase one of the deeper note (13.100.0) adds two more runtime seams, both documented in `docs/app-framework.md` at 1.1.6: `App::config()` (static values shipped once with the window config and read as `ctx.extra` in the client -- the dossier ability's REST URL travels this way, so no `/wp-abilities/` literal lives in JavaScript) and `ctx.fetch()` (the runtime's REST client: relative to the REST root, nonce and JSON Accept attached, the request attributed to the window; it resolves on 4xx, so the client reads `res.ok` rather than catching). One trap worth recording: `ctx.ui( factory )` hands out ONE bag per mounted view and runs the factory on the first call only, so a second `ctx.ui()` with a different factory silently returns the first bag -- the client keeps all of its local state in a single bag for that reason.

Phase two of the app program (#1065) adds a control surface to the Notes section and, in doing so, consumes five more 1.1.6 seams, all still Experimental:

- The effect vocabulary (`includes/framework/app/class-effects.php`): the four new server actions use exactly two of the eleven named effects, `$os->toast( $message )` and `$os->announce( $type, $action, $ids )`. No effect can ask a question -- confirmation is the client's job (next point).
- `ctx.dispatch( action, args, { confirm: { title, message, label, danger } } )` (`src/app-runtime/client.ts`): opens the shell's own confirm dialog before the request ever leaves the browser and resolves `false` without dispatching on decline. Trash (danger-styled) and Publish (naming the permanence of a signed version) use it; Purge and the anchor retry are idempotent and skip it, answering with a toast instead.
- `applySelection`, `createMarquee`, `copyText` (`CLIENT_API`, `src/app-runtime/index.ts`): the Finder-rules selection math, the desktop rubber-band select, and the clipboard write behind the menu's Copy link / Copy ID rows. All three arrive through the same `openStationAppsPending` queue the phase-one client script already used -- no new build step.
- `window.wp.os.dragManager.start( { payload: { type: 'shortcut', … }, origin } )` (`src/drag/types.ts`; the pattern as WP Explorer uses it, `apps/my-wordpress/parts/wire.ts`): a pointer-event lift, not HTML5 `dataTransfer`. A Notes row or tile lifts with `entityId: 'signal-noise:notes'` and `restPath: 'wp/v2/posts'`, so the shell's existing Recycle Bin and folder drop targets accept it with no new drop-target code in this plugin.
- `<os-context-menu>` / `<os-context-menu-option>` (`src/ui/components/os-context-menu/os-context-menu.ts`): the app renders its own instance at a fixed `{ x, y }` inside its own view, the same pattern WP Explorer uses -- not the framework's `menu` effect, which opens through `openActionMenu` at the pointer and is not used here. A full-window backdrop closes it; picking an option fires a bubbling `os-context-menu-pick` carrying the option's id.

Phase four of the app program (#1071) is the phone phase -- an Attention section and five measured phone fixes -- and consumes two more 1.1.6 seams neither earlier phase read:

- `os-mode-changed`, a `CustomEvent` dispatched on `document` by the mode controller on every REAL band crossing (a call that would leave the mode unchanged returns early and fires nothing). The client subscribes exactly as WP Explorer's own app does (`apps/my-wordpress/my-wordpress.os.ts`): on the event it repaints, and it mounts or tears down the desk-only marquee and drag listeners for the band the window is in right now, rather than carrying whichever band's behaviour it was born with for the rest of its life -- a window opened on the desk and carried into the phone band used to keep its drag listeners; one opened on the phone never got them back.
- `<os-table stacked>` and its `sticky-columns` observed attribute (`src/ui/components/os-table/os-table.ts`, `stack-on-phone.ts`): `stacked` is the kit's own decision to paint a card per row on the phone instead of a sideways scroll with a pinned column, and the component stands its sticky band down once `stacked` is set -- which is why the client writes `sticky-columns="0"` on the phone rather than omitting the attribute; the two are the same state, one said out loud, and there is no way to conditionally omit an attribute on this html tag without a second template. `stackOnPhone()` itself moves `sticky-columns` aside for you, but only inside `createListTableSync()` (the imperative `os-preserve` table rig), which is not how this app's table is built, so the app sets both attributes itself.
- `wp.os.saveSession.flush()` (`assets/js/desktop.min.js`: the namespace object carries `saveSession`, whose `flush` is the session persister's own; the shell's post-update reload is `await ye.flush(), window.location.reload()`). The stale-build line's Reload awaits the same flush before it reloads, inside a try/catch so a shell without it still reloads. This is the seam the first build believed was unreachable from an app; the review measured that it is public, and the spec's booked cost ("an unflushed reload on the operator's click") was retired with it.

## Host one — the classic admin page inside an app window (#1074)

The S&N Dashboard is now an App Framework app (`apps/sn-dashboard/`) that
paints the classic admin page's own HTML inside a window, produced by the same
render callables, with every form saving through the same handler table. This
section records the seams that port rests on, all Experimental at 1.1.6, and
the two measurements that made an iframe unnecessary.

**The desktop document IS wp-admin — measured, not assumed.** `/openstation/`
resolves to `wp-admin/admin.php?page=openstation`; the document carries
`body.wp-admin.wp-core-ui`, and core's `common`, `forms`, `dashboard`,
`list-tables`, `buttons`, `edit` and `media` stylesheets are already loaded on
it. A probe measured `.form-table th` at 257px/10px/left, `.postbox` with its
1px border, `.button-primary` with its background and 2px radius and `.notice`
with its 4px left border — inside a window, with no stylesheet of ours. That
is the whole reason a leaf's admin HTML can be painted directly rather than
sandboxed: **no iframe is used, and none is needed.** It is also the one fact
most worth re-measuring after an upstream release, because a shell that stops
booting on `admin.php` would take the styling of every leaf with it.

**`View::capture()` collects echoed HTML.** A view callable
(`function ( State $state, Os $os )`) may echo, may return a string, or both:
`OpenStation\App\View::capture()` runs it inside `ob_start()` and returns the
echoed buffer concatenated with a returned string, discarding the buffer and
re-throwing if the view throws. Our leaves are echo-only renderers, so a view
that runs `sn_admin_render_active_tab()` needs no rewriting of any leaf at
all — the framework's capture and our own capture helper nest cleanly.

**`os-action` names the action; `os-arg-*` carries the args.** The runtime
walks up from the event target looking for the nearest ancestor carrying
`os-action` (or `os-bind`) whose default event matches the one that fired, and
then reads **every** attribute whose name begins with `os-arg-`, keying each
arg by the remainder of the name. That is the whole arg vocabulary: attribute
names, no encoding, no JSON. A `<form>`'s own contribution is different — the
runtime builds a `FormData` from the form and hands it over as
`$args['values']`, with a repeated field name collected into an array.

**`values` drops anything that is not a string.** The FormData walk keeps an
entry only when its value is a string, so a `File` never reaches the server
through an `os-action` form. Every form in the port map carries `files: false`
— not one admin form uploads — which is why the whole 35-leaf write surface
can ride this seam. A future leaf with an upload cannot, and must stay a real
`<form>` posting to the classic page.

**A click is not `preventDefault`ed — a rewritten link must lose its `href`.**
The runtime prevents the default only for `submit`, for `contextmenu`, and for
a `keydown` that matches `os-keys`. A `<a href>` that gains `os-action` would
therefore dispatch the action *and* follow the href, which on the desktop
document means navigating the whole shell away. So the rewrite pass removes
`href` from every anchor it converts to `go` or `door`, and leaves alone the
anchors it does not convert (`#fragment`-only, `mailto:`, `javascript:`, and
anything already carrying `os-action`).

**Painted HTML never runs an inline `<script>`.** The runtime parses the
view's HTML by assigning it to a `<template>` element's `innerHTML` and then
patching the resulting nodes into the window — a morph that matches children
positionally and syncs attributes, REMOVING any attribute the server's node
does not carry (so a client-written marker attribute never survives a paint;
admin.js marks bound elements with a property instead) — and script nodes
created by the parser that way are never executed, by the HTML spec, not by an
OpenStation choice. The leaves' inline bootstraps therefore need re-creating after each
paint: the rewrite marks them `data-snt-exec` and `assets/os-host.js` clones
each one once so it runs. The same paint boundary is why
`assets/admin.js` grew `window.snAdmin.init( root )` — a `DOMContentLoaded`
binding fires once, and a window's markup arrives long afterwards and again on
every action.

**`WP_HTML_Tag_Processor` is the rewrite tool, and it is core's, not
upstream's.** The pass over captured HTML runs on WordPress core's HTML API
(`wp-includes/html-api/`), which is a stable public class with a documented
tag-by-tag cursor and attribute setters that re-serialize safely. It is the
one dependency in this port that OpenStation cannot break: a regex over admin
HTML would be the alternative, and admin HTML is exactly the corpus where a
regex fails quietly.

**The window-args seam carries the admin handles.** The classic page's assets
are gated on the classic hook suffixes (`sn-admin`, `snt-analytics-tokens`,
`sn-analytics-admin`, `snt-confirm`, `sn-analytics-brush`, `sn-resume-admin`,
`sn-freshness-dot`, `snt-health-suggest-actions`, the uptime handles), so
nothing of ours loads on the desktop page unless the host asks for it. It asks
through `openstation_app_window_args` — the same filter the Signal & Noise app
already uses for its symlink-lost stylesheet and client script — appending
each style and script handle to the window's own companion lists, registering
any that is not registered yet with the same localized data the classic
enqueue functions attach (by calling the same data-building functions, never
by copying their literals). One handle is new: `snt-os-host`, the host script,
which depends on `sn-admin`.

**The dock.** `inc/desktop-mode-dock.php` no longer injects a manual
`sn-dashboard` item through `openstation_dock_items`; the app registers its own
entry under the same id with `->placement( 'dock' )`, and the submenu and badge
that item carried become the window's `menu` effect and `badge` effect, fed by
the same `snt_desktop_dock_badge()`. `snt_desktop_admin_url()` is unchanged and
the classic page stays registered, so every door elsewhere in the plugin that
opens an admin URL still opens what it opened.

- **The window root wears the shell's palette; a hosted admin page must not.** `.os-app` sets `--os-ui-surface`, `--os-ui-fg` and the colour scheme from the desktop theme. The classic pages are light-only, so under a dark palette a hosted page paints white cards with light text (measured on live 2026-09-06). `assets/os-host-admin.css` gives the two host roots wp-admin's own canvas as tokens, remaps `--os-ui-*` onto them and sets `color-scheme: light` -- the rule `chromeless.css` states for an admin page in a window. And the chromeless strip rule in `admin.css` is retired: the shell builds an in-window tab strip only from a dock item's submenu, and the app owns the dock entry now, so a page's own `.sn-nav-tabs` is its navigation in every window.
- **`State::accept()` types every write.** A declared default fixes the slot's type; a write of another shape falls back to the default silently (`app/class-state.php`). A string-valued param (the analytics `range` carries `custom` and calendar tokens) must be declared with a string default, and a test stub of `State` must coerce the same way or the pins cannot see it.
- **One request does what two requests did.** A classic save redirects, so the next paint is a new request with empty request-static memos; a window's replay and repaint share one request, and a memo filled before the write answers after it (`sn_setting()`'s merged settings, measured on the Identity save). After a successful write the host calls the estate's resetters and fires `snt_os_host_wrote`; a new request-static memo that a leaf writes and reads in one paint needs a resetter on that action.
- **A dispatch is a REST request and carries none of `wp-admin/includes/`.** The classic page runs inside wp-admin, where `wp-admin/includes/admin.php` has loaded `submit_button()`, `get_plugins()`, the screen API and the rest; `desktop-mode/v1/apps/<id>/dispatch` loads none of it. Measured 2026-09-06: Integrity → MCP Clients answered 500 "Call to undefined function submit_button()" in the window while capturing cleanly under WP-CLI. `snt_os_host_capture()` requires the library once when `submit_button()` is absent -- admin-ajax.php's own precedent. `get_current_screen()` still answers null on a dispatch: a REST request has no screen.

## Host two — S&N Analytics on the same seam (#1075)

The second app (`apps/sn-analytics/`) consumes no framework seam host one
did not already consume. It is recorded here because the two hosts standing
side by side settle three things a single host could not.

**One seam, two apps.** `inc/openstation-host.php` (capture, rewrite,
replay, own pages, expand) and `assets/os-host.js` (the paint seam, the
submitter, `snt:paint`) are shared, not copied: the Analytics app calls
`snt_os_host_capture()` and `snt_os_host_rewrite()` with its own arguments.
The one extension the second host needed is a keep-list on the rewrite (see
the export, below); everything else is the first host's code reached by a
second caller. Framework-wise both are `App::define()` + one `->view()`
with the tab state in STATE, both `->capabilities( 'manage_options' )` with
every action re-checking `current_user_can()`, and both paint wp-admin HTML
directly into the window with no iframe, for the reason measured in the
host-one section above.

**Own pages are per host, and each host's pages are the other's doors.**
`snt_os_host_rewrite()` takes the set of `page=` slugs THIS window answers
to. For the Dashboard that is the eight top-tab slugs plus the legacy ones,
filtered by the POST allowlist, with `sn-analytics` deliberately excluded —
a different window's surface, so a link to it is a `door`. The Analytics
host inverts exactly that: its own page is `sn-analytics`, and a link into
`sn-theme-options` (the unconfigured gate's CTA into Measurement →
Analytics) is a `door` that opens the classic Dashboard page as an admin
window. Neither host tries to drive the other's window; a cross-host `go`
waits for the doors program named in the proposal's Out of scope.

**A view whose response is a file cannot be a view.** The export form posts
`sn_action=analytics_export`, and `sn_handle_analytics_export()` sets
`Content-Disposition`, echoes a raw CSV or JSON body and `exit()`s. Nothing
about that fits a contract whose whole shape is "the callable echoes HTML
and `View::capture()` collects it", and the framework has no effect that
hands the browser a file. So the rewrite pass leaves that one form REAL: it
keeps `method="post"`, keeps its `action` on the classic page URL, and
gains `target="_blank"`, so the download is a navigation in a new tab. This
is the export's counterpart to host one's `sn_force_update_check` door:
faithful means the destination still happens, not that the mechanism is
identical.

**The state a host keeps is the page's own state, validated by the page's
own validators.** The Analytics page put its state in the URL and
whitelisted it on read; the window puts the same nine params in State and
applies them through `snt_analytics_resolve_view()`,
`snt_analytics_resolve_window()`, `snt_analytics_resolve_class()` and
`snt_analytics_resolve_compare()`, resetting on a view switch exactly the
keys `snt_analytics_view_reset_params()` returns. A host that re-derived
those rules would be a second implementation of the page's URL contract,
free to drift; calling them is why a classic URL and a window state stay
the same fact.

**One limitation, restated from the host-one fold.** `assets/admin.js`
persists each analytics panel's collapse state in `localStorage` and
restores it in a pass that runs once, over `document`, at parse time —
outside `window.snAdmin.init( root )`, which is the only thing the host
re-arms after a paint. Inside a window the panels still open and close (the
toggle is a delegated document listener), but a panel does not come back
the way the reader left it. Recorded rather than fixed: moving the restore
into `init()` is a change to the classic page's behaviour as well, and no
one asked for one.
