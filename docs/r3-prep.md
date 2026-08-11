# R3 prep — the read door travels, the crawler ledger surfaces, contrast gets fixed

Build sessions start **here**, not by re-deriving. Written 2026-08-11 immediately
after R2 closed; recon is current against `origin/main` @ `58b075f` (v10.88.0).

Sequence and economics: [roadmap-release-sequence.md](roadmap-release-sequence.md).
Previous releases: [r1-prep.md](r1-prep.md) · [r2-prep.md](r2-prep.md).

---

## The headline finding: R3's gates are NOT all clear

The sequence doc marks two R3 rows as gated on R2, and R2 shipped — so both read
as unblocked. **Neither actually is.** Each cleared gate revealed a second one
underneath, and in both cases the second gate is the harder half.

| Row | Stated gate | Real state |
|---|---|---|
| Contrast — **fixes** | R2's contrast report | Report shipped (v10.82.0) — but it is the **arithmetic** tier. Fixes need the **rendered-pair** tier, which does not exist. |
| Give-back ratio | R2's AI-referral segment | Segment shipped (v10.85.0) — but the crawler side needs a durable local count, see below. |
| Rights-read count at render | none stated | The row's own prose carries one, and it is unmet. |
| Read door from web/phone | none stated | Worker + new trust boundary; the largest row on the board. |

**Do not open R3 expecting four unblocked rows.** Two need a precondition built
first, and one of those preconditions is shared.

---

## Session split

| Session | Rows | Why |
|---|---|---|
| **3A** | The durable crawler-count snapshot | **Shared precondition** for two rows. Build once, alone, first. |
| **3B** | Rights-read count at render + give-back ratio | Both consume 3A. Ship together; they share the read. |
| **3C** | Contrast: rendered-pair tier, then fixes | Two steps in one arc; the tier is the gate on the fixes. |
| **3D** | Reach the read door from the web and the phone | `[workers]`, own session, own threat-model section. |

Routing: **Opus** orchestrates and reviews; **Sonnet** implements from the briefs;
**Haiku** for mechanical sweeps. **Fable off.**

**Bump per PR** (the R1 lesson, re-earned in R2): a release that sits un-versioned
is invisible to the updater, and `SNT_VERSION` derives from the plugin header.
And **verify the merge before tagging** — chaining `gh pr merge` and `git tag`
into one command removes the only check between them, which is how a v10.88.0 tag
briefly pointed at v10.87.2's commit.

---

## 3A — The durable crawler-count snapshot (build this first)

**Where:** `inc/machine-readers-api.php` — `snt_mr_fetch( $days, $view )` at
line ~150.

**What exists:** an outbound `wp_remote_get` to the rights-signals worker, cached
in a **15-minute transient** (`set_transient`, line ~215).

**Why that blocks two rows:** the machine-readability row's own prose names the
gate — *"once that read can be served from state the site already holds, so a
reader's page never waits on a sensor call."* A display transient is not state
the site holds:

1. On a **cache miss**, a reader's page render blocks on an outbound HTTP call to
   a Cloudflare worker. That is precisely what the row forbids.
2. Transients live in the **object cache** under Breeze/Redis, so a cache flush
   evaporates them. This project already learned that once — the health scan
   moved from a transient to a durable option in v6.47.2 for exactly this reason
   ([[realtime-zero-vs-null]]'s neighbour: an absent read is not a zero read).

**The shape:** a cron-written durable option (`autoload=no`), mirroring
`SN_HEALTH_CACHE_KEY`. The render path reads the option and never fetches. A
never-written option is **absent**, not zero — the surface must say "not measured
yet" rather than publish a confident 0.

**Tests must pin:** the render path performs **no** `wp_remote_*` call under any
cache state (the strongest assertion here — stub the HTTP layer to fail loudly
and assert it was never reached); a never-written snapshot renders as unknown,
never as zero; a stale snapshot states its own age.

## 3B — Rights-read count at render + give-back ratio

Both consume 3A. Build the count first, the ratio second — the ratio is a
division whose numerator is the count.

**Give-back ratio** = a crawler operator's ledger crawl counts set against **that
operator's referred human visits**. The human side shipped in v10.85.0 as the
`ai` source category.

**THE TRAP, and it is the one R2B already flagged:** the crawler taxonomy and the
AI-referral host list are **two different vocabularies** and must not be joined
by name. `GPTBot` (a crawler user-agent) and `chatgpt.com` (a referrer host) are
the same *operator* and nothing in either list says so. The join needs an
explicit operator map — one place that says "these UA families and these referrer
hosts are the same company" — not a string match that happens to work today.

**Also:** a ratio with a zero denominator is not zero, it is undefined. An
operator that crawled 400 times and referred nobody has a *meaningful* answer
("never sent a reader back"); an operator with no crawl data has **no answer**.
Those must render differently.

**Tests must pin:** the operator map joins UA family to referrer host explicitly;
an operator with crawls and zero referrals reads as "none sent back"; an operator
with no crawl data reads as unknown, not 0; the render path makes no sensor call.

## 3C — Contrast: the rendered-pair tier, then the fixes

**Where:** `inc/health-contrast-tokens.php`. Its own docblock (lines 14–19) states
the gate plainly: it is the **arithmetic** tier, and *"the rendered-pair tier
needs computed styles from a real render (the headless harness), which this check
does not run."*

**So the fixes row is gated on a tier nobody has built.** Shipping fixes against
the arithmetic tier would mean changing colours for pairs that never co-occur —
churn on the palette to satisfy a table, with no reader affected.

**The harness exists** — the headless-Chrome rig used for the desktop-widget
geometry measurements ([[desktop-mode-widget-mount-contract]]). Reuse it; do not
build a second one.

**A live example of why the arithmetic tier is not enough**, from v10.88.0: the
provenance panel's hover/focus link colour measured **3.29:1** and failed AA. The
contrast report could not see it, because that is a **hardcoded CSS pair**, not a
theme-token pair. The report's coverage sentence says so. **The rendered tier
must therefore read computed styles, not token declarations** — or it inherits
the same blind spot one level up.

**Tests must pin:** a pair that is rendered and fails is reported; a token pair
that never co-occurs is *not* reported as a defect; the report distinguishes the
two tiers; hardcoded (non-token) pairs are within the rendered tier's reach.

## 3D — Reach the read door from the web and the phone `[workers]`

**Where:** `inc/mcp/mcp-read-guard.php` (the door's own kill switch,
`sn_mcp_read_enabled`), plus a new edge component.

**The row:** an authorized entry point at the edge that brokers the sign-in and
holds the secret, so the same allowlist, kill switch and audit trail hold from any
device. **Read only** — the write door stays attended, deliberately.

**This is the largest row on the board and it opens a new trust boundary.** Today
the read door's credential lives on one laptop; the row's whole point is that it
should not have to. That changes who can reach it from "whoever has the laptop" to
"whoever completes an OAuth flow", which is a different threat model, not a wider
one.

**Preconditions before any code:**
- Its own threat-model section, the way the agent surfaces got one. The existing
  model reasons about a hostile *paragraph*; this adds a hostile *caller*.
- Decide what the edge holds. A worker that stores the credential is a new secret
  at a new location — the thing the provenance worker's key handling is careful
  about ([[mcp-doors-ground-truth]]: the native server is the ONLY MCP endpoint;
  this row must not quietly create a second one).
- The kill switch must reach the edge. `sn_mcp_read_enabled` is a WordPress
  option; an edge broker that cannot see it is a door with no lock from the
  inside ([[mcp-kill-switch-runbook]]).

**Recommendation: do not schedule 3D until 3A–3C are done.** It is the only row
here that can go badly in a way the others cannot, and it is the only one whose
blast radius includes credentials.

---

## Carried forward

- **Third-party embeds** — decided as **(b)**, the facade pattern
  ([r1-prep.md](r1-prep.md)). Unscheduled. **Step one is a corpus count** of
  existing embeds, not a facade component.
- **`verify.yml`'s self-heal** did not fire on a 2026-08-11 failure and was
  deliberately not reflex-fixed: a rebuild-that-always-fires turns writer bugs
  into invisible hourly churn. If touched, it should **escalate on repeats**
  rather than re-heal. Ledger repo.
- **The sweep re-deriving its index from records** rather than keeping its own
  state — the real "two writers of one artifact" fix, parked with reasons.
- **Cache purge is blocked environment-wide** for every session (classifier).
  Not routed around; the owner purges from the dashboard or TTL heals.

## Two coordination rules earned on 2026-08-11

1. **Say the LAYER before fixing.** Two correct fixes for one symptom, shipped in
   parallel by two sessions, produced a worse bug than the one they fixed (the
   double provenance panel). The release arrangement priced out *version*
   collisions; it does not price out *symptom* collisions. A claim must name the
   layer, not the file.
2. **Verify the reader-facing surface, not the layer you touched.** "R2A verified
   end-to-end" was true of the ledger chain while the page was rendering double.
   Both sessions verified what they had just changed. Curl the page.
