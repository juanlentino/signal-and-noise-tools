# OpenStation v1.1.0 — runtime verification checklist

Closes the one gap [docs/openstation-compat.md](../openstation-compat.md)
cannot close from source: the post-rename (`openstation_*`) path has never
executed against a live WordPress admin. Everything in that file is
source-verified at tag `v1.1.0`; nothing in it is runtime-verified.

Owner-run. Budget ~30 minutes.

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
