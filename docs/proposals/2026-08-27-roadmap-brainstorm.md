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

## Round two — the theme/plugin sweep (same day)

The owner asked what the theme and plugin themselves could gain, since round one leaned
edge/instrument-heavy. A second `roadmap_board` publish (fingerprint `9bc35d38…`, gates
green, live-verified) added **9 more Considering rows**, taking the board to
**31 / 7 / 29 / 17**:

- **Machine learning (2):** kernel-ranked search on `/notes/?s=` (today it's stock WP
  `LIKE` order — the OpenSearch route points at it and the tf-idf kernel never touches
  it); the corpus index published as a browsable reference (public graduation of the
  distinctive-vocabulary row).
- **Proof of origin (3):** quote-with-receipt (select→cite with anchor; folds in
  `::target-text` landing styling and academic citation formats); version-diff for
  readers (the re-anchor chain made legible); the manuscript beside the proof (canonical
  prose mirrored into the ledger, which today receives only `content_hash`).
- **Operations (2):** offline reading via service worker; passkey sign-in with the
  password demoted to fallback (no WebAuthn anywhere in either repo).
- **Accessibility (1):** pre-publish gate extended to missing-alt and skipped-heading
  warnings.
- **Analytics (1):** a localStorage reading ledger that never phones home.

### The denied-list perimeter (assembled this session — check it before proposing)

The owner remembered a cut list mid-session, and it was real, spread across three specs:

- **Newsletter/email (B6)** — `2026-07-06-plugin-roadmap-8.9-to-9.0-design.md`: cut,
  "revisit only on a pivot toward editorial broadcast"; reinforced by the `/notes`
  subscribe page's published copy, "No subscription form. No schedule." Killed a
  proposed email-digest row this session.
- **Reader-facing release notes** — theme
  `2026-07-01-stack-audit-abilities-consolidation-design.md` §9, standing-declined
  (also: URL shortener, Rocket Loader, dashboard-widget sprawl, new admin-bar nodes,
  brutalist wp-admin styling). Killed a proposed public-changelog row this session.
- **ActivityPub** — theme `2026-07-02-activitypub-adoption-design.md`: built, verified,
  and unwound the same day; "never active in there."
- **C2PA** — declined twice (`2026-07-29-site-provenance-design.md`); native provenance
  is the chosen road. **A5 source-cohort retention** — interpretively unsafe.

### Just-build backlog (deliberately NOT board rows — small, self-evident once shipped)

> Now tracked as the living queue in [docs/BACKLOG.md](../BACKLOG.md); the list below is
> this session's record.

1. Hover previews for internal note links (reuse the `footnotes-popover.js` pattern).
2. A real print stylesheet (one `@media print` fragment exists in `block-styles.php`).
3. Topic hubs for the 23-tag vocabulary — no taxonomy template exists; each tag needs a
   one-sentence description to clear the contentless-page SEO trap on record.
4. Reply-by-email on notes, reusing the `contact-email.php` DOM-assembled mailto.
5. CI: a next-PHP lane (both repos pin 8.3 only; public repos, minutes are free).
6. CI: editor-integration smoke against WordPress nightly (the pre-publish gate and
   draft echoes ride `@wordpress` packages).
7. CI: stub-parity sweep — diff test-stub signatures against the pinned WP source (the
   stub-drift trap is 13× bitten by memory's count).

Killed by grep in this round (already built): webmentions (the citations cluster is a
four-tier verified-claim system), backlinks (`cited-by`), year navigation (index spine),
`text-wrap`/hanging punctuation (v9.4.0), `forced-colors` + `prefers-contrast`
(`base.css`), reading-progress bar (`article-toc.js`), copy-permalink/Web Share
(v9.10.0), key custody (the plugin never signs — author-side by design).

## Follow-up for the next release train

The option override now diverges from `sn_maturity_roadmap_static_board()` across many
more columns. Per the standing convention in that file's comments, fold the override into
the static PHP array at the next release so code-canonical catches back up. Mechanical
copy; the live option remains canonical until then.
