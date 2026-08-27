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
| 1 | Print stylesheet | theme | S | One `@media print` fragment exists in `inc/block-styles.php`; extend to a full typeset page — provenance footer, URLs shown after links, no nav chrome |
| 2 | Reply-by-email on notes | theme | S | Reuse the `inc/contact-email.php` DOM-assembled mailto (no scrapeable address); subject prefilled with the note title |
| 3 | Hover previews for internal note links | theme | S–M | Reuse the `assets/js/footnotes-popover.js` pattern; progressive enhancement, honors reduced-motion |
| 4 | Stub-parity sweep | both (CI) | M | Diff test-stub function signatures against the pinned WP source; the stub-drift trap is 13× bitten — turns the ambush into a red CI line |
| 5 | Next-PHP lane in CI | both (CI) | S | Repos pin PHP 8.3 only. One matrix lane on the next PHP RC; `continue-on-error` at STEP level, `timeout-minutes` set. Public repos — free minutes; the argument is runner-hold, not money |
| 6 | Editor smoke vs WordPress nightly | plugin (CI) | M | Pre-publish gate + draft echoes ride `@wordpress` packages; a scheduled job makes a core release break a cron, not a writing session |
| 7 | Topic hubs for the 23-tag vocabulary | theme | M | No taxonomy template exists. HARD PRECONDITION: one written sentence per tag (owner writing task), or the pages trip the contentless-page SEO trap on record |

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

- 2026-08-27 — file created from the roadmap brainstorm; rewritten same day as the full
  release-planning queue (priorities, gates, chores, watches) at owner direction.
