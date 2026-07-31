# SN MCP Consolidation — Session 1 Findings

Reconnaissance only, no code changed. Repo: `signal-and-noise-tools` at v10.24.0. Session 1 of the plan in `sn-claude-code-handoff.md` (specs archived at `~/.claude/session-data/SN-MCP-new/`).

## #0 — Telemetry Phase 0a: LIVE
`~/.claude/settings.json` wires `PostToolUse`/`PostToolUseFailure` on `mcp__.*` to `~/.claude/hooks/log-mcp.sh`; `~/.claude/telemetry/mcp-calls.jsonl` has real rows (verified). **Confirmed deviation**: `args_hash` is `sha256(tool_input)` first 22 hex chars — not the spec's base64 prefix, which was reversible and violated the spec's own acceptance test 3. Deviation is correct; spec needs fixing.

## #1/#4 — Server implementation: hand-rolled, not the adapter
`composer.json` requires only `"php": ">=8.3"` — no `wordpress/mcp-adapter`, no `wordpress/php-mcp-schema`, no `vendor/` dir at all. The whole MCP layer is hand-written in `inc/mcp/` (10 files): `mcp-server.php` (JSON-RPC 2.0 method router, hand-built), `mcp-endpoint.php` (two REST routes, `/mcp` read + `/mcp-rw` write), `mcp-capabilities.php` (allowlists), `mcp-tools.php` (ability→tool projection + dispatch), plus `mcp-resources.php`/`mcp-prompts.php` (resources/prompts already implemented) and three hardening files (`mcp-rw-guard.php`, `mcp-rw-audit.php`, `mcp-read-guard.php`).

**Protocol version**: this server negotiates `2025-06-18`/`2025-03-26`/`2024-11-05` (`mcp-capabilities.php:21`), never `2025-11-25` — so `sn-mcp-conformance.md`'s entire §0/§0.5 framing (audited against the adapter's 2025-11-25) doesn't apply; there's no adapter to be behind.

**Real baseline is 68 tools, not 66**: read allowlist (`sn_mcp_allowlist()`) = 33 slugs, rw allowlist (`sn_mcp_rw_allowlist()`) = 35 slugs, zero overlap, verified by direct count. `sn-mcp-consolidation.md`'s mapping table accounts for 66 and misses two live abilities: `signal-noise/topic-clusters` (v10.21.0) and `signal-noise/cadence-flags` (v10.22.0), both read-door, neither mapped to any of the 11 new tools.

**Verdict: INVALIDATES** the adapter-rewrite framing in the kickoff prompt and conformance §0.5. There is no `wp_register_ability()` + `create_server()` migration to do — consolidating the surface means editing `sn_mcp_allowlist()`/`sn_mcp_rw_allowlist()` in `mcp-capabilities.php` directly. This is *less* work than assumed, not more.

## #2 — Composer dependencies: absent, confirmed conclusively
`composer.json`'s only `require` is PHP >=8.3; `require-dev` is lint/analysis tooling only. No vendor dir, no lock file, zero references to adapter namespaces anywhere in `inc/mcp/`. Closes #1/#4 definitively.

## #3 — Annotations: already fully wired, not a blocker
`inc/abilities-dismiss.php:75-81` and others register `meta.annotations` (`readonly`/`destructive`/`idempotent`, WP Abilities vocabulary). `inc/mcp/mcp-tools.php:241-262` (`sn_mcp_ability_annotations()`) translates this into MCP's `readOnlyHint`/`destructiveHint`/`idempotentHint`, with a documented precedence order and a curated override map (`sn_mcp_rw_annotation_destructive_overrides()`, lines 176-213) for abilities that under-declare risk. `sn_mcp_project_tool()` (lines 279-297) attaches this to every rw-door tool.

**Verdict: INVALIDATES** the conformance doc's "open blocker" — there's nothing to raise upstream; the exact annotation table the spec proposes for the 11 new tools is a pattern this codebase already implements for 35 existing abilities.

## #5 — Shared code paths: mixed, with one major gap
- **Scan family is two families**: structural scans (block-migrations, pattern-adoption, duplicate-body) don't touch `inc/ml-kernel.php`; ML-kernel scans (near-duplicate, link-candidates, keyword-candidates, topic-clusters, cadence-flags) all share `inc/ml-kernel.php`'s TF-IDF/BM25/cosine primitives. `sn_scan`'s "thin wrapper" framing is honest for the second group only.
- **Fingerprint gating**: confirmed exactly as hoped. `ai-drift-apply`/`ai-link-apply` (`inc/abilities-ai-health.php`) require a 32-char md5 fingerprint echoed from the paired suggest call, 409 on mismatch.
- **`dismiss-candidate`**: content-derived key `(post_id, block_fingerprint, candidate_type)`, confirmed non-UUID (`inc/abilities-dismiss.php:96`). But it only dispatches `surface: block-migrations | pattern-adoption` — the other five planned `sn_scan` sources (near-dup, link-candidates, keyword-candidates, topic-clusters, orphan/pair) have **no dismissal store at all** today. `sn_dismiss` absorbing it is new plumbing for 5/7 sources, not a wrapper.
- **Biggest gap found this session: `mode: "revision"` does not exist anywhere.** Grepped every apply ability for `dry_run`/`preview`/`wp_save_post_revision` — zero hits. Every apply writes live via `wp_update_post()` only; "revision" in existing descriptions refers to WordPress's automatic post-save revision, not a staged, non-live write. `sn_apply`'s core PR-pattern selling point is new engineering, not a wrapper — acceptance test 6 (mode:revision → live post byte-identical) would fail against every existing apply ability today.

**Verdict: INVALIDATES** the "wrapper, low risk" framing of `sn_apply`'s migration path specifically. Recommend splitting the apply phase: 5a builds the revision-mode write primitive in isolation first, 5b wires the four gates around it.

## #6 — `ai-*-suggest` model call: confirmed
`inc/ai-bootstrap.php` — gated on `is_supported_for_text_generation()` (corrected in v2.5.0 from a broken `wp_has_ai_client()` check), model pinned `claude-sonnet-5` (both default and fallback, deliberately identical for resolvability), call site `snt_ai_generate_with_constraints()` (line 275). Credential lives entirely in WordPress core's Settings → Connectors (Anthropic provider plugin) — **SN never holds its own key**, so retiring the 14 `ai-*-suggest` tools carries zero credential-rotation risk. Existing token-usage/spend logging (`SN_AI_USAGE_LOG_OPT`, `SN_AI_SPEND_ROLLUP_OPT`) already tracks this independent of MCP telemetry.

## Extra — modernization-program interactions
**(a)** `inc/insights.php:295-302` (v10.24.0) feeds kernel `cadence_flags` into the AI-billed `get-insights` advisor prompt — but the raw `signal-noise/cadence-flags` ability is separate, non-AI, read-door. Retiring `get-insights` doesn't touch the kernel data; it's genuinely just the prose wrapper. But `cadence-flags` itself is unmapped in the consolidation spec (see #5) and needs a home, likely `sn_health`.

**(b)** BM25 exists only as kernel math (`inc/ml-kernel.php:216`), used for candidate-vs-candidate scoring, never exposed as query-driven corpus search — no ability or tool does this today. Best fit: a `query` field added to `sn_posts`'s scope union, not a new tool (same "field, not shape" merge test the spec already uses). `create-draft` has zero code; `sn_apply`'s target union has no "new post" variant, so it needs a 4th target type plus a *structural* (not policy-only) block on `mode: publish` for that change type.

**(c)** The rw door already has kill-switch (`SN_MCP_RW_DISABLED` constant + `sn_mcp_rw_enabled` option, checked before even `manage_options`), credential-split (bound app-password UUID), rate-limiting (checked before tool-name validation), and audit logging (`sn_mcp_rw_audit_record()`) — all door-level, automatically inherited by any new tool registered on the rw allowlist. `sn_apply`'s four gates should be built as an additional, ability-level layer on top of this, not a reimplementation of it.

## Re-baselined build order
Phases 0a/0b/1/2 hold unchanged. Drop the adapter-rewrite framing everywhere (registration is plain `wp_register_ability()` + allowlist edit, no `create_server()` call exists to write). Insert a decision point before phase 3 to place `topic-clusters`/`cadence-flags`. Split the `sn_apply` phase into 5a (build the revision-mode write primitive, new, with its own acceptance tests) and 5b (wire the four gates around it, closer to the original scope). Budget `sn_dismiss` as new plumbing for 5 of 7 scan sources, not a wrapper. Phases 6–12 hold as written.

---

# Session 2 Findings — Layer B telemetry (shipped v10.25.0)

Layer B middleware built per `sn-telemetry-spec.md`: every `tools/call` on both doors inserts one row into `{$prefix}sn_tool_call` (new `inc/mcp/mcp-telemetry.php` + instrumentation at all 6 return points of `sn_mcp_call_tool()`). Fail-open verified (any wpdb/state failure = silent no-op, tool response byte-identical), privacy pinned (top-level arg keys + sha256 only, never values), one INSERT on the hot path, retention via cron-free probabilistic prune (~1/50 inserts, 90 days, LIMIT 500 — "schedule nothing" guardrail holds). Kill switch: `sn_mcp_telemetry_enabled` filter.

## Spec deviations (session 2)
- **Interception point is `sn_mcp_call_tool()`, not the JSON-RPC router.** By the router, an execute() failure is flattened to a message string — the WP_Error *code and status are gone*, and the schema_error/server_error split (metric 3, the whole reason Layer B exists) needs them.
- **Outcome classification is HTTP-status-first, not code-name matching.** First implementation used a code-name regex grounded on a single-line grep; adversarial review proved the grep missed every multi-line `new WP_Error(` construction (8+ real codes misclassified, and substring 'missing' flipped `snt_impl_missing` — a real 500 — into schema_error). Re-grounded on a paren-balanced sweep of all 196 constructions under inc/: 168 carry `array('status'=>N)`; 4xx→schema_error, 429→refused (`write_throttle` for `snt_surfaces_throttled`, else `rate_limit`), 5xx→server_error. Status-less reachable set: `sn_tag_not_unused` (caller-argument, mapped explicitly) + the insights/narration AI-response failures (server-side, correct under the server_error default). **Lesson for all later sessions: never ground a claim about `new WP_Error(` sites on a single-line grep.**
- **`door` column added** ('read'|'rw') — the spec's table predates the two-door finding.
- **VARCHAR not ENUM** for layer/outcome/refusal_gate — house style (no ENUM column exists anywhere in inc/).
- **`actor`** resolves the bound app-password UUID on both doors (the resolver is door-agnostic); `routine:<name>` reserved, produced by no code path yet.

Tests: tests/mcp-telemetry.php, 69 asserts incl. watched-RED wiring evidence and failure-shape wpdb stubbing. Full sweep at ship time: 354 files, 12,912 asserts, 0 failures; PHPStan + PHPCS clean.
