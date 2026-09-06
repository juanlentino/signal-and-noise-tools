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

## Amendments (2026-09-06, from the build)

### Task A

1. DROPPED `section_id` from the `$cfg` contract. The plan's cfg listed `{ post_type, statuses[], meta_key?, meta_value?, kind_filter?, section_id, verify_link }`. Nothing in the item builder reads a section id — the `edit`/`verify` dispatch args carry only `item`/`title`, and the handler reads the section from state, not from args. Shipping it would have been a key computed and never read. Everything the plan wanted it for is answered by `post_type`. All other cfg keys are exactly as specified.

2. `signal-noise.os.php`: post-items.php and pages.php are required UNGUARDED (they are Task A's own files, always present); only citations.php and schedules.php carry the `file_exists` guard the plan asked for. The plan's wording ("the four require_once lines guarded by file_exists") would have put a permanent existence check on two files that always ship.

3. TOUCHED A FILE OUTSIDE MY LIST: tests/openstation-app-actions.php. Added a `get_post_type_object()` WP stub (after `get_post_status()`, ~line 98). NO pin moved and no expected value changed. Reason: `note_allowed( …, 'publish' )` now asks the post type object for its own publish capability, and `post_publish_cap()` refuses ('' → false) when the object cannot answer, so without the stub six existing publish pins went red (measured: 43 passed / 6 failed → 49 passed / 0 failed). I deliberately did NOT add a `post→publish_posts` fallback map in production code to paper over a harness gap: an unknown right must stay a refusal.

4. Decision 14's order pins landed with ALL SIX sections (`notes, pages, ledger, discography, citations, schedules`) because Task B's citations.php and schedules.php had already landed when I wrote them. Their root counts are `array( 3, 2, 1, 2, 0, 0 )` — the two entry sections count 0 in this suite because their stores are not stubbed there and both parts guard with function_exists.

5. inc/note-dossier.php gained a named constant `SN_NOTE_DOSSIER_POST_TYPES = array( 'post', 'page' )` rather than an inline literal pair. Slightly beyond "admits page"; it is pinned in tests/note-dossier.php.

6. New user-facing string: `A page is signed when it is published.` (post-items.php `post_unsigned_sentence()`). The shipped Notes sentence names "a note", so a page needed its own; the note sentence is unchanged and still pinned.

7. Editorial's Related gate requires the resolver: the block is omitted when `sn_prov_subject_kind()` is absent, rather than falling back to `post_type === 'post'`. A post outside the Notes category is not a note, so a post-type fallback would have been the producer's assumption dressed as a guard.

### Task B

Four, all small and all deliberate.

1. `detail.hero` is `''` on BOTH sections, not the item title. The plan's Task B contract writes `detail { hero: title, … }` for Citations, but `hero` is rendered by the client as `<img class="snt-detail__hero" src=${ d.hero }>` (apps/signal-noise/signal-noise-client.js:878). A title in an `<img src>` is a broken image on every citation row. Neither a citation nor a schedule row has an image, so both pass `''` — which the client already handles (the `d.hero ? … : ''` ternary on that same line). Pinned as such in tests/openstation-app-entries.php.

2. Citations title falls back `source_title` -> source HOST -> raw `source_url`. The plan writes "source_title or the source host"; the raw URL is a last resort so a row whose source URL has no parseable host never paints an empty title. This is exactly `sn_cit_render_row()`'s ladder (inc/citations-admin.php:184), so a row reads the same in the window as in the leaf.

3. The Scheduled badge carries `title => the window` alongside `text` and `tone`. The plan wrote the badge as `{ text: status, tone: … }`; the item contract (inc/openstation-app.php:48-49) declares badge as `{text,tone,title}`, and Notes already uses `title` for hover text. Purely additive.

4. The purge-URL `table` block is emitted ONLY when the decoded list has at least one URL. The plan says "+ one `table` block of the purge URLs". An empty table is noise, and Discography already omits its Tracks table on an empty list (parts/discography.php:64). The `Purge URLs` FACT is always present and carries the count, including "0" — so "no purge URLs" is still stated, just not as an empty table. Pinned both ways (a `[]` column and a NULL column both produce no block).

### Task C

None. The three changes are exactly the ones the contract names and nothing else moved. Two comment blocks adjacent to the changed lines were reworded (the `updated:` docblock said "Notes only", which was no longer true, and the DRAG_ENTITY docblock needed no change); no other line of the client differs. `git diff --stat` on the branch is exactly the two assigned files: the client (the two gates, the map, a reworded comment) and its suite (four lines).

## Amendments (2026-09-06, from the review)

Six reviewers (the Pages section, the entry sections, the client, security, tests, readout) and two refuters per finding: twelve findings survived and are applied.

1. **`perm => readable` guards private posts only.** WordPress applies it to the `private` status and leaves draft, pending and scheduled unrestricted, so the guarantee the plan, the builder's docblock and the policy doc made was false. Both post queries now add a `posts_where` clause, `post_status = 'publish' OR post_author = <me>`, for a user without the type's `edit_others_*` capability; `editable` was not used because it would also hide other authors' published posts. The suite pins the property with another author's draft, not the parameter.
2. **A citation never checked reads "not checked"**, and "no response" is reserved for a check that ran and got nothing; a status code is a status code. Three states, said apart.
3. **A target is resolved before it is named.** A target that exists but is untitled reads "(no title)" and keeps its link; only a missing target is "unresolved", and it offers no link. The link and the wording come from one resolution.
4. **A fragment with no start reads "always"**, the engine's, the leaf's and the dossier's word; "never" is for an absent end.
5. **The entry sections declare only the columns the list view does not already paint** (target and last status; action and ends): the built-in Status and Date columns carry the tier and the last check, the status and the start.
6. **The server actions are pinned through both sections**: under Pages a page id acts and a note id is refused, under Notes the mirror.
7. **`hasDossier` has its false case pinned** for Discography and a foreign section; the post suites' query stub honours order and page size with fixtures whose date order differs from array order; the drag map's page key is pinned with its value.

