# ADR-0002: Tag reassignment belongs to `sn-apply` as a term-level `tag_merge`

- **Status:** Proposed
- **Date:** 2026-08-15
- **Applies to:** signal-and-noise (WP plugin, MCP write door)
- **Supersedes:** none
- **Related:** the MCP consolidation program (68 → 11 tools, telemetry-first)

## Context

The notes corpus carries **83 distinct tags across 42 notes, 47 of them used exactly once** — a
57% singleton rate, measured 2026-08-15. `Provenance` (15 posts) and `Music Provenance` (12) were
splitting the primary archive between two names for the same thing. The remediation plan is
`tag-merge-map.md`: 83 terms → 23, via 53 merges and 7 deletions.

**There is no MCP path to execute it.** `suggest-tags` is read-only. `prune-unused-tags` only
deletes terms that already have zero posts. The single place tags appear in a write payload is
`create_draft`, which only applies to posts that do not exist yet. The plan is currently a manual
WP-admin pass.

Two facts shaped the decision more than the missing capability itself:

1. **The singleton rate is structural, not a one-time mess.** Nothing constrains the tag
   vocabulary at authoring time, so every new note can mint new terms. A cleanup tool run today
   leaves the generating process untouched, and the singleton count starts climbing again
   immediately.

2. **The obvious API shape is the wrong one.** The intuitive design is per-post tag replacement,
   `{post_id, tags: [...]}`. Working the actual map by hand made its failure mode clear (below).

## Decision

### 1. No new tool. A new `change.type` on `sn-apply`.

`sn-apply` is already the single mutation door, with four gates — fingerprint, server-side
validation, mode capability, idempotency — reported on every call whether or not an earlier gate
failed. A tag change type inherits all four, plus `dry_run`-by-default, the revision/publish
capability split, and the existing audit log.

Adding a top-level tool would also cut directly against the consolidation program, which is
retiring tool surface on telemetry and admitting new surface only on evidence.

### 2. The operation is a **term-level merge**, not per-post tag replacement.

```jsonc
// merge one term into another
{ "type": "tag_merge",
  "fingerprint": "<term-state token, see §3>",
  "payload": { "from": "Music Provenance", "to": "Provenance" } }

// retire a term outright; posts keep their remaining tags
{ "type": "tag_merge",
  "fingerprint": "<term-state token>",
  "payload": { "from": "Spotify", "delete": true } }
```

`target` is `{ "scope": "taxonomy" }` — no `post_id`, because the operation is not scoped to a
post. This mirrors the existing precedent of `target.scope` for `provenance_anchors` and
`maturity_roadmap`.

Why term-level beats per-post:

- **Volume.** 53 merges + 7 deletes = **60 operations**, against 42 per-post writes each carrying
  a complete tag array.
- **Failure mode.** Per-post replacement fails *silently by omission*: leave a tag out of the
  array and it is gone, with no error, because an array is a complete statement of intent.
  A `{from, to}` pair cannot express an accidental deletion.
- **It is what WordPress already does.** Renaming a term onto an existing name is a native merge:
  post associations are preserved and duplicates collapse automatically. The tool wraps a
  supported operation rather than reimplementing one.
- **Idempotency is natural.** Re-running a completed merge is a no-op — `from` no longer exists.
  Retries are safe by construction, not just by the idempotency key.

### 3. The concurrency token is **term state**, not content hash

This is the part with no existing analogue. Every other `sn-apply` change type fingerprints
`post_content`. A term merge has no post to hash.

The token is the **term's own membership**: `sha256(term_id + ':' + sorted(post_ids).join(','))`,
read immediately before the write and passed as `change.fingerprint`.

If a peer session tags any post with `Music Provenance` between the read and the write, the
membership changes, the token changes, and the write **409s** instead of silently merging a set
the caller never saw. Without this, tags become the only unguarded write surface in an otherwise
fingerprint-gated system.

### 4. `rollback` must be a real per-post manifest

Every other change type in `sn-apply` is trivially invertible — `link_reshape` inverts by
reshaping back, `unlink` by relinking. **A merge does not invert.** Once two terms are one term,
they cannot be split again without the record of which posts came from which side.

So `tag_merge` must return, and persist, a manifest of the form
`{from, to, post_ids: [...], executed_at}`. `rollback: null` is acceptable for the change types
that can reconstruct themselves. It is not acceptable here. This is the single hard requirement
before the change type may run outside `dry_run`.

### 5. Close the vocabulary at authoring time — do this first

`sn-validate` already performs a tag-vocabulary-membership check. **Confirm its severity on the
`create_draft` path**; if it is a WARNING rather than an ERROR, raise it to ERROR against the
23-term vocabulary in `tag-merge-map.md`.

This is a smaller change than the merge engine and it addresses the cause rather than the symptom.
Sequenced the other way round, the merge tool gets run again in six months against a fresh crop of
singletons.

### 6. Telemetry — and the dimensionality gap that blocks it

The consolidation program is telemetry-first: surfaces are retired on measured evidence after a
baseline window. A change type that cannot be measured cannot participate, so this is a
precondition, not a follow-up.

**What `tag_merge` inherits for free.** Per FINDINGS.md (c), the rw door already carries the
kill switch (`SN_MCP_RW_DISABLED` + `sn_mcp_rw_enabled`), credential split, rate limiting, and
audit logging (`sn_mcp_rw_audit_record()`), all door-level and automatic for anything on the rw
allowlist. Layer B (v10.25.0) inserts one `{$prefix}sn_tool_call` row per `tools/call`, fail-open,
one INSERT on the hot path, retention by probabilistic prune, kill switch
`sn_mcp_telemetry_enabled`. **None of this should be reimplemented.**

**The gap — verified in source, not assumed.** `inc/mcp/mcp-telemetry.php:144` documents the
captured field as *"Sorted, comma-joined, truncated **top-level** argument keys. **Never a
value.**"*, and `:153` is a plain `array_keys( $args )`.

`change.type` is a **nested value**. It is therefore not captured, and **every `sn-apply` change
type today aggregates into one undifferentiated `sn-apply` count** — `link_reshape`, `unlink`,
`link_insert`, `og_card`, `create_draft` and the rest are indistinguishable in telemetry.

Shipping `tag_merge` into that makes the most destructive change type in the system the least
observable one. It also silently degrades the retirement model: `sn-apply`'s aggregate count can
never justify retiring or keeping any individual change type.

**Fix: add a bounded `change_type` dimension.** Populate it at the existing interception point,
`sn_mcp_call_tool()` — not the JSON-RPC router, for exactly the reason Layer B chose it: by the
router the `WP_Error` code and status are already flattened to a message string.

**This is a deliberate, bounded exception to the "never a value" privacy pin, and must be written
into the telemetry spec as an explicit carve-out rather than left implicit.** It is defensible
because `change.type` is a closed enum of 16 schema-fixed identifiers, carries no user content,
and has bounded cardinality. The carve-out should enumerate the permitted values, so it cannot
later drift into "log the payload".

**Outcome classification needs a `conflict` bucket.** The Layer B classifier is status-first:
4xx → `schema_error`, 429 → `refused`, 5xx → `server_error`. A term-state **409 is contention, not
malformed input** — and the 409 rate is the single most important signal for whether the token
granularity from §3 is right. Under the current classifier it is buried with genuine schema
errors and unreadable. Splitting 409 into its own `conflict` outcome fixes this here and
retroactively improves every existing fingerprint-gated change type.

**What to measure, and what each number decides:**

| Metric | Decides |
|---|---|
| `dry_run` : live ratio | A destructive op should be overwhelmingly dry runs. An inverted ratio means the gate is being skipped. |
| 409 `conflict` rate | Whether the term-state token (§3) is the right granularity, or too coarse and thrashing. |
| posts affected per call | Blast radius. A merge touching far more posts than the map predicted is the signal to stop. |
| merge : delete split | Whether `delete: true` is being used as a blunt instrument. |
| rollback manifest written | Must be 100%. Any `false` is a defect, not a statistic — see below. |
| idempotency replay rate | Whether retries are landing as intended no-ops. |

**The rollback manifest must not live in the audit log.** `inc/audit-log.php:24` states
*"Retention: 90 days, enforced by daily cron `sn_audit_log_prune`."* A manifest stored there is
pruned at 90 days, and the rollback capability required by §4 **evaporates with no signal at
all** — the merge stays irreversible while the record that could have reversed it is deleted on a
schedule. The manifest needs its own durable store, or an explicit exemption from the prune.
This interaction is the reason §4 is a hard requirement rather than a nice-to-have.

**Baseline-window hygiene.** Executing the 83 → 23 map is a one-time burst of ~60 operations. Run
it outside the consolidation program's baseline window, or tag it so it can be excluded — a
migration burst inside the baseline would badly distort the usage evidence the program retires
surfaces on.

**Schedule nothing.** The "schedule nothing" guardrail holds; the probabilistic prune model needs
no new cron, and `tag_merge` should add none.

## Consequences

**Good**

- The 60-operation map becomes scriptable, auditable, and reversible, with the same gate semantics
  as every other write.
- `prune-unused-tags` becomes genuinely useful afterwards: it only removes zero-post terms, so it
  can sweep the residue without risk.
- Tag hygiene stops being a manual admin ritual, which matters because it recurs.

**Costs and risks**

- A destructive, non-self-inverting operation enters the write door. Mitigated by the manifest
  (§4), by `dry_run` default, and by the mode capability split — routine credentials get
  `revision` only, and a taxonomy change cannot be meaningfully staged as a revision, so
  `tag_merge` should be **publish-only** and refuse `mode: "revision"` explicitly, in the same way
  `og_card` and `anchor_sweep` already do.
- The term-state token is a new fingerprint kind. It needs its own tests; reusing the
  content-hash path would silently accept a stale set.
- Taxonomy edits change archive membership and therefore the site's internal navigation surface.
  They change no rendered prose, so under the post-publish edit policy they are free edits needing
  no on-page correction, and they mint no provenance version.

## Alternatives rejected

- **A standalone `sn-tags` tool.** Rejected: new top-level surface against a program actively
  reducing it, and it would have to re-implement all four gates.
- **Per-post tag replacement.** Rejected: silent-omission failure mode, more writes, and it does
  not match the operation WordPress performs natively.
- **Extending `prune-unused-tags` to merge.** Rejected: its contract is "deletes terms that
  already have zero posts", which is safe precisely because it cannot touch a term with
  membership. Merging is the opposite of that guarantee and belongs behind the write door's gates.
- **Doing nothing and running the map by hand.** Reasonable for this pass — the term editor does
  60 renames in roughly twenty minutes and the map is already written. Rejected as the standing
  answer because the singleton rate regenerates.

## Open question

Whether `create_draft`'s vocabulary check is currently ERROR or WARNING is unverified. §5 is
written as "confirm, then raise if needed" rather than asserting the current behaviour.
