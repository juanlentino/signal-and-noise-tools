# Dashboard: mission control

**Date:** 2026-08-19
**Surface:** Signal & Noise → Dashboard tab (plugin)
**Status:** Design approved, not yet implemented

---

## The problem

The Dashboard tab renders fifteen stat tiles in one flat grid, every tile the same
weight, followed by four unranked sections (External APIs, RSS activity, Recent
deploys, Maintenance).

Three specific defects:

1. **Seven of the fifteen tiles are version tiles**, all reading "up to date" — nearly
   half the grid spent on one bit of information that is almost always the same bit.
   All seven repeat verbatim in the SN Deploy Status widget on the WP home screen.
2. **Four kinds of thing share one grid at equal weight**: version state (7), health
   verdicts (5), activity (1), measurement (2). `HEALTH 0 findings` renders at exactly
   the same visual weight as `CACHES 3/3 fresh`.
3. **Nothing is designed for the normal case.** `sn_admin_glance_sort_by_attention()`
   already promotes `err` then `warn`, and it is carefully built — it even carries an
   `attention` opt-out so a cold cache cannot outrank a real health finding. But when
   everything is green, which is nearly every visit, the sort is a no-op and the result
   is fifteen equal tiles.

This is the same defect the Health tab had before v11.13.0 — "four kinds of thing on one
scroll under a single fraction" — and the same earning rule applies.

## What we are building

Three zones plus an action row, governed by one rule: **state earns space**. A zone whose
inputs are all green collapses to a single line. Only a zone that needs attention expands.
The shape of the screen becomes the status, readable before any number is.

The owner can **pin** a zone open across visits.

## Decisions, and why

| Decision | Rationale |
|---|---|
| Three zones (attention / fleet / measurement) + action row | The three questions actually being asked: is anything wrong, did it ship, how is the site doing |
| State earns space, not kind | Fixed-shape zones still spend the grid on green tiles that say nothing — the thing being removed |
| Pinning, stored per user | Buys back the muscle memory that a state-driven layout costs, without a site-wide setting |
| A pin can force a zone open, never closed | A view convenience must not be able to hide a problem |
| `unknown` is a third state, not a shade of green | Never-probed is neither healthy nor broken. Zero-vs-null, and the same lesson as the cold-cache note in the attention sort |
| Measurement never collapses | It has no green/red state, so there is nothing to fold |
| Five figures, capped | More is bloat; the cap is enforced by the narrow-viewport reflow, not by taste |
| Views is the hero figure | It carries the only sparkline, which earns it the full-width row on narrow and avoids an orphaned fifth figure |
| Zone modules behind a thin orchestrator | The only option that leaves `admin-tab-dashboard.php` smaller than it found it |

### Cut from the Dashboard entirely

Applying the v11.13.0 earning rule — it earns a place only if it can change, it tells you
to act or where to go, and **no other surface already owns it**:

| Cut | Goes to | Why |
|---|---|---|
| SN Deploy Status sidebar widget | — | Repeats all seven versions verbatim |
| Login blocks 7d | Security tab | Already owned there; has read `0` since it shipped |
| External APIs (GitHub rate) | Surface only when low | Interesting at 4% remaining, noise at 99% |
| RSS feed activity | RSS tab | The detail view pasted onto the summary |

Folded rather than cut: Recent deploys (inside Fleet), Diagnostics.
Dropped: the Cron event **count** — the verdict is actionable, the number is not.

## Architecture

Five files replace one 1114-line file (over the 800-line ceiling, and this redesign
touches most of it).

| File | Responsibility |
|---|---|
| `inc/dash-zones.php` | Zone contract, collapse/pin logic, renderer |
| `inc/dash-zone-attention.php` | Health, cron, caches, provenance, login guard |
| `inc/dash-zone-fleet.php` | 7 component versions, last deploy, recent-deploy fold |
| `inc/dash-zone-measurement.php` | The five figures |
| `inc/admin-tab-dashboard.php` | Composition + maintenance actions + diagnostics |

`sn_admin_glance_grid()` and `sn_admin_glance_sort_by_attention()` are **untouched**. They
become how an expanded zone renders its tiles — the job they already do well.

### The zone contract

Each builder is pure — no echo, no WordPress rendering — and returns:

```php
[
  'id'      => 'attention',                  // stable key; also the pin preference key
  'state'   => 'ok' | 'attention' | 'unknown',
  'summary' => 'Nothing needs attention',    // the collapsed line
  'detail'  => 'health, cron, caches, provenance, login guard',
  'cards'   => [ /* existing glance-card arrays, unchanged shape */ ],
]
```

**State derivation**, in this order:

1. Any input never probed → `unknown`. "Never probed" means the value is **absent or
   null**, distinguished with `array_key_exists()` rather than a falsy check — a probe
   that ran and returned `0` is measured and must not read as unknown
2. Any card with an `err` or `warn` pill that has not opted out of attention → `attention`
3. Otherwise → `ok`

`unknown` is checked first on purpose: a zone containing both an unmeasured probe and a
green one is not green.

### Rendering

- `ok` → one line, collapsed, green check.
- `attention` → expanded, ordered first on the page, tiles ranked by the existing sort.
- `unknown` → one line, collapsed, dashed border, neutral grey, `?` glyph.
- Pinned → expanded regardless of `ok`/`unknown`. **A pin never collapses an `attention`
  zone.**

## Data flow

| Figure | Source | Cost |
|---|---|---|
| Views 7d (+ sparkline) | Existing glance builder | Free |
| AI spend 30d | Existing glance builder | Free |
| Anchored count | Existing glance builder | Free |
| Citations | Local `sn_citations` table | One indexed query |
| Search clicks 7d | Search Console client | **A real read** |

Search Console sits outside the glance cache, so it gets its own transient. **Implementation
note:** the Dashboard has no single glance-cache TTL constant to inherit — pick the TTL from
the Search Console client's own existing cache if it has one, and otherwise 15 minutes, which
is short enough to stay current on a screen refreshed a few times a day and long enough that
repeat views cost nothing. **On cache miss or API error the figure renders `unknown`,
never `0`** — an unreachable API is missing evidence, not zero clicks. Citations reads the
local table, where `0` is a genuine measured zero and renders as `0`.

## Pinning

Stored in **user meta** (`sn_dash_pins`, an array of zone ids) — a personal view
preference, not a site setting. Rendered server-side as `<details open>` so the correct
state is present on first paint with no flash.

Toggling persists via a small fetch to a REST route on the existing `signal-noise/v1`
namespace, with a `permission_callback` of `current_user_can( 'manage_options' )` — the
same capability that gates the admin page itself (`inc/admin-page.php:36`). **Without JavaScript the
disclosure still works for the session**; only the persistence is lost. No new stylesheet —
CSS goes in the existing `assets/admin.css`.

## Responsive

Zones are one-per-row already, so narrow costs nothing structurally.

- Wide: measurement is a single flex row of five figures.
- Below ~600px: views takes a full-width hero row (it carries the sparkline); the other
  four reflow to a 2×2. One plus four, no orphan.
- Expanded `attention` tiles: 2-up on narrow, existing grid on wide.

## Testing

Zone builders are pure, so they test without WordPress like the rest of the suite.

- State derivation for each of the three states, including the precedence rule that
  `unknown` beats `ok`.
- **A pin cannot collapse an `attention` zone** — the safety property, tested directly.
- `unknown` renders distinctly from `ok` (zero-vs-null).
- Search Console failure renders `unknown`, not `0`.
- Citations `0` renders `0`.
- Renderer escaping.
- Every new suite negative-controlled by mutation before being trusted.

## Out of scope

- **The SN Deploy Status widget cut ships separately.** It touches the WP home screen, not
  this tab, and has its own rule about home widgets. Shipping it after the Dashboard lands
  keeps a home-screen regression from being caused by a tab redesign.
- No changes to the Security, RSS, or Measurement tabs beyond the cut items already being
  owned there.
- No changes to the glance card model or the attention sort.
