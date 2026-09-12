# Enforcement audit — Signal & Noise ecosystem — 2026-09-11

Read-only. Nothing was changed, no PR was opened, no remote resource was touched.
Sources were read from this worktree at `b31945f`, the sibling checkouts under
`~/Projects/` (theme `8e70a71`, five workers — four of them 1–2 docs/CI commits
behind origin, diffed against origin via the GitHub API where it mattered), the
GitHub rulesets API, the read-only `sn` MCP door, the read-only Cloudflare MCP
(`workers_list`), and the memory repo.

**Category definitions used here**

- **ENFORCED** — a cited mechanism rejects the violation. Bypassing needs a deliberate disable.
- **PARTIAL** — a mechanism exists but has a named gap (one path not its sibling; local not CI; warns; skippable; not a required check).
- **HOPED-FOR** — written down or habitual; nothing rejects a violation.
- **UNVERIFIABLE-HERE** — lives in a dashboard I cannot read from this session; what to check is listed in §6.

The single most important structural fact, because it recurs in almost every
row below: the `Require PR on main` ruleset on **both** the plugin and the theme
carries `bypass_actors: [{RepositoryRole 5 (admin), bypass_mode: always}]`.
Every version-bump and every `docs:` commit in the last sixty on `main` landed
by direct push (`git log origin/main` — 34 of the last 60 have no `(#N)`). So
every "CI gate" in this document is a gate on *pull requests you choose to open*,
not on `main`. That is a legitimate release design; it is just not enforcement.

---

## 1. The table

Grouped by layer. Within each layer: HOPED-FOR, then PARTIAL, then UNVERIFIABLE-HERE, then ENFORCED.

### Layer 1 — Plugin (`signal-and-noise-tools`)

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| P1 | Nothing hardcoded; configuration and data stay out of logic | `~/.claude/CLAUDE.md`; seed list | **HOPED-FOR** | None. 18 live `juanlentino.com` literals across 13 files in `inc/` (e.g. `inc/health-edge-workers.php:76`, `inc/machine-readers-api.php:28,263`, `inc/settings.php:49`, `inc/agent-ard.php:78`). `tests/config-drift.php` tests the *drift detector*, not the absence of literals. | Write the literal. |
| P2 | No file over ~150 lines; componentise | `~/.claude/CLAUDE.md` | **HOPED-FOR** | None. 350 of 541 `inc/*.php` exceed 150 lines; 14 exceed 800 (the ECC hard cap). The 800-line PreToolUse hook in `rules/ecc/web/hooks.md` is documentation, not installed. | Exists today. |
| P3 | TDD, 80% coverage | `rules/ecc/common/testing.md` | **HOPED-FOR** | No coverage tool anywhere (`coverage: none` in every setup-php step). 650 standalone suites exist, but nothing measures what they cover. | Ship without a test. |
| P4 | Conventional-commit messages | `rules/ecc/common/git-workflow.md`; `~/.claude/hooks/gsd-validate-commit.sh` | **HOPED-FOR** | The hook exits 0 unless `.planning/config.json` has `hooks.community: true`; this repo has no `.planning/`. Dead. Release commits (`v13.109.21: …`) would fail it anyway. | Any message. |
| P5 | Every nonce is verified before a `$_POST` read | WPCS `WordPress.Security.NonceVerification` | **PARTIAL** | Sniff is `severity 0` plugin-wide (`phpcs.xml.dist:65`) because `inc/admin-post-handler.php:163` checks once before dispatch. Every `admin_post_*` / `wp_ajax_*` side handler I read does check (`inc/admin-bar.php:271ff`, `inc/provenance-admin.php:201,229`, `inc/provenance-rotation.php:446`, `inc/audit-log-export.php:322`, `inc/provenance-chain-backfill.php:446`). But a *new* side handler without a check has no gate. | Add a handler outside the dispatcher. |
| P6 | Output is escaped | WPCS `WordPress.Security.EscapeOutput` | **PARTIAL** | Active, but excluded for 7 render files (`phpcs.xml.dist:83-90`) and `InputNotSanitized` excluded for 7 more (`:103-110`). Those files are review-only. | Edit an excluded file. |
| P7 | No SQL injection (prepared statements) | WPCS `WordPress.DB.PreparedSQL` | **PARTIAL** | Active, excluded for 13 analytics/rollup files (`phpcs.xml.dist:136-149`); `DirectDatabaseQuery` off entirely (`:158`). Semgrep `p/php` + `p/security-audit` run (`semgrep.yml`) but are **not a required check**. | Edit an excluded file; merge red Semgrep. |
| P8 | No undefined symbols (PHPStan) | `phpstan.neon`; `phpstan.yml` | **PARTIAL** | Runs on every PR, fails the job. **Not in the ruleset's required checks.** `phpstan.neon` header still says "CI runs soft (non-blocking) for the first cycle" — the workflow has no `continue-on-error`, so the comment is stale, and the ruleset makes it soft anyway. Level 1, baselined (baseline has 1 entry). | Merge with PHPStan red. |
| P9 | Secrets never committed | `~/.claude/CLAUDE.md`; `gitleaks.yml`; `.gitleaks.toml` | **PARTIAL** | Gitleaks on push+PR, full history. Not a required check; runs *after* a push to main lands. PreToolUse regex hook (`settings.json`: `sk-ant-|AKIA|PRIVATE KEY`) blocks three shapes only. | Direct push; a token shape outside the three patterns. |
| P10 | Every registered ability sits in a classified permission tier | `docs/ops/ability-permission-policy.md` | **ENFORCED** | `tests/ability-permission-policy.php` enumerates every `wp_register_ability` and fails on an unclassified one (v13.109.20 fixed it to see all 103). Swept by `tests/run.sh` → required check "Test suite". | Direct push to main. |
| P11 | Every REST route has a `permission_callback` | WP core (5.5+ `_doing_it_wrong`) + review | **ENFORCED** (weakly) | Core emits a notice, not a refusal. All 19 `register_rest_route` calls carry one (the first draft of this row said 22; that grep counted a function definition and a `function_exists()` guard — `tests/rest-routes.php` now derives the number); 20 are `manage_options` or a token/signature check; 2 are `__return_true` **by design** (webmention `inc/citations-endpoint.php:137`, VC fetch `inc/provenance-credential.php:194`). No test pins the count. | Register a route with `__return_true`. |
| P12 | Anonymous callers cannot enumerate users / read comments / use `/batch/v1` | `docs/REST-HARDENING.md` | **ENFORCED** | `inc/rest-hardening.php:48` (`rest_endpoints` prefix removal) + `inc/security-headers.php:124` (401 on `/wp/v2/users`); pinned in `tests/rest-hardening.php`. | Disable the plugin. |
| P13 | Anonymous `/wp/v2/posts` never carries rendered content | `docs/REST-HARDENING.md` | **ENFORCED** | `rest_prepare_post` / `rest_prepare_page` empty `content.rendered`; pinned in `tests/rest-hardening.php`. | Cookie-auth; plugin off. |
| P14 | Test suite cannot pass silently | `tests/run.sh` | **ENFORCED** | A suite with no summary line, or `0 passed, 0 failed`, is a **failure** (`tests/run.sh`). Negative controls: `tools/stub-parity.php --self-test`, `tests/lib/assert-envelope.php --self-test`. | `SKIP` list (currently one file, documented). |
| P15 | Test stubs match the pinned WordPress stubs | `ci.yml` phpcs job | **ENFORCED** | `tools/stub-parity.php` after its self-test; job "WordPress Coding Standards" is required. | Direct push. |
| P16 | Every workflow job declares `timeout-minutes` | `~/.claude/rules/github-actions-cost.md` | **ENFORCED** (plugin) / **HOPED-FOR** (theme, workers) | Ruby YAML check in `ci.yml` "Every job declares a timeout"; refuses zero jobs found. **The theme's `ci.yml` has no equivalent** — it complies by habit (all 30 min). Worker repos: by habit. | Direct push (plugin); anything (theme/workers). |
| P17 | The release payload contains no dev-only paths | `.gitattributes` export-ignore | **ENFORCED** on the manual path / **PARTIAL** on the normal path | `deploy.yml` greps the archive for `tests/|.github/|docs/` and fails. The **normal** install path (WP updater reading the tag archive, `inc/wp-update-integration.php`) has no such assertion; `tests/export-ignore.php` pins the attribute file only. `.gitattributes` still lists `.pre-commit-config.yaml`, which does not exist. | Normal updater path is unchecked. |
| P18 | Activation on an old theme must not fatal | `signal-and-noise-tools.php:40-65` | **ENFORCED** | Pre-flight `file_exists` on the legacy theme module; admin notice + early `return`. | — |
| P19 | Settings migrate once, idempotently | `signal-and-noise-tools.php:623-627` | **PARTIAL** | Activation hook + `admin_init` lazy check; one-shot content migrations behind a master sentinel (`inc/content-migrations.php`). Nothing tests a migration against a real DB (`tests/contracts-smoke.php` needs live WP and is SKIPped in CI). | Sentinel option edited by hand. |

### Layer 2 — Theme (`signal-and-noise`)

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| T1 | Dark mode via `data-theme="dark"` | `~/.claude/CLAUDE.md` | **HOPED-FOR** (deliberately waived) | Theme `CLAUDE.md` §Stack exempts itself. Recorded contradiction with the global rule; no mechanism either way. | n/a |
| T2 | Every job declares a timeout | as P16 | **HOPED-FOR** | No YAML guard in theme `ci.yml`. | Add a job without one. |
| T3 | Release = squash-merge → annotated tag → **draft** release | theme `CLAUDE.md` §Versioning | **HOPED-FOR** and **stale** | Prose only. Memory `release-drafts-and-manual-deploy` records the owner reversing "always draft" to "public by default" on 2026-09-10; theme `CLAUDE.md` still says "Drafts stay drafts forever". | — |
| T4 | Output escaping / sanitisation | theme `phpcs.xml.dist:40` `WordPress.Security` | **PARTIAL** | Sniff active with fewer exclusions than the plugin; "WordPress Coding Standards" is required. Same NonceVerification / DirectDatabaseQuery shape. | Direct push. |
| T5 | Theme never assumes the plugin is present | README "Cross-package contracts" | **ENFORCED** by construction | Every plugin call is a filter with a default (`inc/discography-render.php:110`, `inc/music-featured-render.php:34`) or is wrapped in `function_exists` (45 guards; the four bare `snt_*` calls are each guarded 3–6 lines earlier). | — |
| T6 | Analytics stores nothing on the device (no cookies, no storage) | seed list; `docs/cookieless-analytics-research.md` | **ENFORCED** (beacon) / **PARTIAL** (estate) | Theme `tests/beacon.php:186` pins `assets/js/sn-beacon.js` contains no `localStorage`/`sessionStorage`; source has 0 refs to `document.cookie`/`indexedDB`. Worker never emits `Set-Cookie` (0 refs in `src/`) but **no worker test pins that**. Nothing pins the *rest* of the front-end JS (`sn-bundle`, PWA shells) — the rule is stated for analytics only, and the desktop app uses storage by design. | Add storage to any other script. |
| T7 | Enqueue discipline (no inline `<style>` in templates) | memory `template-inline-styles-defeat-theme-css` | **PARTIAL** | Theme tests `assets-frontend.php`, `asset-combine.php`, `block-styles.php` pin the enqueue set; nothing scans templates/patterns for `style=` (memory `census-block-attributes-not-serialized-output` explains why a grep is insufficient). | Inline style in a pattern. |
| T8 | Live site still renders (hourly smoke) | `smoke-test.yml` | **ENFORCED** as detection | Hourly curl for a marker + min bytes. Detects, does not prevent; pins PHP **8.2** syntax on push while production runs 8.4 (the parity lane is in `ci.yml`, not here). | — |

### Layer 3 — MCP surface

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| M1 | Validation runs before **any** write; no write path skips it | seed list | **PARTIAL** | `sn-apply` gate 2 calls `snt_sn_validate_*` directly and cannot be skipped (`inc/sn-apply/validation.php`; `tests/abilities-sn-apply*.php`). **Seven legacy apply abilities are still registered, still `show_in_rest => true`, and reference no validator at all**: `block-migrations-apply`, `pattern-adoption-apply`, `ai-alt-apply`, `ai-drift-apply`, `ai-orphan-apply`, `ai-link-apply`, `update-post-surfaces` (grep: 0 refs to `snt_sn_validate_` in their modules). `ai-link-apply` is on the rw door (`inc/mcp/mcp-capabilities.php:56`). wp-admin editor writes never see it. | Call a legacy apply; edit in wp-admin. |
| M2 | `sn-validate` is a mandatory gate, not an optional pre-call | seed list | **PARTIAL** | As a *tool* it is optional (read door, `edit_others_posts`). As a *gate* it is mandatory only inside `sn-apply`. The description of `sn-validate` itself says "call it as many times as needed" — it is advertised as advisory. | Skip it. |
| M3 | Remote door is read-only: no mutation reachable remotely | seed list; `docs/ops/remote-mcp-revoke-runbook.md` | **ENFORCED** (structural, two repos) | Worker registers only `BRIDGE_TOOLS` (14 read twins + ping, `sn-remote-mcp-worker/src/bridge.mjs`; `test/contract.test.mjs` pins the count). Origin bridge dispatches only `sn_mcp_remote_slugs()` (`inc/mcp/mcp-remote-guard.php:9-26`, 14 slugs, all `remote-*` twins), refused before auth is even reported (`inc/mcp/mcp-bridge-route.php`). Twins are `show_in_rest => false` from birth (`inc/abilities-remote-set.php:29`). The two 14-lists are byte-identical today; **no cross-repo test pins them equal** — parity is a manual diff. | Edit both lists in two repos. |
| M4 | Read door ↔ write door separation | README "Agent surface" | **ENFORCED at the door** / **PARTIAL at the abilities REST route** | Two routes, two permission callbacks (`inc/mcp/mcp-endpoint.php:55,80`), two allowlists pinned by `tests/mcp-capabilities.php`. Rw door adds kill switch + bound app-password UUID + 30/min + audit log (`inc/mcp/mcp-rw-guard.php`). **But** `POST /wp-abilities/v1/abilities/<slug>/run` reaches every `show_in_rest => true` ability — including all 8 rw-door slugs and the 7 legacy applies — with any `manage_options` application password, and **none of the rw door's four controls apply there**: `rest_pre_dispatch` guards exist only for the read kill switch (`mcp-read-guard.php:430`) and the remote kill switch (`mcp-remote-guard.php:288`); there is no rw run-route guard. The rw *audit* row from `mcp-tools.php` is not written either (only `sn-apply`'s own enrichment row). Both allowlists are `apply_filters`-able (`mcp-capabilities.php:46,58`). | Basic-auth to the abilities run route (see E3 for the edge rule that catches it — off-repo). |
| M5 | `sn-apply`: `dry_run` defaults true; fingerprint required; idempotent replay | `inc/abilities-sn-apply.php` | **ENFORCED** | Four gates in code; DB-verified zero-writes test (`tests/abilities-sn-apply.php`); the gates run identically via the door and via the abilities REST route. | — |
| M6 | Routine credential gets `revision` only; `publish` needs the bound owner credential | `inc/sn-apply/gates.php:72-108` | **ENFORCED** | Compares the authenticated app-password UUID with `sn_mcp_rw_bound_uuid()`. Holds on the REST run route too (an unbound UUID is `revision`-only there — the gate does *not* rely on the door, despite the comment at `gates.php:26-30` saying it is "trivially true"). Filterable via `sn_apply_granted_modes`. | Register the filter. |
| M7 | Bridge secret is a constant, never an option | `inc/mcp/mcp-bridge-route.php` | **ENFORCED** | `defined('SN_BRIDGE_TOKEN')`; route unregistered when absent. | wp-config edit. |
| M8 | Remote payload shape changes bump the contract | `inc/mcp/mcp-remote-contract.php` | **ENFORCED** per repo / **PARTIAL** across | `tests/remote-contract-shapes.php` hashes the 8 output schemas against the version; worker `test/contract.test.mjs` pins its side. Cross-repo skew is *observed* by `get-deploy-status` (`contract_live: 5`, `contract_expected: 5` today), never refused — "A DECLARATION, never a gate". | Ship one side. |
| M9 | Every read-door slug is genuinely read-only | `ability-permission-policy.md` rule 1 | **HOPED-FOR** | The `readonly` annotation "is a CLAIM; the execute callback is the evidence" — and nothing tests the evidence. No test asserts read-door abilities perform no `update_option`/`update_post_meta`. | Register a writing ability with `readonly => true`. |
| M10 | Read door rate-limited, fail-closed for remote | `mcp-read-guard.php` | **ENFORCED** | 120/min; remote path fails closed when the store is unavailable (`tests/mcp-read-rate-limit.php`). | — |

### Layer 4 — Analytics worker (`signal-and-noise-analytics-worker`, clonable, audited from source)

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| W1 | Only the site can send beacons | README "token-gated" | **HOPED-FOR** (the token is public) | `p.k !== env.SN_PX_TOKEN → reject` (`src/index.js:341`), but `k` is inlined into every public page (`theme inc/beacon.php:41-47`, `window.SN_BEACON`). It is a bot-filter, not an authenticator. No `Origin`/`Referer` enforcement — `selfHosts` (`:392`) classifies referrers, it does not gate. | `curl -d '{"k":"<from view-source>",…}'`. |
| W2 | Beacon volume is bounded | `wrangler.toml [[ratelimits]]` | **ENFORCED** | 600/min/IP via `PX_LIMITER` (`:362`); body bounded (`readTextBounded`); event enum; admin-path and logged-in-cookie rejection (`:352`). Rate-limit *errors* fail open (`:366-369`). | Rotate IPs. |
| W3 | Server-side refresh authenticated | `src/index.js:452,775` | **ENFORCED** | `SN_SRV_TOKEN` compared with `hash_equals` on the origin (`inc/analytics-refresh-rest.php`). | — |
| W4 | No cookies set | seed list | **PARTIAL** | 0 `Set-Cookie` in source; **no test pins it** (0 refs in `test/`). | Add one. |
| W5 | Code on `main` is what runs | memory `git-connected-worker-never-runs-deploy-script` | **HOPED-FOR** | Repo has **no rulesets**; Cloudflare Builds deploys `main` on push. `test.yml` runs on PR/push but nothing requires it. `get-deploy-status` confirms live `source_commit` = origin HEAD for all five workers today. | Push to main. |
| W6 | Dependencies carry provenance; cooldown; no outbound redirects | `test.yml`, `deploy.yml` | **ENFORCED** in CI | `scripts/attestation-gate.mjs`, `dependency-cooldown.mjs`, `outbound-redirect-gate.mjs` run before `npm test`; the manual `deploy.yml` re-runs the attestation gate. Same "not required" caveat as W5. | Push to main. |

### Layer 5 — Edge and infrastructure (Cloudflare, Cloudways)

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| E1 | Never re-enable Cloudflare's WebMCP toggle | memory `webmcp-injection-sits-above-the-anchor-vantage` | **HOPED-FOR** | Dashboard toggle. The rights-anchored health check would *detect* the injection (as it did 2026-08-23→28) — a finding, not a refusal. | Click. |
| E2 | Five security headers emitted at the edge (CSP, HSTS, XCTO, XFO, Referrer-Policy) | README "Security" | **UNVERIFIABLE-HERE** / **PARTIAL** as detection | Transform Rule, not in any repo. `inc/health-check-cf-security-headers.php:52-58` probes **presence only** — a weakened CSP value is invisible. Health scan is daily; a finding is a report. | Edit the rule. |
| E3 | Basic-auth to `/wp-abilities/` is blocked | memory `waf-blocks-basic-auth-on-abilities` | **REFUTED 2026-09-12 — the rule does not exist** | This row originally read UNVERIFIABLE-HERE and credited a WAF custom rule "Block Basic-auth on abilities API" as shipped 2026-08-26, on the strength of the memory note alone. `inc/health-check-cf-security-headers.php` now probes it, and its first run measured the route **open** through the edge (cf-ray present, not refused); the owner confirmed the rule was never created. The memory note is retracted. **M4's seam has never had an edge control** — `sn_mcp_rw_guard_run_route` is and always was the only thing in front of the abilities run route. | — (already open). |
| E4 | Rights signals travel with content, REST included | seed list; `docs/REST-HARDENING.md` | **ENFORCED** (two independent layers) / **PARTIAL** parity | Origin: `rest_post_dispatch` headers (`inc/rest-hardening-policy.php:87-89`, pinned `tests/rest-hardening.php:225`). Edge: worker `withTdmHeaders` on every non-bypassed path (`sn-rights-signals-worker/src/index.mjs:153-156`; `test/index.test.mjs:18` pins `/wp-json/*`; `admin-bypass.test.mjs:21` pins `/wp-json` is not bypassed). The two `Content-Signal` literals are pinned separately in two repos and equal today; nothing compares them. Direct-to-origin loses HTML `<meta>` tags, `tdmrep.json`, `license.xml`, the edge `robots.txt`, and Markdown negotiation — REST headers survive. | Diverge one literal. |
| E5 | `robots.txt` Content-Signal / AI-crawler policy | `inc/robots-txt.php`; worker `robots.mjs` | **PARTIAL** | Two authors: origin `robots_txt` filter (tested) and the worker's own `/robots.txt` response (tested). Which one a reader sees depends on the route pattern and on whether a physical file exists on the server (memory `robots-physical-file-bypasses-pointer`). `health-check-rights-signals` reads the live URL only. | Upload a physical `robots.txt`. |
| E6 | Login path defended at the edge (denylist, IPv6 criterion) | login-guard worker | **ENFORCED** at edge / **PARTIAL** at origin | Route `juanlentino.com/sn-login*`; refresh keeps last-known on failure (`src/index.js:702-718`). Origin keeps its own slug (`inc/login-hide.php`) so direct-to-origin still hides `wp-login.php`, but has no denylist. | Origin IP. |
| E7 | Cache rules / APO behaviour / purge coverage | memory `every-update-fires-the-full-purge-chain`; `inc/cloudflare-purge*.php` | **UNVERIFIABLE-HERE** / **PARTIAL** | Purge-on-publish and its verification log are tested (`tests/cloudflare-purge*.php`, `purge-verification-log`). Cache rules themselves are dashboard. | — |
| E8 | Worker code deployed = repo `main` | `get-deploy-status` | **ENFORCED** as detection | Live `source_commit` matches origin HEAD for all five today. Note `workers_list` shows `sn-analytics`, `sn-login-guard`, `sn-provenance` `modified_on` **2026-09-11 04:05–04:42Z**, ~24 h after their last commits — a settings/secret/binding change or a re-deploy that no repo records. | Dashboard edit. |
| E9 | WP-Cron is not in the request path | `inc/health-check-wp-cron-request-path.php` | **PARTIAL** | Detected by health scan; fix lives in `wp-config.php` + Cloudways cron optimiser (dashboard). | — |

### Layer 6 — Site and content operations (`juanlentino.com`)

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| S1 | Post-publish claim-changing edits carry a visible on-page correction | seed list; practised (two Notes carry "Correction, September 3, 2026.") | **HOPED-FOR** | No code. The provenance chain *mints a new version* on any prose change (visible in the record panel, `ledger_impact: new_version`), so an edit is **witnessed**, but nothing requires the correction block. `corpus_integrity` currently flags both existing correction lines as `date_coherence` info findings — the checker reads the practice as drift. | Edit without a notice. |
| S2 | No collisions between new content and the scheduled corpus | seed list; memory `design-against-the-scheduled-queue` | **PARTIAL** | `sn-validate` collision checks span `SNT_CORPUS_STATUSES` (incl. `future`) and are mandatory inside `sn-apply` `create_draft`. wp-admin has only the **advisory** pre-publish panel (`inc/pre-publish-gate.php`: "never calls lockPostSaving"). The `draft-echoes` / `near-duplicate-scan` reads are opt-in. | Publish from wp-admin. |
| S3 | Scheduling / publish discipline (drafts scheduled by hand; MCP never publishes a draft) | `sn-apply` description | **ENFORCED** for MCP / **HOPED-FOR** for wp-admin | `create_draft` is REVISION-ONLY; overdue-future refusal (`snt_sn_apply_schedule_overdue`); post_status re-asserted after block edits. wp-admin is WordPress. | wp-admin. |
| S4 | Tag hygiene (23-tag vocabulary, no new tags via MCP) | memory `tag-vocabulary-migrated-83-to-23` | **PARTIAL** | `create_draft.tags` = "existing vocabulary only" (gate 2); `health-check-tag-hygiene` detects. wp-admin creates tags freely; `wp_set_post_tags` creates on miss (memory). | wp-admin. |
| S5 | Cron health: 22 recurring jobs firing | `inc/watches.php`; `cron_health` | **ENFORCED** as detection | `cron_health.status: good`, 22/22 on schedule today; Site Health overdue rule; morning brief. Nothing *re-schedules* a lost hook automatically. | — |
| S6 | Watches ripen on state, not date | memory `a-watch-ripens-on-state-not-a-date` | **PARTIAL** | 7 pending, 0 ripe; 3 of 7 are `date_only: true` (nothing measured when they fire). | — |
| S7 | Every Note is signed, hashed, anchored, mirrored | README "Provenance" | **ENFORCED** as detection | `anchor: 41/41 confirmed`; `provenance_integrity: 10 checked, 0 failed` (sample of fleet 43 per sweep); ledger-CI health check; `sn-prov/v1/confirm` is Ed25519-verified (`inc/provenance-webhook.php:410-428`). The ledger repo (`signal-and-noise-provenance`, public) has **no ruleset**; the worker's GitHub token could rewrite history and only the next sweep would notice. | Force-push the ledger. |
| S8 | Content-only edits ship without a version bump | memory `content-updates-no-bump-straight-live` | **HOPED-FOR** | Judgment; `ci.yml` rule 2 exempts `docs/` and `tests/` from the CHANGELOG requirement but does not stop a bump. | — |
| S9 | Webmention receiver can only ever create `unverified` rows | `inc/citations-endpoint.php` | **ENFORCED** (tier) / **HOPED-FOR** (volume) | Public by protocol; SSRF host block; dedupe by `pair_hash`. **No origin rate limit** — unique `source` URLs are unbounded inserts; only an edge rate rule (unverified) would cap it. | Flood with unique sources. |

### Layer 7 — Release and repo discipline

| # | Rule | Source | Category | Enforcement mechanism | Bypass |
|---|---|---|---|---|---|
| R1 | Security review before squash merge | `~/.claude/CLAUDE.md`; memory | **HOPED-FOR** | `security-review.yml` runs on every PR (9–10 min) but **is not a required status check** (ruleset 17434571 requires exactly: CHANGELOG entry present, PHP syntax check, Test suite, WordPress Coding Standards, WordPress Plugin Check, Parity cron still firing). Admin bypass is `always`. **PR #1145 was merged past an `in_progress` security review on 2026-09-10** (memory `a-bounded-wait-loop-falls-through-to-the-action`). Direct pushes never trigger it (`on: pull_request` only). | `gh pr merge` before it finishes; direct push. |
| R2 | No direct pushes to main; PR flow | rulesets "Require PR on main" (plugin `17434571`, theme `17434570`) | **HOPED-FOR** for the owner | `bypass_actors: RepositoryRole admin, always`. 34 of the last 60 commits on plugin `main` are direct. Only `non_fast_forward` and `deletion` are un-bypassable (ruleset "Protect main"). | Routine. |
| R3 | Squash-merge only | memory `release-drafts-and-manual-deploy` | **HOPED-FOR** | Repo allows merge, squash **and** rebase (`allow_merge_commit: true`, `allow_rebase_merge: true`; ruleset `allowed_merge_methods: [merge, squash, rebase]`). Squash is a habit. | Pick another button. |
| R4 | Version bumps only for code and functional changes | `~/.claude/CLAUDE.md`; theme `docs/VERSIONING.md` | **HOPED-FOR** | `tools/cut-release.sh` refuses an **empty** `## [Unreleased]` and a dirty tree; nothing checks the bullets describe shippable paths. The observed cadence (v13.109.11→.21 in ~48 h) is one bump per merged fix, which contradicts the global "bumps only at END of session" line — the repo's README and memory `cross-session-release-routing` are the operative rule. | Cut anyway. |
| R5 | PATCH vs MINOR vs MAJOR classification | `~/.claude/CLAUDE.md`; memory `a-fix-is-a-patch-even-when-it-adds-code` | **HOPED-FOR** | Judgment at `cut-release.sh patch|minor|major`. | — |
| R6 | CHANGELOG on every commit | `~/.claude/CLAUDE.md` (seed) | **PARTIAL** | `ci.yml` "changelog" job (required): rule 1 (bump ⇒ `## [x.y.z]` heading), rule 2 (shippable path changed ⇒ +1 non-blank line under `## [Unreleased]`). Gaps, all documented in the job: PR-only (direct pushes exempt); `docs/`, `tests/`, `.github/`, `tools/` exempt; a reflow satisfies it; the `no-changelog` **label** skips it. "Every commit" is not what it checks — "every merged shippable PR" is. | Direct push; label. |
| R7 | Version header has a tag on main | `version-tag-parity.yml` | **ENFORCED** as detection | Daily cron + `tools/version-tag-parity.php --check`; its own liveness is guarded by the required "Parity cron still firing" job (a cron cannot witness its own absence). | — |
| R8 | Deploy only from a tag; deployed version == tag | `deploy.yml` | **ENFORCED** | Ref guard `v[0-9]*`; post-rsync `Version:` compared to `${TAG_REF#v}`. Emergency path only; the normal path is the WP updater reading tags (`inc/deploy-workers.php:156`). | — |
| R9 | Never skip the WP updater; no rsync to live | memory `never-skip-the-wp-updater` | **HOPED-FOR** | `deploy.yml` exists precisely to skip it (`workflow_dispatch`, `concurrency: deploy`). Owner rule in memory only. Theme deploy is rsync `--delete` to live (memory). | `gh workflow run deploy.yml`. |
| R10 | Third-party agent skills are not installed | `docs/adr/adr-0001-third-party-agent-skills.md` | **HOPED-FOR** | ADR only. | Install one. |
| R11 | Worker repos: reviewed before deploy | (none written) | **HOPED-FOR** | 0 rulesets on all five worker repos, the ledger repo, and the memory repo. Push = deploy (Cloudflare Builds). | Push. |
| R12 | Memory is committed and pushed | memory `memory-is-a-git-repo-commit-and-push` | **PARTIAL** | `Stop` hook in `~/.claude/settings.json` inspects the memory dir and warns; it does not block. | Ignore the warning. |
| R13 | Actions-minutes discipline (public = free) | `rules/github-actions-cost.md` | **ENFORCED** by construction | Plugin, theme, rights-signals, provenance ledger are **public**; the four private workers run short jobs with timeouts. | — |

---

## 2. Counts

**By category** (74 rows; a row carrying two grades is counted under the weaker one)

| Category | Rows |
|---|---|
| HOPED-FOR | 22 |
| PARTIAL | 25 |
| UNVERIFIABLE-HERE | 3 |
| ENFORCED | 24 |

**By layer**

| Layer | HOPED-FOR | PARTIAL | UNVERIFIABLE | ENFORCED | Total |
|---|---|---|---|---|---|
| 1 Plugin | 4 | 7 | 0 | 8 | 19 |
| 2 Theme | 3 | 3 | 0 | 2 | 8 |
| 3 MCP | 1 | 4 | 0 | 5 | 10 |
| 4 Analytics worker | 2 | 1 | 0 | 3 | 6 |
| 5 Edge / infra | 1 | 4 | 3 | 1 | 9 |
| 6 Site / content | 3 | 4 | 0 | 2 | 9 |
| 7 Release / repo | 8 | 2 | 0 | 3 | 13 |
| **Total** | **22** | **25** | **3** | **24** | **74** |

The shape is the finding: the **code** layers (plugin, MCP) are mostly enforced and well-pinned; the **process** layer (release/repo) is almost entirely hoped-for; and every rule that crosses a repo boundary drops one grade.

---

## 3. Seam analysis

### Plugin → REST API — *does not survive*

`POST /wp-json/wp-abilities/v1/abilities/<slug>/run` is registered by core for every ability with `show_in_rest => true`. That is all 8 rw-door slugs and the 7 legacy apply abilities. What a caller with any `manage_options` application password gets there, compared with the rw door:

| Control | rw door `/signal-noise/v1/mcp-rw` | abilities run route |
|---|---|---|
| rw kill switch (`sn_mcp_rw_enabled`) | yes | **no** (no `rest_pre_dispatch` guard for rw slugs; read and remote have one) |
| bound app-password UUID | yes | **no** |
| 30/min rate limit | yes | **no** |
| door audit row (`mcp-tools.php`) | yes | **no** (`sn-apply` writes its own enrichment row; the other 14 write nothing) |
| `sn-apply` four gates | yes | yes (in-ability) |
| owner-vs-routine mode grant | yes | yes (checks the UUID directly) |
| `sn-validate` on legacy applies | n/a | **never** (M1) |

The one thing standing between this and the internet is a Cloudflare WAF rule that exists in no repository (E3). The plugin's own comments at `inc/sn-apply/gates.php:26-30` assert the door always runs first — that is true of the door and false of the route.

### MCP read door → MCP write door — *structural, with one soft edge*

Two routes, two callbacks, two allowlists, one test suite pinning membership and size. The remote door is a third structure (14 twins that exist only as reads). The soft edge is `apply_filters('sn_mcp_allowlist')` / `('sn_mcp_rw_allowlist')`: any plugin or mu-plugin can move a slug between doors at runtime, and `tests/mcp-capabilities.php` tests the default arrays, not the filtered result on the live site.

### Theme → plugin — *survives, quietly*

Deactivating the plugin loses: SEO emission, login slug (origin falls back to `wp-login.php`), REST hardening (users/comments/batch come back; `content.rendered` returns), origin TDM headers, webmention receiver, provenance chip/panel (filter returns empty), discography/music (filters return `[]`), purge-on-save, all abilities and both MCP doors. Nothing fatals: every plugin call is filtered or `function_exists`-guarded (T5). What *does* keep working — the beacon (`theme inc/beacon.php`), the edge headers, the rights-signals worker — is exactly the set the plugin does not own, which is a good sign about the split. The silent part: no admin notice says "the plugin is off, these eight surfaces are gone".

### Origin → edge — *loses most of the rights surface*

Direct-to-origin (Cloudways IP, or a route-pattern miss) loses: CSP/HSTS/XCTO/XFO/Referrer-Policy (all five are Cloudflare-only by design, README "Security"); HTML `<meta name="tdm-reservation">`; `/.well-known/tdmrep.json`; `/license.xml`; the worker-authored `robots.txt`; Markdown content negotiation; web-bot-auth licence offers; the login-guard denylist; the beacon rate limiter; and every WebMCP/rights `_sn/` endpoint (the abilities Basic-auth block was listed here too until 2026-09-12, when it was found never to have existed — see E3). Survives: REST TDM headers (origin-owned since v9.83.0), route removal, rendered-field stripping, the origin login slug, all `permission_callback`s. The `Content-Signal` string is pinned in both repos and equal today; there is no test that reads both.

### Site → worker — *accepts more than the docs imply*

The beacon token is inlined into every page. Anyone can send well-formed beacons with the real `k`; the worker will accept up to 600/min per IP, dedupe nothing, and only the visitor-hash salt makes them look like distinct visitors. Bots that execute JS already do this. The worker does not check `Origin`/`Referer` against the zone, and `SN_SITE_HOST` is used to *classify* referrers, not to gate. Server-side calls (`SN_SRV_TOKEN`) and the provenance webhooks (HMAC, `X-SN-Signature`) are properly authenticated; the bridge is bearer-gated and unregistered when unarmed. The provenance ledger repo that the worker commits to has no branch protection.

### Local dev → production — *the widest seam*

Changes no repo, review, or changelog records:

- **wp-admin**: any post edit (bypasses every MCP gate — the fingerprint, the validator, the correction convention, the tag vocabulary); any plugin option (`sn_mcp_rw_enabled`, `sn_mcp_read_enabled`, `sn_mcp_remote_enabled`, the bound UUID, the roadmap board override, every settings tab); creating application passwords; installing plugins; editing templates in the Site Editor.
- **wp-config.php / server**: `SN_BRIDGE_TOKEN`, `SN_MCP_*_DISABLED`, `SN_TDM_*`, `DISABLE_WP_CRON`, a physical `robots.txt`.
- **Cloudflare dashboard**: the WAF rule that closes M4; the header Transform Rule; cache rules; APO; the WebMCP toggle; worker secrets, bindings and routes (`modified_on` on three workers moved 24 h after their last commit); Access policies on `mcp.juanlentino.com`.
- **GitHub settings**: both rulesets (including the admin bypass itself); required-check lists; repo merge methods; secrets (`SSH_PRIVATE_KEY`, `ANTHROPIC_API_KEY`, `CLOUDFLARE_API_TOKEN`).
- **Cloudways**: PHP version (8.4 vs CI's 8.3 pin), Varnish, cron optimiser, the app user.

The plugin's `configuration_drift` fact and `sn_site_facts` acknowledge-snapshot exist for the first bucket only.

---

## 4. Also report

### Rules that exist only in enforcement (no document explains them)

- **A suite with zero assertions is a failure** — `tests/run.sh`. Its header tells the 2026-08-11 story; nothing in README/docs does.
- **`no-changelog` label skips the CHANGELOG gate** — only in `ci.yml:369`.
- **CHANGELOG rule 2's exempt path set** (`inc/`, `blocks/`, `assets/`, main file are "shippable"; everything else is not) — `ci.yml:376`.
- **Job name "Parity cron still firing" is load-bearing** — renaming it makes every PR unmergeable (`ci.yml:434-437`); the ruleset matches exact strings.
- **Stub-parity: an unguarded call to a WP-shaped symbol core lacks fails CI** — `tools/stub-parity.php`; not in docs.
- **`tests/lib/` is not swept**; helpers there must ship their own `--self-test` — `ci.yml:186-197`.
- **`force_refresh` is stripped from two remote twins** and unknown keys are refused by `additionalProperties:false` — relies on core's normalize→validate→permissions order, which "this harness cannot pin" (`inc/abilities-remote-set.php:38-52`).
- **`show_in_rest => false` on remote twins** exists to deny a switch-state oracle — `abilities-remote-set.php:29-35`.
- ~~**The WAF Basic-auth block** — memory only; `.github/security-scan-instructions.md` does not mention it, so the security reviewer cannot reason about M4.~~ **Withdrawn 2026-09-12: the rule does not exist** (E3). The entry was itself the bug — an off-repo control asserted from memory, never measured. `security-scan-instructions.md` mentioned it as of 2026-09-11 and has been corrected.
- **Rate-limit errors fail open on the beacon** — `src/index.js:366-369`.

### Contradictions

| Document says | Enforcement / reality |
|---|---|
| `phpstan.neon`: "CI runs soft (non-blocking) for the first cycle" | `phpstan.yml` has no `continue-on-error` (it fails the job); the ruleset makes it non-required (soft in a different sense). |
| theme `CLAUDE.md`: "Drafts stay drafts forever" | Owner reversed to public-by-default 2026-09-10 (memory `release-drafts-and-manual-deploy`). |
| global `CLAUDE.md`: "Version bumps and tags only at END of session" | Plugin cuts one version per merged fix (11 cuts, ~48 h); README says a PR never bumps and a cut is a separate act. |
| global `CLAUDE.md`: "No file over ~150 lines" | 350/541 files exceed it; ECC says 800 max; 14 exceed that. |
| global `CLAUDE.md`: dark mode via `data-theme` | Theme `CLAUDE.md` waives it. |
| `inc/sn-apply/gates.php:26-30`: "an unbound or mismatched UUID is denied at the door, before sn_apply's execute_callback ever runs" | True via `/mcp-rw`; false via `/wp-abilities/v1/…/run`. |
| `docs/REST-HARDENING.md`: origin TDM header value "never observed" in production because the Worker overwrites it | Correct — and therefore `tests/rest-hardening.php` pins a value production never serves; the edge literal is the served one. |
| README: "96 plugin-registered Abilities" | 103 seen by the permission policy (v13.109.20); README also says the test suite, not the paragraph, is where the number is true. |
| `.gitattributes` export-ignores `.pre-commit-config.yaml` | File does not exist; no pre-commit hooks are installed (`core.hooksPath` unset, `.git/hooks` empty). |
| memory: "Security review before squash merge" | Not a required check; #1145 merged past it. |

### Dead enforcement

- `~/.claude/hooks/gsd-validate-commit.sh` — no-op without `.planning/config.json`; this repo has none.
- ~~`tests/js/*.mjs` never run in CI~~ **Corrected during Phase 3:** `prov-verify-core.test.mjs` and `desktop-mode-boot.mjs` are shelled out to by `tests/provenance-verify-core.php` and a sibling PHP wrapper, so they ARE in the sweep. The other five (`mobile-apps`, `analytics-composition`, `analytics-responsive`, `mobile-shell-geometry`, `window-geometry`) are Playwright browser harnesses — manual by design, and they say so in their headers. `tests/desktop-status-resilience.cjs` is likewise wrapped. The original row was a grep for a `node` step, not a read of the wrappers.
- `tests/contracts-smoke.php` — SKIPped in CI (documented; needs live WP). The only migration/DB-touching suite.
- `phpstan-baseline.neon` — 1 entry; the "climb later" in `phpstan.neon` has not happened (level 1).
- `sn_mcp_read_guard_run_route` / `sn_mcp_remote_guard_run_route` — correct code, but for any *external* caller the WAF rule (E3) refuses the request first; they matter only direct-to-origin. Not dead, but shadowed.
- `cron-orphan-cleanup.yml` — manual tool for hooks left by five plugins that are no longer installed; harmless, but it is a workflow with SSH secrets that exists for a one-time cleanup.
- Theme `smoke-test.yml` lint step pins PHP **8.2**; production is 8.4 and the theme's `ci.yml` lane already covers it — this one is a stale duplicate of a required check name ("PHP syntax check").
- The theme has no "every job declares a timeout" guard; the rule is enforced in one repo and copied by hand into the other.

### Undocumented surface (publicly reachable, in no doc outside changelog/proposals)

- `GET /wp-json/signal-noise/v1/desktop/discography` (`inc/desktop-mode-explorer.php:446`) — `manage_options`.
- `GET|POST /wp-json/signal-noise/v1/openstation/preferences` (`inc/openstation-preferences.php:189`) — `manage_options`.
- `GET /wp-json/sn-prov/v1/credential/{uid}` (`inc/provenance-credential.php:188`) — **public**, `__return_true`.
- `POST /wp-json/signal-noise/v1/bridge` — `show_in_index: false`, proposals only.
- `POST /wp-json/wp-abilities/v1/abilities/<slug>/run` for 15 write abilities — documented as "the Abilities REST route" in one README clause; the write list is nowhere.
- Edge: `/_sn/status`, `/_sn/verify`, `/_sn/version` (provenance); `/_sn/rights-signals/{version,taxonomy,crawler-list-status,machine-readers}`; `/_sn/login-guard/status`; `/_sn/remote-mcp/status`; `/webmcp/bridge.js`; `/ns/tdm`; `/tdm-policy`. `docs/MACHINE-READERS.md` covers part of this; the status/version endpoints are documented only in the workers' READMEs.
- `ai-pair-suggest`, `describe-tags`, `apply-tag-description`, `prune-unused-tags`, `unschedule-cron-event`, `purge-all-caches` on the rw door — listed as a count ("8 slugs") in README, never by name.

---

## 5. Top 5 gaps, by blast radius

**1. The write surface at `/wp-abilities/v1/…/run` is guarded by a dashboard rule and nothing else (M4, M1, E3).**
Blast radius: any leaked `manage_options` application password = every content and cron write, with no kill switch, no rate limit, no door audit, and — for seven of them — no validator. The WAF rule closes it for the internet today; it is unrecorded, unprobed, and gone on a direct-to-origin path.
Cheapest gate: a `rest_pre_dispatch` guard in `inc/mcp/mcp-rw-guard.php` mirroring `sn_mcp_read_guard_run_route` — refuse the abilities run route for any slug on `sn_mcp_rw_allowlist()` **or** carrying `destructive => true` / `readonly => false` unless `sn_mcp_rw_credential_authorize()` passes; ~40 lines plus a test. Separately, flip the seven legacy applies to `show_in_rest => false` (their in-plugin callers dispatch in-process, per the WAF memory's own trace).
Worth it: **yes**. It is the only HOPED-FOR row whose failure is silent, remote, and total, and it turns a dashboard dependency into code the security reviewer can read.

**2. Admin bypass on `Require PR on main`, and security review not required (R1, R2).**
Blast radius: everything CI promises is void for the person who ships 90% of the commits. #1145 is the measured instance.
Cheapest gate: two ruleset edits — add "Security Review" (job `security`) to `required_status_checks`, and change the admin bypass from `always` to `pull_request` (bypass only for PRs, never for pushes) — plus keep cutting releases via a PR from `tools/cut-release.sh` (the changelog job already handles the bump case).
Worth it: **partly**. Requiring the 10-minute security review on every PR is real friction for a one-line CSS fix; requiring it only on paths `inc/**`, `blocks/**`, `apps/**` (the review already filters by path) is the honest version. Removing the push bypass is worth it; the version-bump commit is the only thing that uses it and can be a PR.

**3. Worker repos have no protection and deploy on push (W5, R11).**
Blast radius: five production edge services (analytics, login defence, rights signals, provenance signing, the remote MCP door) accept an unreviewed push straight to the zone. The provenance worker holds the Ed25519 signing key.
Cheapest gate: one ruleset per repo — `pull_request` + required "Test suite" — copied from the plugin's, without the admin bypass. Two API calls each.
Worth it: **yes for `sn-provenance-worker` and `sn-remote-mcp-worker`** (keys and a door). For the other three it is a convention that has never been broken; add it when a second contributor exists.

**4. Beacon authenticity is a public token (W1, S9).**
Blast radius: analytics integrity, not security — a forged stream pollutes every rollup, insight and narration downstream, and nothing in the pipeline can tell. Same shape for unbounded webmention inserts.
Cheapest gate: none that is cheap and honest — a per-page HMAC nonce still ships to the client. The honest fix is *detection*: `reader-anomalies` and `shape_stability` already exist; a "beacons from IPs that never fetched HTML" ratio on the worker is a 20-line rollup.
Worth it: **no, as a gate**. Record it as a known ceiling; the site has no cookies by design and this is the price.

**5. The correction and collision conventions are unenforced at the wp-admin path (S1, S2).**
Blast radius: reader trust — a claim changes, the ledger mints a version, no reader sees why; a scheduled Note lands on a published one. Both are visible after the fact (ledger diff; `draft-echoes`) and neither is destructive.
Cheapest gate: for corrections, a `pre_post_update` check that refuses a `publish`→`publish` save on a Note whose normalized prose changes unless the post contains a `signal-noise/correction`-shaped block (or the batch carries one) — but that also blocks typo fixes, which is why it is not built. For collisions, a `transition_post_status` hook that runs `draft-echoes` and refuses above a threshold.
Worth it: **no**. These are editorial judgments made by one person; a hard gate would be tuned around within a week. Keep them conventions, but fix the instrument: the `corpus_integrity` `date_coherence` check should recognise a correction line as intentional rather than reporting the convention as drift.

---

## 6. What to verify yourself (dashboards I cannot read)

**Cloudflare → juanlentino.com**

1. **Security → WAF → Custom rules**: `Block Basic-auth on abilities API` **does not exist** (confirmed by the owner and by the live probe, 2026-09-12). To create it: action Block, expression `(http.request.uri contains "wp-abilities" and any(http.request.headers.names[*] == "authorization"))`. Match on `http.request.uri`, **not** `http.request.uri.path` — the path form leaves `/?rest_route=/wp-abilities/v1/...` open, and the health probe cannot see that difference (it probes the `/wp-json/` spelling only). Until it exists, gap 1 has no edge layer at all.
2. **Rules → Transform Rules → Modify Response Header**: the five security headers — read the **CSP value**, since the plugin probe checks presence only.
3. **Rules → Cache Rules** and **Speed → APO**: which paths bypass cache (`/wp-json/*`, `/_sn/*`, `/sn-login*`). Memory notes Speed Brain blocked MCP OAuth once.
4. **Agent Readiness / WebMCP toggle**: OFF (E1). **Managed robots.txt / Content Signals**: OFF (the worker authors `robots.txt`; a managed one would double the line).
5. **Workers & Pages → each `sn-*` worker → Deployments and Settings**: why `sn-analytics`, `sn-login-guard`, `sn-provenance` show `modified_on` 2026-09-11 04:05–04:42Z, a day after their last commit (secret rotation? binding edit? re-deploy?). Confirm each has Builds connected to `main` and no dashboard-edited code.
6. **Zero Trust → Access → Applications → `mcp.juanlentino.com`**: policy = the owner identity + the service token only; JWT `aud` matches the worker's `ACCESS_AUD`.
7. **Security → WAF → Rate limiting**: whether anything caps `POST /wp-json/signal-noise/v1/webmention` (S9) and `/wp-json/*` generally.

**WordPress → wp-admin**

8. **Users → Application Passwords** for every admin user: how many exist, and which one is the bound rw UUID (`Tools → MCP`). Every *other* `manage_options` app password is a key to gap 1.
9. **Tools → MCP**: the three switches (`read`, `rw`, `remote`) match what you believe; `SN_MCP_*_DISABLED` constants in `wp-config.php`.
10. **Plugins**: no mu-plugin registers `sn_mcp_allowlist` / `sn_mcp_rw_allowlist` / `sn_apply_granted_modes` / `snt_rest_hardening_policy` filters (M4, M6, P12).
11. **Site Health → Info**: `DISABLE_WP_CRON` true and the Cloudways cron optimiser present (E9).

**GitHub → Settings**

12. **Rules → Rulesets** on both main repos: the admin bypass (`always`) is what you want; the required-check list is exactly the six/five contexts listed in R1 — PHPStan, Gitleaks, Semgrep, Security Review, Claude Review and the PHP matrix lanes are **not** among them.
13. **Rulesets on the five worker repos, the ledger repo, the memory repo**: none exist.
14. **Settings → General → Pull Requests**: merge commits and rebase merges are still allowed (R3).

**Cloudways**

15. PHP version (8.4 per memory; CI pins 8.3 with an 8.4 parity lane), whether a physical `robots.txt` exists in `public_html` (E5), and whether the origin IP is reachable directly (the whole of the origin→edge seam).
