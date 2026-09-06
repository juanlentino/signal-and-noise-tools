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
