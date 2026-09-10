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

## Verify after installing

1. Tab to a Geography row link in S&N Analytics and press Enter. The mechanism is
   tested; the browser path is not.
2. Re-measure tap targets in the phone layer — expect zero of ours under 24px,
   twenty upstream ones remaining.
