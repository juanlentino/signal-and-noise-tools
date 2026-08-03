# REST Authorization Audit — signal-and-noise-tools

- **Date:** 2026-08-03
- **Plugin version audited:** 10.33.3
- **Scope:** every `register_rest_route()` in the plugin + every `wp_register_ability()` (each is independently REST-reachable — see §0)
- **Threat model:** silent modification of the published record. No PII, no payment flow.
- **Phase:** 1 (inventory + audit, read-only). No code changed.

## TL;DR

**The authorization posture is sound. No CRITICAL or HIGH authorization defect was found.**
Every one of the 65 abilities and 15 native routes carries a `permission_callback`; the only
`__return_true` in the plugin is the intentionally-public verifiable-credential route. Sensitive
reads (audit log), destructive ops (cron unschedule, tag prune, cache purge, attachment delete),
and admin ops are all `manage_options`. Content mutation is scoped to `edit_post` /
`delete_post` on the specific target, and the handlers re-validate the *same* post_id the
callback checked (no confused-deputy gap). Both structural-content writers are
fingerprint-validated with 409-on-stale.

The two items genuinely worth acting on in Phase 2 are **additive, not fixes**:

1. The **`Content-Signal` TDM header the brief asks for is missing** (the other two TDM headers
   already ship as of v9.83.0). — *Phase 2.2*
2. **No rate limiting on the native Abilities run-route** for the O(n²) / AI-billing scans
   (only the MCP-rw door is rate-limited). Low severity — all are `manage_options`. — *note*

Several brief items are **already handled**: `/wp/v2/users`, `/wp/v2/comments`, `/batch/v1`
removed for anonymous; `TDM-Reservation` + `TDM-Policy` headers on all namespaces;
`/wp/v2/posts` rendered-content stripped for anonymous.

---

## §0 — The surface the brief did not assume: abilities are REST-reachable directly

The brief modeled the MCP endpoint as the REST surface. It is not the only one. Every ability
registered with `'show_in_rest' => true` (63 of 65) is independently invocable at:

```
POST /wp-json/wp-abilities/v1/abilities/signal-noise/<slug>/run
```

gated **only by that ability's own `permission_callback`** — the `manage_options` MCP door
floor does **not** apply on this path. Confirmed by `inc/rest-api.php:12-14`, which documents the
run-path and records that the legacy `signal-noise/v1` action routes were removed in v7.0.0 in
favor of it.

**Consequence:** each ability's own callback is the binding constraint, so each must be correct
*standalone*. It is — see the table. But it means:

- **The MCP `manage_options` floor is defense-in-depth, not the gate.** For `manage_options`
  abilities this is moot (both paths require admin). For `edit_post` abilities it matters: they
  are reachable by a non-admin **Editor/Author** via the run-route (see Finding 4).
- There are effectively **two doors to every ability**: the MCP JSON-RPC door
  (`manage_options` floor → per-ability `check_permissions()` at `mcp-tools.php:436` → `execute()`
  at `:450`) and the native run-route (per-ability callback only). Both enforce the ability
  callback; the MCP door adds a floor on top.

---

## §1 — Native `register_rest_route()` inventory (15 routes)

| Namespace / route | Method | permission_callback | Capability actually checked | Args validated | Destructive? |
|---|---|---|---|---|---|
| `sn-prov/v1/confirm` | POST | `sn_prov_confirm_permission` | Ed25519 detached-sig verify over raw body (`inc/provenance-webhook.php:267`) | sig/pubkey length-checked | writes provenance meta |
| `signal-noise/v1/analytics/refresh` | POST | `sn_analytics_refresh_permission` | `hash_equals` shared secret `X-SN-Refresh-Key` (`analytics-refresh-rest.php:50`); 503 if unset | header only | triggers rollup (idempotent) |
| `sn-prov/v1/status` | GET | inline closure | `manage_options` | — | no |
| `signal-noise/v1/credential/(?P<uid>[A-Za-z0-9-]+)` | GET | `__return_true` | **public — by design** (verifiable credential) | uid regex-constrained | no (read; only returns notes that have a signed VC) |
| `signal-noise/v1/site-health/cron` | GET | inline closure | `manage_options` | — | no |
| `signal-noise/v1/site-health/scheduled-actions` | GET | inline closure | `manage_options` | — | no |
| `signal-noise/v1/desktop/site-views` | GET | inline closure | `manage_options` | — | no |
| `signal-noise/v1/desktop/machine-readers` | GET | inline closure | `manage_options` | — | no |
| `signal-noise/v1/analytics/series` | GET | `sn_analytics_rest_can_read` | `manage_options` | range/class resolved server-side | no |
| `signal-noise/v1/analytics/dimension/(?P<dim>[a-z]+)` | GET | `sn_analytics_rest_can_read` | `manage_options` | `dim` regex `[a-z]+` | no |
| `signal-noise/v1/analytics/distribution/(?P<metric>[a-z]+)` | GET | `sn_analytics_rest_can_read` | `manage_options` | `metric` regex `[a-z]+` | no |
| `signal-noise/v1/analytics/event-props` | GET | `sn_analytics_rest_can_read` | `manage_options` | — | no |
| `signal-noise/v1/analytics/anomalies` | GET | `sn_analytics_rest_can_read` | `manage_options` | — | no |
| `signal-noise/v1/mcp` (read door) | POST | `sn_mcp_read_permission` | kill switch → `manage_options` (`mcp-endpoint.php:169`) | JSON-RPC body dispatched | via tools only |
| `signal-noise/v1/mcp-rw` (write door) | POST | `sn_mcp_rw_permission` | kill switch → `manage_options` → bound app-pw UUID (`mcp-endpoint.php:189`) | JSON-RPC body + rate-limit gate | via tools only |

## §1b — Abilities inventory (65 abilities), grouped by capability

All 65 registrations pair 1:1 with a `permission_callback` (parity verified). Helpers defined in
`inc/abilities-permission-helpers.php`.

**`manage_options` (admin-only) — 40 abilities.** Reads, destructive ops, admin actions:
`get-audit-log`, `export-audit-log`, `run-audit-prune`, `list-cron-events`, `get-cron-history`,
`unschedule-cron-event`, `run-cron-event`, `purge-all-caches`, `get-deploy-status`,
`clear-template-overrides`, `list-template-overrides`, `run-health-scan`, `get-health-scan`,
`run-insights-scan`, `get-insights`, `run-narration`, `get-narration`, `block-migrations-scan`,
`pattern-adoption-scan`, `duplicate-body-scan`, `near-duplicate-scan`, `keyword-candidates`,
`link-candidates`, `topic-clusters`, `cadence-flags`, `list-posts`, `get-post-content`,
`get-analytics-events`, `get-analytics-summary`, `get-collector-status`,
`get-machine-readers-summary`, `anchor-status`, `anchor-sweep`, `get-rss-stats`, `merge-tags`,
`prune-unused-tags`, `sn-posts`, `sn-site-facts`, `sn-scan`, `sn-validate`.

**`edit_post` on `$input['post_id']` — 17 abilities.** Content mutation / per-post suggest:
`update-post-surfaces`, `block-migrations-suggest`, `block-migrations-apply`,
`pattern-adoption-suggest`, `pattern-adoption-apply`, `ai-generate-meta-description`,
`ai-generate-og-card-title`, `ai-generate-excerpt`, `ai-drift-suggest`, `ai-drift-apply`,
`ai-alt-inline-suggest`, `ai-link-suggest`, `ai-link-apply`, `ai-pair-suggest`,
`regenerate-og-card`, `suggest-tags`, `dismiss-candidate`, `prepop-dismiss`.

**`edit_post` on `$input['attachment_id']` — 3 abilities:** `ai-alt-suggest`, `ai-alt-apply`,
`ai-orphan-suggest`.

**`delete_post` on `$input['attachment_id']` — 1 ability:** `ai-orphan-apply` (force-delete;
re-verifies orphanhood at apply time, 409 if newly referenced).

---

## §2 — Findings, by severity

### Finding 1 — MEDIUM — `Content-Signal` TDM header missing (Phase 2.2 gap)
**File:** `inc/rest-hardening-policy.php:42-45`
The rights-reservation stack already reaches REST as of v9.83.0: `snt_rest_hardening_tdm_headers`
on `rest_post_dispatch` emits `TDM-Reservation: 1` and `TDM-Policy` on **every** REST response
across **all** namespaces (duck-typed on `method_exists($result,'header')`, so `/wp-json/…` and
`/?rest_route=…` both get them). What the brief asks for and the policy does **not** yet set:
`Content-Signal: search=yes, ai-train=no, ai-input=yes`.
**Fix (Phase 2):** add `'Content-Signal' => 'search=yes, ai-train=no, ai-input=yes'` to the
policy `headers` array. It flows to all namespaces automatically. Two sub-notes:
- Brief wants values from **filterable constants**; today they live in a filter
  (`snt_rest_hardening_policy`). Equivalent reach; if literal constants are preferred, define
  `SN_TDM_*` constants and reference them in the policy.
- `$result->header($name, $value, true)` uses **replace = true** (`rest-hardening.php:109`).
  For TDM-namespaced headers nothing else sets them, so no real clobber — but it does not meet
  a strict "never overwrite an existing header" reading. Consider `false` (append/keep) if that
  invariant matters.
**Breaks MCP/editor?** No.

### Finding 2 — LOW — No rate limiting on the native Abilities run-route for expensive scans
**Files:** `inc/mcp/mcp-tools.php:387` (rw rate gate is MCP-rw-door-only); `abilities-corpus.php`,
`abilities-narration.php`, `abilities-insights.php`.
`near-duplicate-scan` is O(n²); `run-narration` / `run-insights-scan` / `ai-*` incur AI-provider
billing. The MCP-rw door has `sn_mcp_rw_rate_limit_gate()`, but the native run-route
(`/wp-abilities/v1/…/run`) has none. Blast radius is bounded: all are `manage_options`, so only
an admin can trigger them, and `block-migrations-scan` caches per-user for 1h. An admin can
already load their own site by hand, so this is genuinely LOW — noted for completeness because
the brief called these out. **Fix (optional):** if desired, gate the expensive slugs behind a
per-user transient throttle at the impl layer (as `update-post-surfaces` already does at
`abilities-update-post-surfaces.php:159`), so both doors inherit it.

### Finding 3 — LOW / INFO — Content mutation is reachable by non-admin Editors/Authors
This is **by design and correct**, surfaced so the owner can confirm the policy. Because the
`edit_post` abilities are reachable via the native run-route (§0), the binding constraint for
`update-post-surfaces`, `block-migrations-apply`, `pattern-adoption-apply`, and the `ai-*-apply`
writers is WordPress's `edit_post` meta-cap — i.e. an **Editor** (all posts) or **Author** (own
published posts), not only an admin. That matches the authority those roles already hold in the
block editor, so it is not an escalation. **Decision for the owner:** if the intent is that
*only* an admin may ever mutate published content programmatically (tighter than WP's editorial
model), these callbacks would need `manage_options`. If the site has no non-admin users (single-
author site), this is moot. **I recommend leaving as-is** unless you run non-admin editors.

### Finding 4 — INFO — MCP path's binding constraint is the admin app-password credential (1.4)
Both MCP doors floor on `manage_options` via WordPress application-password auth; the **rw** door
additionally binds to one specific app-password UUID (`sn_mcp_rw_app_password_uuid`,
`mcp-rw-guard.php`) plus a kill switch checked before the cap. So on the MCP path the credential
*is* the constraint, exactly as the brief anticipated — an administrator application password.
**No change recommended; do not re-scope the credential autonomously.** Reported per §1.4.

---

## §3 — Items the brief lists that are already handled (no action)

- **`export-audit-log` / `get-audit-log` / `run-audit-prune`** → `manage_options`. ✅
- **`unschedule-cron-event`, `prune-unused-tags`, `purge-all-caches`, `run-audit-prune`,
  `run-insights-scan`, `run-narration`** → `manage_options`. ✅
- **`purge-all-caches` template-override sweep** — the irreversible
  `include_template_overrides` flag is scrubbed at the rw door (`mcp-tools.php:432`) and
  `clear-template-overrides` is held off both MCP doors. ✅
- **`update-post-surfaces`, `block-migrations-apply`, `pattern-adoption-apply`** — the two
  structural writers are **fingerprint-validated with 409-on-stale**
  (`abilities-block-migrations.php:109`, `abilities-ai-pattern-adoption.php:87`); a stale client
  cannot overwrite newer content. `update-post-surfaces` sets explicit reviewed fields (not a
  read-modify-write over post_content), validates status + public post type via shared corpus
  gates (`abilities-update-post-surfaces.php:105-115`), REJECTs over-length input rather than
  truncating, and throttles writes per post. ✅
- **Confused-deputy check (1.2)** — every content handler resolves the *same* `$input['post_id']`
  the callback checked; no handler re-reads a different identifier. ✅
- **Corpus scan SQLi (1.5)** — `near-duplicate-scan`, `duplicate-body-scan`,
  `block-migrations-scan` use `WP_Query`/`get_posts`, **no raw `$wpdb`** in the corpus abilities.
  No dynamic SQL to parameterize on those paths. ✅
- **`post_id` handling (1.5)** — cast to int, existence + status + post-type validated in
  `update-post-surfaces`; structural writers 404/409 on missing/changed targets. ✅
- **`/wp/v2/users` unavailable unauthenticated (2.3)** — already removed for anonymous, along
  with `/wp/v2/comments` and `/batch/v1` (`rest-hardening-policy.php:32-37`). Note: removal is
  **anonymous-only**; a logged-in Subscriber can still reach `/wp/v2/users` (core WP behavior —
  usernames only, no PII). Flagged for awareness, not a plugin defect.
- **`/wp/v2/posts` clean-content bypass** — rendered `content`/`excerpt` stripped for anonymous
  callers (`rest-hardening.php:58`). ✅

---

## §4 — Fixes that would break MCP tools or the block editor

Nothing in the recommended Phase 2 set (add `Content-Signal` header; optional run-route
throttle) breaks either. The **only** change that would regress callers is **Finding 3's
optional tightening** to `manage_options`: it would break the block-editor / native run-route
path for any non-admin Editor/Author using the `ai-*` and content abilities, and is therefore
gated on the owner's answer to "do non-admin editors use these tools?" Do not apply it without
that confirmation.

---

## Phase 2 recommendation (pending approval)

1. Add `Content-Signal: search=yes, ai-train=no, ai-input=yes` to the hardening policy headers
   (single-line change; inherits all-namespace delivery). *(Finding 1)*
2. **Decision needed** before any authz code change: does the site have non-admin Editors/Authors
   who should be able to mutate content via these tools? If **no**, we can tighten the content
   writers to `manage_options`. If **yes** (or single-author), leave as-is. *(Finding 3)*
3. Optional: per-user throttle on the expensive scan impls so both doors inherit it. *(Finding 2)*
4. Verification script (2.3) + CHANGELOG + session summary.

**Stopping here for approval before touching any code.**

---

## Resolution status (Phase 2 — 2026-08-03)

Owner approved "headers + optional throttle" and delegated the content-write-cap
decision to the recommendation.

| Finding | Severity | Resolution |
|---|---|---|
| 1 — `Content-Signal` header missing | MEDIUM | **FIXED.** `Content-Signal: search=yes, ai-train=no, ai-input=yes` added to the hardening policy, sourced from new `defined()`-guarded constants `SN_TDM_RESERVATION` / `SN_TDM_POLICY_URL` / `SN_TDM_CONTENT_SIGNAL` (overridable in wp-config, still refinable via the `snt_rest_hardening_policy` filter). Delivered on all namespaces via `rest_post_dispatch`. `tests/rest-hardening.php` updated (2→3 headers, new value pinned). Commit `d83bd4c`. |
| 2 — no rate limit on native run-route | LOW | **FIXED (scoped).** New `inc/abilities-rate-gate.php` — per-user fixed-count-per-window transient throttle, fail-open without the transient API. Wired into `near-duplicate-scan` (O(n²)) and `run-insights-scan` (forced scan). `run-narration` deliberately excluded (async, deduped background trigger — see handler at `abilities-narration.php:103`). Caps 10/60s, filterable. `tests/abilities-rate-gate.php` added. Commit `1136e49`. |
| 3 — content-write cap is `edit_post` | LOW/INFO | **NO CHANGE (recommended).** Left as `edit_post`: it mirrors the authority Editors/Authors already hold in the block editor, so tightening to `manage_options` would break legitimate editorial flows without closing a real hole. Revisit only if the owner wants programmatic content mutation to be admin-exclusive. |
| 4 — MCP binding constraint is the admin app-pw | INFO | **NO CHANGE (by design).** Reported per §1.4; credential scope not altered. |

**Verification:** `docs/security/rest-audit-verification.sh` — a runnable, prod-facing
curl sequence covering all five Phase-2.3 checks (anon 401/403, Subscriber 403, admin
200, `/wp/v2/users` removed, TDM headers on `/wp/v2/posts` incl. a CDN-strip note). Not
run in CI (touches production); run by hand with the MCP app password.

**Test status:** full standalone sweep green — 364 files, 13,440 assertions, 0 failures.
