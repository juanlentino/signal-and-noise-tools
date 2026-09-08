# Local S&N responsive browser regression

`mobile-apps.mjs` renders the real Home painter, Analytics controls and canonical
report/native-surface painters using CLI-only WordPress reader stubs. Home includes
fixture attention and recent-work rows; Analytics includes the existing populated
report fixture, comparison SVG, expandable table and grouped matrix stress fixture.
The fixtures are **not production readings**. The custom-range controls and report
fixture are independent: selecting a control does not query/repaint server data.

It loads actual shared/app CSS and bundles the sibling OpenStation repository's
real custom elements in memory, including their shadow styles. The bounding window
and two-tab navigation are a minimal fixture, not a booted OpenStation desktop.
WordPress Dashicons/font assets are not available in this harness. No service worker,
installation, live database, remote request, destructive action or export is exercised.

## Run

Provide already-installed dependencies; this test never installs packages:

```sh
PLAYWRIGHT_PATH=/path/to/installed/playwright \
ESBUILD_PATH=/path/to/installed/esbuild \
OPENSTATION_PATH=/path/to/read-only/openstation \
CHROMIUM_PATH=/path/to/installed/chromium \
MOBILE_ARTIFACTS=/tmp/snt-mobile \
node tests/js/mobile-apps.mjs
```

The first two paths may be omitted when packages resolve normally. The OpenStation
path defaults to `../openstation`; Chromium defaults to Playwright's matching browser.
PHP must be on PATH. The CLI fixture is under `tests/fixtures/`, outside the standalone
`tests/*.php` sweep, and refuses non-CLI access.

## Assertions and evidence

The matrix runs both apps at phone widths 320/390/430, landscape 844×390, tablet
768×1024 and 1024×768, desktop 1280×900, and a 540px window within a 1440px desktop.
Additional app-container widths exercise 599/600/601, 619/620/621, 639/640/641,
819/820/821 and 900px. Short desktop fixtures at 360×360 and 844×360
exercise the new 360×360 manifest floor (46 app/size cases total).

Assertions cover horizontal containment, usable scrolling area, real emulated touch
or mouse-wheel scrolling, labeled Home rail actions reached through keyboard and
phone taps, component tab touch/click and arrow keys, range selection, custom-date
form values, compact filters, phone date touch sizing, phone pulse density, native
row expansion, SVG containment, grouped matrix column preservation and keyboard
horizontal scrolling when necessary. Links are intercepted; form submissions are
recorded locally, not sent to a server.

Refresh is exercised with Enter, Space and touch/mouse in both apps. The harness
bundles the shell's actual `findTrigger`/`readBinding` functions, asserting exactly
one `refresh` binding per activation, an accessible button name and a 44px actual
button target. Home uses visually hidden slotted text because the shell does not
forward a host `aria-label` onto its inner button. This verifies
the supported `os-action` contract, not an HTTP dispatch or server repaint. PHP
tests separately invoke the real Analytics refresh callback and verify filter
preservation. The bounding fixture omits desktop titlebar/borders: it does not
simulate a pointer resize of the full shell.

## Independent app-window geometry regression

Run `node tests/js/window-geometry.mjs` with the same dependency environment above;
`WINDOW_ARTIFACTS` selects its output directory. The suite varies browser width,
browser height, app width and app height independently, using the shell's real
window and app-runtime CSS and matching ancestors (including titlebar/tab space).
It covers short desktop windows inside tall browsers and container boundaries.

The complete registered Campaigns route runs with deterministic reader data;
IndexNow and Performance use their actual leaf painters. Landing Home and the
original synthetic Analytics composite provide additional coverage. Checks measure
visibility through clipping ancestors, scroll reachability including final table
rows, URL wrapping, selection and unchanged link targets. Wide/tall desktop controls
must remain fixed. Set `RESPONSIVE_CSS_REF=a93dd9e` to run a negative control using
the released CSS without changing the working tree: it must fail on the audited bugs.

These are local Chromium geometry tests with static shell chrome, not a booted
WordPress/OpenStation instance, real shell tab/resize interactions, all report
routes, or physical Safari/PWA verification. The separate original harness retains
its component interaction coverage; neither suite proves the omitted workflows.

## Native mobile height-chain regression

Run `node tests/js/mobile-shell-geometry.mjs` with the dependency environment above.
`MOBILE_ARTIFACTS` selects its output directory. Six viewport sizes, browser/standalone
markers and mobile–desktop–mobile transitions produce 36 cases. These markers exercise
shell CSS, not an installed PWA or the browser display-mode media feature.

The fixture follows `includes/registries/native-windows.php` in OpenStation:
`.os-window__body > os-stack > os-tabpanel > .os-app > .snt-app`. The stack and panel
otherwise have auto height; `container: size` removes the app's intrinsic contribution.
Analytics alone supplies definite wrapper heights without overriding `display` or
`hidden`. Tests wait for custom-element upgrades/updates and two animation frames,
then check nonzero host, visible controls/report, scroll/end reachability and hidden
panels after every mode transition. Actual `mobile.css` supplies the mobile chrome.

Use `RESPONSIVE_CSS_REF=v13.107.1` as the negative control: the host collapses to zero
and the suite must exit nonzero. Saved HTML and ancestor traces support independent
engine probes. The desktop suite now covers 130 cases with the same native Analytics
wrappers and loads Analytics CSS alongside Home, IndexNow and Performance to detect
cross-app leakage. Hidden Analytics panels must retain zero geometry and `display:none`.

A local macOS WKWebView probe can load saved HTML to compare the released and fixed
height chain. This is macOS WebKit geometry evidence, **not physical iOS Safari**,
installed-PWA, safe-area, real shell navigation or live-data verification.

## Information-rich Overview regression

`node tests/js/analytics-responsive.mjs` uses the same installed dependency paths;
`ANALYTICS_ARTIFACTS` selects output (default `/tmp/snt-analytics-responsive`).
It runs the registered Overview callback, canonical header/cards/chart/body and
insights renderer via `php tests/openstation-app-analytics.php --fixture-overview`.
`--warning` selects an actionable falling-views signal instead of no-forecast;
`--json` returns markup plus state. Readers/framework services are deterministic
fixtures, not analytics observations. Optional Uptime/Movers readers are absent,
so an empty right rail in these screenshots is not a production design change.

Nine browser/window geometries, each with both signal states, cover phone
390×844/430×932, phone landscape 844×390, tablet 768×1024, narrow/medium/wide
windows in a 1440×900 browser, the 360×360 minimum window and an 821×360 short
window. Actual native stack/panel/mount wrappers and mobile/desktop CSS are loaded.
Every case saves HTML, initial/expanded screenshots and numerical evidence in
`results.json`. The suite asserts:

- A live height chain, hidden inactive panels, no horizontal report overflow,
  bottom reachability and actual emulated touch or mouse-wheel scrolling.
- All filter groups, filtered totals, full forecast/warning explanation and period
  summary retained; rectangular prose layout, actual segment/export/Refresh targets.
- Range keyboard selection, popup Escape without losing focus, and focused select
  identity surviving the real toolkit DOM morph. `os-key` is essential: a live
  select's auto-generated ID otherwise makes an unkeyed server node incompatible.
- Every Compare value, traffic Enter/Space and tap/click, Custom opening from the
  active rolling period, date Apply, and Refresh Enter/Space/tap or click. Bindings
  use the toolkit's `findTrigger`, `readBinding`, `boundValue` and `morphChildren`;
  requests call the actual PHP action and render the next state, not a fake result.
- CSV/JSON FormData retain the nonce, export action, class and custom dates; the
  form remains POST/target=_blank in production. The harness prevents submission:
  it does **not** download or verify a live CSV/JSON response.
- Existing Full insights opens/closes with Enter/Space and exposes the remaining
  signal. No new sheet, actions menu or default-hidden information is introduced.

`tests/openstation-analytics-responsive.php` covers escaped native signal status,
confidence and complete explanation plus classic-output isolation after capture.
The existing `snt_analytics_surface` hook receives a `signal` piece with the original
signal under `data['signal']`; only the duration-scoped native capture changes its
presentation. Without that capture the classic chip bytes remain unchanged.
`tests/openstation-app-analytics.php` adds semantic grouping and Custom seed tests,
including rejection of malformed arguments and protection of existing custom dates.

For a before/after comparison, set `FIXTURE_ROOT` to an isolated baseline copy
with these test fixtures copied into it and set `OPENSTATION_PATH` explicitly.
The baseline should fail new geometry/focus/Custom expectations; this is not a
passing baseline claim. The fixture transport is not a booted shell REST session:
no WordPress authentication, real date-reader queries, shell resize/tab navigation,
network export, installed PWA or physical iOS device is covered. The unit suite
uses its existing simplified window resolver; the normal PHP sweep separately
covers the production range/date resolvers. Insets and footer chrome are structural
fixtures, not a pixel-identical capture of WordPress's font assets and bottom nav.

The design prioritizes information grouping and legibility, not a guaranteed
smaller pixel height. Labeled traffic and larger actual touch targets can increase
phone toolbar height. All controls still scroll with reports in constrained windows;
wide/tall windows retain fixed controls. No metrics, period totals or methods text
were removed to win a height assertion.

## Scoped integration decisions

- Analytics has local Refresh in its normal toolbar and Login Defense toolbar;
  Posts/Search receive Refresh alone, not range/class controls they cannot honor.
- Home already has Refresh; Webhooks deliveries, Provenance commits and Block
  Migrations already provide their own local Refresh. Cron now adds one for its
  live read-only snapshot, including the empty state. No blanket refresh toolbar
  is injected into settings/forms or beside explicit scan/sync operations.
- Refresh re-reads through existing server readers; it does not promise to bypass
  their caches or force a scheduled data collection.
- Complex matrices retain column relationships and horizontal keyboard scrolling;
  no blanket stacked-table conversion is made. Individual simple leaf tables are
  not all browser-covered by this fixture and need per-leaf review before redesign.
- Traffic segments expose neither a sizing token nor a button part, but the
  button inherits its line-height. Analytics sets that inherited line-height to
  32px; the toolkit's 12px vertical padding yields a measured 44px target, without
  shadow injection. The select trigger still has a hardcoded 37.5px height and no
  exposed sizing part/token; this harness documents that limit rather than claiming
  every control is 44px. Refresh uses its supported `button` part.
- Custom dates retain the supported form fields/footer composition and accessible
  submit behavior. The separate Apply row is intentional here rather than another
  unverified flex-host override.

`results.json` contains every case, geometry, assertion and browser error. Screenshots
capture both the top and bottom of each app. Nonzero exit means at least one assertion
failed. This is Chromium layout/interaction evidence, **not** iOS Safari, full shell
navigation, all report routes, real data, PWA installation, safe-area or production
verification. Re-run the full shell/device workflow before release.
