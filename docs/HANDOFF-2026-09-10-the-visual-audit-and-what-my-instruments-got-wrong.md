# Handoff — 2026-09-10: the visual audit, two accessibility releases, and what my instruments got wrong

Picks up after `HANDOFF-2026-09-09` (plugin **v13.109.0**). This session covers
**v13.109.18** and **v13.109.19**; the .7–.17 run between them was earlier the
same day and is in `docs/changelog/v13.md`. Both releases are merged, tagged and
public; `origin/main` is clean at `66de919`.

**Provenance of this document:** I did this work. Every number below is from a
test run, a commit, or a measurement I took in a browser this session. What the
owner has installed is **not** verified — the live site still reported
`?ver=13.109.17` when I last looked, so neither release has reached it.

**The honest framing:** this session began when the owner asked whether I had
done a visual design review "with all you have at your disposal." I had not. I
had been doing defect repair. What follows is the audit that answer forced, and
it found real things — but the most durable output is the record of how often my
own instruments lied, in both directions.

## What shipped (do not redo)

### v13.109.18 — a rewritten link answers the keyboard

`snt_os_host_rewrite_link()` removes an anchor's `href`, and it must: the runtime
does not `preventDefault` a click, so a surviving href navigates the whole
desktop instead of the window. But an `<a>` with no `href` is not focusable and
exposes no role, and nothing put either back.

Measured across all 13 S&N Analytics views on the shipped build: **~63 actionable
controls reachable by pointer and by nothing else** — 30 on Geography (every
row's drill-down), 14 on Technology, 7 each on Overview and Content, 4 on Login
defense, 1 on Events. All `cursor: default`.

The removal and its compensation now happen together in `snt_os_host_unhref()`
(`inc/openstation-host.php`), which sets `tabindex="0"` and `role="link"`. They
were separate before, and that is exactly how the compensation went missing
beside a removal that had to happen.

`assets/os-kit.js` gained a `keydown` listener. It had only `click`, and an `<a>`
without an `href` does not fire a click on Enter — a focusable control that does
nothing would have been worse than the mouse-only one it replaced. It keys off
the rewriter's own `role="link"`/`tabindex` marker rather than `.snt-go`, so it
covers both the cross-tab links os-kit dispatches and the `os-action` links the
framework runtime dispatches.

Also in .18: `--os-ui-fg-faint` measures **3.00:1** on the app surface — the 3:1
tier, for large text and non-text — and two 11px S&N Home labels used it for body
text needing 4.5:1. Both moved to `--os-ui-fg-muted` (**8.18:1**).
`.sn-an-settings-help`, emitted by PHP and styled by nobody, inherited core's
`#646970` at 3.19:1 and joined the analytics repair list it was missing from.

**A diagnosis I withdrew.** The first root cause blamed `canonical.php` for
stripping `os-action` and substituting a click-only handler. A controlled
comparison — anchors that *kept* `os-action` versus anchors that lost it, same
page, same painter — found both populations equally unfocusable, 5/5 and 2/2.
The pass regressed nothing. The rewriter was the sole cause.

### v13.109.19 — the tap targets clear the floor

WCAG 2.2 SC 2.5.8 puts a 24×24 floor under a pointer target. Measured in the
phone layer: 118 controls, 35 under the floor. That is **not** the finding — five
are legitimately exempt (links inside a sentence, where the inline exception
applies) and twenty belong to OpenStation's own widget frame. **Ten were ours**,
plus two the live probe could not see because their state never rendered.

The widget doorway links — *Open Analytics →*, *Open Health →*, *Open Uptime →*,
*Open RSS tab →*, *Open Dashboard →*, *Open Machine Readers →*, *Cron events →*,
*Run a scan →* — rendered 17–18px tall with `padding: 0`. They are built in JS
with an inline `style:` string, **one per widget file with no shared helper**.
`.snt-home__view-all` and `os-button.snt-sys__v` carried the same line box from
the stylesheet side; `.snt-sys__v` is scoped to its button form because the same
class also labels static values nobody clicks.

**These measure 18px at 1920px wide too.** It was never a mobile-only defect. The
phone is only where it bites, which is why several earlier desktop passes walked
straight past it: none of them measured target size.

### Tests added

- `tests/openstation-app-contrast-tier.php` (4 pins) — `--os-ui-fg-faint` may not
  be the `color` of small text. Parses each rule's own font declaration so the
  large-text exemption survives.
- `tests/openstation-widget-tap-target.php` (9 pins) — reads each `el( 'a', … )`
  object literal **whole**, because `style:` and `text:` appear in either order
  and scanning forward from one key found five of eight.
- `tests/openstation-host.php` → **154 passed**, with two new pins: an anchor
  that *lost* its href gains `tabindex`/`role`; one that *kept* one gains
  neither. Without the second, a fix that tabindexed every anchor would put the
  `mailto:` and the `#fragment` into the tab order and still pass.

Every new guard was verified **red** against the unfixed code before being
trusted, and both halves of the tap-target guard were re-verified under mutation.

**Run the host suite with WordPress on disk or it verifies nothing:**
`SNT_WP_HTML_API=/path/to/wp-includes/html-api php tests/openstation-host.php`.
The bare run *skips* 15 rewrite pins and still prints a pass line.

## What my instruments got wrong (the part worth reading)

Four probes **invented defects that did not exist**, each by a different
mechanism, and a screenshot killed each one:

1. `:focus-visible` had not engaged — Chrome keys it on the last input
   *modality*, still script/mouse after a programmatic `.focus()`. Verdict "no
   focus ring on 8/8 stops"; reality, a three-layer ring.
2. A computed style read **mid-transition**. `getComputedStyle` returns the
   current interpolated frame, not the resting value. A 300ms settle flipped 8/8
   from NONE to YES.
3. `textContent` is blind to **slotted** content — 49 of 50 controls scored as
   unnamed because their labels are projected from the light DOM. Real count: 1.
4. `innerText` is blind to **shadow DOM** — a fully-painted view read as
   half-loaded at `textLen: 242`.

Plus an unquoted `--include` glob turning a real result into `files=0`, a
tab-detector returning `'?'` on both sides of a click and reporting
`navigated: false` for a navigation the screenshot showed plainly, and a 45s CDP
ceiling silently killing a four-view loop.

**And one near-miss in the other direction.** The tap-target fix was about to be
`.sn-aw-foot a { min-height: 24px }`. That class is real, emitted ten times in
PHP, and documented in its own stylesheet as the shared widget footer. Measuring
the live DOM first showed **zero** of the failing links are inside it — in the
shell those widgets render from JS, a different path entirely. The rule would
have merged, passed review, passed its own test, and changed nothing on screen.

The rule that came out of it: a DOM readout is a **hypothesis**; the render is
the **evidence**; and when a defect was found by measuring the render, derive the
fix's selector from the render too. Recorded in memory as
`a-dom-read-is-not-a-pixel` (extended) and `a-class-emitted-is-not-the-class-rendered`
(new).

## Corrections to standing notes

- **The phone-PWA staleness is narrower than we had it.** The service worker's
  cache key is `{version: "1.1.7", shellBuild: "3b29…"}` — *OpenStation's*
  version, not ours. `src/pwa/sw.ts` only intercepts our assets when
  `openstation_pwa_admin_asset_cache` is opted in; we never reference it and
  upstream asserts it false by default. All 40 of our assets are `?ver=` stamped.
  In a normal tab `serviceWorker.controller` is `null`. **A phone-only symptom
  after our release is a real defect, not a missing Reload.**
- **`window.outerWidth` reads fine** (1920) in the extension context, contrary to
  the note that said it reads 0 — that is how I captured and restored the window.
- **macOS Chrome enforces a ~500px minimum window width.** A real window cannot
  reach phone widths; the in-app pane's emulation can, but has no wp-admin
  session. The owner's own fix was to separate the tab into its own window.

## Open, and not mine to close

- **Accent as small link text — 3.76:1, 9 instances** (`snt-go`, `strong`, 11–13px).
  Deliberately not fixed: muting it would strip the accent from every "→" link.
  It wants a text-accent token and an owner decision.
- **[openstation#789](https://github.com/WordPress/openstation/issues/789)** —
  `os-text-field` cannot expose an accessible name without rendering a visible
  label; 7 first-party search fields ship unnamed. No supported fix downstream.
- **[openstation#790](https://github.com/WordPress/openstation/issues/790)** —
  widget-frame chrome buttons at 20×20 and 22×22.
- **Public site `theme-color` mismatch** — the two metas key off
  `prefers-color-scheme` while the site's theme is `data-theme` with its own
  toggle. OS light + site dark yields white browser chrome against `#0a0a0a`.
  Confirmed by emulation. That is the **theme** repo, not this one.
- **`user-scalable=no, maximum-scale=1`** in OpenStation's viewport meta blocks
  pinch-zoom (WCAG 1.4.4). Upstream's.
- **Two suites fail in a worktree and pass in the main checkout** —
  `admin-class-orphans` (14) and `direct-access-guard-window` (1). Vacuity
  failures; they fail identically with this session's changes reverted.

## Never measured, still

Hover and active states as a system. Motion beyond the six colour transitions.
Typographic hierarchy as a deliberate scale rather than per-leaf. Data
visualisation as part of the design system. 320px. Any of it under an actual
screen reader.

## Verified after installing — both passed

The owner installed and I measured on the running build (`?ver=13.109.19`
confirmed, with the keydown listener present in the shipped `os-kit.js`, before
anything else was read).

1. **Keyboard path works end to end.** Geography: 30/30 actionable anchors carry
   `tabindex="0"` and `role="link"`, **0 unreachable** — it was 30/30 unreachable.
   A real Enter on "US" opened the drill-down ("Top pages · Country = US" with a
   Clear control). My `contentChanged` flag was ambiguous because the toolbar
   prefix is shared; the screenshot decided it.
2. **Every tap target the release addressed clears the floor.** The seven doorway
   links measure **24px** (were 17–18); `View all (6)` is 62x24 and the three
   system rows 334x24 (were x18). Zero of ours remain under 24.

**One thing the fix revealed rather than caused.** The Analytics drill-down row
links ("US", "Smyrna", "Buenos Aires") are 15px tall, `display: inline`,
`padding: 0` — about 30 on Geography alone. They were ALWAYS 15px and always
mouse-clickable; before v13.109.18 they had no `tabindex`/`role`, so a
tap-target probe's selector never matched them and they were not counted as
controls at all. Making them keyboard-reachable brought them into the
population. Whether they are a strict SC 2.5.8 failure is genuinely arguable —
the inline exception has a second limb ("size otherwise constrained by the
line-height of non-target text") and they sit in table rows beside numeric cells
at the same line-height. **Owner's call, 2026-09-10: leave them.**

**And one flag I withdrew.** I reported these links as missing a pointer-cursor
affordance. Measured: `body.os-active, body.os-active * { cursor: default
!important }` in OpenStation's `desktop.css` — 50/50 real `a[href]`, 50/50
`<button>` and 7/7 of ours all compute `default`. Nothing in the shell shows a
pointer, by design. Our links were never anomalous; I had compared them to web
convention instead of to their neighbours. Owner's call: leave the behaviour.
That rule also means our own `sn-analytics.css` `cursor: pointer` had never
applied — removed in #1161 (v13.109.19+, unreleased at time of writing), with
the `color` half kept because it IS live.

---

# Part 2 — the upstream arc, later the same day

With the plugin side closed, the owner asked me to act on the OpenStation issues
this session had opened. **Standing invitation:** `AllTerrainDeveloper` on #362,
*"Please go ahead and open a PR if you're up for it."* That is a maintainer's
invitation on an earlier thread, not on these — I verified #789 and #790 had
**zero comments** before acting, and said so.

No push access to `WordPress/openstation`; PRs come from the `juanlentino` fork,
`fix/<slug>` onto `trunk`, matching how #366 landed.

## Three PRs open, awaiting a maintainer

- **[#791](https://github.com/WordPress/openstation/pull/791) → fixes #790.**
  The widget frame's chrome buttons raised to the 24x24 floor (redock 20x20,
  in-chrome close 20x20, corner close 22x22). Raised the box rather than adding
  an invisible hit area **because `.os-widgets__chrome` is the drag handle**
  (`cursor: grab`, `touch-action: none`) and a pseudo-element overlay would sit
  between the pointer and the drag it is meant to start. The spacing limb
  mattered too: adjacent flex siblings with `gap: 8px`, so at 24px each the
  centres are 32px apart and the test pins that arithmetic.
- **[#792](https://github.com/WordPress/openstation/pull/792) → fixes #789.**
  Adds `hide-label` to `os-text-field`. The `<label>` still renders, still pairs
  by `for=`, still supplies the accessible name; only the visual goes.
  `display: none` or dropping the element for `aria-label` alone would both be
  easier and worse. Declared as `hideLabel` so their `kebab()` yields
  `hide-label` — verified against their own implementation, not assumed. Four
  first-party search fields adopt it; no `<os-text-field>` under `apps/` is
  unnamed after it.
- **[#793](https://github.com/WordPress/openstation/pull/793) → fixes #762.**
  Post Stats' canvas chrome reads `--os-ui-color-border` /
  `--os-ui-color-text-subtle` instead of literal black. **The fallbacks are
  deliberately not black** — a fallback ships precisely when the token fails to
  resolve, so a black one restores the bug in the only case it exists to cover.

**A verification limit that applies to all three.** I could not run their vitest:
`devEngines` pins Node to `>=24 <25`, this machine is on v26, and `npm` refuses
before install. I said so in every PR rather than implying otherwise, and instead
extracted each test's assertion logic into plain node and ran it against the real
files **in both directions** — passing on the patched file and **failing on the
pre-patch file**, so the guards are failable rather than vacuous. The vitest
harness itself is unexercised. If CI reds, suspect the harness before the fix.

## Two issues root-caused, no PR — deliberately

- **#764 — it is their template renderer, not the Plugins app.** They do not use
  lit; `src/ui/core/html.ts` is their own ~400-line renderer, and its attribute
  part (L532) **removes an attribute whose composed value is the empty string**
  rather than setting it empty — including the placeholder it wrote itself at
  L325, because `AttrPart.last` is `last?: string` and `'' !== undefined` on the
  first pass. `os-select` is behaving correctly when it skips an option with no
  `value`. I did **not** patch it: `formatText()` maps `null`/`undefined`/`false`
  to `''` too, so "empty means remove" is also how every conditional attribute in
  their tree omits itself, and which of the two candidate shapes they want is a
  design call on their core renderer. Retitled to name the real cause and scope —
  `alt=""` is unrenderable too, not just one dropdown.
- **#765 — no timeout exists anywhere in the chain.** `AbortController` /
  `AbortSignal` / `signal:` appear **zero** times under `apps/plugins/`. `fetch`
  rejects on network failure but not when the server accepts and holds the
  socket, so the promise stays pending and `runUpdate`'s (correct) `finally`
  never runs. **What the original report missed: one stall wedges the whole
  window.** `drain()` sets `inFlight = true` before awaiting and clears it in a
  `finally` on that same promise, so every later update queues behind a `drain()`
  that returns at its guard — no plugin in that window can update again until
  reload. Retitled to say that. No PR: the timeout duration and abort semantics
  are a judgement call, Core's own `wp.updates` has no client timeout to copy,
  and aborting the fetch does not abort the upgrader.

**#532 remains untouched on purpose.** It asks to move agent runs onto a jobs
pattern — architectural. The #362 invitation was for a specific fix, not a blank
cheque, and turning up unannounced with a subsystem rewrite is not a favour.

**#789's own count was wrong and is corrected in place.** It said seven; on
current `trunk` it is four. Trunk moved, and one of my seven was a false positive
— my scanner matched the literal `<os-text-field>` inside `os-number-field`'s
help *summary string*. Fixed by editing the title and adding an update block, not
by posting a comment, so no subscriber ping. Comment-stripping is not enough when
the corpus contains code samples inside strings.

## Memory maintenance — two sweeps, 156 bytes, and why that is the story

`plugin/MEMORY.md` sits under a measured ~24.4KB cap (the loader truncates and
says so) and had grown 22.1KB -> 24.1KB since the last sweep.

**The archive sweep found no junk.** 273 pointers, **zero duplicates, zero
orphans**. Every `CLOSED`/`SHIPPED`/`RESOLVED` line a keyword scan flagged turned
out to be carrying a live guard — *"NEVER re-enable Cloudflare's toggle"*, *"act
ONLY on `decision = build_ranges`"*, *"the wp-cli `--by=name` trap"*. **Those
markers ARE the guard**; archiving them causes the re-opening they prevent. Only
a genuinely closed #1083 pointer and a six-day-old `ripe: []` snapshot came out.
94 bytes.

**The consolidation sweep corrected my own advice.** I had said consolidation was
the remaining lever. Measured, the `Instruments` cluster is a **hub with
spokes**, not near-duplicates: `negative-control-your-own-instruments` is cited
**46** times, and the three biggest carry **85** citations between them. Merging
those means rewriting 85 links and collapsing the most connected node in the
corpus. **Inbound-link count, not topic similarity, decides what can merge.**
Only the two lowest (1 and 0 inbound) were safe — folded into
`cli-measurement-traps` for 62 bytes.

**Two silent mangles in that merge, caught only by content diffing.** Splitting
frontmatter with `split('\n', N)[N]` ate the first line of each body; one section
shipped starting mid-sentence, having lost the line that named both the scenario
and the tool. Every structural check passed while the text was amputated —
headings present, claims greppable, pointers intact. What caught it was diffing
every substantive line of the originals out of `git show HEAD:<path>` against the
merged file. That is now the written rule for any future merge.

Net: index at **23,944 bytes**, 456 of headroom. A reprieve, not a fix.

## Where it stands

| | |
|---|---|
| plugin `main` | `6e17782`, v13.109.19 released; #1161 merged, riding in Unreleased |
| upstream PRs | #791, #792, #793 — open, unreviewed, CI unverified locally |
| upstream issues | #789 corrected · #764, #765 root-caused and reframed · #533, #532 untouched |
| memory | `1864a39`, synced, private |

Nothing is running in the background. The two most likely things to need a reply:
CI on those PRs (suspect the Node pin before the fix), and whichever shape they
choose for #764, since that one changes their core renderer.
