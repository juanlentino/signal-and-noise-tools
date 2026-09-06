# The Signal & Noise app, phase two: the control surface

Phase two of the app program (#1065). Phase one (#1058, v13.100.0) made the
dossier read the whole estate about one note. Phase two lets the reader ACT on
notes from the same window, in the idiom OpenStation's own WP Explorer uses,
so nothing here is invented chrome.

## What this is

Selection, a context menu, four server actions, and drag-out, for the Notes
section. The Discography section is untouched: its items are not posts.

## Decisions (from the code map, `map2/*.md`)

| Question | Decision | Why |
|---|---|---|
| Where do actions live? | In a context menu only. No bulk bar, no select-all, no checkboxes. | The Explorer pins "selection never opens a toolbar" in its own test; the framework's bulk-bar slot is what Posts/Pages wear, not the Explorer. |
| How is a selection made? | The framework's Finder rules through `applySelection`: plain click replaces (and opens the dossier, as today), Cmd/Ctrl toggles, Shift extends from the last selected; a marquee on the empty canvas on desk. | One definition serves tiles and rows; the marquee is `createMarquee`, phone excluded. |
| Where is the count? | A status footer the app paints: "%1$d of %2$d items" plus " — %d selected". | The framework has no footer slot; the Explorer authors its own. |
| How is the menu reached? | Right-click, a "More actions" button in the row and in the dossier header, a 500 ms / 10 px long press on touch or pen. | The Explorer's three triggers. |
| What scopes to the selection? | A menu action acts on the whole selection only when the clicked item is part of a multi-selection; otherwise on the clicked item. Labels stay singular; confirm messages pluralise. | The Explorer's rule, verbatim. |
| What confirms? | Trash (danger; "Move this to the Trash?" / "Move %d items to the Trash?"; label "Trash") and Publish ("Publish this note?" with the permanence sentence; label "Publish"). Purge and anchor retry do not confirm: idempotent, and they toast. | `ctx.dispatch( action, args, { confirm } )` is the only confirmation primitive; a danger dialog opens on the safe control. |
| How does an action answer? | `$os->toast()` in the Explorer's words and `$os->announce( 'post', action, ids )`; the app's own `watch( 'post' )` repaints it. No undo: the platform's toast carries no action. | The effect vocabulary is eleven names; none is confirm, focus or undo. |
| What can be dragged? | Every Notes row and tile lifts as a `shortcut` payload (`kind: post`, `ref`, `title`, `icon`, `entityId: signal-noise:notes`, `restPath: wp/v2/posts`, `items[]` when the row is in the selection). Desktop drop: an alias tile. Trash drop: the shell trashes through `restPath` and announces. Not on the phone; not with a modifier held. | The Explorer's lift, verbatim; the Trash target already refuses what it must. |
| Which operations? | Open in editor, View on site, Copy link, Copy ID (parity with the Explorer); Re-check now (phase one); Purge edge cache; Retry anchor dispatch; Publish; Move to Trash. | The estate has no per-note purge, no per-note citation re-check UI, no per-note kernel or inspection; purge and anchor retry are the two honest per-note operations the code offers. |
| Purge semantics | `sn_cf_post_purge_urls()` for the note, `sn_cf_purge_urls()`, then the SAME deferred probe a save schedules (`SN_CF_PROBE_HOOK` at `time() + SN_CF_PROBE_DELAY`, rescheduled). The probe log receives a MEASURED verdict later, never a row the button wrote. | v13.87.2: a manual purge writing the log moved the stale count with the operator's own button. |
| Anchor retry semantics | `sn_prov_reconcile_post( $id )`: re-dispatches the note's `unanchored` commits byte-identically. Offered only when the note carries one. "Re-anchor" has no per-note meaning (genesis-only) and is not offered. | The chain is append-only; the only honest per-note advance is the dropped-webhook recovery. |
| Capabilities | Per item: `edit_post` (editor, re-check), `delete_post` (trash), `publish_posts` + `edit_post` (publish); app-wide `manage_options` for purge and anchor retry. A row is disabled, never hidden, when absent. The window stays `edit_posts`. | The Explorer disables "Move to Trash" when `!canDelete`; per-action gates follow phase one's `verify`. |
| Phone | Long press opens the menu; the "More actions" button sits in the dossier header; no marquee, no drag; the footer stays. | The Explorer's mobile rules. |

## Architecture

- `apps/signal-noise/parts/actions.php` (new): the four handlers and two helpers, so `signal-noise.os.php` stays composition-only.
- State gains `selected` (array of post ids as strings, declared default `array()`), local-mutated; `go` resets it; `trash` resets it and clears `item` when the open note was trashed.
- Items gain `canEdit`, `canDelete`, `canPublish`, `unanchored`, `link`; the Notes section gains `restPath`; the payload gains `can: { purge, anchor }`.
- The client gains: the `select` local reducer; `ui.menu` in the ONE `ctx.ui()` bag; `renderMenu`, `openMenu`, a plain-JS long-press helper; `runAction`; the drag lift and the marquee wired in `mounted` (desk only); the status footer; the "More actions" buttons.
- No new ability, no REST surface: everything is an app action gated server-side.

## Error handling

An action that finds nothing it may act on toasts "Nothing could be …" and changes nothing. A purge on an unconfigured Cloudflare toasts "Cloudflare is not configured." An anchor retry with no unanchored commit toasts "Nothing to dispatch." A trash that removed the open note closes the dossier. A confirm the user declines dispatches nothing (the runtime returns false).

## Testing

`tests/openstation-app.php` moves its pins (state keys, actions, section keys). `tests/openstation-app-actions.php` (new) drives the four handlers through the State/Os stubs: capability asked per item and per action, the selection rule, the toast strings, the announce, the probe rescheduling, the reset of `selected`/`item`. `tests/openstation-app-client.php` (new) pins the client and the CSS by substring and count: one `ctx.ui(`, `applySelection`, `createMarquee`, `os-context-menu`, `dragManager.start`, `'shortcut'`, `confirm:`, the fixed menu order, the footer. The sandbox pass covers select, marquee, the three menu triggers, trash with its dialog, purge with its toast and scheduled probe, publish, drag to the desktop and to the Trash, and the phone's long press.

## Out of scope

Undo; select-all and checkboxes; per-note Search Console inspection and kernel rebuilds; bulk publish (a signature is permanent, one at a time); any Discography change; the phone queue and gestures (phase four).

## Amendments (2026-09-06, from the build)

Recorded by each builder against the contract plan; none required re-planning.

### Task A — server (`apps/signal-noise/parts/actions.php`, `signal-noise.os.php`, `tests/openstation-app-actions.php`)

1. **Namespace.** The plan named `SignalNoise\App` for `parts/actions.php` while registering handlers with `__NAMESPACE__` from the `.os.php`, which is `SignalNoise\OpenStationApp`: a contradiction the builder honoured with a bridging constant. Resolved for consistency: `parts/actions.php` sits in `SignalNoise\OpenStationApp` like every other part, and registration uses `__NAMESPACE__`.
2. **`anchor_action`'s refusal string when `manage_options` is absent.** The plan specifies a toast only for "no unanchored commit". The capability refusal uses `__( 'The dispatch could not be retried.' )` instead, because "Nothing to dispatch: every version is anchored or pending." would be a false statement about the chain. Pinned.
3. **`publish_action` resets `verdict` on success only** (the plan says "reset verdict (the chain changes)"; a refused publish changes no chain, so clearing a shown verdict there would lose information for nothing).
4. **`purge_action` checks `sn_cf_is_configured()` before building targets**, matching the plan's sentence order. Consequence, pinned: a user without `manage_options` on a configured site gets "Nothing to purge.", not the unconfigured string.
5. **"Nothing to purge."** also covers allowed targets that yield zero URLs (the plan only names the no-targets case). Pinned.
6. **The plan's `count( $chosen ) > 1` clause in `targets()` is unobservable:** if a one-member selection contains the clicked id, merging it adds nothing; if it does not, `in_array` already refuses. The clause was kept (it states the Explorer's rule), but the first-draft pin — "a forged `selection: true` on a one-member selection still acts on one note" — passed against code with the clause deleted, a vacuous pin. It was replaced with a mutation-sensitive one (clicked note 11, state selection `['12','21']`, `selection: true` → must be `[11]`), verified red when `in_array` is removed. The clause itself is documentation, not a guard, and no test can make it one.
7. **`tests/openstation-app-actions.php` widens the `Os::can()` stub** from `tests/openstation-app.php`'s single `$GLOBALS['__os_can']` boolean to a per-capability map (`$GLOBALS['__os_can'][ $cap ] ?? true`), because these pins must refuse `delete_post` while allowing `edit_post`. `Os` is a framework class, not a WP function, so stub-parity is unaffected (it passes).

### Task B — client (`apps/signal-noise/signal-noise-client.js`, `signal-noise.css`, `tests/openstation-app-client.php`)

1. **`applySelection` signature.** The plan's decided fact #4 says `applySelection( current, id, { shift, toggle }, order )`. The 1.1.6 source (`src/app-runtime/selection.ts:17-37`) declares `applySelection( selected, order, id, { ctrl?, shift? } )`. The source signature was coded (`applySelection( selectedIds( state ), order, String( args.id ), { ctrl: !! args.toggle, shift: !! args.shift } )`) and that exact call text is pinned in the test. The plan's reducer arg names (`{ id, shift, toggle, order }`) are kept as the local reducer's own contract.
2. **`createMarquee` options.** The plan says `{ canvas, className, onChange }`. The source declares `{ root, canvas (a selector string), item?, select, className }` and returns the teardown. The source's names were used: `{ root: ctx.root, canvas: '.snt-canvas', item: '[data-item-id]', className: 'snt-marquee', select: ( ids ) => ctx.local( 'select-set', { ids } ) }`. No re-attach loop was written: the source (and `wire.ts:126-135`) attaches to the mount root, which the runtime morphs but never replaces.
3. **List rows live in shadow DOM.** This app's list view is the kit's `<os-table>`, which paints its rows imperatively into its own shadow root — unlike WP Explorer's hand-rolled light-DOM table. Consequences:
   - A row cannot be given `data-item-id` / `data-snt-drag` from the template. The title cell carries them instead, via `column.render`.
   - The drag lift, right-click and long press resolve the row through `event.composedPath()` (a new `closestInPath()` helper) rather than `Element.closest()`, which stops at the boundary.
   - `@contextmenu` and the long-press handlers hang on the `<os-table>` element, not on each row.
   - Row selection visuals are handed to the component (`.getRowId=${ row => String( row.id ) }` + `.selection=${ selectedIds( ctx.state ) }`), so the kit paints them with its own tokens. No `.snt-row.is-selected` rule was added — there is no `.snt-row`.
   - `.snt-more` is always visible rather than revealed on row hover/focus: the list-view copy is inside the table's shadow root where a hover rule written in `signal-noise.css` can never reach it, and a control that appears in one view but not the other is worse than one that is simply always there.
4. **Drag icon.** The plan says `icon: item.thumb || ''` (the Explorer's item key). This app's items carry `thumbnail` (`parts/notes.php:136`); `item.thumb` is always undefined here. Used `one.thumbnail || ''`.
5. **`entityId`.** The plan hardcodes `entityId: 'signal-noise:notes'`. Writing that as a `section.id === 'notes'` test would have made the pinned Notes-gate count 3 instead of 2. It is a `DRAG_ENTITY = { notes: 'signal-noise:notes' }` map instead: a section opts in by name, the literal is present, and the gate count stays 2. The lift refuses when either `restPath` or the entity id is empty.
6. **The section gate for the control surface reads the descriptor** (`isPostSection( data )` = `section.kind === 'post' && section.restPath`), not the section id — same reason as (5), and it is the more honest predicate: the four actions are post actions and the lift routes through the REST collection.
7. **Verify row gate.** The plan says "read `item.detail.actions` labels". The client matches on `a.dispatch === 'verify'` instead: the label is display text and is translated; the dispatch name is the fact.
8. **Copy toasts tell the truth.** `copyText()` resolves false on an insecure origin or an old WebView. `copyAndSay()` toasts `'Link copied.'` / `'ID copied.'` only on a true resolve, and the framework's own failure string otherwise. The pinned strings are still present.
9. **`data-snt-drag` is painted blank (not omitted)** on a non-actionable section, so the lift's selector is `[data-snt-drag]:not([data-snt-drag=""])[data-item-id]`.
10. **The Escape handler now closes the menu first** and the dossier only when no menu is open (the plan's requirement), which meant restructuring phase one's `onKey` — same gating (`ctx.root.contains` or `.os-window--focused`), same teardown, now inside a teardown list alongside the marquee and the drag listener.
11. **`node --check` is not run from the PHP suite.** `ci.yml` has no setup-node step, so a node-dependent pin would either fail-closed in an unrelated lane or fail-open when node is missing. It was run by hand (passes) and is reported here instead.
12. **A small extra:** `.snt-detail__more` (a wrapper positioning the dossier header's More button beside the ✕) and `aria-multiselectable="true"` on the canvas — both required by the surface, both defined in the sheet.
