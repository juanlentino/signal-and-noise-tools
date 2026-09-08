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
- The shell's `os-segment` inner button has hardcoded padding/font size and exposes
  neither a sizing token nor a button part. Its compact traffic-class touch target
  cannot be safely enlarged with plugin CSS alone; no shadow-DOM injection or
  speculative token is added. Refresh uses the real exposed `button` part.
- Custom dates retain the supported form fields/footer composition and accessible
  submit behavior. The separate Apply row is intentional here rather than another
  unverified flex-host override.

`results.json` contains every case, geometry, assertion and browser error. Screenshots
capture both the top and bottom of each app. Nonzero exit means at least one assertion
failed. This is Chromium layout/interaction evidence, **not** iOS Safari, full shell
navigation, all report routes, real data, PWA installation, safe-area or production
verification. Re-run the full shell/device workflow before release.
