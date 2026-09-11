# Plan — closing the enforcement gaps (from the 2026-09-11 audit)

Source: [docs/audits/enforcement-audit-2026-09-11.md](../audits/enforcement-audit-2026-09-11.md).
Row ids (M4, R1, …) refer to that table.

Rule for inclusion: a mechanical gate that rejects the violation, costs less
than the thing it protects, and would not be tuned around within a week.
Everything that fails that test is listed at the end as "stays a convention",
with the reason.

Order is blast radius, not ease. Each phase is one PR (or one owner action).

---

## Phase 1 — the abilities REST write surface (M4, M1, E3) — plugin PR, MINOR

The seam: `POST /wp-abilities/v1/abilities/<slug>/run` reaches every
`show_in_rest => true` ability with any `manage_options` application
password, and none of the rw door's controls apply there. Today only a
Cloudflare WAF rule (dashboard, unrecorded) closes it.

Constraint discovered during the audit: wp-admin's own buttons
(`assets/snt-ability-run.js:69`, command palette) call the **same route** with
cookie + nonce. So the guard cannot key on the route; it must key on the
credential — exactly the shape of the WAF rule.

1. **`inc/mcp/mcp-rw-guard.php` — `sn_mcp_rw_guard_run_route()` on `rest_pre_dispatch`.**
   Fires only when: route matches `#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#`
   **and** the request authenticated by application password
   (`rest_get_authenticated_app_password() !== null`) **and** the ability's
   meta is not `readonly => true`. Then it applies the door's stack in order:
   rw kill switch → bound-UUID credential check → 30/min rate limit → audit
   row via `sn_mcp_rw_audit_record()`. Refusals reuse `sn_mcp_rw_error()`
   codes so the error vocabulary stays one. Cookie-auth requests are untouched.
   Reads stay as they are (the read guard already covers them).
2. **`tests/mcp-rw-guard-run-route.php`** — mirror of
   `tests/mcp-read-guard-run-route.php`: app-password + rw slug + switch off →
   403; app-password + wrong UUID → `credential_not_authorized`; cookie auth →
   passthrough; `readonly => true` ability → passthrough; a non-door
   `destructive` ability (`block-migrations-apply`) → guarded. Negative
   control: the guard with the credential check stubbed to `allow` must pass
   the same request it refused.
3. **`inc/sn-apply/gates.php:22-30`** — fix the comment that says the door
   always runs first (it does not on this route; the gate is what holds).
4. **`.github/security-scan-instructions.md`** — one paragraph naming the
   abilities run route as a write surface and the guard as its control, so the
   reviewer can reason about M4.
5. **Edge probe for the WAF rule** (E3): extend
   `inc/health-check-cf-security-headers.php` (or a sibling in the same pack)
   with one request to `home_url('/wp-json/wp-abilities/v1/abilities')`
   carrying `Authorization: Basic Zm9vOmJhcg==`; expected 403 from the edge.
   A 200/401 means the rule is gone. Cached like the header probe; same SSRF
   guard. Now the dashboard rule has a witness in code.
6. **Not in this phase:** running `sn-validate` inside the seven legacy apply
   abilities. Five of them are candidate-driven with scan-minted fingerprints
   (the validator would re-check what the scan already asserted); the honest
   fix is retiring them into `sn-apply` change types, which is the standing
   consolidation program, not a gate. Recorded, not built.

CHANGELOG under `[Unreleased]`; cut as MINOR (new user-visible control).

## Phase 2 — GitHub rulesets and merge settings (R1, R2, R3, R11) — owner action, no code

All via `gh api`; each is one call and reversible. Needs your go: these change
your own workflow.

1. **Plugin + theme `Require PR on main`:**
   - add required checks: `Security Review` job `security`, `PHPStan static analysis`, `Gitleaks` job `gitleaks`, `Semgrep` job `semgrep`. (`security` is `if:`-gated for forks/dependabot; a skipped required check counts as passing, so Dependabot PRs still merge.)
   - `bypass_mode: always` → `pull_request`. Bypass still works *on a PR*; direct pushes to `main` stop. Consequence: the version-cut commit becomes a PR — `tools/cut-release.sh` already refuses a dirty tree, so the flow is branch → cut → PR → squash → tag. One extra step per release, ~1 minute. `docs:` commits likewise.
   - leave `strict_required_status_checks_policy: false` (up-to-date-branch is not worth the re-runs on a solo repo).
2. **Repo merge methods:** `allow_merge_commit: false`, `allow_rebase_merge: false` on both. Squash becomes the only button.
3. **`sn-provenance-worker` and `sn-remote-mcp-worker`:** a `Require PR on main` ruleset with `pull_request` + required `test` (their `test.yml` job), **no** bypass actor. Signing key and the remote door earn it. The other three workers, the ledger repo and the memory repo stay unprotected on purpose (single contributor, no key material, convention unbroken) — revisit when a second contributor appears.
4. Update memory `main-protected-by-rulesets-not-classic` and `release-drafts-and-manual-deploy` with the new flow (commit + push the memory repo).

## Phase 3 — cheap pins that make existing rules true (M9, W4, E4, P11) — plugin PR + worker PR, PATCH

1. **`tests/mcp-capabilities.php`** — every slug on `sn_mcp_allowlist()` must resolve to a registration whose meta has `readonly => true`; every rw slug must not. Turns the "readonly is a claim" line in the policy doc into a check. (Static source scan, same technique the retired-slug pin already uses.)
2. **`tests/rest-routes.php`** (new, ~40 lines) — pins the `register_rest_route` count (22) and that exactly two are `__return_true`, by name. A third public route fails the sweep and has to be argued into the list.
3. **`inc/health-check-rights-signals.php`** — compare the served `Content-Signal` on `/wp-json/` to `SN_TDM_CONTENT_SIGNAL` byte-for-byte. Divergence between the two repos' literals becomes a finding the same day instead of "silently diverged" (the v10.70.1 story).
4. **Analytics worker `test/handle.spec.js`** — one assertion: no beacon response, accepted or rejected, carries `set-cookie`. Ten lines; the rule is currently true by accident.
5. **`tests/run.sh` / `ci.yml`** — run `node --test tests/js/prov-verify-core.test.mjs` in the tests job (it is a plain node test; the other seven `tests/js/*.mjs` are browser harnesses and stay manual, say so in a comment).

## Phase 4 — theme parity (P16, T3, T8) — theme PR, PATCH

1. Copy the "Every job declares a timeout" Ruby step from plugin `ci.yml:64-101` into theme `ci.yml` lint job.
2. `smoke-test.yml` lint: PHP `8.2` → `8.4` (production).
3. `CLAUDE.md` §Versioning: replace "Drafts stay drafts forever" with the 2026-09-10 rule (public by default), and the `git push origin HEAD:main` line with the PR flow from Phase 2.

## Phase 5 — instrument and document fixes — plugin PR, PATCH (or fold into Phase 3)

1. **`inc/corpus-integrity-scan.php` `date_coherence`** — a sentence matching `^Correction, <Month D, YYYY>\.$` (the practised correction line) is intentional, not drift: skip it, count it under a new `corrections` key so the practice is *visible* rather than flagged. Test with the two live sentences as fixtures.
2. `phpstan.neon` header — delete "CI runs soft" (it is hard-failing, non-required; after Phase 2 it is required).
3. `.gitattributes` — drop the dead `.pre-commit-config.yaml` line.
4. README "Agent surface" — name the 8 rw-door slugs instead of counting them; link the abilities run route as a write surface.
5. `docs/MACHINE-READERS.md` — add the `_sn/*/status|version` endpoints and `sn-prov/v1/credential/{uid}` to the public-surface list.
6. `~/.claude/hooks/gsd-validate-commit.sh` — leave; it is a GSD opt-in, not this repo's rule. Note in the audit that it is inert here (already done).

## Stays a convention (and why)

| Row | Why not a gate |
|---|---|
| P1 hardcoded literals | 18 hits, all either the legacy-host constant, schema `@context` URLs, or example strings; a scan would need an allowlist longer than the finding. |
| P2 file size (150/800) | Would red 350 files; not a rule this codebase ever followed. Drop the line from the global CLAUDE.md or accept it as aspiration. |
| P3 coverage 80% | No runner produces coverage for these standalone suites; `run.sh`'s zero-assertion rule is the meaningful half. |
| W1 public beacon token | Any client-side secret ships to the client. Detection (Phase 3 shape-stability) is the honest answer. |
| S1 correction notice, S2 collisions in wp-admin | Editorial judgment by one person; a hard hook would be bypassed the first time a typo fix needed it. Phase 5.1 makes the practice visible instead. |
| R4/R5 version classification | Judgment. `cut-release.sh` already refuses the empty and dirty cases. |
| R9 rsync/deploy.yml | Emergency path; removing it removes the recovery lever. |
| S9 webmention volume | Pair-hash dedupe + edge rate rule; an origin limiter on a public protocol endpoint would refuse legitimate bursts (a mention going viral). Check the edge rule (audit §6.7) instead. |

## Sequence and estimate

| Phase | Where | Size | Needs you |
|---|---|---|---|
| 1 | plugin | M (guard + 5 tests + probe) | review |
| 2 | GitHub | S (6 API calls) | **go, and accept the PR-per-cut flow** |
| 3 | plugin + analytics worker | S | review |
| 4 | theme | S | review |
| 5 | plugin | S | review |

Phases 1, 3, 5 are one plugin PR each (or 3+5 folded). Phase 2 is the one
that changes how you work; do it after Phase 1 merges so the first PR-per-cut
release is a real one.
