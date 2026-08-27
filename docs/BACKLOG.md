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
| Fold the board override into `sn_maturity_roadmap_static_board()` | Mechanical copy; the option is canonical until it lands. Two board writes on 2026-08-27 widened the divergence |
| Operations Done-ceiling graduation | Ops Done sits at the 5-row ceiling; the NEXT Operations ship requires graduating the oldest row to the Ops maturity page first |

## Ready to build — prioritized

| P | Item | Repo | Size | Notes / first step |
|---|------|------|------|--------------------|
| 1 | Topic hubs for the 23-tag vocabulary | theme | M | No taxonomy template exists. HARD PRECONDITION: one written sentence per tag (owner writing task), or the pages trip the contentless-page SEO trap on record |

## Planned on the board — each waiting on its named gate

These are the board's seven Planned rows. Each names its gate; none is schedulable until
the gate opens. When one opens, it moves into the prioritized table above.

| Family | Item | Gate |
|--------|------|------|
| Analytics | AI-attention section in the weekly digest | None hard — first candidate to promote into the build queue when digest work next opens |
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
