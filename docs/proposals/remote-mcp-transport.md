# Remote MCP transport + OAuth (R3 §3D) — design proposal

**Status:** SCOPING ONLY — nothing implemented from this document.  
**Audited against:** `origin/main` @ `3211713` (v10.99.2).  
**External docs read:** 2026-08-12.  
**Target version if built:** v11.0.0 (MAJOR — setup requires user action).  
**Question:** what OAuth + transport layer would make a **phone-reachable, analytics-only MCP read door** safe enough to ship — and under what findings should it not be built?

This is not a proposal to reopen drafts on the phone. Corpus-spanning reads are out by construction. This is the transport and credential design for a **named, curated set of ~N read-only analytics/stats tools**, reached from Claude mobile/web.

---

## Prior art

Roadmap 3D and adversary **A5** (hostile caller who reaches a brokered entry without the laptop) are already modelled in the agent-surface threat model.

| Source | What it settled |
| --- | --- |
| `docs/security/agent-surface-threat-model.md` §8.1–§8.4 | 3D is a **different adversary**, not a wider laptop. Assets include draft/scheduled bodies on the full read door. Edge that cannot see `sn_mcp_read_enabled` must not fail open. Audit must name the brokered session. |
| Same doc §8.5 | **Do not build the broker next.** Fix F2 and the missing read ceiling first; then reconsider broker vs scoped token. |
| Same doc §8.6 | **F2 CLOSED** (2026-08-11): `sn_mcp_read_guard_run_route()` on `rest_pre_dispatch` applies the read kill switch to native run-route slugs on the read allowlist. |
| Same doc §8.7 | **F1 BOUNDED, not closed:** 120/min/identity ceiling on both MCP read and native read-run paths; **fail-open** when the store is unavailable (`null` count → allow). A brokered path must fail **closed**. |
| Same doc §8.8 | **DECLINED the edge broker holding a credential** (2026-08-11). Disposition: edge broker = **No**; scoped expiring read-only token = **Not yet** (right shape, no named user story); outbound digest = cheaper alternative. |
| Same doc §8.8 reopening condition | Build **only if** there is a **concrete, repeated phone task that requires an agent reading the corpus** — then build the **scoped token**, not the broker: named subset, short expiry, revocable from wp-admin, **fail closed** on that path. |
| `docs/r3-prep.md` §3D | Same row in prep form: edge entry that brokers sign-in; kill switch must reach the edge; do not create a second MCP endpoint that quietly holds a secret. |
| `docs/proposals/render-scan-deterministic.md` | House format for R3 proposals: Prior art → Options → Increments → Kill criteria → Open questions. This document follows that shape. |

### Why this proposal exists after a correct decline

§8.8 declined the **broker-holding-credential** shape, not “phone never sees numbers.” Owner direction (2026-08-11 / this brief):

- MCP on Claude mobile/web is **analytics/stats only**.
- **Writes stay on the desktop app** — settled, not revisited.
- Drafts-adjacent reads (`get-post-content`, `list-posts`, `sn-posts`, body-scanning tools) are **out by construction**.

That is a **narrower reopening** than the original 3D sentence. It matches §8.8’s “scoped token, named subset” shape more than it matches “edge holds the application password.” The prior decline remains correct for the object it declined.

Whether the reopening condition is fully met is still an **owner call** (agent + analytics vs push digest). This proposal scopes the transport **if** that call is yes.

---

## Survey

What exists today on `origin/main` @ `3211713`. Every claim below was read in source.

### MCP doors

| Surface | Route | Auth floor | Notes |
| --- | --- | --- | --- |
| Read door | `POST /wp-json/signal-noise/v1/mcp` | `sn_mcp_read_permission` → kill switch then `manage_options` | `inc/mcp/mcp-endpoint.php:157-171`, `inc/mcp/mcp-read-guard.php:336-345` |
| Write door | `POST /wp-json/signal-noise/v1/mcp-rw` | kill switch → `manage_options` → bound app-password UUID | `inc/mcp/mcp-endpoint.php:182-189` area; rw guard separate file |
| Native abilities run-route | `…/wp-abilities/v1/abilities/<slug>/run` | **per-ability `permission_callback` only** | Documented in `docs/security/rest-audit-2026-08-03.md` §0; path regex in `inc/mcp/mcp-read-guard.php:41-55` |

`sn_mcp_permission()` is explicitly “administrator authenticated via application password” language in the endpoint file (`inc/mcp/mcp-endpoint.php:50-56`). There is **no OAuth**, **no Bearer token principal**, and **no remote-specific door**.

Protocol fallback version is `2025-06-18` (`inc/mcp/mcp-capabilities.php:17-21`). Transport is **WordPress REST POST of JSON-RPC**, not a dedicated Streamable HTTP MCP endpoint.

### Read allowlist (full door — 38 tools)

`sn_mcp_allowlist()` at `inc/mcp/mcp-capabilities.php:63` returns the curated read set. Analytics-shaped entries already present include (non-exhaustive of the full 38):

- `signal-noise/get-analytics-summary` (`:69`)
- `signal-noise/get-analytics-events` (`:75`)
- `signal-noise/get-insights` (`:73`)
- `signal-noise/get-narration` (`:74`)
- `signal-noise/get-rss-stats` (`:70`)
- `signal-noise/get-health-scan` (`:66`)
- `signal-noise/uptime-status` (`:67`)
- `signal-noise/get-deploy-status` (`:68`)
- `signal-noise/get-cron-history` / `list-cron-events` (`:71-72`)

**Drafts-adjacent / corpus tools also on the same allowlist** (must not ride a phone token):

- `get-post-content`, `list-posts`, `duplicate-body-scan`, `near-duplicate-scan`, keyword/link candidates, `sn-posts`, `sn-scan`, etc. (`inc/mcp/mcp-capabilities.php:85-127` region).

The MCP call gate is `sn_mcp_is_allowed()` per door (`inc/mcp/mcp-tools.php` / `mcp-capabilities.php:275-276`). That gate is **MCP-only**. The REST flank is independent (below).

### Ability permissions (the real gate)

Analytics abilities use `snt_ability_perm_manage_options` → `current_user_can( 'manage_options' )`:

- Helper: `inc/abilities-permission-helpers.php:40-41`
- `get-analytics-events`: `inc/abilities-analytics.php:40`
- `get-analytics-summary`: `inc/abilities-analytics.php:80`
- Both register `'show_in_rest' => true` (`inc/abilities-analytics.php:54`, `:115`)

So a caller who is a `manage_options` user reaches these via:

1. MCP read door (allowlist + floor), and  
2. Native run-route **without** the MCP allowlist (`docs/security/rest-audit-2026-08-03.md` §0).

There is also a family of native analytics REST routes gated by `sn_analytics_rest_can_read` / `manage_options` (`docs/security/rest-audit-2026-08-03.md` §1 table: `analytics/series`, `dimension`, etc.).

**Consequence for any brokered token that becomes a full admin identity:** the token reaches **every** `manage_options` ability and admin REST route, not only the MCP tool list. Scoping tools/list alone is a door with an open window.

### F1 — read rate ceiling (fail-open)

- Cap: `SN_MCP_READ_RATE_LIMIT_PER_MINUTE = 120` (`inc/mcp/mcp-read-guard.php:71-73`)
- Store: object cache group or transient (`:110-116`, `:127-133`)
- Decision path: null count becomes 0 and allows (`:151-163`)
- Applied on MCP `/mcp` **and** native read-allowlisted run routes via `rest_pre_dispatch` priority 11 (`:274-294`)
- Kill switch is priority 10, same hook (`:296-297`) so “closed” beats “slow down”

Docblock states the brokered path must fail closed first (`:151-155`), matching threat model §8.7.

### Kill switches

| Switch | Meaning | Fail-on-absence |
| --- | --- | --- |
| Option `sn_mcp_read_enabled` / constant `SN_MCP_READ_DISABLED` | Read path dark | **Fail-open on absence** (untouched = on) — `inc/mcp/mcp-read-guard.php:25-26`, `:321-326` |
| Edge observability of that switch | Not implemented (no broker) | Threat model §8.3 precondition 4: edge that cannot read it must not assume open |

### Telemetry / audit

- **Both doors:** shape telemetry `sn_mcp_telemetry_record()` from `sn_mcp_call_tool()` (`inc/mcp/mcp-telemetry.php` header; call sites in `mcp-tools.php`) — not a session identity for A5.
- **RW only:** `sn_mcp_rw_audit_record()` with redacted args — read door has no equivalent forensics trail of *which session*.

Threat model §8.3 precondition 5 still open for any brokered path.

### Edge estate already in use

`inc/health-edge-workers.php` documents four Workers in the health check surface: **sn-analytics**, **sn-login-guard**, **sn-provenance**, **sn-rights-signals**. The machine-readers path already uses a **Bearer read token** to a Worker endpoint (`docs/MACHINE-READERS.md` sensor contract: `Authorization: Bearer <SN_MR_READ_TOKEN>`). That is prior art for **scoped edge credentials that are not WordPress application passwords**.

### Outbound alternative already shipping

`inc/security-digest.php` is deterministic, opt-in, weekly, no AI — the §8.8 “push not pull” pattern. Analytics attention digest is a separate product question; transport design should not pretend push is free of tradeoffs, only that it exists as an alternative threat surface.

---

## Constraints

Hard constraints from the brief and from prior art. Violating any makes the design useless.

1. **F1 must fail closed on any brokered path.** Local ceiling may stay fail-open for laptop + application password. Brokered path may not degrade to that default.
2. **Token scoped AND expiring.** Never an always-on broker credential. Site must be able to rotate and revoke without redeploying Anthropic.
3. **Abilities are REST-reachable.** MCP allowlist is not sufficient. Permission model must hold on native run-route and adjacent analytics REST.
4. **Infrastructure:** WordPress on Cloudways; Cloudflare in front; Workers available; **no long-running processes on the WP host**.
5. **Writes / drafts out of scope.** Desktop retains write door. Phone slice excludes drafts-adjacent abilities by construction.
6. **GitHub Actions:** public repo minutes are free, but do not design CI that would be copied into private repos as multi-job tax; no browser CI, no per-PR multi-minute matrix for this feature.
7. **v11.0.0 MAJOR** if shipped — owner setup action required (connector + OAuth + revoke surface).

---

## Transport findings

### Claim under verification

> Claude mobile/web “Add custom connector” is remote + OAuth only; WordPress application passwords can never work there.

### What current Anthropic docs say (read 2026-08-12)

**Claude Help Center — custom connectors / remote MCP**  
URL: https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp  
Article `dateModified` in page metadata: **2026-08-11**.

Read claims:

- Custom connectors using remote MCP are available on Claude, Cowork, Claude Desktop, and **mobile apps** (Free/Pro/Max/Team/Enterprise; Free limited to one custom connector).
- When you add a custom connector, **Claude connects to the remote MCP server from Anthropic’s cloud infrastructure**, not from the local device — so the server must be **internet-reachable**.
- Setup: supply the **remote MCP server URL**; optionally **Advanced settings → OAuth Client ID and OAuth Client Secret**.
- Security section: adding a connector **typically** runs an **OAuth** sign-in so Claude never sees the application password; permissions revocable by disconnecting the connector.

**Not stated in that article (important negatives):**

- No support for WordPress application passwords / HTTP Basic.
- No documented “paste a static API key” path for DIY custom connectors in the consumer UI.
- OAuth client id/secret is **optional advanced** — implying discovery/DCR/CIMD paths may also work when the server speaks the MCP auth profile (see below), but **not** that unauthenticated private data is acceptable.

**Claude Platform — MCP connector (Messages API)**  
URL: https://platform.claude.com/docs/en/agents-and-tools/mcp-connector  
Read 2026-08-12.

- Remote MCP servers must be **public HTTPS**; supports **Streamable HTTP and SSE**.
- Local STDIO cannot be used.
- Auth field is `authorization_token` — described as **OAuth authorization token** if the server requires it; API consumers obtain/refresh tokens themselves.
- Spec pointer: MCP authorization (linked as 2025-11-25 family).

This is the **API** product surface, not identical to claude.ai mobile UI, but it confirms Anthropic’s remote MCP posture: **HTTPS remote transport + Bearer/OAuth token**, not WP app passwords.

### What the MCP specification requires for remote auth (read 2026-08-12)

**Authorization (spec version 2025-11-25)**  
URL: https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization  

- HTTP transports **SHOULD** use this OAuth profile; STDIO should not.
- MCP server is an **OAuth 2.1 resource server**; client is OAuth client; AS may be colocated or separate.
- Based on OAuth 2.1, RFC8414 (AS metadata), RFC7591 (DCR), RFC9728 (Protected Resource Metadata), Client ID Metadata Documents.
- Discovery: **401 + `WWW-Authenticate` with `resource_metadata`**, and/or `/.well-known/oauth-protected-resource…`
- Tokens presented as Bearer; short-lived tokens recommended in tutorial guidance.
- Tutorial (https://modelcontextprotocol.io/docs/2026-07-28/tutorials/security/authorization): authorization code + **PKCE**; Streamable HTTP SDK patterns.

**Transports (2025-11-25)**  
URL: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports  

- Standard remote transport is **Streamable HTTP** (POST/GET; optional SSE streaming).
- Replaces older HTTP+SSE transport from 2024-11-05; servers may keep SSE for compatibility.
- Origin header validation required for Streamable HTTP (DNS rebinding).

**Plugin reality vs remote MCP:** today’s `/wp-json/signal-noise/v1/mcp` is a **REST-hosted JSON-RPC POST** with **application-password identity**. It is not an OAuth resource server, does not advertise PRM, and is not advertised as a Claude custom-connector URL shape. **[inferred]** Claude’s connector client will not complete WordPress application-password Basic auth as a substitute for MCP OAuth — nothing in the Help Center or Platform docs describes that path, and the Help Center explicitly frames connector auth as OAuth so Claude never sees the password.

### Verdict on the transport claim

| Sub-claim | Verdict | Notes |
| --- | --- | --- |
| Remote URL required for mobile/web connector | **Confirmed** | Anthropic cloud egress; server must be public HTTPS. |
| OAuth is the expected auth for private data | **Confirmed as “typically” / platform Bearer-OAuth** | Help Center OAuth language; Platform `authorization_token`; MCP OAuth 2.1 RS profile. |
| Application passwords work in Claude custom connector UI | **No evidence; treat as unsupported** | Not documented; incompatible with “Claude never sees your password” framing and with MCP PRM/OAuth discovery. |
| Auth is *only* OAuth forever | **Slightly softer than the 27-day-old note** | Help Center makes Client ID/Secret *optional advanced*; MCP allows unauthenticated servers when data is public. For **private analytics**, designing without OAuth (or without Bearer tokens obtained via OAuth) is still a dead end. Third-party writeups mention additional directory auth types (`static_headers`, etc.) for **catalog** connectors; those were **not** verified as available to owner-added custom connectors on claude.ai — treat as **[unverified / out of scope]** unless re-checked against a live UI. |

**Bottom line for design:** the 27-day-old note is **still directionally correct**. Remote + OAuth (or OAuth-obtained Bearer) is the path. WordPress application passwords do not become a mobile connector auth. A design that only “exposes `/mcp` on the internet” without an OAuth resource-server layer does not meet Claude mobile/web.

**Protocol version gap to plan for:** plugin fallback `2025-06-18` vs connector ecosystem moving through `2025-11-25` and a 2026-07-28 RC that stresses stateless Streamable HTTP. The remote surface should negotiate versions explicitly and not assume WP REST JSON-RPC alone is enough for Claude’s client.

---

## Options considered

### Option A — Open existing `/mcp` to the internet; keep application passwords

**Idea:** Cloudflare path to `signal-noise/v1/mcp`; owner pastes URL into Claude.

**Killed by:** Claude custom connector does not speak WP application passwords (Transport findings). Also expands A5 onto the **full 38-tool read allowlist including drafts** with fail-open F1.

### Option B — Edge broker that stores a WordPress application password and proxies all read tools

**Idea:** Worker holds admin app password; Claude OAuth’s to Worker; Worker calls origin as admin.

**Killed by:** Threat model §8.8 disposition table (“Edge broker holding a credential → **No**”). Permanent second secret, not rotatable as a session; REST flank becomes entire `manage_options` surface; drafts asset rides along unless Worker reimplements allowlist **and** origin still accepts the stored admin identity on run-route.

### Option C — Full OAuth Authorization Server inside the WordPress plugin

**Idea:** PHP implements PRM, AS metadata, authorize, token, DCR/CIMD; `/mcp` becomes resource server.

**Killed by:**

- No long-running process on Cloudways WP host; OAuth AS is doable request-scoped but **session/consent UX, key management, DCR abuse, and AS availability** sit on the same host as the CMS — worst place for a new internet-facing auth system.
- Still must solve Streamable HTTP / Claude transport expectations that are awkward behind WP REST and page caches.
- Kill switch / rate limit / token store on fail-open transients re-imports F1 open failure mode unless redesigned.

WP can still **mint and revoke** tokens (owner UX); it should not **be** the public AS+MCP edge.

### Option D — Cloudflare Worker as remote MCP resource server + OAuth AS (or AS sidecar); origin never sees Claude

**Idea:** Claude talks **only** to `https://…/mcp` on a Worker. Worker speaks Streamable HTTP + OAuth 2.1 (PRM, AS metadata, auth code+PKCE, short-lived access tokens). Worker fulfills tools by calling **origin over a separate, non-admin channel**.

**Tradeoff that can kill it:** Worker supply chain (threat model R-3D-b); must not hold a long-lived WP admin secret; must fail closed on rate store and kill-switch probe.

**Why it survives if built carefully:** matches infrastructure (Workers already in estate); matches Claude remote model; matches §8.8 “scoped token not broker”; phone kill can hit Worker KV/flag without laptop if designed that way.

### Option E — Push-only (extend digest / email / notification); no inbound MCP

**Idea:** §8.8’s cheaper shape. No A5 inbound door.

**Killed as the answer to *this* brief’s stated want** if the owner insists on **agent-interactive** stats on phone (ask follow-ups, compare ranges). Survives as the correct answer if the real want is “know what happened this week without a laptop.” **Not killed technically** — product kill only.

### Option F — Third-party hosted MCP OAuth gateway

**Idea:** SaaS MCP auth proxy in front of the site.

**Killed by:** third place holding reach into the site; cannot meet “revoke from wp-admin / phone without depending on vendor”; supply chain outside the existing Cloudflare estate.

### Recommended shape (composite of D + site-side scoped token)

**Name:** **Remote analytics MCP edge** (not “the read door from anywhere”).

```
Claude mobile/web
    │  Streamable HTTP + OAuth Bearer
    ▼
Cloudflare Worker  (MCP resource server + OAuth AS metadata)
    │  short-lived, scope-bound access token validated at edge
    │  fail-closed rate limit (edge store)
    │  kill flag readable at edge
    ▼
Origin bridge (one of two concrete bindings — pick in Increment 1 design spike)
    ├─ Binding α: Worker → origin with **HMAC-signed** requests; origin maps to
    │    a non-admin “remote analytics” principal whose permission callbacks
    │    allow ONLY the named ability slugs (and matching analytics REST if any).
    └─ Binding β: Worker holds **only** a site-minted, rotating, ability-scoped
         origin token (hash stored in WP); never a WP application password;
         token rejected if expired/revoked/scope miss.
```

**Non-negotiables in this shape:**

1. **Claude never receives a WordPress application password.**
2. **Edge never stores an application password.**
3. **Token scopes encode the named ability set** (e.g. `sn.analytics.read`), not `manage_options`.
4. **Origin permission callbacks** for those abilities must accept the remote principal **without** granting general admin REST. Prefer a dedicated capability or explicit allowlist check inside a new callback used only by remote-gated abilities — do not reuse `snt_ability_perm_manage_options` alone for the remote principal.
5. **MCP tools/list on the remote server advertises only the named set.** Origin full read door stays laptop/app-password as today.
6. **F1 brokered counter lives at the edge** (Worker KV / Durable Object / rate-limit binding). If the store is unavailable → **429/503 deny**, never allow.
7. **Kill switch:**
   - Edge flag (phone-reachable revoke URL or CF dashboard / email link that flips KV) for **immediate** stop without laptop.
   - Origin option still darkens origin bridge; if edge cannot confirm origin kill state, **fail closed** (refuse), opposite of `sn_mcp_read_enabled` absence semantics on local.
8. **Session identity in telemetry** on every brokered call (threat model §8.3.5).

### Token lifecycle (owner-physical)

| Step | Who | What |
| --- | --- | --- |
| **Issue** | Owner in wp-admin (v11 setup) | Enables “Remote analytics MCP”; sets redirect allowlist; Worker deployed with site public key / shared HMAC; optional pre-reg OAuth client id/secret for Claude Advanced settings. |
| **Authorize** | Owner on phone | Claude connector → OAuth consent page (hosted on Worker or tiny WP page behind existing login for **consent only**, not token mint as AS of record). Grants `sn.analytics.read` (name TBD). |
| **Access token** | AS | Short-lived (minutes–hours). Refresh token optional; if present, **rotating** refresh, absolute session cap (e.g. 7–30 days). |
| **Scope** | AS + RS | Fixed named ability set; no draft tools; no write door; no `mcp-rw`. |
| **Rotate** | Owner or scheduled | Origin “rotate bridge secret” invalidates all sessions; OAuth clients must re-auth. |
| **Revoke (hurry)** | Phone-first | (1) Disconnect connector in Claude settings (Help Center). (2) **One-tap revoke all remote sessions** link that hits Worker (signed magic link or CF Access). (3) wp-admin list of sessions. (4) `SN_MCP_READ_DISABLED` / remote-specific constant as last resort — laptop. |
| **Kill switch that requires only a laptop** | Insufficient alone | Allowed as belt; **not** the primary A5 control. |

### F1 fail closed — concrete

| | Local read ceiling (today) | Brokered remote path (required) |
| --- | --- | --- |
| Counter location | WP object cache / transient (`mcp-read-guard.php:110-133`) | **Edge durable store** (KV/DO) keyed by OAuth client + subject + window |
| Unavailable store | null → 0 → **allow** (`:163`) | null/error → **deny** (fail closed) |
| Cap | 120/min (may stay) | Stricter remote cap **[owner pick]** e.g. 30/min; separate from local |
| Bypass if edge down | N/A | Origin bridge refuses unauthenticated direct Claude hits; no public origin MCP for remote clients |

Why this cannot silently become fail-open: the allow path requires a successful counter increment acknowledgment (or an atomic rate-limit API success). Timeouts and errors map to deny. Tests must red if anyone ports `null === $count ? 0` into the remote gate.

### REST flank — concrete

A leaked remote access token must **not** authenticate as a general `manage_options` user on:

- `POST /wp-json/wp-abilities/v1/abilities/signal-noise/get-post-content/run`
- `POST …/sn-apply/run` or any rw ability
- Admin AJAX / other plugin routes

**Enforcement stack:**

1. **Edge:** only implements/proxies named tools; no general HTTP reverse proxy to `/wp-json/`.
2. **Origin bridge auth:** distinct from application passwords; validated only on bridge endpoints or on ability callbacks that check remote scope.
3. **Ability callbacks for remote-exposed slugs:** either split remote-safe execute paths or add `snt_ability_perm_remote_analytics()` that requires remote scope claim **and** refuses when the request is not bridge-authenticated.
4. **Native run-route:** if a remote principal could ever hit it, the same callback must refuse out-of-scope slugs. Prefer **never minting a cookie/app-password for that principal**.
5. **Regression tests:** stolen remote token matrix against draft tools, rw door, and `manage_options` REST — all must 401/403.

### Blast radius (if token leaks)

Assume access token + still-valid refresh until expiry/revoke.

**Can read (illustrative analytics slice — final N chosen elsewhere):**

- Aggregate analytics summaries and events (ranges, classes already supported by abilities).
- Cached insights / narration prose (operational commentary — not draft posts, but still owner-voice content).
- RSS stats, uptime, deploy status, health scan **summary** (findings counts/labels — not post bodies).
- Cron history metadata (hooks/timings — low sensitivity but ops picture).

**Cannot reach (by construction of scope + bridge):**

- `get-post-content`, `list-posts`, `sn-posts`, body/duplicate/near-duplicate/candidate scans.
- Entire MCP **write** door and `sn-apply` / AI apply tools.
- Application passwords, user email export, audit-log plaintext routes (rw-gated / admin).
- Arbitrary WP admin, plugin install, options write.
- Other sites on the Cloudflare account (Worker scoped to this zone/route).

**Residual even in the safe slice:** traffic patterns, revenue-ish engagement numbers, outage state, and narration text that may mention content titles. That is acceptable only if the owner accepts analytics confidentiality class for phone risk.

### Failure modes (“if the thing broke, would this number move?”)

| Failure | Healthy-looking? | Detection |
| --- | --- | --- |
| Edge rate store fails open (bug) | Yes — traffic continues | Synthetic probe that **forces** store error must see deny; alert on probe. |
| Origin kill switch on, edge still serves cached tool results | Yes if Worker caches bodies | No body cache for sensitive tools, or cache key includes kill epoch; edge revalidates kill flag every request. |
| Scope allowlist drifts wider than owner intent | Yes — more tools appear in tools/list | Pin remote allowlist in tests; tools/list snapshot test. |
| Bridge secret leaked | Yes until rotate | Treat like production incident; rotate invalidates all; audit bridge auth failures. |
| Claude disconnects but refresh still valid server-side | Partial | Server-side session list + absolute TTL; do not rely only on Claude UI disconnect. |
| Telemetry table insert fails | Yes (telemetry is fail-open by design today) | Separate **security** log for remote sessions must not share telemetry’s fail-open posture — remote auth events fail closed or page the owner. |
| “0 remote calls” while phone works via unexpected path | Yes | Only one ingress path; origin rejects non-bridge remote-shaped traffic; monitor bridge vs `/mcp` app-password volume separately. |

---

## Increments

Smallest shippable slices. Stop after any increment if kill criteria trip.

### Increment 0 — Transport proof only (no analytics value)

**Ship:** Worker URL that speaks Streamable HTTP MCP with OAuth discovery; **one** tool `sn_remote_ping` returning `{ ok: true, ts }` with no site data; fail-closed rate limit; phone OAuth through Claude custom connector.

**Proves:** Claude mobile/web can complete remote MCP + OAuth against *this* estate.  
**Does not prove:** data safety, REST flank, or product value.  
**Exit:** connector works end-to-end on phone; disconnect + edge revoke both stop further calls within one access-token TTL.

### Increment 1 — One real analytics tool + origin bridge

**Ship:** `get-analytics-summary` only (or equivalent single ability); bridge auth; permission callback that is not full admin; session id in logs; edge kill flag; absolute session TTL.

**Proves:** scoped data path without drafts; REST flank tests for that one slug.  
**Exit:** stolen-token test cannot call `get-post-content` or `/mcp-rw`.

### Increment 2 — Named set (~N tools)

**Ship:** owner-chosen analytics/stats set (session decides membership; design only requires a **named list constant**). tools/list advertises exactly that set. Cap + audit per tool.

### Increment 3 — Phone-first revoke UX

**Ship:** magic-link / email “kill remote MCP now”; session list in wp-admin; Claude disconnect documented in runbook.

### Increment 4 — Hardening pass

**Ship:** stricter caps, anomaly alerts (volume as signal — R-3D-c), dependency pin for Worker, kill-switch probe in health check (mirror `inc/health-edge-workers.php` pattern).

**Do not ship in v11.0.0:** write tools, draft tools, DCR open to the world without rate limits, caching of tool results across kill, or CI browser jobs.

---

## Threat model delta

If this ships, update `docs/security/agent-surface-threat-model.md` §8 (model → partial implementation) with:

| ID | Change |
| --- | --- |
| A5 | Enters scope on the **remote analytics** surface only — not on laptop app-password `/mcp`. |
| F1 | Closed **for brokered path** via edge fail-closed counter; local path may remain fail-open ceiling. |
| F2 | Already closed; remote path must not reintroduce single-route kills. |
| F3 | Reframed: edge holds **OAuth session material + bridge secret**, never WP application password; still a new secret — inventory it. |
| R-3D-a | Mitigated by short access TTL + rotating refresh + absolute session cap + phone revoke. |
| R-3D-b | Worker deps in health/dependency process; minimal dependencies. |
| R-3D-c | Named subset only; volume alerts. |
| §8.8 disposition | “Scoped expiring token” moves from **Not yet** to **In progress / Shipped** only after Increments 0–2; broker-holding-app-password remains **No**. |

New residual risks to name when implementing:

- **R-3D-d — Claude-cloud egress.** Anthropic’s infrastructure becomes a hop that sees tool results (Help Center: server reached from Anthropic cloud). Confidentiality class is “shared with Anthropic for that session,” not “only my phone.”
- **R-3D-e — Consent UX phishing.** Fake OAuth pages; mitigate with fixed AS host, known Worker hostname, and no email links carrying bearer tokens.

---

## Kill criteria

Stop and **do not build further** (or roll back the remote surface) if any of these hold:

1. **Claude custom connector cannot complete OAuth against a Worker AS** without storing a WP application password or running an AS on the WP host — transport proof (Increment 0) fails.
2. **No phone-reachable revoke** that stops traffic within one access-token TTL without a laptop — A5 without a kill switch.
3. **Any design that authenticates the remote caller as full `manage_options`** on origin (application password or admin cookie) — REST flank is open by construction.
4. **Fail-open rate limit** on the brokered path survives code review or incident — F1 not closed.
5. **Drafts or corpus tools** appear on the remote tools/list or become reachable with the remote token — asset class §8.8 refused.
6. **Edge must hold a non-rotatable secret equivalent to admin** to function — reverts to declined broker.
7. **Owner use case collapses to push notifications** after Increment 0–1 — prefer digest extension; delete the inbound door rather than maintain it.
8. **Healthy telemetry while bridge accepts unscoped calls** — cannot answer the house diagnostic question; unsafe to operate.

A valid successful outcome of this scoping effort is: **build Increment 0 only, then stop** — or **build nothing** and extend outbound digest instead. That is consistent with the 2026-08-11 decline.

---

## Open questions for the owner

1. **Is the reopening condition met?** Is “ask Claude on my phone about this week’s stats” a repeated task that **requires an agent**, or would a richer weekly email/push digest be enough?
2. **Confidentiality class of analytics + narration:** acceptable in Anthropic’s cloud hop (R-3D-d)?
3. **Session TTL defaults:** access token lifetime; refresh absolute cap; max concurrent sessions (1 vs few).
4. **Phone kill UX preference:** magic link email, Cloudflare dashboard only, Telegram/other already-used ops channel, or all three?
5. **Binding α vs β** (signed bridge requests vs site-minted origin token) — any preference given existing `SN_MR_READ_TOKEN`-style secrets?
6. **Hostname:** new subdomain (e.g. `mcp-analytics.<site>`) vs path on apex via Worker route? (Affects OAuth redirect allowlists and mental model of “second MCP endpoint.”)
7. **OAuth client registration:** pre-register Claude’s client (Advanced settings client id/secret) vs CIMD/DCR for a single-owner setup?
8. **Health panel:** should remote-MCP edge join `inc/health-edge-workers.php` as a fifth worker check in the same release train?
9. **Board row:** after this proposal, should R3 §3D stay `planned`, move to a narrower `remote-analytics-mcp` row, or stay declined until Increment 0 is explicitly scheduled?

---

## Appendix A — Citation index (repo)

| Claim | Where |
| --- | --- |
| Read allowlist SoT | `inc/mcp/mcp-capabilities.php:63` |
| Protocol version fallback | `inc/mcp/mcp-capabilities.php:17-21` |
| MCP read route registration | `inc/mcp/mcp-endpoint.php:157-171` |
| `manage_options` floor | `inc/mcp/mcp-endpoint.php:50-56` |
| Read permission + kill switch | `inc/mcp/mcp-read-guard.php:336-345` |
| F1 fail-open check | `inc/mcp/mcp-read-guard.php:151-168` |
| F1 dispatch + path coverage | `inc/mcp/mcp-read-guard.php:179-294` |
| Analytics ability caps | `inc/abilities-analytics.php:40`, `:80` |
| `manage_options` helper | `inc/abilities-permission-helpers.php:40-41` |
| REST flank / dual doors | `docs/security/rest-audit-2026-08-03.md` §0–§1 |
| A5 / F1 / F2 / decline / reopen | `docs/security/agent-surface-threat-model.md` §8.1–§8.8 |
| R3 3D prep | `docs/r3-prep.md` §3D |
| Edge workers in estate | `inc/health-edge-workers.php` header |
| Bearer edge token prior art | `docs/MACHINE-READERS.md` sensor table |
| Push digest pattern | `inc/security-digest.php` header |
| Read telemetry (both doors) | `inc/mcp/mcp-telemetry.php` header |

## Appendix B — External sources (dated 2026-08-12)

| Source | URL |
| --- | --- |
| Claude Help Center: custom connectors / remote MCP | https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp (`dateModified` 2026-08-11) |
| Claude Platform: MCP connector | https://platform.claude.com/docs/en/agents-and-tools/mcp-connector |
| MCP Authorization 2025-11-25 | https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization |
| MCP Transports 2025-11-25 | https://modelcontextprotocol.io/specification/2025-11-25/basic/transports |
| MCP Authorization tutorial (2026-07-28 docs tree) | https://modelcontextprotocol.io/docs/2026-07-28/tutorials/security/authorization |

Unverified third-party claims about directory auth enums (`static_headers`, etc.) were **not** used as design foundations.

---

*End of proposal. No implementation is authorized by this document alone.*
