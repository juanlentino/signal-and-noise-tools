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
| *(empty — the board is code-canonical again)* | |

~~Fold the board override~~, ~~Operations Done-ceiling graduation~~ and ~~reset the
override~~ — all THREE closed on 2026-08-27; see the log entry below. The ceiling chore
was understated: three families were at the wall, not one.

## THE PLAN — ratified 2026-09-01, owner: "Your recs. All yours."

Two rulings recorded here so no session re-litigates them:

- **D1 — `anchor` stays LOCAL.** Its payload can name unpublished post titles
  (`post_status => 'any'`, `pending[]` carries `post_id` + `title`), and the
  byte-identical twin rule forbids narrowing. Not a remote twin; the ledger is
  separately public. Do NOT re-propose without a new design for the parity rule.
- **D2 — batch edit lands as a WP-ADMIN bulk action**, never an `sn-apply`
  change type. The "post_date never moves" MCP invariant stays whole; a
  human-gated admin path is a different risk object. The overdue-`future`
  coercion is still the trap to design against.

Execution order (rationale: the two contract bumps collapse into ONE worker
deploy by doing the parity renderer and cron redesign BEFORE the twins):

| Wave | What | Ships as |
|---|---|---|
| 0 | Housekeeping: stale row struck, superseded branches deleted | docs PR, no bump |
| 2 | MCP output parity + output-schema re-validation | v13.51.0 |
| 3a | `cron_scheduled`/`cron_history` sections redesigned ("two jobs overdue", never raw hook names) | rides the next release |
| 1+3b | Remote twins: `provenance_integrity`, `machine_readers`, + redesigned `cron_*` twins — ONE contract bump to v2, ONE worker deploy; verdict-map reasons rewritten for the post-Access threat model in the same change | v13.52.0 + worker |
| 4 | SSRF Step 2 (rebinding) -> breached-credential rejection -> measurement weave 0–3 | one release each |
| 5 | Batch edit per D2 | its own arc |

Watches stay watches: OpenStation tag > v1.1.5 (ritual + compat doc + the Notes
essay), wave-4 retirement read ~Sep 25, site pulls releases via wp-admin.

## Ready to build — prioritized

| P | Item | Repo | Size | Notes / first step |
|---|------|------|------|--------------------|
| ~~1~~ | ~~**MCP options-not-tools — Phase 0.2 + Phase 2**~~ **BOTH SHIPPED** | plugin | M | 0.2 shipped as v13.46.0 (`dimensions` column + `by_dimension` rollup); Phase 2 shipped as v13.47.0 (the `dismiss` change type). This row went stale while the work landed under the release train — struck 2026-09-01 |
| ~~2~~ | ~~**MCP options-not-tools — Phase 3**~~ **SHIPPED COMPLETE, v13.49.0** | plugin | M | `merge_tags`, `clear_template_overrides` and `schedule_cron_event` all shipped as `sn-apply` change types. `ai-orphan-apply` stays out as planned — irreversibility is the one thing the gates cannot fix. **The plan's cron half was wrong and was corrected in build:** it named an allowlisted `run_cron_event` PLUS a fire-and-forget `schedule_health_scan`, which cancel out — `SN_HEALTH_CRON_HOOK` is itself a daily scheduled hook, so allowlisting `run_cron_event` to scheduled hooks would admit the health scan and dispatch it synchronously. Shipped as ONE booking type; `run-cron-event` stays off every door because dispatch is the hazard and booking is not. Bound derived by inverting the existing `snt_cron_sn_owned_hooks()` predicate. Also fixed a pre-existing hole it exposed: `sn_health_scan_daily` was absent from that allow-list, so the rw-doored `unschedule-cron-event` could silently stop the daily health scan |
| 1 | **Remote door — two parity-safe twins** | plugin + worker | M | `provenance_integrity` + `machine_readers` as remote twins. Full scope, traps and the cross-repo contract bump in [HANDOFF-2026-09-01-remote-phase4.md](HANDOFF-2026-09-01-remote-phase4.md). `anchor` is BLOCKED on a design decision (its payload names unpublished post titles and the byte-identical twin rule forbids narrowing); `cron_*` deferred on output quality |
| ? | **Batch edit — including schedule dates** (owner: *"I think we'd add a batch edit in the plugin anyways"*, 2026-08-30) | plugin | M–L | **FIRST STEP IS A SURFACE DECISION, not code.** Today NOTHING can move a `post_date`: no ability sets one, and `sn-apply`'s posture is purely protective — dates are captured, passed through, re-asserted, and a violation triggers a restore whose effect is VERIFIED by re-reading the row. An overdue `future` post refuses up front (409 `snt_sn_apply_schedule_overdue`) because core would silently early-publish it on any write, restore included. **If this lands as an MCP change type**, the guard stops being "post_date never moves" and becomes "never moves except for this type" — a strictly weaker invariant, and the one that currently guarantees an edit cannot publish a scheduled post early. **If it lands as a wp-admin batch action**, the invariant is untouched: it protects MCP writes, and a human-gated admin path is a different risk object. The admin route looks right, but it is the owner's call. Either way the overdue-`future` coercion is the trap to design against — a batch spanning that boundary publishes early |
| ~~?~~ | ~~**SSRF guard — validate the whole rrset**~~ **STEP 1 SHIPPED, v13.50.1** | plugin | S | `sn_ssrf_resolve_host_all()` + `sn_ssrf_ip_blocked()`; blocks if ANY address in the rrset is internal. Pinned by `tests/ssrf-guard-rrset.php`, negative-controlled twice (singular seam stubbed to the first record; mutation to `array_slice($ips,0,1)`). **The plan's fallback design was wrong and was corrected in build:** falling back to the singular seam only when the plural lookup returns nothing bypasses any stub whose host really resolves — `provenance-genesis.php` stubs `raw.githubusercontent.com` to `10.0.0.9`, `gethostbynamel()` resolves it for real, so the suite went red and silently hit the network. Shipped as a UNION of both lookups. **STEP 2 (DNS rebinding) REMAINS**: connect-time pinning via `http_api_curl`/`CURLOPT_RESOLVE`, hook reachability UNVERIFIED, currently documented as accepted residual risk |
| ✅ COMPLETE v13.55.0 → v13.62.0 (+ worker v1.3.0) | **Measurement weave — all phases shipped; crossexam twin is an owner call** | plugin | M |
| ✅ v13.63.0 | **Search coverage (URL Inspection, weekly)** | plugin | S | The discriminator the zero-impression finding needed: per post, Google's index verdict. Next is editorial, not tooling: not-indexed → crawl/quality/internal links; indexed-but-zero → topic demand (the market-side question the weave declined to buy data for). | Shared path normalizer (three live spellings today, diverging on bare/empty inputs — a join across them drops rows silently), then GSC onto the read door as sections, into `snt_insights_collect_signals()`, and the TF-IDF↔GSC disagreement scan. All over data already synced daily and readable by no agent. Phase 4 (remote twins) and Phase 5 (enum drift check, independent) follow. Plan: [proposals/measurement-weave-2026-08-31.md](proposals/measurement-weave-2026-08-31.md) |
| ✅ COMPLETE v13.54.0 → v13.60.0 | **Breached-credential rejection** | plugin | M | The one auth surface neither the edge guard nor `two-factor`/WebAuthn can see — `grep` finds no password policy anywhere in `inc/`. HIBP k-anonymity, live API only (the offline corpus is ~84.2 GB across 1,048,576 files, measured). Mode A set-time fail-CLOSED, Mode B login-time advisory fail-OPEN memoized against the stored hash. Plan: [proposals/breached-credential-check-2026-08-31.md](proposals/breached-credential-check-2026-08-31.md) |
| ? | **MCP output parity + output-schema re-validation** | plugin | S | **Unblocks the `cron_*` remote twins deferred on output quality.** A shared column-declaring table renderer so a tool's text block cannot drift from its `structuredContent`, plus re-running the output schema inside the tool wrapper — the MCP SDK converts a mismatch into a client-visible `-32602` it never rethrows, so schema drift ships as a silent success in our telemetry. Both from the OpenSEO read; see [proposals/extraction-survey-2026-08-31.md](proposals/extraction-survey-2026-08-31.md) |
| ? | **SSRF Step 2 — connect-time pinning (DNS rebinding)** | plugin | M | **Hook question SETTLED 2026-08-31, and the obvious reading is wrong twice.** `http_api_curl` IS live — not via `WP_Http_Curl` (deprecated 6.4.0, off the request path; it is what the docs name) but via `WP_HTTP_Requests_Hooks::dispatch()`, which bridges Requests' `curl.before_send` with the handle by reference. **The blocker is transport, not the hook:** the bridge fires only when Requests picks cURL; without the extension it falls back to fsockopen, the hook never fires, and the request goes out unpinned with nothing saying so — a silent fail-open. **First step is that decision (refuse vs proceed-and-record), not code.** Changes every outbound request in the plugin, so it wants its own review round. Detail: [proposals/ssrf-guard-multi-address-2026-08-31.md](proposals/ssrf-guard-multi-address-2026-08-31.md) |

## Blocked on the owner (not buildable by me)

| Item | What is needed | Payoff when done |
|------|----------------|------------------|
| ~~Tag descriptions~~ **DONE 2026-08-28: all 23 live-verified** | Nothing to do. Drafted in the house register, owner approved all 23 as-is, seeded by v13.23.0 (never-clobber, own flag), verified on the public archives (both surfaces). The arc grew a full loop the same day: v13.24.0 `tag_hygiene` watches for new undescribed/unused tags (negative-controlled red→clean on a planted tag), v13.25.0 `describe-tags` + `apply-tag-description` on the rw door draft and write the sentence for any future tag, few-shot from the approved 23 | Both surfaces lit on every tag; new tags are a one-approval workflow from a session |
| ~~Thin-tag decision~~ **RESOLVED 2026-08-27: keep all 23** | Nothing to do — see the log entry below | The vocabulary stays as the 83→23 pass left it |

## Planned on the board — each waiting on its named gate

These are the board's seven Planned rows. Each names its gate; none is schedulable until
the gate opens. When one opens, it moves into the prioritized table above.

| Family | Item | Gate |
|--------|------|------|
| Proof of origin | Extend signing/anchoring to pages, then media | Sequenced after current notes-chain stability; owner call on timing |
| AI | Move the operative AI channel to the desktop platform's native agents | The native runner proving stable enough to trust with the same fences (agents arc currently DISABLED) |
| AI | Retire legacy single-purpose tools the consolidated set absorbed | Usage evidence, not a date |
| Machine learning | Extend the deterministic layer, pipeline by pipeline | A real editorial question demanding it |
| Machine readability | Usage-preference header + robots rule, with rights-dialect parity sweep | The internet standards body finalizing the spec |
| Operations | ~~Dependency provenance gate for worker deploys~~ **DONE 2026-08-28 — both legs shipped in all five workers** | The attestation leg shipped earlier (CI-wired everywhere, verified 2026-08-27). The cooldown leg landed 2026-08-28 as `scripts/dependency-cooldown.mjs` + `.cooldown-accept.json` (min_age_days 7, per-version reviewed accepts, fail-closed on unmeasured ages), byte-identical across all five workers, a CI STEP beside the attestation gate. Negative-control proof in sn-remote-mcp-worker v1.1.0 (RED at 11d against the real registry; per-version accept excused exactly one). Releases: remote-mcp v1.1.0, analytics v1.21.0, login-guard v1.12.0, provenance v1.13.0, rights-signals v1.21.0 |

## Watches (not releases — time passes, then a number is read)

| Watch | Reads |
|-------|-------|
| signed_agent — **ARC CLOSED 2026-08-30. A2 shipped; its rationale did not survive the split** | Verified is **314 / 13,478**, and `identity.by_agent` (plugin v13.45.0, read live) says it is **TWO agents: `seo-ahrefs` 243 and `dev-headless-chrome` 71**. **Zero AI vendors.** In the same window `ai_training` was 3,178 reads and `unsigned` was 13,164 of 13,478 measured (97.7%) — **the AI-training crawlers sign nothing at all**. So the population proving identity is not the population the licence is for. `invalid` 0 / `unknown_key` 0: everyone who signs, signs correctly — the mechanism works, the audience is wrong. The bridge confound is **dead**: `by_surface` shows `agent-discovery` = **1**, so the 53→311 growth was Ahrefs, not `/webmcp/bridge.js`. **A2 (worker v1.24.0) stays** — headers only, fail-open, zero cost, terms waiting the day an AI agent signs. But do NOT cite "verified identity is growing" as evidence it works without naming who: Ahrefs will keep inflating that share. **The only number that would make A2 load-bearing is a verified hit from an agent whose `purpose` is `train`. Today: zero.** That is the watch, not the aggregate |
| IPv6 criterion | Poll `sn-status{ipv6_criterion}`; act ONLY on `decision = build_ranges` |
| GSC drift section | ~Sep 3: flips from "accruing" to the good zero or its first rows — last unverified surface of the v13.11.0 work |
| ⌘K invoke — **RETEST RUN 2026-08-28, STILL BROKEN, filed as WordPress/openstation#705** | The owner-driven console session measured the full matrix on v1.1.4: native commands fire; every publicly-registered command (our 19 at boot AND a fresh witnessed console probe) lists but silently no-ops; store intact throughout, callbacks fire by hand; no windows open, so the earlier bridge-race reading is superseded — the palette reads the public store for LISTING only and resolves INVOKE elsewhere. Framed upstream without a regression claim (same signature on 1.1.3). Watch #705; mitigation avenue if upstream stalls is recorded in memory (migrate the 19 palette commands onto the registry the dock commands already use — the invoke table that works) |
| Wave-4 telemetry re-read (~Sep 25) | `sn-site-facts{tool_telemetry}`: did the absorbed singles' traffic collapse toward `sn-status`/`sn-metrics`? On 2026-08-28 the pair had 22 + 1 calls vs. real traffic on the singles — window not ripe, and it will not ripen passively: the dominant caller is agent sessions, which now deliberately default to the consolidated pair (memory: `default-to-consolidated-reads`). Retire (major, wave-2 precedent) only on a collapsed read |
| Second timestamp anchor (parked 2026-08-28) | Two triggers, either fires → this becomes a build row: an observed window where pending OTS proofs cannot upgrade, or a credible non-Bitcoin public anchoring authority. Until then: single-anchor Bitcoin OTS is the accepted posture |

## Deliberately not queued

- **Considering / Later columns** — live on the board; they are ideas, not work.
- **The denied perimeter** — newsletter/email, reader-facing release notes, ActivityPub,
  C2PA, cookieless retention, URL shortener, Rocket Loader, dashboard-widget sprawl,
  admin-bar nodes, brutalist wp-admin. Sources in the brainstorm doc.

## Log

- 2026-08-28 (board graduations, v13.28.0) — the PUBLIC BOARD caught up with the day,
  found by the owner ("it's not dry") after I called the queue empty: the board is part
  of the ledger and seven releases had not moved it. AI: "Spend with an address"
  graduated considering → done with an HONEST REWRITE — its "never estimated" clause
  described the Health spend watch, not the shipped per-feature ledger, and the done
  sentence now says which is which; "Staged body edits" retired to make ceiling room
  (its claim already stands verbatim-in-substance as the AI page's first principle —
  the cheapest graduation on record, no page addition needed). Operations:
  dependency-provenance planned → done rewritten as facts; "morning brief" retired,
  its uncovered half (no AI, no content prose on the mail path) landing as the ops
  page's TENTH principle; the emptied planned cell REFILLED by owner choice — the
  calendar of quiet failures promoted with its gate named inline. The board's own
  tripwires (no-empty-cell, fold count, prose pins) all fired and were answered, and
  the retired rows are pinned in NO column. Also fixed here: the ⌘K watch row above,
  stale since v1.1.4 shipped carrying #683.

- 2026-08-28 (later) — the dependency-provenance row CLOSED: the cooldown leg shipped to
  all five workers in one pass (the gate script was already byte-identical across repos,
  so one reference implementation + four copies). Proven able to fail before being
  trusted: policy raised past the youngest real package went RED; a per-version accept
  excused exactly that version while its siblings stayed blocked. Each worker's own CI
  ran the new gate green before merge.


- 2026-08-28 — the Operations dependency-provenance row is **half built**, found while
  answering an unrelated question about the remote MCP worker's maturity. The attestation
  leg ships and is CI-wired in all five workers; the minimum-age cooldown leg exists
  nowhere. Half-done is the state most likely to be mis-scoped when picked up — the row's
  stated gate ("a one-time audit showing enough of the tree publishes attestations") has
  ALREADY been satisfied, so a reader would reasonably conclude the whole row is ready to
  build. Row rewritten to say which half remains.

- 2026-08-27 — the AI-attention digest row GRADUATED to done as v13.20.0, and it was a
  **phantom queue item**: the section was already built. `snt_narration_collect_signals()`
  assembles the `ai_attention` block, the system instruction has a paragraph for it, and
  Test 8b quotes this row's own contract back at itself. Nothing was built; the board was
  moved to match the code. This is the third time this estate has been bitten by a shipped
  feature whose row never graduated — **grep the implementation before building any queued
  row**, not just before proposing one. Owner settled the one genuine ambiguity: the row's
  clauses are about CONTENT, so an on-demand digest satisfies "weekly digest" (the digest's
  dashboard surface and weekly cron were retired in v9.4.1 and stay retired).

- 2026-08-27 — the Analytics gate opened again as v13.19.0. The AI-referral row
  GRADUATED onto `/maturity/analytics/` as its thirteenth honesty principle, restoring the
  `MAX_DONE - 2` headroom v13.18.0 spent. Two things the measurement changed. It is a
  **graduation, not a retirement**: unlike the two earlier Analytics exits, that page said
  nothing about what is counted, and `/stats/` publishes only Reading rhythm and Most read,
  so the row is not self-evidencing — a bare removal would have deleted the claim from the
  public record. And a mid-prep finding of mine was **wrong**: I reported the analytics
  principles as unpinned, having grepped `tests/` for the function name; the pins count
  rendered `<li>` elements instead and were there all along. The feature is untouched —
  `inc/insights-narration.php` still assembles the `ai_referrals` signal.

- 2026-08-27 — the override is RESET and the board is code-canonical again, closing the
  arc. Order was load-bearing and held: install v13.18.0 first (deploy read `13.18.0`
  live), observe, then reset. Two things worth keeping. **The merge report changed shape
  after the install** — `override_held` fell 19 -> 16 and three CONFLICTS appeared
  (Machine readability/done, Accessibility/done, Operations/done), which is exactly the
  three cells where code had dropped a graduated row while the override still held it:
  the graduations' own signature, benign for a reset. **And the verification was
  independent of the door**: the post-reset fingerprint came back
  `c0f00721f6136c424a48b1c3f66edc65`, the value computed from the floor locally BEFORE
  the write, and a fetch of the public page matched the local static board **28 / 28
  cells**, 80 rows, with all four graduated rows absent. A door reporting its own success
  is not evidence a reader sees it.

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
