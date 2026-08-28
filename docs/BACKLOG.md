# Backlog — the release-planning queue

The single internal working queue for the estate (plugin + theme). The public roadmap
board at `/maturity/roadmap/` is the *published* planning copy; this file is where
release order, priorities, and gates actually live. Rules:

- Re-verify any item against the code before starting it (the twice-burned rule), and
  check the denied-list perimeter in
  [2026-08-27-roadmap-brainstorm.md](proposals/2026-08-27-roadmap-brainstorm.md) before
  adding anything.
- Considering/Later rows on the board are commitments to nothing — they enter this file
  only when they become real work with a priority or a gate.
- Priorities are the owner's to reorder; sizes are S/M/L.

## Chores bound to the next release train

| Chore | Notes |
|-------|-------|
| **Reset the board override — AFTER installing v13.18.0** | The fold shipped in v13.18.0, so the floor now matches the board minus four graduated rows. `sn_apply` `roadmap_board` with `payload.reset: true` drops the option and returns the page to code-canonical. **Order is load-bearing:** resetting before the update lands falls back to the DEPLOYED version's floor, which is the stale one. Verify first that `gates.fingerprint.observed` matches the new floor's fingerprint |

~~Fold the board override~~ and ~~Operations Done-ceiling graduation~~ — both SHIPPED
in v13.18.0; see the log entry below. The ceiling chore was understated: three families
were at the wall, not one.

## Ready to build — prioritized

| P | Item | Repo | Size | Notes / first step |
|---|------|------|------|--------------------|
| — | *(empty — every queued build has shipped)* | | | New entries come from the board's Considering/Later columns or a measured defect |

## Blocked on the owner (not buildable by me)

| Item | What is needed | Payoff when done |
|------|----------------|------------------|
| **Tag descriptions — partial is fine** | One written sentence per tag, in wp-admin → Posts → Tags (`description`). Editorial voice, deliberately not mine to write. **NOT all-or-nothing**: both consuming surfaces fall back cleanly per tag, so writing the top few first is a valid first pass | Each sentence lights up BOTH surfaces at once — the archive's hero dek (theme v12.11.0) and the tag's meta description (plugin v13.14.0). An undescribed tag keeps the corpus dek and emits no meta description, exactly as today |
| ~~Thin-tag decision~~ **RESOLVED 2026-08-27: keep all 23** | Nothing to do — see the log entry below | The vocabulary stays as the 83→23 pass left it |

## Planned on the board — each waiting on its named gate

These are the board's seven Planned rows. Each names its gate; none is schedulable until
the gate opens. When one opens, it moves into the prioritized table above.

| Family | Item | Gate |
|--------|------|------|
| Analytics | AI-attention section in the weekly digest | **Now gated (v13.18.0): an Analytics retirement.** Folding the override put Analytics done at 4, the canary limit, so this row cannot graduate to done until an older Analytics row retires onto the family maturity page. It was "none hard" only because the floor was stale |
| Proof of origin | Extend signing/anchoring to pages, then media | Sequenced after current notes-chain stability; owner call on timing |
| AI | Move the operative AI channel to the desktop platform's native agents | The native runner proving stable enough to trust with the same fences (agents arc currently DISABLED) |
| AI | Retire legacy single-purpose tools the consolidated set absorbed | Usage evidence, not a date |
| Machine learning | Extend the deterministic layer, pipeline by pipeline | A real editorial question demanding it |
| Machine readability | Usage-preference header + robots rule, with rights-dialect parity sweep | The internet standards body finalizing the spec |
| Operations | Dependency provenance gate for worker deploys | One-time audit showing enough of the tree publishes attestations |

## Watches (not releases — time passes, then a number is read)

| Watch | Reads |
|-------|-------|
| signed_agent window | Scheduled task fires 2026-08-30 21:00 UTC; hands-off, decides on the full-week number |
| IPv6 criterion | Poll `sn-status{ipv6_criterion}`; act ONLY on `decision = build_ranges` |
| GSC drift section | ~Sep 3: flips from "accruing" to the good zero or its first rows — last unverified surface of the v13.11.0 work |
| Upstream ⌘K release (#683) | Still untagged; the Cmd+K seam breaks every upgrade — retest on tag |

## Deliberately not queued

- **Considering / Later columns** — live on the board; they are ideas, not work.
- **The denied perimeter** — newsletter/email, reader-facing release notes, ActivityPub,
  C2PA, cookieless retention, URL shortener, Rocket Loader, dashboard-widget sprawl,
  admin-bar nodes, brutalist wp-admin. Sources in the brainstorm doc.

## Log

- 2026-08-27 — the board-override FOLD shipped as v13.18.0, and it was not the
  mechanical copy this file promised. The override held **19 of 28 cells**; two
  independent instruments agreed on which (the door's own merge report, and a parse of
  the rendered page — negative-controlled first: 9 of 28 cells returned byte-identical
  through `esc_html`, which is what makes the other 19 drift rather than a lossy read).
  Folding as-is RED the CI canary: `MAX_DONE` is 5 and the door accepts it, but the
  shipped floor must stay at 4, and **three** families sat at the wall — Machine
  readability and Accessibility alongside the Operations one this file named. Four rows
  graduated first (Accessibility's alt-text PAIR by owner call, Operations' one-dashboard
  row, and Machine readability's crawler-manifest row — the last adding NO principle,
  because its destination already stated all three of its clauses). Nine pins went red
  and were retargeted rather than relaxed. The fold also SPENT Analytics' headroom,
  which is why the digest row above now carries a gate it did not have this morning.
- 2026-08-27 — thin-tag decision RESOLVED: **keep all 23, merge nothing.** I proposed
  merging 7 on count + co-occurrence evidence, then READ the affected notes and withdrew
  it: every one of those tags is the most SPECIFIC descriptor its notes have
  (`ai-disclosure` on two notes about disclosure labelling; `black-box-royalties` on the
  note about money going to the largest nameable unit). Co-occurrence measures
  co-PRESENCE, not duplicated meaning. The mechanical 4-tag CAP fails the same way — it
  would strip `provenance`, the only PILLAR tag, from the two flagship provenance essays.
  `freelance-business` is also load-bearing: its 2 notes carry no other tag, so merging
  would orphan them. No note loses a tag, so no retagging is needed.
- 2026-08-27 — topic-hub GROUNDWORK shipped both sides (plugin v13.14.0 #836, theme
  v12.11.0 #244), and the item turned out to sit on a LIVE DEFECT. Tag archives had no
  branch in `sn_seo_meta_for_current_view()` at all: 23 indexable archives with no
  canonical and no meta description, and /tag/provenance/ vs its /page/2/ serving
  different notes under an identical <title>. Fixed (pretty paged self-canonical); the
  hero dek now renders the term's own description when written. The hubs themselves stay
  blocked on 23 sentences — moved to the owner-blocked table above, with the thin-tag
  question raised alongside so effort is not spent on tags that should be merged.
- 2026-08-27 — editor smoke SHIPPED (plugin v13.12.0 #833 + v13.12.1 #834). Daily cron
  against WordPress NIGHTLY (7.2-alpha, two majors ahead of prod's 7.0); requirements
  DERIVED from our own source, four negative controls run first. Two false readings were
  caught before trusting it — an over-broad derivation (8 noise failures) and, worse, a
  hook-name filter that returned a clean 0 while never looking at the pre-publish gate.
  Registration in cron-liveness had to be a SEPARATE release: the guard correctly refuses
  to judge a workflow not yet on the default branch.
- 2026-08-27 — PHP lanes SHIPPED in both repos (plugin v13.11.2 #832, theme v12.10.2
  #243). The item assumed "next PHP"; the real gap was the PRESENT — production runs
  **8.4** and CI pinned 8.3, so CI had never tested the live version. Lanes: 8.4
  production parity (blocking), 8.5 readiness (blocking, measured clean first — the
  host does NOT offer 8.5 yet), 8.6 nightly (non-blocking, step-level). All six lanes
  green on first run.
- 2026-08-27 — stub-parity sweep SHIPPED in BOTH repos (plugin v13.11.1 #831, theme
  v12.10.1 #242; byte-identical tools/stub-parity.php, wired into the existing PHPCS job).
  Findings: three suites stubbed wp_get_post_revision() by value where core declares
  &$post — fixed. An earlier draft's 381 arity "failures" were all artifacts; the file
  records why those checks were dropped so they are not rebuilt.
- 2026-08-27 — hover previews SHIPPED as theme v12.10.0 (PR #241, tagged, draft cut;
  owner updates via wp-admin). Server-stamped data attributes, zero reader-side fetch;
  16 assertions on the real filter, two mutations proven red.
- 2026-08-27 — reply-by-email SHIPPED as theme v12.9.0 (PR #240, tagged, draft release
  cut; owner updates via wp-admin). CodeQL raised a real high on the new mailto line —
  fixed by validating + percent-encoding the decoded parts, not by dismissal.
- 2026-08-27 — print stylesheet STRUCK: already shipped in v9.10.0 as the theme's
  118-line `assets/css/print.css` (media="print" on singles + pages, external URLs
  revealed, resume fold forced open). The backlog entry came from reading one of the
  three files a grep listed. Residue: eyeball that the provenance panel prints legibly.
- 2026-08-27 — file created from the roadmap brainstorm; rewritten same day as the full
  release-planning queue (priorities, gates, chores, watches) at owner direction.
