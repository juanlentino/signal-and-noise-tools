# Where the console actually lives

**Status:** findings + recommended scope, 2026-08-19. Awaiting owner review.
**Parks:** [dashboard-metabox-console-2026-08-19.md](dashboard-metabox-console-2026-08-19.md) and its plan.

---

## The question

v11.28.0 shipped a Dashboard tab that was supposed to be a command center and isn't
— 53% of the viewport empty, the Maintenance panel dominating. The first correction
was to rebuild the tab as a WP metabox console. That was also wrong, for a reason
the owner named: **he works in OpenStation, not Classic Admin.** WP metaboxes are
native to Classic Admin. Building a metabox console inside an OpenStation window is a
second command center inside a window, on a desktop that already is one.

## Finding 1 — the desktop is already ~80% of the console

Eight SN widgets are registered (`inc/desktop-mode-widgets.php:133-233`). Against the
Dashboard tab's facts:

| Already covered | Partial | Not covered at all |
|---|---|---|
| Theme + plugin versions | Health (different framing: passed/total vs findings count) | **Cron events + orphans** |
| Seven worker versions | Views (widget is 14d, tab is 7d) | **Cache freshness** |
| Last deploy time | Maintenance (missing force update-check) | **AI spend 30d** |
| Anchored / provenance | Override diagnostics (can clear, can't list) | **Search clicks** |
| Uptime, RSS, machine readers | | **Citations** |
| | | Recent deploy list |

## Finding 2 — an admin page in a window CANNOT adopt OpenStation styling

This contradicts the working assumption, so it is quoted rather than summarised.
OpenStation `AGENTS.md:31`:

> The palette is scoped to `body.os-active`, never `:root`. `variables.css` is a
> dependency of `chromeless.css`, so it also loads inside every iframe window — a real
> `wp-admin` document. On `:root` the palette would repaint WordPress's own UI in
> there… Iframe documents carry `os-chromeless`, match nothing, and render on the
> fallback literals. **An admin page in a window looks exactly as it does outside one
> — that is a promise, and `tests/vitest/brand-palette.test.ts` holds you to it.**

So the `--os-*` tokens are for **widget chrome only**. There is no filter, body class
or stylesheet that opts an admin page into shell styling, and upstream enforces the
absence with a test. Designing around "we can make the tab look like OpenStation" is
designing around something that does not exist.

Note also that SN's own widgets deliberately do *not* use the `--os-*` tokens
(`assets/desktop-mode-widget.js:15-18`) — an earlier audit found them the wrong fit
for SN's dark-glass treatment, so widget styling is SN's own inline vocabulary.

## Finding 3 — the mirror-widget mistake has already been made once

"SN Pulse" was retired in v9.53.0 for duplicating Site Views + Health
(`inc/desktop-mode-integration.php:26-28`). A widget that mirrors the Dashboard tab is
the same mistake with a new name.

## What follows

**The desktop is the console, and it mostly exists. The work is small and additive,
not a redesign.**

Ranked by value-to-effort:

1. **Cron widget** — the biggest real hole, and the data is already localized as
   `cronSummary`. Answers "is the site awake?", which nothing on the desktop does.
2. **Cache freshness** — Quick Actions can purge but shows no verdict, so you purge
   blind.
3. **Force update-check on Quick Actions** — trivial. Its own description already
   promises it (`inc/desktop-mode-widgets.php:182`) and the button is absent; the
   capability exists on Cmd+K.
4. **A measurement tile** (AI spend / search clicks / citations) — only if these are
   checked daily from the desktop. Otherwise they belong where they are.

**Explicitly not doing:** restyling the admin page to look like OpenStation (refused
upstream), a metabox console (parked), a widget that mirrors the Dashboard tab (the
SN Pulse mistake), or deleting the SN Deploy Status widget (the earlier plan had this
exactly backwards — in OpenStation the widget is the native surface).

**The Dashboard tab keeps its current job**: settings, maintenance, diagnostics —
wp-admin-native, because in a window it is promised to look that way, and when
OpenStation is inactive it is all there is.

## What already shipped from the abandoned direction, and stays

Direction-neutral and live: the active-tab resolver, the request snapshot, the
**warming fix** (a real defect the owner saw on screen — the fleet reported "1 of 7
never probed" while the widget beside it listed all seven), and the briefing sentence
builder. The metabox code is committed but inert — not loaded, `function_exists()`
guarded — and is reusable if the Classic Admin surface ever wants it.
