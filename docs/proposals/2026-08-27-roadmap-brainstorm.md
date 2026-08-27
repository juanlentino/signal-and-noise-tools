# Roadmap brainstorm — 2026-08-27

The owner-announced board brainstorm from the 2026-08-27 handoff. Open sweep across all
seven families with priority on the empty columns (Analytics and Machine learning had no
Considering rows; Accessibility was the thinnest live column), plus a second round through
a future-proofing lens. Every candidate was grepped against both repos before it was
proposed — the twice-burned rule — and several died to that grep (embeddings versioning:
`ml-embeddings-compare.php` already IS the side-by-side instrument; OG-card content
credentials: `og-card-provenance.php` already embeds the VC in the PNG; content half-life:
`analytics-posts-lifecycle.php`; cluster naming: `analytics-topics.php` labels).

## What shipped (board write, no code change)

One `roadmap_board` publish through the write door, fingerprint `3f0fa8f6…` observed from
a dry-run, all four gates green, caches purged, live page re-verified on the bare URL.
Board moved **29/7/10/12 → 31/7/20/17** (Done/Planned/Considering/Later).

### Stale rows promoted to Done (Operations, 3 → 5 — ceiling met exactly)

Both sat in Considering while their modules were live: `inc/morning-brief.php` and
`inc/config-drift.php`. Restated as shipped capability.

### New Considering rows (13)

- **Analytics (3):** Search Console unanswered-questions surface (owner-eyes only, never a
  model); outbound clicks as an aggregate event (named as the family's first new-collection
  ask); a deterministic year-in-review page.
- **Machine learning (2):** per-note distinctive vocabulary from the existing tf-idf
  kernel; month-of-year seasonality of themes.
- **Accessibility (4):** keyboard-walk instrument; WCAG 2.2 24px target-size instrument;
  heading/link-text quality sweep; APCA recorded beside AA on every contrast run.
- **Proof of origin (1):** peer cross-site witnessing — the *Provenance Without
  Institutions* thesis demonstrated live.
- **AI (1):** AI spend itemized by door.
- **Operations (1):** credential/domain/cert expiry watch ("the calendar of quiet
  failures"). Net Considering change here: −2 promoted, +1 added.

### New Later rows (5 — "when X settles" temperament, placed with owner approval)

Post-quantum successor key (Proof of origin); signed continuity/succession statement
(Proof of origin); rights-stamped bulk corpus dataset (Machine readability); versioned
ability contracts (AI); provenance-intact static export — "the site that can leave"
(Operations).

### Killed in review

Fediverse/ActivityPub actor (owner cull — furthest from thesis, largest standing
commitment); plus everything the greps disproved, listed above.

## Follow-up for the next release train

The option override now diverges from `sn_maturity_roadmap_static_board()` across many
more columns. Per the standing convention in that file's comments, fold the override into
the static PHP array at the next release so code-canonical catches back up. Mechanical
copy; the live option remains canonical until then.
