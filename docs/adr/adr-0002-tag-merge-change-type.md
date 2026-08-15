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
