# Roadmap release sequence

The batching plan for the forward-looking rows on the hub-wide maturity board
(`[sn_maturity_roadmap]`, `inc/maturity-roadmap-shortcode.php`). The board says
*what* and *how sure*; this file says *in what order and with what*.

Derived from the live board at fingerprint `7bbb2e473be826f69d7f1d76088468c9`
(2026-08-10). Re-derive rather than trust this file if the board has moved since.

This file **refines** the v2 surface-tagged tier ordering; it does not replace it.
Where the tier list already binds rows into a single arc, that binding wins — see
*Reconciliation* at the bottom.

---

## Scope

The board carries 47 forward-looking rows: **16 planned, 18 considering, 13 later**.

**The `later` column is deliberately not sequenced.** Giving every `later` row a
release slot collapses the distinction the board makes — it turns `later` into a
slower `planned`. Those 13 rows stay unscheduled until the re-triage below.

That leaves 34. Two cannot be scheduled at all, because they fire on someone
else's calendar:

| Row | Family | Fires when |
|---|---|---|
| Speak the coming standard | Machine readability | the IETF finalizes the usage-preference header and robots rule (spot-verify status before acting) |
| Move the operative AI channel to native agents | AI | the upstream runner is stable — agents are currently disabled by owner decision |

A third event-triggered row, *Homework shown*, sits in `later` and is covered by
the same rule.

**Net: 29 rows sequenced across six releases, 3 held in a gated pool.**

---

## Baseline finding — the AI retirement is NOT ready

*Retire the legacy single-purpose tools the consolidated set absorbed* is worded
"on usage evidence rather than on a date". Checked 2026-08-10 against
`scan_telemetry` (30-day window):

- `table_present: true` — the telemetry table is healthy, so this is a real
  measurement, not a failed read.
- **9 total runs**, across only **3 scan types**: `emdash` (7), `block_migrations`
  (1), `duplicate_body` (1).
- `sn_scan` supports 8 scan types. **5 of them produced no row at all.**

A missing row is *absent*, not *measured zero* — the rollup only emits rows for
types that actually ran. Retiring a tool because it has no row would be reading
never-measured as unused. Two further gaps: `sn_apply` usage lives in the
rw-audit `change_type` column, not here, so the evidence base is split; and proxy
`-32602` refusals are invisible to every layer, so a tool can be *attempted* and
leave no trace anywhere.

**Disposition:** the retirement stays in the pool until the window is thick
enough to distinguish "unused" from "unobserved" across all 8 scan types. It is
not an R1 item. This is Tier 1 item 1, and it remains correctly *passive*.

---

## R0 — prerequisites that are not board rows

1. **`done`-column graduation + the item cap.** `SN_MATURITY_ROADMAP_MAX_ITEMS`
   is 12, enforced per column, but the roadmap write is *wholesale* — the first
   family whose `done` reaches 13 fails gate 2 and blocks **every** board edit,
   including the one that would fix it. The same validator guards the read path:
   an over-cap override returns `null` from
   `sn_maturity_roadmap_override_board()` and the public page silently falls back
   to the static board. Note the v10.63.0 redesign already folds
   planned/considering — `done` cells are the ones left open, which is why `done`
   is both the fastest-growing column and the one with no fold to hide behind.
2. **Resync the stale static DR floor.** `origin/main` carries Operations
   `done=2 / planned=1`; live carries `done=3 / planned=1`. The disaster-recovery
   fallback recovers to a board that is wrong. Sync rides the next real release.

A third prerequisite has no board row and gates R6: the **one-time attestation-
coverage audit** that *Dependency provenance gate* declares it lands after.

---

## The sequence

`[w]` marks a worker deploy, batched per the deploy split (only `sn-analytics`
auto-deploys from main; the other three ship manually).

### R1 — the alt-text arc
| Family | Row |
|---|---|
| Accessibility | Alt-text coverage for inline SVG artwork — *one arc with the next row* |
| Accessibility | Alt-text quality, not just coverage — *one arc with the previous row* |
| Accessibility | An accessible treatment for third-party embeds — *design decision first* |
| Proof of origin | Key history with a future — *unblocks R2* |
| Machine learning | Draft-time echoes |

### R2
| Family | Row |
|---|---|
| Proof of origin | Extend signing and anchoring beyond notes — *gated on R1* |
| Accessibility | Contrast audited at the token level — **report only** |
| Analytics | AI-attention section in the weekly digest — *thread: Machine readability* |
| Analytics | AI-referred humans as a channel — *the segment R3's ratio depends on* |
| Machine learning | Extend the deterministic layer, pipeline by pipeline |

### R3 `[w]`
| Family | Row |
|---|---|
| AI | Reach the read door from the web and the phone |
| Machine readability | The rights-read count published at render — *thread: Analytics* |
| Analytics | Give-back ratio per crawler — *gated on R2's segment* |
| Accessibility | Contrast — **fixes** — *gated on R2's report* |

### R4
| Family | Row |
|---|---|
| Accessibility | Charts that speak — *thread: Analytics; retrofit on the live stats page* |
| Analytics | Traffic rhythm flags |
| Machine learning | Corpus drift as an editorial mirror — *one arc with the next row* |
| Machine learning | Reading paths from cluster geometry — *one arc with the previous row* |

### R5 — the verification quartet
Sequenced after R2's signing extension, per the tier list. The in-page surface
needs **its own threat-model section** before it ships: anonymous callers are a
new trust boundary.

| Family | Row |
|---|---|
| Proof of origin | A standalone verifier anyone can run |
| Proof of origin | Provenance for the software itself — *thread: Operations* |
| Machine readability | Provenance pointers in the machine surfaces — *thread: Proof of origin* |
| Machine readability | An in-page tool surface for verification — *thread: Proof of origin* |
| Operations | Configuration drift |

### R6
| Family | Row |
|---|---|
| Analytics | Search-side metrics from Search Console — *one GSC arc with the next row* |
| Machine readability | Google's crawl and robots reports vs the ledger — *one GSC arc; reads worker ledger data* |
| Operations | Dependency provenance gate — *gated on the attestation audit* |
| Operations | A morning brief — *R2's prose rule applies* |
| Operations | Restore proof, not backup existence — *scope against where backups actually run first* |
| Machine readability | The corpus schema published as a machine surface |
| AI | Scheduled read-only agent runs |

R6 is the heaviest release and the most gate-dependent; expect it to split.

### Pool — sequenced only once their gate clears
| Family | Row | Gate |
|---|---|---|
| AI | Retire the legacy single-purpose tools | telemetry baseline (see above) |
| AI | Richer edit primitives beyond sentence scale | held for capacity |
| Proof of origin | A second, independent anchor | held for capacity — small, **may jump the queue into any `[w]` release when worker time opens** |

---

## The three forces behind the ordering

1. **Explicit gates already written into the board copy.** "Key history — landing
   before signing extends beyond notes" is a hard dependency (R1 → R2).
   "Contrast — landing report-first, findings published before any fix ships" is
   one row occupying two releases (R2 → R3). "Dependency provenance gate —
   landing after a one-time audit" needs a spike that is not on the board.
2. **Cross-family threads and owner-bound arcs ship together.** Every "a thread
   shared with X" row is half a feature; splitting one ships an argument with a
   missing half.
3. **Worker rows batch per deploy.** R3 is the worker-heavy release.

---

## Reconciliation with the v2 tier list

These groupings come from the tier list, not from this sequence, and they
override any convenience batching:

- **Alt-text SVG + alt-text quality = ONE arc** (tier items 5+6) → both in R1.
- **GSC pair = ONE arc** (tier item 11; one client, three consumers) → both in R6,
  even though the cross-exam leg reads worker data.
- **Corpus drift + reading paths = ONE arc** (tier item 17) → both in R4.
- **Verification quartet = ONE arc after signing reaches pages/media** → R5,
  after R2.
- **Second anchor may jump the queue** when worker time opens.
- **Reserved headroom per surface is an owner rule** — every release above should
  leave slots for off-board `[theme]` presentation debt, `[plugin]` hardening and
  telemetry follow-ups, and `[workers]` observability work. Slot new items by
  dependency direction, never append-at-bottom.

---

## Execution economics — measured, not estimated

Everything below comes from a full sweep of local transcripts since the Tue 13:00
reset, cross-referenced against the usage panel on 2026-08-10 (Max 20x). Earlier
estimates in this project were wrong by ~15x. **Re-measure; never re-estimate.**

Effective units below weight cache-reads 0.1x, cache-writes 2x, output 5x. The
unit is arbitrary — but it *cancels*, because the budgets were derived in the same
unit they are spent in.

### The budgets

| Bucket | Weekly | Notes |
|---|---|---|
| All models | **≈1.70B effective** | 1.309B measured = 77% |
| Fable | **≈420M effective** | 319.5M measured = 76% — **4.0x smaller** |

Reset: **Tuesday 13:00.** One full working session (a release plus planning, 228
turns) measured **8.2M effective = 0.48% of the all-models week**. The whole of
R1–R6 is **12–16% of ONE week**. The program was never the constraint; the ~77%
baseline of everything else is.

### Lever 1 — model routing (dwarfs everything else)

The panel's bar reads "all models", so **Fable draws on BOTH buckets — it costs
twice.** Opus, Sonnet and Haiku draw once, and only Fable carries a sub-cap.

| Same program | Cost |
|---|---|
| Opus orchestrating, Sonnet/Haiku implementing | **~16% of one week** |
| Fable orchestrating | **~65% of the Fable bucket** |

**Routing for program work:**

- **Opus** — orchestration, planning, review, the release ritual. Main thread only.
- **Sonnet** — implementation of a row that the prep doc has already specified.
- **Haiku** — mechanical sweeps: coverage scans, grep passes, fixture counts.
- **Fable** — **off**, unless a task specifically needs it.

The effective unit here is model-*blind*; real accounting is near-certainly weighted
by model price, which would make Sonnet/Haiku delegation cheaper still. Treat 16%
as a ceiling.

### Lever 2 — session length (measured 45% swing)

Per-turn cost scales with context already loaded. Same session, same turns per quarter:

| | avg context | eff/turn | quarter total |
|---|---|---|---|
| First quarter (67 turns) | 133k | 28.7k | 1.93M |
| Last quarter (68 turns) | 301k | 41.5k | 2.82M |

**Identical work costs ~45% more at turn 200 than at turn 30.** Cap a session at
**~80–100 turns or ~150k context**, then hand off. Splitting a release across two
sessions is *cheaper* than one long one, not more expensive.

At 312k context a main-thread turn costs ~31k effective in cache reads alone; a
fresh subagent turn at ~80k costs ~8k — **~4x cheaper for identical work**, and the
gap widens as a session runs.

### The release loop

1. **Open with the prep doc** written at the end of the previous session. No
   re-derivation — that is what the re-orientation tax buys out.
2. **Opus reads only what the prep doc names**, in targeted line ranges.
3. **Delegate implementation to a Sonnet subagent** with the TDD brief. The file
   reads, failed runs and dead ends stay out of the main thread.
4. **Opus reviews the diff**, then one Bash call for the full suite printing only
   `FAIL` lines.
5. CHANGELOG, version, PR.
6. **CI poll in ONE long call** — the two-settled-reads loop, empty-string sentinel.
7. **Write the next prep doc while context is warm** — nearly free here, expensive
   anywhere else.
8. **End the session at ~80–100 turns**, finished or not.

### Guardrails

- Per session: **≤10M effective** (~0.6% of the week).
- Per week: **~12 sessions ≈ 7%** on top of the normal baseline.
- **Hard stop:** if the weekly all-models meter passes **85%**, program work pauses.
  Roadmap work must never be what blocks client work.
- **Trim active connectors** for program sessions. Instruction overhead — the system
  prompt and tool list re-read every turn — was **42% of the measured session**.
  This is what makes MCP consolidation a token lever rather than housekeeping.

### Cadence

**Two weeks, ~12 sessions each**, starting after the Tuesday 13:00 reset:
**+7.4% per week.** Three weeks (+4.9%) if headroom for the unplanned matters more
than finishing sooner.

### Deliberately demoted

The `sn_apply` wholesale-board round-trip was engineered around at length and is
**0.024% of the weekly budget**. Do the compact-diff and patch-shape work for the
**correctness** win — the patch shape removes the wholesale-delete risk — and never
on token grounds. It is not R1.

### Calibration to keep running

claude.ai chat counts against the same buckets and is invisible to local transcripts,
so both budgets above are **floors**. This does not break the plan: the 77% baseline
is measured whole and already contains chat. It only breaks attribution.

At a reset the meter reads 0%. Run one Claude Code session, measure its tokens, read
the percentage — that pins the Code→% conversion exactly. From then on, any gap
between prediction and meter *is* chat, quantified.

Chat has the same quadratic: a long thread re-sends its whole history every message
and re-reads attachments every turn. New thread per topic; Project knowledge instead
of re-pasted documents.

## Standing rule — re-triage `later` after R6

Once the sequence above is complete, the next planning move is **not** to extend
this file. It is to re-read the `later` column as a whole and promote from it:
`later` → `considering` → `planned`, each promotion carrying its gate, per the
board's existing promotion flow.

Sequencing `later` rows before that re-triage defeats the column's purpose. The
re-triage is the trigger for the *next* sequence document, not an appendix to
this one.
