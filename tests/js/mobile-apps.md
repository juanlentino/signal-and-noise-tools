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
