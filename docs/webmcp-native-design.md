# Native WebMCP — worker-owned, anchored (design)

Date: 2026-08-28. Status: approved design, pre-plan.
Origin: the Rights-anchoring incident of 2026-08-23→28 — Cloudflare's WebMCP preview
injected a script tag into served HTML at the zone layer, above the provenance sweep's
vantage, leaving `/tdm-policy/` permanently un-anchorable until the toggle was turned off.
Principle extracted there, load-bearing here: **a rights-anchored site may only ship agent
surfaces from inside the anchored bytes.** No zone-layer injection, ever.

## One sentence

`sn-rights-signals-worker` emits a byte-stable `<script>` tag into every HTML response and
serves a small self-hosted bridge that registers two page-local tools (`verify-page`,
`get-rights-terms`) with the browser's agent API; the bridge becomes the **fifth anchored
rights-signal surface**, watched by the same hourly sweep and the same plugin check that
caught the Cloudflare incident.

## Decisions already made (owner, 2026-08-28)

- Tag on **all HTML site-wide** (worker-rendered pages and WordPress pass-throughs).
- Bridge **is** anchored: fifth `RIGHTS_SIGNALS` row in the provenance sweep.
- Tool pack at launch: **`verify-page` + `get-rights-terms` only.** No third tool
  (markdown already reachable via `Accept: text/markdown` negotiation). YAGNI stands.
- **No MCP server connection**: `data-mcp-url="none"`. The unauthenticated-door question
  (denied-list three-specs, remote-read-scope owner direction) is deliberately not touched.
  `mcp.juanlentino.com` stays auth-gated.

## Components

### 1. sn-rights-signals-worker (center of gravity; minor version bump)

**`src/webmcp-bridge.mjs`** — serves `GET /webmcp/bridge.js` from the existing wildcard
dispatch in `src/index.mjs`.

- Path is deliberately **not** `/.webmcp/…` — that is Cloudflare's reserved namespace; if
  their toggle were ever re-enabled there must be no collision. Root-owned `/webmcp/` is
  dispatched by this worker like every other surface.
- Hand-written ES module, target ≤ ~200 lines, **no bundler** — matches the worker's
  no-build `.mjs` convention. Served with `content-type: text/javascript; charset=utf-8`
  and an explicit cache policy (short max-age; the sweep hashes exact bytes, so no
  immutable/far-future caching).
- Response is byte-deterministic: no timestamps, no request-derived content. The bridge's
  bytes change only when its source changes — that is what makes it anchorable.

**Bridge behavior** (client side):

1. Feature-probe a thin adapter: `navigator.modelContext ?? window.agent`. The exact
   registration API is pinned at implementation time from the then-current WebMCP draft
   (the spec is still moving; the adapter is the only file that touches it). No API
   present → return silently; the page behaves exactly as before.
2. Register `verify-page`:
   - Read the in-page verification manifest — the v11.7.0 data-shaped
     `<script type="application/json">` block that every signed subject already carries
     (`inc/provenance-machine-pointers.php`). The manifest lists inputs and endpoints and
     asserts nothing (P-51); this tool is the "caller computes" party it was designed for.
   - Manifest absent → return the honest absence: `{ signed: false }` plus a pointer to
     the rights terms. Not an error.
   - Manifest present → dynamically load `prov-verify-core.js` (the plugin asset the
     `/verify` docket already consumes — ONE verification core, never a vendored copy;
     cross-repo copies drift). Compute signature, content-hash, live-match, and anchor
     status client-side; return a structured verdict naming each leg.
   - Core fails to load or a fetch fails → return a tool error result; never throw into
     the page.
3. Register `get-rights-terms`:
   - Same-origin fetch of `/tdm-policy/` with `Accept: application/ld+json` → return the
     ODRL document plus pointers to `/license.xml` and `/.well-known/tdmrep.json`.
   - No terms are duplicated into the bridge; the answer is always the served policy.

**Tag emission** — one deterministic tag, everywhere HTML is served:

```html
<script type="module" src="https://juanlentino.com/webmcp/bridge.js" integrity="sha384-…" data-mcp-url="none"></script>
```

- **SRI is part of the design** (pending owner confirmation at spec review): the
  `integrity` attribute is computed by the worker from the bridge source at module scope
  (a static string, no per-request work). This makes the anchored policy page attest the
  exact executable bytes it hands agents — and turns skew into a red check: a new
  `rights-assertions.mjs` invariant requires the tag's `integrity` value to equal the
  hash of what `/webmcp/bridge.js` actually serves. Same-origin, so no `crossorigin`
  attribute is needed.
- Consequence, accepted: the tag changes **once per bridge release** (not per request),
  so every bridge release drifts tdm-policy's bytes and the existing sweep re-anchors it
  automatically within the hour — inside the plugin check's 2h grace. Bridge releases
  are therefore visible as policy-page ledger versions; for this site that is a feature
  (the legal surface's history records which tool bytes it shipped), not churn.
- Pass-throughs: appended in `<head>` alongside the TDM meta tags via the existing
  `html-injector.mjs` streaming pattern (content-type gate already confirmed upstream).
- Worker-rendered HTML (tdm-policy HTML branch, `/ns/tdm` HTML branch): added in their
  templates. Never on ODRL/JSON/markdown representations.
- Otherwise the tag is byte-deterministic: attribute order, quoting, and URL never
  change casually — every byte of it lives inside anchored tdm-policy bytes.

**`scripts/rights-assertions.mjs`** — new invariants (with mutations), same static-on-PR /
live-postdeploy gating as the existing 39:

- Tag present exactly once on the policy HTML representation.
- Tag present on an HTML pass-through.
- Tag absent from the ODRL representation, `license.xml`, `tdmrep.json`, `robots.txt`,
  and the markdown representation.
- `/webmcp/bridge.js` serves 200, JS content-type, non-empty, and contains the shape
  marker the sweep validator will use (parity: the invariant and the validator must
  reference the same marker string).
- The tag's `integrity` value equals the sha384 of the bytes `/webmcp/bridge.js`
  actually serves (SRI parity — tag and asset can never skew silently).
- `Vary: Accept` discipline on the policy URL unchanged.

### 2. sn-provenance-worker (minor version bump)

Fifth row in `RIGHTS_SIGNALS` (`src/rights-signals.mjs`):

```js
{ slug: "webmcp-bridge", url: "https://juanlentino.com/webmcp/bridge.js", validate: (t) => /* shape marker */ }
```

- Validator follows the existing minimal-shape philosophy (never a parser): a marker
  string chosen from the shipped bridge source, e.g. the registration call. It exists so
  a WAF page or empty response is never signed with the production key.
- Nothing else changes: the sweep signs, OTS-stamps, and versions the bridge under
  `rights-signals/webmcp-bridge/v<n>` automatically, hourly, de-duplicated against the
  newest ledger record's `content_hash` (no KV state — established during the incident).

### 3. signal-and-noise-tools plugin (minor version bump + CHANGELOG)

- `inc/health-check-rights-anchored.php`: `$targets` gains
  `'webmcp-bridge' => home_url( '/webmcp/bridge.js' )`. The evaluator, grace window
  (7200s), and state option are untouched — the fifth slug rides the existing machinery.
- `tests/health-check-rights-anchored.php`: fixture cases for the fifth slug — anchored
  match, drift-past-grace, and no-record-at-all.
- The sibling rights-*signals* check is **deliberately untouched**: the bridge is not a
  reservation signal; it is an anchored asset.

## Rollout order — the anchoring dance

1. **rights-signals worker first.** Tag + bridge go live. tdm-policy's served bytes
   change; the existing sweep mints **tdm-policy v4 within the hour**, inside the plugin
   check's 2h grace — no finding, no manual anchoring. Policy prose is untouched, so no
   `POLICY_VERSION` bump; §6's "each published version is cryptographically timestamped"
   is satisfied by the automatic anchor. Verify live after deploy (per-colo race rules).
2. **provenance worker second.** Next sweep mints `webmcp-bridge v1`. Shipping this
   first would 404 hourly against a bridge that does not exist yet.
3. **plugin last, only after v1 exists in the ledger index** — otherwise the
   "ledger has no record at all" finding arms during the gap.

One PR per repo, one arc (batch-PR convention). Release/tag mechanics follow each repo's
standing rules.

## Failure modes

| Condition | Behavior |
|---|---|
| Browser has no agent API | Bridge returns silently; zero page effect |
| Page is not a signed subject | `verify-page` returns honest absence, not an error |
| `prov-verify-core.js` fails to load | Tool error result; nothing thrown into the page |
| A verification fetch fails | Verdict names the unreachable leg (docket convention) |
| Cloudflare WebMCP toggle re-enabled | Second tag appears → tdm-policy drifts → the plugin check fires. The incident is now self-detecting; runbook says keep the toggle off |
| Future CSP | Same-origin module script — `script-src 'self'` compatible |

## Testing

- **Worker:** new invariants + mutations in `rights-assertions.mjs` (static + live);
  bridge pure functions (manifest parsing, verdict shaping, pack selection) unit-tested
  under the repo's vitest. The adapter is exercised with a stubbed agent API — presence,
  absence, and registration-throw.
- **Plugin:** fixture-driven evaluator cases as above; suite runs offline.
- **Live, post-rollout:** curl the tag on a note page and the policy page; curl the
  bridge; confirm `rights-signals/webmcp-bridge/v1.json` and tdm-policy `v4.json` in the
  ledger; Trust checks board fully green.

## Out of scope (named, so they stay out)

- Any MCP server connection or unauthenticated tool door (own arc, own gate).
- A third tool (`get-markdown` or otherwise).
- Path-scoping requests or other upstream asks to Cloudflare.
- Policy prose changes (a §-appendix mention of the bridge, if ever, is its own
  `POLICY_VERSION` arc).
