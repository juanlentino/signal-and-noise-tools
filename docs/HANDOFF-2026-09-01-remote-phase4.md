# Handoff — Remote door, Phase 4 remainder (for Tuesday 2026-09-01)

Written 2026-08-31 ~03:30 UTC, at the end of the v13.48–13.50 session. Everything
here is DECIDED except where it says otherwise; the work is scoped and the traps
are named. Nothing is half-built — the tree is clean at v13.50.0.

## What already shipped (do not redo)

- **Task 4.1** — `sn_mcp_remote_verdicts()`, all 32 local sections carrying an
  explicit remote decision, `tests/mcp-remote-verdicts.php`, four negative
  controls. v13.50.0.
- **Task 4.A** — Precondition A CLEARED. The read ceiling fails closed on the
  remote path, local fail-open intact. v13.50.0.

## The owner ruling that reframed this (2026-08-31)

The remote door is **behind Cloudflare Access**, and `access.mjs` verifies the
JWT against the team JWKS (validates `aud`/`iss`, fails closed on every path) —
it does not trust the header's presence. Plus: the bridge grants
`sn_read_remote_analytics` via a `user_has_cap` filter added at
`mcp-bridge-route.php:357` and removed in a `finally` at 361, so the capability
exists only during a verified request, and a direct origin call has it not at all.

**Consequence:** the earlier exposure objections to the candidate sections were
priced against a stranger holding a leaked token. The realistic reader is the
owner, authenticated as the owner. Those objections mostly dissolve. The verdict
map's `reason` strings for the deferred sections still argue the OLD model and
should be rewritten when each is next touched.

## READY TO BUILD — two twins, parity-safe

`provenance_integrity` and `machine_readers`. Both verified safe for the
byte-identical twin rule:

- `provenance_integrity` — the sweep is `post_status => 'publish'`
  (`inc/provenance-integrity.php:429`), so `failing[]` can only name public titles.
- `machine_readers` — aggregate only: family/surface/purpose counts. No UA
  samples, no per-post fields.

**Scope:** register two twins in `inc/abilities-remote-set.php` (copy the
`remote-uptime-status` shape; `output_schema` copied BYTE-IDENTICALLY from the
admin registration — `tests/abilities-remote-set.php:151` enforces `===`), add
both slugs to `sn_mcp_remote_slugs()`, flip their verdicts to `true` with the
twin named, extend the write-slug refusal pin.

**This is CROSS-REPO.** The contract hash covers the slug-keyed map of twin
`output_schema`s, so adding twins changes it. Bump all three together:
1. worker `CONTRACT_VERSION` in `sn-remote-mcp-worker/src/mcp.mjs` ("1" -> "2")
2. plugin `SN_REMOTE_CONTRACT_VERSION` ('1' -> '2')
3. a NEW DISTINCT sha256 in `SN_REMOTE_CONTRACT_VERSION_HASHES`

Run the failing test to get the computed hash — RED-then-pin is the intended
workflow, not an error. A version bump without a shape change is refused by the
test. The worker ships via `npm run deploy`; **git is not the deploy path**.

## BLOCKED ON A DECISION — `anchor`

Not a build. `snt_prov_anchor_overview()` queries `post_status => 'any'` and
`pending[]` carries `post_id` + `title` (`inc/abilities-provenance.php:74-76`),
so the payload **can name unpublished post titles**. The byte-identical twin
rule means a twin cannot narrow it. Three ways out, none free:

1. **Narrow the local ability** — changes the desktop surface and every consumer.
2. **Allow a twin to be a documented NARROWING of its local schema** — needs the
   parity pin to become "twin is a subset, and the subset is declared", which is
   a weaker invariant and a real review.
3. **Leave `anchor` local.** Cheapest, and the ledger is separately public anyway.

Recommendation: (3) unless reading anchor state from a phone turns out to matter.

**RULED 2026-09-01: option (3). `anchor` stays local** — owner accepted the
recommendation ("Your recs. All yours."). Recorded in docs/BACKLOG.md as D1.

## DEFERRED — `cron_scheduled` / `cron_history`

Not safety, **output quality**. The payload is a list of raw hook names, which
tells you machinery exists without telling you whether anything is wrong. What a
phone wants is "all 22 recurring jobs fired on schedule" or "two are overdue".
That is the partition's "model, never levers" pass, and it is a redesign of the
section, not a twin. Do it as its own increment.

## DEFERRED — `analytics_top_content`

Safe under the Access model. Thin utility (the dashboard covers it). One residual
worth keeping in the record: the rollup stores REQUESTED paths, so a logged-out
visitor requesting an unpublished slug lands that path string via a 404. Owner
previews are already dropped — the worker rejects beacons carrying a
`wordpress_logged_in_*` cookie. Revisit only if wanted.

## DROPPED — `404_log`

Weakest on every axis; the 2026-08-11 partition already leaned out.

## PERMANENT — corpus-reaching sections stay out

`corpus_integrity`, `pattern_content`, and the `sn-posts`/`sn-scan`/`topic_clusters`/
`keyword_candidates`/`link_candidates` family. NOT because of the threat model the
owner corrected, but because "the remote door is analytics scope" is a cheap
invariant to hold and expensive to re-derive. Zone config sits above Access and the
worker, so do not lean the whole thing on one layer.
