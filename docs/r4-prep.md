# R4 prep — the corpus looks at itself, and the paths wait for a surface

Build sessions start **here**, not by re-deriving. Written 2026-08-14 immediately
after R3 closed end to end (v11.1.2 graduated the last board row); recon is
current against `origin/main` @ `9519385`.

Sequence and economics: [roadmap-release-sequence.md](roadmap-release-sequence.md).
Previous releases: [r1-prep.md](r1-prep.md) · [r2-prep.md](r2-prep.md) · [r3-prep.md](r3-prep.md).

---

## The headline finding: R4's two ML rows are NOT one arc

The sequence doc pairs them — *"Corpus drift as an editorial mirror — one arc
with the next row"* / *"Reading paths from cluster geometry — one arc with the
previous row"* — and the v2 tier list agrees (item 17). Both are in ML
`considering`, and both consume the same kernel, so the pairing reads natural.

**They do not ship together, and the reason is not scope.** One is
plugin-only and writer-facing; the other is **reader-facing and therefore
needs the theme**, which is a separate repo on a separate release train.

| Row | Surface | Repos | Verdict |
|---|---|---|---|
| Corpus drift as an editorial mirror | admin, writer-only | plugin | ships alone, cleanly |
| Reading paths from cluster geometry | reader-facing chains | **plugin + theme** | own arc, both halves planned together |

**Owner decision 2026-08-14: SPLIT.** Drift ships now; paths becomes its own arc.
The reason is the R2A incident, not caution: v10.86.0 shipped the pages render
verb and the theme had no slot for it, so the first signed page enqueued
provenance CSS and rendered **nothing**. A mechanism whose surface ships later is
a mechanism that is invisible and untested in the only environment that counts.
Pairing them here would have re-run that exactly.

---

## 4A — Corpus drift as an editorial mirror (build this now)

**The row (ML `considering`, verbatim):** *"Corpus drift as an editorial mirror:
how the site's vocabulary and topic weights shift across the years, computed from
corpus statistics and shown to the writer — never to a model."*

### What exists

`inc/ml-kernel.php` is a **pure, zero-I/O, zero-WP** module: `snt_ml_tokenize()`,
`snt_ml_corpus_stats()`, `snt_ml_tfidf_vector()`, `snt_ml_cosine()`,
`snt_ml_bm25_score()`, `snt_ml_topic_clusters()`, `snt_ml_cluster_label()`, plus
the cadence pair. `inc/ml-pipelines.php` is the filterable slug → callable
registry; eight pipelines today, drift is **#9**.

### The gate the row's own prose hides

**The kernel is time-blind — and that is NOT the blocker.** `snt_ml_corpus_stats()`
takes documents and no dates, but the *glue* layer can bucket published notes by
post date and call the pure statistics once per bucket. The kernel stays pure,
the caller slices. **No historical snapshots are needed, and none should be
built** — the corpus already carries its own history in post dates, and a stored
time series would be a second source of truth that can drift from the first.

The real gap is one layer up:

> **`snt_ml_cosine()` returns a similarity, not a mirror.**

Comparing 2024's term weights to 2025's yields *one scalar*. That number tells a
writer their vocabulary changed by 0.31 and nothing about **what** changed —
which is the entire content of the row's promise. A mirror needs **per-term
deltas**: which terms rose, which fell, which are new, which went silent. No
kernel primitive produces that. **Build it, pure, in the kernel.**

### The second gate: a thin bucket must refuse to speak

Early years hold few notes. A term appearing in one note and then two has not
"risen" — the corpus is too small for the word to mean anything. This project
has already learned the shape twice:

- `snt_ml_cadence_deviation_robust()` reports **SPAN** precisely because *"a fixed-count
  window says nothing about how much time it observed, and callers must be able to
  refuse to trust a window that only saw a moment"* — see [[nominal-window-is-not-measured-window]].
- The rights-read count renders **unmeasured, never zero**, when never measured —
  [[realtime-zero-vs-null]].

Drift inherits both. A bucket below a floor must return **"too thin to speak"**,
a distinct answer from "no drift" — and the surface must render those two
differently. A confident 0.00 over three notes is the failure mode.

### Tests must pin

- The kernel stays pure: the drift function makes **no** WP call (the existing
  grep-pin over the file's text already enforces this file-wide — keep it green).
- A term present in both buckets with equal weight reports **no movement**, and a
  term absent from one reports as **new/silent**, never as a delta from zero —
  zero and absent are different answers.
- A bucket below the floor returns the **thin** verdict, and the thin verdict is
  **not** reachable by any term-level path (mutation-pin it: raise the floor above
  the fixture's size and the verdict must flip).
- Determinism: same corpus in, byte-identical ordering out — ties broken
  explicitly, never by hash order ([[determinism-tests-cant-catch-identical-garbage]]).
- **The never-to-a-model boundary is an ABSENCE and absences need pins:** drift
  registers **no ability** and appears in no AI-reachable surface. Assert the slug
  is absent from the abilities registry, so a future session that "helpfully"
  exposes it reds CI.

### Surface

Admin only, writer-facing. **Not** an ability, **not** public, **not** in the
remote set. The ML maturity page's scope map gains `drift => live` in the same
release as the feature (the maturity convention: badges flip with the feature,
never before).

---

## 4B — Reading paths from cluster geometry (own arc, NOT this session)

**The row (ML `considering`, verbatim):** *"Reading paths from cluster geometry:
static note-to-note chains that belong to the corpus, precomputed and identical
for every reader — sequencing, not personalization."*

### The gate: the stored partition has no geometry

`inc/ml-artifacts.php` stores, per cluster, `{members, label}` — **membership and
a name**. There are no centroids, no pairwise distances, no ordering. "Cluster
geometry" is exactly the part that is not persisted.

And it cannot be recovered at render time: the artifact layer's contract is that
**"read paths never compute"** — the whole reason the related index exists as
stored post meta. So sequencing requires the build stage to compute and store an
**ordering**, as an **additive** field (existing consumers read that artifact —
[[cross-repo-schema-skew]] and the ledger's additive-key rule both apply).

### The second gate: the theme owns the surface

Tier item 17 is tagged `[plugin; paths +theme]`. A chain a reader can walk
renders where the **theme** puts it. Plan both halves in one arc and ship the
theme slot with, or before, the plugin's render verb — not after.

### And a smaller one, already familiar

`snt_ml_topic_clusters()` **excludes singletons** by design. A note in no cluster
has **no path**, which must render as "no path" — not as an empty chain, and not
as a chain of one. Another [[realtime-zero-vs-null]] instance.

---

## Board state

Both rows sit in ML **`considering`**. The owner's "start R4" is the commitment,
so drift graduates on ship (ML `done` is at 2, far under
`SN_MATURITY_ROADMAP_MAX_DONE` — no retirement needed, unlike v11.1.2's AI swap).
Paths moves `considering` → `planned` with **its gates in the row prose**, which
is what separates planned from considering on this board.

Remember the standing check before calling any board work done: compare the
static floor's fingerprint against the door's `gates.fingerprint.observed`. And
note [[board-override-may-not-exist]] — there is currently **no override**, so
the release moves the public page by itself and a door write is a no-op.
