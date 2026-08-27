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

**Last verified against `v1.1.2`** (2026-08-21). See
[Re-verifying after an upstream release](#re-verifying-after-an-upstream-release)
for the instrument.

**The site runs `v1.1.3`, which is NOT verified here** (2026-08-26). It carries a
known Cmd+K break — every command that needs a JS callback silently no-ops —
diagnosed to upstream's deferred palette runtime. `WordPress/openstation` PR #683
confirms the diagnosis in upstream's own words and carries the fix, so there is
nothing to change here and no issue to file — **but #683 is MERGED TO TRUNK AND
UNRELEASED.** Measured 2026-08-26: v1.1.3 is the latest tag, and #683's commit
(`199a0851`) is **15 commits ahead of it, 0 behind** — no tagged release contains
it. So the break is live on this site and stays live until upstream tags a
release carrying #683 and we upgrade to it. Merged is not shipped; do not read
"fixed upstream" as "working in production".

The name-membership sweep below passes clean against v1.1.3: every upstream name
this plugin references still exists. The break is behavioural, which is exactly
the gap the runtime probe in that section now covers.

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
| **v1.1.2** | **2026-08-21** | Post-rename — **the release this file is verified against** (19 names) |
| **v1.1.3** | **2026-08-24** | Post-rename — **running in production, NOT verified here.** Deferred the palette's Gutenberg runtime to first ⌘K; broke plugin-contributed commands. Fix merged to trunk in #683 but **in no tagged release** as of 2026-08-26 — still broken here |

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
| *(none — postdates the rename)* | `openstation_register_desktop_theme()` | `includes/desktop-themes/registry.php` |

All five renamed functions still accept exactly the argument shapes we pass at
v1.1.0 (re-checked against each function's `$defaults` array, not just its
signature).

**The sixth and seventh rows have no old name, and that is the point.**
Station Home shipped in upstream v1.1.2 (PR #625) and desktop themes in
v1.0.0 (`includes/desktop-themes/`, verified present at that tag) — both
*after* the rename, so neither `openstation_register_station_home_card()`
nor `openstation_register_desktop_theme()` ever had a `desktop_mode_*`
twin. Their wrappers (`snt_os_register_station_home_card()`,
`snt_os_register_desktop_theme()`) therefore check ONE name where every
other wrapper in the compat layer checks two — a deliberate asymmetry, not
a missed case.

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
