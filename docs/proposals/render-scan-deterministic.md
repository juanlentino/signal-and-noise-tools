# Deterministic render-scan mode (R3 §3C)

**Status:** SCOPING ONLY — nothing implemented from this document.
**Audited against:** `origin/main` @ `34a7b57` (v10.99.0).
**Date:** 2026-08-12.
**Question:** what would it take to make the rendered-pair contrast tier
*deterministic*, and should that be built?

This is not a proposal to invent a computed-styles scanner. That instrument
already exists (`tools/contrast-render-scan.mjs`, shipped in v10.90.1, claimed
in `CHANGELOG.md` as closing §3C). This is a proposal about the property the
title names: **the same input must produce the same findings**. A scan that
moves is worse than no scan, because it trains the reader to ignore it.

---

## Prior art

Three contrast tiers were planned; two live in the Health panel; the third
lives as a laptop instrument.

| Tier | Where | What it answers | What it cannot |
|---|---|---|---|
| Arithmetic | `inc/health-contrast-tokens.php` | Which token pairs *would* fail if rendered together | Which pairs meet on screen |
| Usage (declaration) | `inc/health-contrast-usage.php` | Which pairings are *declared* in stylesheets, scored under every shipped palette | Non-resting states, block-inline colours, the computed cascade |
| Rendered | `tools/contrast-render-scan.mjs` | What `getComputedStyle` actually paints, including forced `:hover` / `:focus-visible` | Pages not pointed at; text over images; states that do not yet exist in the DOM; CSS transitions, deterministically |

The usage tier's own file docblock is the contract this proposal inherits
(`inc/health-contrast-usage.php:21-66`). It exists because two individually
correct scans both missed a live failure: the arithmetic tier because it
scores token pairs rather than rendered ones, and a theme-side original
because it required an enclosing token-painted surface and the failing
component had none. It also exists because the theme's own suite reported
20 passed / 0 failed while the live site failed — it scored `theme.json`
root, and **the site serves the High Contrast style variation**.
blood-on-asphalt is 4.60:1 at root (`#e00404` on `#f5f5f5`) and 3.80:1
under High Contrast (`#e00404` on `#e0e0e0`). Every number was right; the
palette was the wrong one (`inc/health-contrast-usage.php:14-19`;
`tests/health-contrast-usage.php:245-248`).

The rendered instrument was built on 2026-08-11 after `docs/r3-prep.md:115-119`
was corrected: the "reuse the desktop-widget headless rig" instruction pointed
at nothing. `CHANGELOG.md` (v10.90.1) then declared §3C closed. That claim is
true of *mechanism* (it reads computed styles) and false of *engineering
properties this brief requires*: it is not deterministic, it defaults to the
live site, and it does not pin High Contrast. Those three gaps are the work.

House conventions this proposal copies rather than re-derives:

- **Unknown ≠ zero.** A never-run scan, an unreadable stylesheet, an
  unscoreable pairing, and a skipped URL are all absences. The motion renderer
  prints "no front stylesheets were readable" rather than "0 uncovered"
  (`inc/health-render-motion.php:17-19, 60-62`). The usage renderer does the
  same (`inc/health-render-contrast.php:243-246`).
- **A cap truncates the LIST, never the headline.** Contrast usage
  (`SN_HEALTH_CONTRAST_USAGE_MAX_ROWS = 25`), arithmetic
  (`SN_HEALTH_CONTRAST_MAX_ROWS = 60`), motion (`SN_HEALTH_MOTION_MAX_ROWS = 50`),
  and link-isolation (`isolated_total` always published) all keep the true
  count in the open sentence.
- **A coverage sentence that overclaims is worse than none.** Every tier
  states its blind spots next to the headline, not only in a file docblock
  (`inc/health-render-contrast.php:219-230, 279-284`).
- **"0 failing" must never read as "the site passes."** The usage limits
  line says this in the panel itself (`inc/health-render-contrast.php:282-283`).
- **Report-only checks do not raise findings.** Arithmetic and motion pack
  `array()` as findings by design (`inc/health-contrast-tokens.php:170-174`;
  `inc/health-motion-scan.php:196-200`). Fixes are a later, separate step.
- **The Health scan is a durable option, not a transient**
  (`SN_HEALTH_CACHE_KEY` in `inc/health-checks.php:54`). A cache flush must
  not evaporate a measurement.
- **The test sweep's gate is the presence of the summary line**, not its
  failure count (`tests/run.sh:18-21, 75-93`). A suite that dies mid-run, or
  that asserts nothing, is not a pass. On 2026-08-11 a hand-rolled runner
  reported "ALL 415 SUITES GREEN" over two fatally broken suites.

---

## Survey

### Arithmetic tier — `inc/health-contrast-tokens.php`

Scores every unordered theme-token pair (`sn_health_contrast_pair_table`,
`:128-150`) with WCAG 2.x relative luminance (`:46-57`) and the
`(L1+0.05)/(L2+0.05)` ratio (`:68-77`). Thresholds: 4.5 body, 3.0 large
(`:36-37`). Verdicts are computed on the *unrounded* ratio so 4.4954 fails
body AA even when displayed as 4.50 (`:121-122`). Zero findings by design
(`:153-154, 170-173`). Coverage field (`:176-180`) still says colours
inlined in block markup and the computed cascade need a real render.

The count is a property of the palette. It never drops when a rendered
defect is fixed. Owner decision 2026-08-11: it misled as a headline three
times, so the renderer collapses it behind `<details>` and keeps the count
in the `<summary>` as a palette-drift tripwire
(`inc/health-render-contrast.php:95-108, 129-138`).

### Usage / declaration tier — `inc/health-contrast-usage.php`

Walks this plugin's front-end CSS plus the active theme's
(`sn_health_contrast_usage_sources`, `:126-148`). Admin sheets are excluded
by a denylist plus a filename-`admin` belt (`:108-115, 135-137`), because
scoring wp-admin colours against theme tokens invents failures no reader
can meet. The denylist is pinned against a derivation from enqueue hooks
(`tests/health-contrast-usage.php:94-158`) after `uptime-status.css` leaked
in — admin-only, not named "admin", two false positives in the live report.

Parser is a regex, not a CSS engine, and says so (`:154-159`). It now
carries at-rule context (`sn_health_contrast_usage_at_spans`, `:178-213`)
so `@media print` is dropped (`:216-244, 272-274`) and a colour is never
anchored to a surface from an incompatible query
(`tests/health-contrast-usage.php:161-202`). Context matching is literal
string equality; overlapping-but-differently-written queries fall back to
the document background rather than invent a co-occurrence (`:48-54`).

Surfaces skip any selector containing `:` (`:354-359`). Two exclusions,
both bought with false positives (`:335-349`):

1. **Pseudo-elements.** `.sn-notes-pillar::before` is a 4px blood rail, not
   the card surface. Treating it as one scored children as blood-on-blood
   at 1.00:1.
2. **Pseudo-classes.** Stripping `:hover` to widen matches paired hover
   *backgrounds* with resting *text*. `.sn-cmdk-trigger` was reported as
   bone-on-blood; it is bone-on-transparent at rest. ~60 fictional
   findings. "State matters, and modelling it properly is a CSS engine,
   not a health check."

Pairings with no enclosing surface score against the document background
and are marked `anchored: false` (`:396-407, 459-461`). That is the
provenance-chip case this module was written to catch: hardcoded `#1f9d55`
on white = 3.49:1, no background of its own
(`tests/health-contrast-usage.php:215-228`). Placement-dependent
"conditional" failures (pass on the page background, fail on another
surface the design system actually paints) are kept **out of the headline
count** (`:662-670` in the report builder; renderer at
`inc/health-render-contrast.php:346-358`).

Palettes: served first, then every `WP_Theme_JSON_Resolver` style
variation, each merged *over* the served palette so a one-token variation
does not silently drop out (`:496-508`). A pairing is reported once per
palette it fails under (`:594-596`; the per-palette loop is `:638-659`).

Token references carrying a fallback (`var(--wp--preset--color--void, #fff)`)
are scored as the **token**, never the fallback (`:302-313`). A non-preset
custom property (`var(--sn-signal, #ff4c47)`) is unscoreable, not guessed
(`:55-62`). That indirection is named as the render tier's job.

**Stated blind spots, the three this proposal is asked to close**
(`:63-66` plus the surfaces note):

1. Non-resting states.
2. Colours inlined in block markup (`has-blood-color` on a paragraph).
3. The computed cascade (specificity, overrides, inherited `color`).

Plus, named in the same breath but not in the brief's three: non-preset
custom-property indirection, and media-query evaluation.

### Usage renderer — `inc/health-render-contrast.php`

Usage leads; arithmetic is collapsed (`:211-212, 300-303` of the test that
pins order: `tests/health-contrast-usage.php:274-305`). Wording flips from
"would fail" to "fails" because a declared pairing is worn by something on
the page (`:214-217`). The limits sentence sits under the headline, not
only in the card coverage (`:279-284`), and names the headless render
tier and `docs/r3-prep.md` §3C as the thing this scan does not replace.

### Motion scan — sibling shape, second data point

`inc/health-motion-scan.php` rides the **same sheet population and the
same rule parser** (`:9, 169-185`). It asks one question per motion
declaration: gated behind `prefers-reduced-motion: no-preference`, or
neutralized to `none` under `reduce` (`:14-26`). Kinds are separate
claims: a transition reset silences no keyframe (`:25-26, 44`).
Script-driven motion is invisible and the coverage sentence says so
(`:203`). Report-only, zero findings (`:188-200`).

Renderer (`inc/health-render-motion.php`) is the H3 IA increment: headline
numbers open, uncovered table folded, cap truncates the list, unknown ≠
zero. Tests (`tests/health-motion-scan.php`) use the real contrast parser,
not a stub — "stubbing it here would green a scan the live path never
runs" (`:21-22`). Hover transitions *are* motion and are counted
(`:64`).

Takeaways for a render scan: share the usage tier's source population
where the question is about stylesheets; do not invent a second parser;
do not stub the live path; say the coverage limit next to the number;
keep the Health surface report-only until someone is ready to act.

### Rendered instrument — `tools/contrast-render-scan.mjs`

Already reads `getComputedStyle` inside the page (`COLLECT`, `:108-310`).
For every element with its own visible text it walks ancestors compositing
translucent layers (`backdrop`, `:228-249`), applies the 4.5 / 3.0 split
from computed font-size and weight (`:293-296, 312`), then — unless
`--no-states` — forces `:hover` and `:focus-visible` through CDP
`CSS.forcePseudoState` on `a, button, [tabindex], summary, input, select,
textarea` (`:368-398`). Resting failures are not re-reported once per
forced state (`:315-351`). Decorative `aria-hidden="true"` is counted,
not silently dropped (`:262-275, 430-437`), because the attribute is also
the easiest way to silence a real defect.

Calibration fixture (`tools/contrast-render-fixture.html`) plants the
ways earlier tiers were fooled: hover-only 3.29:1 (`:30-33, 111` — §3C's
worked example), translucent black needing compositing (`:35-37`),
inherited colour with no declaration (`:39-41`), blood on High Contrast
asphalt 3.80:1 (`:26-28`), chip green 3.49:1 (`:23-24`), plus passes and
two unscoreable cases. Self-test (`tools/contrast-render-selftest.mjs`)
asserts exactly that set — no more, no fewer — because "a scanner that
measures nothing reports a clean site" (`:7-10`). Not in `tests/*.php`
and not in CI (`:21-23`).

**Documented, already-measured non-determinism** (`:47-53`, and the
hairline saga at `:186-201` of the scanner / `:86-104` of the fixture):
forcing a pseudo-class starts any transition it triggers. Computed styles
are sampled at whatever moment the pass reaches the element. Measured
2026-08-11 on `/notes/`: an animated underline's `background-size` was
caught mid-flight as a partial-width 1px hairline; the first fix resolved
any single-colour gradient as a solid layer and invented two 1:1 failures
on titles that are 21:1. The honest answer for a hairline is refuse, not
guess. The scanner's own header says: treat a single run's unscoreable
list as a sample, not a census.

**Default corpus is the live site** (`DEFAULT_URLS`, `:79-87`): seven
production URLs on `juanlentino.com`. `page.goto(..., { waitUntil:
'networkidle', timeout: 45000 })` (`:334`). A failed navigation is
`SKIP`ped and the loop continues (`:335-338`). If every URL skips,
`findings.length` is 0 and the process exits 0 (`:467`). That is a false
green the house rules forbid, and it is in the current instrument.

**Explicitly not for the web server** (`:55-63`). Tried once on Cloudways
on 2026-08-11: no Chrome, host Node 18 vs playwright-core ≥20, and
`npm i` inside `public_html` left `package.json` publicly served until
cleaned up.

`tools/` is `export-ignore` (`.gitattributes:6`). The instrument ships to
nobody. Deliberately not a committed dependency (`:72-73`).

### What already closed part of the gap without a browser

When the live scan found `--concrete` used as text at 2.68:1 and
`--signal` (`#ff4c47`) used as text at 3.29:1, the *pin* landed as
stylesheet fixtures (`tests/prov-verify-contrast.php:4-11`): "that scan
needs a browser and a live site, so it can never run in CI — this fixture
is the part of its finding that CAN be pinned without one." Hover rules
in `prov-verify.css` are asserted to use `--signal-ink`, not `--signal`
(`:137-143`). The decorative exemption is asserted in the markup, not
assumed (`:163-170`). That pattern — live scan finds, PHP fixture pins —
is prior art for how a rendered finding becomes a CI-durable fact
without putting Chrome in Actions.

### CI shape, today

`.github/workflows/ci.yml` is four jobs on every PR (lint, phpcs, tests,
changelog) plus sibling workflows: Plugin Check (spins wp-env), PHPStan,
Gitleaks, Claude Review (path-filtered), Security Review. Every listed
job sets `timeout-minutes: 30`. The test job runs `bash tests/run.sh`
(`ci.yml:93-99`) — PHP fixtures, no Node, no browser, no WP.

This repository is **public** (`gh repo view` on 2026-08-12:
`visibility: PUBLIC`). Public repos on standard runners bill **0**
Actions minutes. The 3,000-minute quota the brief names is the
account-wide private-repo pool, measured at ~99% on 2026-07-29. Both
facts are true at once.

### Theme palettes, the ones a render must score

Sibling repo `signal-and-noise`, not this plugin.

| Palette | asphalt | blood | Where |
|---|---|---|---|
| `theme.json` root | `#f5f5f5` | `#e00404` | theme.json:16-17, 36-37 |
| High Contrast (served) | `#e0e0e0` | `#e00404` | styles/high-contrast.json:9-10 |
| Monolith | `#f5f5f5` | `#000000` (renamed Ink) | styles/monolith.json:9, 13 |

High Contrast redefines asphalt (and rust, concrete). Monolith redefines
blood and signal to greys. A scan that only injects root reports green
on the pairing the live site fails. A scan that only injects High
Contrast misses a Monolith-only defect. The usage tier already scores
all three and names them (`inc/health-contrast-usage.php:496-504`).

---

## Constraints

1. **The live site serves High Contrast, not `theme.json` root.** Measured.
   Any scan that scores only the default palette reports green while the
   live site fails. The usage tier was written because this already
   happened (`inc/health-contrast-usage.php:14-19`).

2. **GitHub Actions budget is binding for private repos, and this repo is
   public.** Standard-runner minutes here are $0. A new per-PR browser job
   is still rejected as the *primary* mechanism, for three reasons that
   survive the $0: (a) billing rounds up *per job*, and a Playwright job
   copied into a private sibling would cost 1–3 minutes per run of a
   3,000-minute pool that is already ~99% consumed; (b) cancelled and
   flaky browser jobs cost more than they save; (c) a non-deterministic
   required check trains the owner to ignore it, which is the failure
   mode this proposal exists to prevent. Larger runners are billed even
   in public repos and are out.

3. **Determinism is the engineering problem.** Font loading, animation
   and transition timing, image decode, network variance, cache state,
   viewport, `prefers-reduced-motion` / `prefers-color-scheme`, and
   palette switching all move the result. Each must be pinned or named
   as unpinnable. See Options / recommended mode.

4. **Do not probe the live site as the primary mechanism.** Live probes
   read the edge (CDN / Breeze minify), not the layer under test. A
   Breeze minify bundle can serve stale assets after an install. The
   current scanner's default URL list (`tools/contrast-render-scan.mjs:79-87`)
   is exactly this, and is why a deterministic *mode* is a different
   instrument even though it shares a file.

5. **House honesty rules, non-negotiable.** Unknown ≠ zero. A cap
   truncates the list, never the headline. A coverage sentence that
   overclaims is worse than none. "0 failing" must never be able to
   read as "the site passes."

6. **PHP 7.4-compatible plugin code**, WordPress admin context, WPCS +
   Plugin Check in CI. The scanner may stay Node and `export-ignore`.
   Anything that lands in `inc/` is PHP 7.4 and must survive both gates.
   Launching Chrome from the Cloudways host is already a measured failure
   (`tools/contrast-render-scan.mjs:59-63`).

7. **The theme is a different repository.** Block templates, `theme.json`,
   and `styles/*.json` do not live here. A plugin-only fixture corpus
   cannot see `has-blood-color` in a theme template unless that markup is
   snapshotted or the theme checkout is an explicit input.

---

## Options considered

### A. Status quo: live Playwright, hand-run, no pins

**What:** keep `tools/contrast-render-scan.mjs` as it is. Point it at
production before a release.

**Killed by:** it is the thing the brief asks to replace. `waitUntil:
'networkidle'` (`:334`) is network-variance by construction. Transitions
are sampled mid-flight (measured). Palette is whatever the edge is
serving today, including a stale Breeze bundle. Failed navigations skip
and can produce exit 0. Useful as a *secondary* spot-check of corpus
drift against what readers see; unfit as a gate or a Health headline.

### B. New per-PR Playwright job on Actions

**What:** `ubuntu-latest`, install Chrome + playwright-core, run the
self-test and/or a fixture corpus on every PR.

**Killed by:** even though *this* repo is public ($0), it is an unbounded
per-PR browser job — the shape the brief says will be rejected. It adds
a job, and billing rounds up per job (`~/.claude/rules/github-actions-cost.md`,
§1). Plugin Check already spends a full wp-env boot for a different
question (`.github/workflows/plugin-check.yml:93-96`). A required
browser check that flakes once will be ignored forever. Chrome-version
drift between `ubuntu-latest` images is a source of variance this
proposal is trying to remove, not add.

### C. Fold Playwright into the existing `tests` job

**What:** one more step in `ci.yml`'s `tests` job, so rounding stays 1.

**Killed by (as the *rendered* scan):** the tests job is a PHP fixture
sweep with no Node setup (`ci.yml:75-99`). Adding `setup-node` +
playwright-core + Chrome converts a cheap deterministic job into a
browser job and couples two failure classes. A Chrome apt failure would
red the PHP sweep. The house already learned that a hand-rolled gate
can swallow a crash (`tests/run.sh:5-16`); mixing runtimes in one job
reopens that shape. **Not killed** for a *browserless* Node unit test of
extracted scoring math (Increment 3) — that is seconds of `node`, no
Chrome, and can live beside `bash tests/run.sh` without becoming a
second job.

### D. jsdom / happy-dom / linkedom "computed styles"

**What:** parse HTML + CSS in Node, skip Chrome.

**Killed by:** §3C's own sentence. Those engines implement a subset of
the cascade and do not match Chrome's `getComputedStyle` for
`color-mix()`, inherited colour through shadow-adjacent WordPress
markup, or forced pseudo-classes. Shipping them as a "rendered" tier
inherits the declaration-tier blind spot one level up — the failure
`docs/r3-prep.md:130-135` names. A cheaper lie.

### E. Extend the declaration tier to score same-rule `:hover` / `:focus`

**What:** when a single rule sets both `color` and `background` under
`:hover`, score that pair without a browser. Would have caught a
same-rule hover-text failure.

**Killed by as a §3C closer:** the v10.88.0 provenance hover was a
`:hover` *colour* against a surface declared elsewhere (or inherited).
The usage tier already tried "strip `:hover` and match more pairs" and
produced ~60 false positives (`inc/health-contrast-usage.php:343-349`).
Same-rule-only scoring would miss inheritance, cascade, block-inline
colours, and compositing — three of the four reasons the rendered tier
was wanted. Keep as a possible *usage-tier* increment, never as a
substitute. The motion scan *does* count `.sn-f:hover` transitions
(`tests/health-motion-scan.php:64`) because "is this motion" is a
property of the declaration, not of the cascade.

### F. axe-core (or pa11y) in Playwright

**What:** reuse an existing contrast engine.

**Killed by:** axe's colour-contrast rule has its own known refusals
(pseudo-elements, opacity, gradients) and would not share this repo's
honesty vocabulary (unscoreable vs fail, decorative counted not
dropped, large-text split, palette naming). It still needs a browser,
so it does not dodge determinism or cost. Adding it *beside* a house
scanner creates two numbers for one question.

### G. On-demand admin action that renders on the host

**What:** a Health-tab button launches a headless Chrome against the
front of the same install.

**Killed by:** measured 2026-08-11 (`tools/contrast-render-scan.mjs:59-63`).
No Chrome on Cloudways, Node 18, and `npm i` in the docroot became a
publicly served `package.json`. The site is the subject, not the host.

### H. Local wp-env / full WordPress bootstrap as the corpus

**What:** spin WordPress, activate theme + plugin, render real routes
under the High Contrast theme mod.

**Killed by as Increment 1:** highest fidelity and highest variance.
Plugin/theme install state, generated global-styles CSS, block
supports, and wp-env image updates all move. Plugin Check already pays
this cost in CI for a *lint*, not a visual measurement
(`.github/workflows/plugin-check.yml:57-74` documents a day when
"latest" core was unfetchable and every PR went red). Worth
reconsidering only if Increment 1's fixture injection is proven unable
to resolve `var(--wp--preset--color-*)` the way `wp_get_global_stylesheet`
does. Not the first slice.

### I. Recommended: deterministic mode of the existing scanner, against repo-controlled fixtures, High Contrast injected from the theme JSON

**What:** same Playwright + local Chrome + `getComputedStyle` +
`CSS.forcePseudoState`. New flag (name bikesheddable; `--deterministic`
is the behaviour): refuse a default live URL list; accept only
`file:` / `http://127.0.0.1` / explicit fixture paths; pin every
pinnable source of variance below; inject palette custom properties
from a JSON file; write JSON that names corpus, palettes, states, and
unscoreable counts. Self-test becomes the proof of determinism: two
runs, identical findings. Health-panel ingest and theme-template
snapshots are later slices.

**Why this beats the others:** it is the only option that (1) actually
reads computed styles, (2) does not inherit the live-edge blind spot,
(3) can pin High Contrast without claiming to be a live probe, (4) adds
no Actions job, (5) reuses an instrument that has already found real
defects, and (6) can fail its own feasibility test (see Kill criteria).

**Live probe, demoted.** `node tools/contrast-render-scan.mjs https://…`
stays, undocumented as a default, labelled as a corpus-drift spot-check.
It must never be the Health headline and must never exit 0 when every
URL skipped.

---

## Increments

Smallest first. Each is independently valuable. Later slices may be
killed without unshipping earlier ones.

| # | Slice | What ships | Independent value | Closes §3C? |
|---|---|---|---|---|
| **0** | Pin the existing instrument | `--deterministic`: disable transitions/animations before any sample; `Emulation.setEmulatedMedia` for `prefers-reduced-motion` + `prefers-color-scheme`; `waitUntil: 'load'` not `networkidle`; `deviceScaleFactor: 1`; zoom 1; refuse to default to `DEFAULT_URLS`; **fail closed** if Chrome is missing or every target skipped (exit ≠ 0, no "0 failing") | Today's laptop run stops lying when a transition is mid-flight, and stops exiting 0 on a total skip. The `/notes/` 1:1 artefact becomes impossible under this flag. | No. Still computed styles, still not a corpus, still not palette-pinned. |
| **1** | Fixture corpus + palette injection | HTML fixtures that `<link>` *real* plugin CSS (never a copied hex). A palette injector writes `:root { --wp--preset--color-<slug>: <hex>; }` from `styles/high-contrast.json` (path configurable; sibling `../signal-and-noise` is the expected local layout). Run rest / hover / focus-visible under **High Contrast first**, then root, then Monolith. Extend the self-test: (a) two consecutive deterministic runs of the calibration fixture are byte-identical on `findings` + `unscoreable`; (b) injecting High Contrast changes the planted blood-on-asphalt computed background from `#f5f5f5` to `#e0e0e0` and the ratio from 4.60 to 3.80; (c) the planted `:hover` 3.29:1 is found only in the hover pass. | A repo-controlled computed-style measurement of the components this plugin owns, under the palette the site serves. | **Yes — this is the slice that meets §3C's own criterion** ("read computed styles, not token declarations") against input we control, including the hover case §3C cites and the High Contrast trap the usage tier was written for. Blind spot #2 (theme block markup) is still open and the coverage sentence must say so. |
| **2** | Coverage ledger | A manifest: each front-end CSS file the usage denylist would *include* must be named by at least one fixture, or listed as `uncovered` with a reason. A PHP or Node test in the existing sweep (no browser) fails when a new `assets/*.css` is added and mentioned in neither the denylist nor the manifest. | New CSS cannot silently shrink rendered coverage. Unknown stays visible. | Follow-on. Honesty, not mechanism. |
| **3** | Browserless scoring tests in the existing `tests` job | Extract `lum` / `ratio` / `composite` / `imageLayer` / large-text split from the in-page `COLLECT` closure into an importable module. A `node tools/contrast-render-math-selftest.mjs` (or a `tests/*.php` port of the same literals) runs inside `tests` / `run.sh` with no Chrome. Hand-derived ratios only, never recomputed by the code under test — the usage-tier convention (`tests/health-contrast-usage.php:14-16`). | Prevents the WCAG math and the hairline/zero-width image rules from rotting without a browser. Does **not** prove `getComputedStyle`. | Follow-on. Protects Increment 1; does not replace it. |
| **4** | Health-panel ingest | The contrast card grows a third block, collapsed like arithmetic, ingesting a durable option written by an admin upload or a WP-CLI `eval-file` of a JSON file produced on the laptop. Never produced by the request that renders the panel. Absent option → "not measured", never 0. Stale beyond `SN_HEALTH_CACHE_TTL` → named as stale. Headline carries true failing count, unscoreable count, decorative-skip count, palettes, states, fixture list. Cap truncates the table. Coverage sentence: corpus + pinned conditions, and "this is not the live site." | The owner already reads this card. A laptop JSON that never appears there will not be re-run. | Follow-on. Surface, not mechanism. |
| **5** | Theme-template snapshots | A local script, run from a theme checkout, emits HTML snapshots of the routes / templates that carry `has-*-color` / `has-*-background-color` into `tools/fixtures/theme/` (or reads them live from the sibling checkout). Increment 1's injector still supplies the palette. | Closes blind spot #2 for the templates we snapshot. Coverage ledger lists which templates are in and which are not. | Follow-on. The brief's second hole. |
| **6** | More states, one more viewport | `:active` via `forcePseudoState`; one mobile viewport (390×844) so width queries the usage tier cannot evaluate (`:48-54`) get a real answer. Open menus / validation errors stay out: forcing a pseudo-class restyles what is there, it does not create it (`tools/contrast-render-scan.mjs:43-45`). | Width-conditional pairings and `:active` colours. | Follow-on. Completeness. |

**What Increment 1 does not close, and must not claim:**

- Theme block-inline colours (Increment 5).
- Script-created DOM (open command palette, open menu, a validation
  error that is not in the fixture).
- Text over photographs or multi-stop gradients (refused, as now).
- Admin UI (out of the usage tier's population on purpose;
  `inc/health-contrast-usage.php:118-122`).
- That "the site passes."

---

## Determinism — source by source

The brief requires each source of variance named, and each pin or
refusal stated. This is Increment 0 + 1. Unmarked guesses are labelled.

| Source | Pinnable? | Pin |
|---|---|---|
| Font loading / webfont swap | Yes, for this question | Fixtures use `system-ui, sans-serif` only (the calibration fixture already does: `contrast-render-fixture.html:20`). Deterministic mode injects the same `!important` stack and/or waits on `document.fonts.ready` then asserts `document.fonts.status === 'loaded'`. Contrast reads computed *colour*, not rasterised glyphs; fonts matter here only for the large-text size split. |
| Animation / transition timing | Yes, for end-state | Before `COLLECT` and before every `forcePseudoState`, inject `*, *::before, *::after { transition: none !important; animation: none !important; }`. This is the pin for the measured `/notes/` hairline race (`tools/contrast-render-scan.mjs:186-196`). End-state `:hover` colour is what we want; the in-flight value is a bug. |
| Image decode | Yes, in the fixture corpus | Increment 1 fixtures carry no raster images. Deterministic mode aborts `http(s):` image requests. `waitUntil: 'load'`, never `networkidle`. |
| Network variance | Yes | Deterministic mode accepts `file:` and loopback only. No CDN, no Breeze, no minify layer. |
| HTTP cache | Yes | `file:` has none. If a local static server is used later, launch with cache disabled (`page.route` or CDP `Network.setCacheDisabled`). |
| Viewport | Yes | Keep `1280×900` (`:329`). Increment 6 adds a second, named pass. |
| Device pixel ratio / zoom | Yes | `deviceScaleFactor: 1`; do not touch zoom. |
| `prefers-reduced-motion` | Yes | CDP `Emulation.setEmulatedMedia`. Default pin: `no-preference`, so motion-gated colour (if any) is the colour a motion-comfortable reader sees. A `reduce` pass is optional and must be labelled; collapsing the two is a lie. |
| `prefers-color-scheme` | Yes | Pin `light`. This site is a light design system. Dark is not a shipped variation (High Contrast is a *style variation*, not `prefers-color-scheme`). |
| Palette / style variation | Yes | Inject `--wp--preset--color-*` from the variation JSON onto `:root`, after linked stylesheets, so the injection wins. High Contrast first. Prove the pin with the 4.60 → 3.80 asphalt assertion in the self-test. Do **not** read `wp_get_global_settings` in deterministic mode — that is a WP bootstrap, and assuming it matches the served variation is how the last false green happened. |
| Scroll / sticky / intersection | Yes | Measure at `scrollY = 0`. Sticky chrome that only appears after scroll is uncovered, and the coverage sentence says so. |
| Pointer / hover from a real mouse | Yes (avoid) | `CSS.forcePseudoState`, already (`:368-371`). A mouse-move sweep is neither fast nor deterministic. |
| Chrome major version | **Not fully** | `channel: 'chrome'` uses whatever is installed (`:328`). Two laptops, or a laptop six months apart, can disagree on `color-mix()`, relative colour, or `lab()`. Mitigations: the self-test must pass on the machine that gates a release; scoring is from resolved `rgb()`/`rgba()` so hex and `var(--preset)` are stable across Chrome versions; `color-mix()` remains a known engine-dependent hole and stays unscoreable if `getComputedStyle` does not resolve it to rgb. **Name Chrome's major version in the JSON report** so two disagreeing runs are diagnosable. |
| States that do not exist in the DOM | **No** | An open menu, a validation error, a command-palette overlay. Forced pseudo-classes restyle what is there (`:43-45`). Uncovered, stated. |
| Text over real images / multi-stop gradients | **No** (correctly) | Refuse, never guess. A refusal is a verdict and is counted (`selftest:81-88`). |
| Subpixel antialiasing / OS colour management | **No, and irrelevant** | We score computed CSS colours, not framebuffer pixels. A pixel-diff screenshot scan would inherit this; this proposal does not take that path. |

**If a source cannot be pinned, the report names it in the coverage
sentence.** It does not get silently folded into "0 failing."

---

## Cost

### What a run costs in Actions minutes

**Recommended path (Increments 0–2, 4–6): 0 billed minutes.**
The scan stays on the laptop. No new workflow, no new job, no
`workflow_dispatch` browser job (those were 29% of a measured month's
private quota when they produced nothing — `github-actions-cost.md` §4).

**Increment 3, if folded into the existing `tests` job:** +0 jobs, so
+0 rounding. Wall-clock add is on the order of 5–15 seconds of `node`
on `ubuntu-latest` (which already has Node). The job already rounds up
to a whole minute; this is unlikely to push it across another integer
**[inferred — I have not timed the current `tests` job on Actions]**.
If it *does* cross a minute boundary, that is +1 billed minute *on a
public repo = $0*, and +1 on a private copy.

### What the rejected shapes would have cost

Assumptions, stated: 20 PRs/month, 2 pushes each, Chrome + playwright-core
cold install ~60–90s, fixture scan ~10–20s, job overhead ~30s → typically
**2 billed minutes per run** after per-job rounding. 40 runs × 2 = **80
billed minutes/month** as a *new* job.

| Shape | Jobs added | Billed min / run (private) | / month @ 40 runs | This public repo |
|---|---|---|---|---|
| New Playwright workflow | +1 | 2 | 80 | **$0** |
| Playwright folded into `tests` | 0 | 0 or +1 if the job crosses a minute | 0–40 | **$0** |
| wp-env full-site render as a new job | +1 | 3–5 **[inferred from Plugin Check already needing 30 min timeout and a core-pin guard]** | 120–200 | **$0**, but a required-check outage class this repo has already had |
| Laptop deterministic mode | 0 | 0 | 0 | 0 |

80 private minutes is unaffordable at ~99% of 3,000. The public-repo $0
does not make a new job a good idea; it makes it *survivable if someone
does it anyway*. This proposal does not.

### Local runtime **[inferred where marked]**

Calibration fixture today is one `file:` page, one viewport, three
states, one implicit palette. Increment 1 with ~8 fixtures × 3 palettes
× 3 states is the same work the current live default does (7 URLs × 3
states) and should stay in the same "a minute or two on a laptop"
band **[inferred — I have not timed a live 7-URL run]**. Increment 6
(second viewport) doubles it. Still a release-gate command, not a
server.

### Pattern risk

Do not write a reusable workflow (`jobs.<id>.uses`) if Increment 3 ever
grows. Composite steps inside `tests` round once. A second job to "keep
the PHP sweep clean" is the expensive kind of cleanliness.

---

## Failure modes

The house trap, applied to this tier: **if the thing being diagnosed
broke, would this number move?**

| If this broke | Would the number move? | False-green shape |
|---|---|---|
| Chrome missing / `channel: 'chrome'` fails | **Must.** Exit ≠ 0, no report that can be read as 0 failing. Today's scanner throws (good) but a wrapper that "best-effort"s would not. | Swallowing the launch error. |
| Every target skipped (`goto` timeout) | **Must.** Today's scanner can exit 0 (`:335-338, 467`). Deterministic mode treats `targets > 0 && measured === 0` as a failed run, not a clean site. | The `tests/run.sh` incident, wearing Playwright's clothes. |
| High Contrast not injected; root palette used | **No, unless we assert it.** This is the original false green. Increment 1's self-test must plant blood-on-asphalt and require 3.80 under the HC pass. A run whose report does not list `High Contrast` in `palettes` is incomplete, not clean. | Scoring `theme.json` while the site serves a variation. |
| Fixture forgot to `<link>` the real CSS | **No.** Unstyled black-on-white passes 21:1. The coverage ledger (Increment 2) and a self-test that the linked stylesheets' rules actually match a fixture element are the movement. | A clean report of an unstyled page. |
| New front CSS file, no fixture | **No, until Increment 2.** Same trap as an allowlist that never learns a new directory. | Coverage silently shrinks. |
| Transition not disabled; mid-flight sample happens to pass | **Maybe, flaky.** The worst kind: a real hover failure at 3.29:1 sampled at the resting 5.01:1. Increment 0 exists to make this impossible. Two-run identity in the self-test is the proof. | The `/notes/` race, inverted: a miss instead of an invented 1:1. |
| `aria-hidden="true"` hung on real text | **The failing count would drop, the decorative-skip count would rise.** The skip count stays in the headline (`:430-437` already). A sudden jump is the signal. `tests/prov-verify-contrast.php:163-170` pins the exemption as an assertion in the markup. | Silencing a defect with an attribute the scanner respects on purpose. |
| Unscoreable list absorbs a resolvable pairing | **Failing count drops, unscoreable rises.** Already happened (`selftest:81-88, 95-100`). Refusals are a verdict. A cap on the unscoreable *table* must not drop the count. | Padding the refuse-list until nobody reads it. |
| Health panel shows last week's JSON | **The number would not move with the code.** Timestamp + stale threshold (`SN_HEALTH_CACHE_TTL`, `inc/health-checks.php:58`) + "not measured" when absent. A missing option is not a 0. | Durable-option rot; the transient lesson in reverse. |
| Live spot-check against CDN | **It would move with the edge, not with the commit.** That is why it is not the primary. A disagreement between fixture scan and live spot-check is a *corpus* bug or a *minify* bug, and must be reported as such, not averaged. | Breeze serving stale CSS after an install. |
| Non-preset `var(--sn-signal)` | **Would move** — `getComputedStyle` resolves it. This is a real win over the usage tier (`inc/health-contrast-usage.php:55-62`) and a reason Increment 1 is worth doing even before theme snapshots. | — |
| Block markup `has-blood-color` in a theme template not in the corpus | **No.** Coverage sentence. Increment 5. | Claiming §3C closed for colours the fixture never loaded. |
| Arithmetic / usage tiers still say 0 | **They should not be read as this tier.** The card already refuses that (`inc/health-render-contrast.php:282-283`). Adding a third block that can also say 0 without a coverage line would undo the card. | Three clean numbers, one unread caveat. |

The self-test's founding sentence applies to the whole tier: a scanner
that measures nothing reports a clean site, and a clean report from a
broken instrument is indistinguishable from a clean site
(`tools/contrast-render-selftest.mjs:7-10`).

---

## Kill criteria

A proposal that cannot fail its own feasibility test is not a proposal.
Any one of these is enough to stop, or to stop after the last green
slice.

1. **Two consecutive deterministic self-test runs disagree** on
   `findings` or `unscoreable` against the committed calibration
   fixture. The instrument is not deterministic. Do not put it in the
   Health panel. Do not tell a release it gated on this. Increment 0
   has failed; do not build 1–6.

2. **High Contrast injection cannot be shown to change a computed
   background.** If, after injecting `styles/high-contrast.json`,
   `getComputedStyle` of the planted blood-on-asphalt element still
   reads asphalt as `#f5f5f5` (or does not read a background at all),
   the scan cannot see the served palette. Shipping it would recreate
   the theme.json false green with a browser's authority behind it.
   Do not build past a spike that proves this pin. If the only working
   pin is a full wp-env theme-mod, revisit Option H as a *local-only*
   spike and re-apply every kill criterion; do not take that path by
   default.

3. **The hover planted case (3.29:1) is not found once transitions are
   disabled.** Then Increment 0 "fixed" determinism by freezing the
   resting state. `forcePseudoState` must still restyle. If it does
   not, the slice that exists to close blind spot #1 does not.

4. **The only defects Increment 1 can find are ones the usage tier plus
   a PHP stylesheet fixture already pin.** Then the rendered tier's
   ongoing cost (Chrome on the laptop, a corpus to keep in sync, a
   third number on the Health card) is not earned. Keep the instrument
   as an occasional hand tool; do not ingest it. This is a valid
   outcome. v10.90.1 already converted the first live findings into
   `tests/prov-verify-contrast.php`. That pattern may be enough.

5. **Corpus sync requires crawling production.** If fixtures cannot be
   kept honest without a live probe as the *writer* of the corpus, the
   primary mechanism has become a live probe. Stop. A live spot-check
   that *reads* production to audit the corpus is allowed; a pipeline
   that *generates* the gate from the edge is not.

6. **Chrome cannot be assumed on the machine that gates releases.**
   playwright-core + `channel: 'chrome'` is a laptop with Google Chrome
   installed (`:72-73`). If that machine goes away, or the gate moves
   to a host we already know cannot run this, the tier has no home.
   Do not add a Cloudways or Actions fallback. Retire the command.

7. **A Health ingest that can render "0 failing" from an absent or
   unreadable JSON.** Increment 4 is killed the moment unknown becomes
   zero. There is no salvage; delete the block.

**Willing conclusion, stated so it can be used:** it is reasonable to
ship **only Increment 0**, keep the self-test local, and continue
converting rendered findings into PHP fixtures the way v10.90.1 did.
That is already how this repo made the 3.29:1 hover durable. Building
the rest is justified only if Increment 1's two proofs (HC pin, hover
found under frozen transitions) both pass, and only if the owner wants
a *recurring* rendered census rather than a find-then-pin workflow.

§3C's criterion ("read computed styles") is already true of the file
that exists. The reason to build anything more is determinism + the
served palette + repo-controlled input. If those three cannot be had
together, **do not build**.

---

## Open questions for the owner

1. **Is a recurring census wanted, or is find-then-pin enough?**
   Increment 1 earns its keep only as a census. If the preferred
   workflow is "run the live instrument when something looks wrong,
   pin what it finds in `tests/*.php`," Increment 0 plus the existing
   self-test is the whole ship.

2. **Where does the theme JSON live on the laptop that would run this?**
   Sibling `../signal-and-noise/styles/high-contrast.json` is the
   obvious convention and is what `tests/provenance-front-contrast.php:80`
   already reasons about. A committed copy inside this repo would drift.
   A required flag (`--theme-root`) fails closed when unset.

3. **May Increment 1 read the sibling theme checkout at all, or must
   the plugin's fixtures stay self-contained?** Self-contained means
   we vendor the three palette JSON blobs (7 tokens each) and accept
   a drift test against the theme when both checkouts are present.
   Cleaner isolation; one more thing to notice when the theme ships a
   fourth variation.

4. **Is Monolith in scope for Increment 1 or a named follow-on?**
   Usage already scores every variation. Skipping Monolith in a
   "rendered" pass would need an explicit coverage sentence. High
   Contrast cannot be skipped.

5. **Health ingest (Increment 4) — upload, WP-CLI, or neither?**
   An admin file upload is a new trust boundary (the plugin already
   has a threat model for hostile *content*; a JSON report is a new
   shape). WP-CLI `eval-file` stays owner-attended and matches
   `tools/` as laptop-only. Neither is also an answer: keep the JSON
   next to the command and do not put a third number on the card.

6. **Should the live default URL list be deleted or merely demoted?**
   Deleting it makes the live spot-check an explicit argument list,
   which is honest. Demoting it keeps one-command muscle memory and
   a footgun.

7. **Is there a fourth style variation, or a `theme.json` custom
   palette on the live site, that `WP_Theme_JSON_Resolver::get_style_variations()`
   would see and this proposal has not named?** I read `styles/` in
   the theme checkout as `high-contrast.json` and `monolith.json`
   only. I have not queried the live site's theme mods.

8. **Chrome channel vs bundled Chromium.** Today's scanner uses the
   locally installed Google Chrome and refuses to be a committed
   dependency (`:72-73, 328`). Bundling Chromium via `npx playwright
   install chromium` would pin the engine better (kill criterion 1
   gets easier) and would make Increment 3's cousin — a *browser*
   self-test — tempting to sneak into CI. That temptation is a reason
   to stay on `channel: 'chrome'`. Confirm.

---

## Appendix: how Increment 1 would speak in the panel

Not a mock; a contract for whoever implements Increment 4, so a clean
sweep cannot be misread.

- **Headline** (always open): `N of M rendered pairings fall below
  body-text AA, across F fixtures, P palettes, S states. U unscoreable.
  D skipped as aria-hidden.` If the option is absent: `Rendered tier
  has not been measured.` Never `0 failing` in that branch.
- **Limits line** (always open): `Computed styles from local fixtures,
  transitions disabled, High Contrast injected from <path>, Chrome
  <major>. Not the live site. Not theme templates not in the corpus.
  A clean count is a clean corpus under these pins, not a clean site.`
- **Table** (folded, capped): worst-first. Remainder line names the
  cap. Columns: fixture, state, palette, path, pairing, ratio.
- **Arithmetic and usage blocks unchanged.** Three tiers, three
  coverage sentences. The usage line that points at "the headless
  render tier (r3-prep §3C)" (`inc/health-render-contrast.php:283`)
  should then point at this block by name, and should not be deleted
  — usage's limits remain true.

Cited behaviour in this appendix that is not yet code is **[proposed]**.
Everything above the appendix is read from the tree at `34a7b57` or
marked **[inferred]**.
