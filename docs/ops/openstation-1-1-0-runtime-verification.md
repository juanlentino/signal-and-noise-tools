# OpenStation v1.1.0 — runtime verification checklist

> **SUPERSEDED IN PART, 2026-08-14 — read this before using the document.**
>
> This checklist was written believing the site still ran the pre-rename
> v0.9.8 and would be upgraded. **The site was already on v1.1.0.** Two
> consequences:
>
> 1. **The baseline pass is gone.** Its whole design — run everything on
>    v0.9.8 first, so each check becomes *"did the upgrade change this?"* and
>    every instrument gets negative-controlled — required a pre-upgrade
>    window that no longer exists. §Pre-flight and §Baseline pass below are
>    **dead as written**; the "Expect `0.9.8`" instruction will simply fail.
> 2. **Most of the remaining checks are already answered by production.**
>    §A–§G cover surfaces that fail *visibly* (missing dock tile, dead
>    command, unmounted widget). The site runs daily on v1.1.0 without those
>    symptoms, which is real evidence.
>
> **What is still worth running: §H and §I only.** Those two sinks fail
> *silently* — a hook that stopped firing writes no row and logs nothing, and
> no amount of normal use surfaces it. Each is one `wp eval` counter read,
> one deliberate invocation, one re-read. Skip the baseline language and read
> the delta against the count you take immediately beforehand.
>
> Everything below is retained as-is for the failure-signature tables, which
> remain correct and are the useful part.

Owner-run. **~5 minutes** for §H + §I; the original ~30 minute estimate
covered the now-superseded full pass.

## RESULTS — 2026-08-14, live on juanlentino.com (OpenStation v1.1.0, plugin v11.7.0)

| § | Seam | Result |
|---|---|---|
| **§C** | `wp.desktop` ⇄ `wp.os` alias | **FAIL** — probe returned `["object","undefined",false,"undefined"]`; `window.wp.desktop` never set |
| **§D** | Cmd+K commands | **FAIL** — palette reports *"No commands matching sn-cmd."* All 23 SN commands dead |
| §A | Dock item | PASS — single SN megaphone tile, plugin cluster, opens dashboard |
| §B | Desktop icons | PASS — `sn-icon-dashboard` present, badge target resolves |
| §E | Widgets | PASS — all 8 SN ids in `openStationWidgets`, and it `===` `desktopModeWidgets`; Deploy Status / Quick Actions rendering live data |
| §F | Chromeless nav | PASS — single nav row, no doubled tab strip |
| §G | Plugins-window icon | Not separately probed (shell surfaces rendered normally) |
| **§H** | Copilot tool log | **PASS** — see below |
| §I | Agent telemetry | **NOT RUNNABLE** — see below |
| §J | Dropzone | PASS (mechanism) — both `desktop-mode.drop.files-detected` and `os.drop.files-detected` registered on `wp.hooks` |

### §H — PASS, delta exactly +1

Before: `16 calls across 9 tools`, `provenance_integrity_status` = **1**.
Asked the Site Assistant *"What is the current provenance integrity status of
this site?"*; it answered with real ledger data (32 Notes, 10 checked, 0
failed, key verdict `ok`).
After: `17 calls across 9 tools`, `provenance_integrity_status` = **2**.

Reading against the three-way table: not `0` (seam dead), not `+2`
(double-fire guard regressed) — **exactly `+1`**. This confirms, in one shot:
`openstation_ai_tool_called` fires and reaches our listener; the family-aware
guard neither over- nor under-suppresses on a single hook family; and
`openstation_ai_tools` schema normalization holds, since a malformed tool
schema would have 400'd the whole provider request instead of answering.

### §I — NOT RUNNABLE (not "unverified")

The producer does not exist on this install. OpenStation's **agents feature is
disabled and the Editorial Agent was deleted by owner decision (2026-08-07)**
— "we have the MCP to do what the WP agents would do". `openstation_agent_tool_result`
and `openstation_agent_completed` fire only from `includes/agents/runner.php`,
so with no agents there is nothing to invoke.

Record this as **unreachable, not unverified** — the distinction matters.
Unverified implies a seam that might be silently broken; unreachable means it
cannot fire at all, so it cannot be broken either. Running §I would first
require re-enabling agents on production, reversing a deliberate owner
decision, and the standing direction is to revisit only when OpenStation
agents are stable rather than Experimental.

### UNPLANNED FINDING — §C/§D are a live regression, root-caused

`window.wp.desktop` is `undefined` after full page load. The only code path
leaving it undefined is `assets/desktop-mode.js`'s first gate returning early,
because line 37 (`window.wp.desktop = window.wp.desktop || window.wp.os`) runs
before every other exit. So the gate saw **neither** global — and therefore
never reached `registerCommand`.

**Mechanism: `defer` decouples DOM order from execution order.**
Measured in the live DOM:

| Script | DOM index | `defer` |
|---|---|---|
| OpenStation `desktop.min.js` (installs `wp.os`) | 56 | **true** |
| ours `desktop-mode-os-compat.js` (alias prelude) | 63 | false |
| ours `desktop-mode.js` (23 commands) | 89 | false |

Deferred scripts execute after **all** non-deferred ones, so despite appearing
*earlier* in the document, OpenStation's shell runs *last*. Both our scripts
execute while `window.wp.os` does not yet exist: the prelude's one-shot
`if ( wp.os && ! wp.desktop )` matches neither branch and silently no-ops, and
`desktop-mode.js` bails at its gate.

This is the ordering hazard [openstation-compat.md](../openstation-compat.md)'s
REJECT #11 anticipated — but it attributed it to the *lazy widget/command
loader* path. It is in fact happening on the **ordinary page-load path**, via
`defer`. `wp_register_script` dependency edges guarantee print order; they
cannot make a non-deferred script wait for a deferred one.

**Why widgets survived and commands did not:** REJECT #11's fix made each
widget file self-aliasing onto a plain global that upstream reads *later*, so
write-before-read still works. Commands must call an API that must already
exist — self-aliasing cannot help when the callee is absent.

**Blast radius:** the 23 Cmd+K commands, plus the attention badge (same IIFE,
after the same gate — currently masked because `snDesktopAttention.total` is
`"0"`, which renders no badge either way). Dock, icons, widgets, dropzone and
all PHP seams are unaffected.

**Suggested fix (not yet implemented):** make `desktop-mode.js` re-attempt
after deferred scripts have run. Deferred scripts execute *before*
`DOMContentLoaded`, so that event is a reliable second chance: extract the
body into an `init()`, call it immediately when either global is present, and
otherwise re-run it on `DOMContentLoaded`, preferring `wp.os.whenReady()` when
available by then. That is immune to any future change in load strategy,
rather than betting on a specific one.

## The hazard this checklist is built around

The post-rename path is **silent when it works and silent when it is
broken**. A hook that never fires, a widget that never mounts, and a command
that never registers all produce the same observable as a working system that
simply had nothing to do: no error, no console warning, no row. "I clicked
around and nothing looked wrong" is not evidence.

Two structural defences, both mandatory:

**1. Run the whole checklist on v0.9.8 FIRST, before upgrading.** This is the
single most important instruction here. It converts every check from *"does
this work?"* (unanswerable when absence is ambiguous) into *"did the upgrade
change this?"* (answerable). It also negative-controls every instrument in the
document — you will have watched each one emit a positive on the pre-rename
line, so a later zero means something. Skipping the baseline pass makes a
clean run on v1.1.0 nearly worthless.

**2. Record numbers, not impressions.** Every DB-backed check is a *delta*
against a captured baseline. A count of `0` after the upgrade is only a
finding if you know it was non-zero before.

## Pre-flight

- [ ] **Prefer staging.** If no staging exists, note that `main` is protected
      and the plugin repo is public — but this upgrade touches only the
      *OpenStation* plugin on the site, not this repo. The blast radius is
      wp-admin's shell, not content.
- [ ] Record the current OpenStation build and confirm it is the pre-rename
      line:
      ```bash
      wp plugin list --name=desktop-mode --fields=name,status,version
      ```
      Expect `0.9.8`. If it already reads `1.x`, the baseline pass below is
      unavailable — say so in the results rather than fabricating one.
- [ ] Confirm the plugin file name, which did **not** rename upstream — the
      slug stays `desktop-mode` even on v1.1.0. A `wp plugin list` filtered on
      `openstation` returning nothing is expected, not a finding.
- [ ] Take a DB snapshot, or at minimum export the two sinks this checklist
      reads:
      ```bash
      wp option get sn_ai_tool_invocations --format=json > /tmp/sn-baseline-invocations.json
      ```
- [ ] Have the rollback ready and **confirm the artifact exists before
      upgrading**, not after a failure:
      <https://github.com/WordPress/openstation/releases/tag/v0.9.8>

## Baseline pass — on v0.9.8, before upgrading

Run **§A through §K below** exactly as written, on the current pre-rename
install, and record each result. Every check should pass here; the
`desktop_mode_*` family is what runs in production today.

- [ ] Any check that **fails on v0.9.8** is a pre-existing bug, not a rename
      regression. Stop and record it separately — do not attribute it to the
      upgrade later.
- [ ] Capture the two counters:
      ```bash
      wp eval 'echo count( (array) get_option( "sn_ai_tool_invocations", array() ) ), "\n";'
      wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sn_tool_call" ), "\n";'
      ```
      Write both numbers down. §H and §I are deltas against them.

## Upgrade

- [ ] Install OpenStation v1.1.0.
- [ ] Hard-reload wp-admin (bypass cache; the shell's JS is versioned but the
      compat prelude is ours).
- [ ] Re-confirm the family actually flipped — this is the precondition for
      every check below, and the one thing that makes a clean run meaningful:
      ```bash
      wp eval 'echo function_exists( "openstation_register_command" ) ? "POST-RENAME\n" : "PRE-RENAME\n";'
      ```
      If this prints `PRE-RENAME`, the upgrade did not take. Everything after
      this point would pass for the wrong reason — the old family still
      running — which is exactly the false-clean this checklist exists to
      prevent. Stop here.

## The checks

### §A — Dock item (`openstation_dock_items` + `openstation_dock_placement`)

The highest-value PHP check: it exercises both dock hooks at once, and
v1.1.0's PR #545 rewrote the surrounding assembly.

- [ ] **A1.** Exactly **one** "Signal & Noise" tile on the dock.
- [ ] **A2.** Its icon is the **megaphone**, not a generic cog.
- [ ] **A3.** Clicking it opens the SN dashboard, and the submenu rides in as
      an in-window tab strip whose entry count matches the wp-admin SN submenu.
- [ ] **A4.** The tile sits in the **plugin cluster** — after Dashboard, Posts,
      Media and the rest of core. *(New in v1.1.0: items are partitioned on an
      `isCore` flag our item deliberately does not set.)*

| Observation | Meaning |
|---|---|
| **Two** SN tiles | `openstation_dock_placement` is not firing — auto-import unsuppressed |
| **Zero** SN tiles | `openstation_dock_items` is not firing, **or** PR #545's `placement`/`isCore` defaults stopped being defensive |
| One tile, **generic icon** | Our explicit item is missing; you are looking at the auto-imported one |
| Tile among **core** items | `isCore` is being inferred for us; harmless cosmetically, but report it |

### §B — Desktop icons (`openstation_register_icon`)

- [ ] Two wallpaper icons present: **SN Dashboard** (`sn-icon-dashboard`) and
      **SN Identity** (`sn-icon-identity`).
- [ ] Both open a working page — not WP's "Sorry, you are not allowed to
      access this page."

Neither present → `snt_os_register_icon()` resolved to no upstream function.
One present → not a rename fault; check the slug resolver.

### §C — The JS alias, direct (`wp.desktop` ⇄ `wp.os`)

**The single highest-signal check in this document.** It proves the aliasing
layer directly instead of by proxy, and it distinguishes the *partial early
shim* from the *full API* — a distinction nothing visual can make.

Paste into the browser console on a shell page:

```js
[ typeof window.wp?.os,
  typeof window.wp?.desktop,
  window.wp?.os === window.wp?.desktop,
  typeof window.wp?.desktop?.registerCommand ]
```

- [ ] Expect exactly `["object", "object", true, "function"]`.

| Result | Meaning |
|---|---|
| `[…, false, …]` | The two globals are **different objects** — the alias did not take, and writes through one are invisible to the other |
| `[…, "undefined"]` on the last slot | Only the **partial early shim** is installed; the full API never merged. Commands will be silently absent |
| `["undefined", "undefined", …]` | The shell is not active on this page at all — you are not testing what you think you are |

### §D — Commands (Cmd+K)

- [ ] Open the command palette, type `sn-cmd`. Expect the SN commands to list
      (23 are registered in `assets/desktop-mode.js`).
- [ ] Run one harmless navigation command — `sn-cmd-nav-dashboard` — and
      confirm it navigates.

Zero SN commands with §C green is a real finding: the alias held but
registration still failed. Zero with §C red is just §C.

### §E — Widgets (`window.openStationWidgets`)

```js
[ Object.keys( window.openStationWidgets || {} ).filter( k => k.startsWith( 'sn-' ) ),
  window.openStationWidgets === window.desktopModeWidgets ]
```

- [ ] Expect the 8 SN widget ids — `sn-site-views`, `sn-health`, `sn-uptime`,
      `sn-deploy-status`, `sn-quick-actions`, `sn-rss-subscribers`,
      `sn-anchors`, `sn-machine-readers` — and `true`.
- [ ] Add one widget from the picker and confirm it **renders content**, not an
      empty frame.

`false` on the second slot is the exact failure the self-aliasing prologue was
written to prevent: two separate registries, upstream reading the one we did
not write to. A widget that mounts but renders empty is a *different* bug —
note it as such rather than filing it here.

### §F — Chromeless nav (`os-chromeless` body class)

- [ ] Open any SN page inside a shell window. Our in-page tab nav must be
      **hidden**, leaving only the shell's own in-window tab strip.

Two stacked navs = the `.os-chromeless` selector is not matching. This is the
one CSS surface outside the dual-registration mechanism, so it fails
independently of everything above.

### §G — Plugins window icon (`openstation_icon_url` field + filter)

- [ ] Open the Plugins window, find **Signal & Noise Tools**, confirm the icon
      is ours rather than a wp.org placeholder.
- [ ] Confirm the REST response carries the post-rename **key** — matched on
      our own plugin rather than whichever happens to sort first:
      ```bash
      wp eval '
        $rows = (array) rest_do_request( new WP_REST_Request( "GET", "/wp/v2/plugins" ) )->get_data();
        foreach ( $rows as $r ) {
          if ( false === stripos( (string) ( $r["plugin"] ?? "" ), "signal-and-noise" ) ) { continue; }
          foreach ( array( "openstation_icon_url", "desktop_mode_icon_url" ) as $k ) {
            printf( "%-24s %s\n", $k, array_key_exists( $k, $r ) ? "present: " . $r[$k] : "ABSENT" );
          }
        }'
      ```
      Expect **both** keys present (the belt dual-writes). `openstation_icon_url`
      `ABSENT` means the dual-write is not reaching the post-rename key, and
      the shell — which reads only that one on v1.1.0 — will fall back to a
      wp.org placeholder. Note that this prints nothing at all if the match
      fails; no output is an inconclusive run, not a pass.

### §H — AI Copilot seams (`ai_tools`, `ai_tool_called`, `system_prompt_appendix`)

The delta check. Note the count from the baseline pass, then:

- [ ] Ask the Copilot something that calls an SN tool (e.g. *"what's the site
      health status?"*).
- [ ] Re-count:
      ```bash
      wp eval 'echo count( (array) get_option( "sn_ai_tool_invocations", array() ) ), "\n";'
      ```

| Delta | Meaning |
|---|---|
| **+1 per tool call** | Correct — this is the pass condition |
| **0** | `openstation_ai_tool_called` never fired, or never reached our listener |
| **+2 per call** | The family-aware double-fire guard has regressed. **Report this loudly** — it silently corrupts the telemetry the MCP consolidation program's retirement decisions read |

- [ ] Separately confirm the tool **schemas** were normalized: the Copilot
      answering at all (rather than the provider 400-ing the whole request) is
      the evidence that `openstation_ai_tools` fired. A provider-side 400
      mentioning schema is this seam failing.

### §I — Agent telemetry (`agent_tool_result`, `agent_completed`)

- [ ] Trigger one agent invocation.
- [ ] Re-count against baseline:
      ```bash
      wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sn_tool_call" ), "\n";'
      ```

Same three-way reading as §H: `+1` per tool call is correct, `0` means the
seam is dead, `+2` means the double-fire guard regressed.

- [ ] Force one **failing** tool call if you can arrange it cheaply, and
      confirm a row lands with a non-`ok` outcome — that is seam 2
      (`agent_completed`), which seam 1 never exercises. Skipping this leaves
      the failure path unverified; say so rather than implying coverage.

### §J — Dropzone (`os.drop.files-detected`)

- [ ] Drag a `.txt` file onto the desktop. Confirm the drop is claimed and a
      note is created — exactly **one**, not two.

Two notes would mean both filter names fired (a transition shim upstream); the
WeakSet guard exists for precisely that and its failure would show here.

### §K — Living tree traffic (`openstation_living_tree_traffic`) — weakest check

- [ ] Confirm the wallpaper's tree responds to traffic.

**Honest caveat:** this is a visual, analog surface with no numeric readout,
and a filter that fails returns a plausible default rather than an error. This
check cannot reliably distinguish "working" from "fell back to the default."
Record it as *observed* or *not observed* — never as *verified*.

## Recording the result

- [ ] Note the OpenStation version, the date, and one line per section.
- [ ] For anything that failed, capture the **observation**, not your
      interpretation — "zero SN tiles" outlives "dock hook broken".
- [ ] Update [docs/openstation-compat.md](../openstation-compat.md)'s
      *What is verified vs. what is not* section, replacing
      **"source-verified against v1.1.0, runtime-unverified on both lines"**
      with what this run actually established. Name the sections that were
      skipped or inconclusive (§K almost certainly; §I's failure path likely).

## What this checklist still cannot prove

- **The pre-rename path after upgrading.** Once the site is on v1.1.0, only
  the `openstation_*` family fires. The `desktop_mode_*` registrations become
  untestable on that install — which is why the baseline pass is the only
  chance to see them work.
- **The both-families transition case.** No upstream release ships both, so
  the double-fire guard's collapse-to-one-row behaviour stays theoretical. §H
  and §I verify it does not *mis*fire on a single family; they cannot verify
  it does the right thing on two.
- **Any seam whose only failure mode is a plausible default** — §K, and §A4's
  ordering. A wrong value that looks reasonable passes every check here.
