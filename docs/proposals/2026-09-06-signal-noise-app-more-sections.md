# The Signal & Noise app, phase three: more sections

Phase three of the app program (#1068). Phase one (#1058) made the dossier read
the whole estate about one note; phase two (#1065) let the reader act on notes
in the Explorer's idiom. Phase three adds the sections the estate already has
data for, each a glance and a door, never a second home.

## What this is

Three sections beside Notes and Discography, registered through the existing
section registry (`snt_os_app_sections`, inc/openstation-app.php): Pages,
Citations, Scheduled fragments. Discography is untouched.

## Decisions (my recommendation, executed)

| Question | Decision | Why |
|---|---|---|
| Which sections? | Pages, Citations, Scheduled fragments. | Each has a store the estate already reads (the signed pages, `sn_cit_*`, `sn_schedule_all()`), an admin leaf to open as the door, and a clear glance. Redirects, webhooks and media have no glance worth a tile. |
| What is a Page here? | A page opted into signing (the provenance sign meta), kind `post`, `restPath: wp/v2/pages`, with the same items, dossier, menu and drag-out Notes have. | The dossier's trust block already resolves the subject kind through `sn_prov_subject_kind()`; the phase-two actions are post actions and apply once `note_allowed()` admits the page type for this section. |
| What do the dossier builders do with a page? | `sn_note_dossier_post()` admits `post` and `page`; trust and editorial run as for a note; numbers and operating state run when the page is public; the ability `note-dossier` accepts a page id under the same `edit_post` gate. | The builders are already type-guarded in one place; widening that place widens all four honestly. |
| Citations: items? | One entry per citation row: id = the row id (non-numeric-safe, as Discography's are), title = source title or host, status = tier, columns = tier, target note, last checked; detail = the row's facts as a text block plus the target note's title; actions = a door (URL) to Integrity → Citations and, when the target note is a Note, a URL to the note. | Read-only: the graph is measured by the verifier, never edited by hand. |
| Scheduled fragments: items? | One entry per schedule row: title = the target note's title, status = the row's status (queued, active, done…), columns = starts, ends, status; detail = the window, the action (reveal/hide), the purge URLs; actions = a door to Connections → Scheduled and a URL to the note. | The leaf's three operations stay in the leaf for this phase; when they come, they come gated as the leaf gates them (`manage_options`). |
| Which sections get the control surface? | Pages only (kind `post` with a `restPath`). Citations and Scheduled fragments are entries: no selection actions, no drag, no menu beyond the doors. | Phase two's gate is the descriptor (`kind === 'post' && restPath`), so nothing new is needed to keep the entries inert. |
| Capabilities | Pages: `edit_pages` to see the section, per-item rights as Notes. Citations and Scheduled fragments: `manage_options`, like the leaves they open. | The section registry re-checks `capability` on every call. |
| Phone | As phase two left it: entries open as pages, the doors are buttons. | Nothing new. |

## Architecture

- `apps/signal-noise/parts/pages.php` (new): the Pages section, built on the same helpers Notes uses (extract the shared item builder from `parts/notes.php` into `parts/post-items.php` so both sections call one function with a post type and a section id).
- `apps/signal-noise/parts/citations.php` and `parts/schedules.php` (new): entry sections.
- `inc/note-dossier.php`: `sn_note_dossier_post()` admits `page`; `inc/abilities-note-dossier.php`: the input description says "a note or a signed page".
- `apps/signal-noise/parts/actions.php`: `note_allowed()` admits the current section's post type, read from the descriptor, never from the client.
- The client is unchanged except for one thing: a section's kind/restPath already gate everything; entries paint from their items alone (the foreign-section test proves it).

## Testing

`tests/openstation-app.php` gains the three sections' registration pins (order, kind, capability, restPath); `tests/openstation-app-sections.php` (new) drives each items builder over stub stores. `tests/note-dossier.php` gains the page case. The sandbox pass: a signed page's dossier and menu, a citation entry's door, a schedule entry's door, on desk and phone.

## Out of scope

New operations; the phone queue; Discography.
