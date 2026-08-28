# Native WebMCP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Worker-owned WebMCP: a byte-stable SRI-pinned tag on all HTML, a self-hosted bridge registering `verify-page` + `get-rights-terms`, anchored as the fifth rights-signal surface. Spec: `docs/webmcp-native-design.md` (this repo).

**Architecture:** Three repos in strict rollout order — `sn-rights-signals-worker` (tag + bridge), then `sn-provenance-worker` (fifth sweep row), then this plugin (fifth check target). The bridge's client code lives as a real, unit-testable ES module; the served asset is composed from it via `Function.prototype.toString()` (no bundler, matching the worker's no-build convention). SRI is computed at module scope from the exact served bytes, so the tag and asset cannot skew.

**Tech Stack:** Cloudflare Workers (no-build `.mjs`), vitest, HTMLRewriter, WebCrypto (SHA-384 SRI, and in-browser Ed25519/SHA-256 via the existing `prov-verify-core.js` decision core), standalone-PHP CLI tests in the plugin.

**Repo paths (local checkouts):**
- Worker A: `/Users/juanlentino/Projects/sn-rights-signals-worker`
- Worker B: `/Users/juanlentino/Projects/sn-provenance-worker`
- Plugin: this worktree (`signal-and-noise-tools`, branch `claude/rights-anchoring-145a7f`)

**Standing rules that bind this plan:** `main` is ruleset-protected in every repo — feature branch + PR, never direct push. One PR per repo per arc. Version bumps at PR time per each repo's own convention (`npm version` stages `package-lock.json` too). After any deploy, verify live with per-colo patience (re-run once before concluding drift).

---

## Phase A — sn-rights-signals-worker (branch `feat/webmcp-bridge`)

Setup (once): `cd /Users/juanlentino/Projects/sn-rights-signals-worker && git fetch origin && git checkout -b feat/webmcp-bridge origin/main && npm ci && npm test` — expect the existing suite green before touching anything.

### Task 1: Bridge client module — pure functions, no wiring

**Files:**
- Create: `src/webmcp-bridge-client.mjs`
- Test: `test/webmcp-bridge-client.test.mjs`

The client module is the source of truth for the code browsers run. Hard constraints, enforced by Task 3's composition test: every exported function must be **self-contained** — it may call the other exported functions and browser globals, but must NOT reference module-scope constants or imports (serialization via `toString()` would silently drop them).

- [ ] **Step 1: Write failing tests for the inert pieces**

```js
// test/webmcp-bridge-client.test.mjs
import { describe, it, expect } from "vitest";
import {
  snAgentApi, snReadManifest, snRightsPointers, snGetRightsTerms,
} from "../src/webmcp-bridge-client.mjs";

describe("snAgentApi", () => {
  it("prefers navigator.modelContext, falls back to window.agent, else null", () => {
    const mc = { registerTool: () => {} };
    expect(snAgentApi({ navigator: { modelContext: mc } })).toBe(mc);
    const ag = { registerTool: () => {} };
    expect(snAgentApi({ navigator: {}, agent: ag })).toBe(ag);
    expect(snAgentApi({ navigator: {} })).toBeNull();
    expect(snAgentApi({ navigator: { modelContext: {} } })).toBeNull(); // no registerTool
  });
});

describe("snReadManifest", () => {
  const doc = (json) => ({
    getElementById: (id) =>
      id === "sn-verification-manifest" && json !== undefined ? { textContent: json } : null,
  });
  it("parses the v11.7.0 manifest block", () => {
    const m = snReadManifest(doc('{"subject":{"uid":"u","kind":"note","version":2}}'));
    expect(m.subject.uid).toBe("u");
  });
  it("returns null when the block is absent or malformed", () => {
    expect(snReadManifest(doc(undefined))).toBeNull();
    expect(snReadManifest(doc("not json"))).toBeNull();
  });
});

describe("snRightsPointers", () => {
  it("names the four public rights surfaces", () => {
    const p = snRightsPointers();
    expect(p.human_policy).toBe("https://juanlentino.com/tdm-policy/");
    expect(p.license_xml).toBe("https://juanlentino.com/license.xml");
    expect(p.tdmrep).toBe("https://juanlentino.com/.well-known/tdmrep.json");
    expect(p.robots).toBe("https://juanlentino.com/robots.txt");
  });
});

describe("snGetRightsTerms", () => {
  it("fetches the ODRL representation with an explicit ld+json accept", async () => {
    let seen;
    const fetchFn = async (url, init) => {
      seen = { url, accept: init.headers.accept };
      return { ok: true, json: async () => ({ "@type": "Policy" }) };
    };
    const out = await snGetRightsTerms(fetchFn);
    expect(seen.url).toBe("/tdm-policy/");
    expect(seen.accept).toBe("application/ld+json");
    expect(out.policy["@type"]).toBe("Policy");
    expect(out.links.human_policy).toBe("https://juanlentino.com/tdm-policy/");
  });
  it("returns an error result, never throws, on a non-2xx", async () => {
    const out = await snGetRightsTerms(async () => ({ ok: false, status: 503 }));
    expect(out.error).toContain("503");
  });
});
```

- [ ] **Step 2: Run to verify failure** — `npx vitest run test/webmcp-bridge-client.test.mjs` → FAIL (module not found).

- [ ] **Step 3: Implement the inert pieces**

```js
// src/webmcp-bridge-client.mjs
//
// SOURCE OF TRUTH for the code browsers run at /webmcp/bridge.js. The served
// asset is COMPOSED from these functions via Function.prototype.toString()
// (src/webmcp-bridge.mjs), so every function here must be self-contained:
// call sibling exports and browser globals only — never a module-scope
// constant or import, which serialization would silently drop. The
// composition test (test/webmcp-bridge.test.mjs) imports the composed source
// as a data: URL module to prove it stands alone.

export function snAgentApi(w) {
  var api = (w && w.navigator && w.navigator.modelContext) || (w && w.agent) || null;
  return api && typeof api.registerTool === "function" ? api : null;
}

export function snReadManifest(doc) {
  var el = doc.getElementById("sn-verification-manifest");
  if (!el) return null;
  try { return JSON.parse(el.textContent); } catch (e) { return null; }
}

export function snRightsPointers() {
  return {
    human_policy: "https://juanlentino.com/tdm-policy/",
    license_xml: "https://juanlentino.com/license.xml",
    tdmrep: "https://juanlentino.com/.well-known/tdmrep.json",
    robots: "https://juanlentino.com/robots.txt",
  };
}

export async function snGetRightsTerms(fetchFn) {
  var f = fetchFn || fetch;
  var res;
  try {
    res = await f("/tdm-policy/", { headers: { accept: "application/ld+json" } });
  } catch (e) {
    return { error: "policy fetch failed: " + (e && e.message ? e.message : e) };
  }
  if (!res.ok) return { error: "policy fetch failed: " + res.status };
  var odrl = await res.json();
  return { policy: odrl, links: snRightsPointers() };
}
```

- [ ] **Step 4: Run to verify pass** — `npx vitest run test/webmcp-bridge-client.test.mjs` → PASS.
- [ ] **Step 5: Commit** — `git add src/webmcp-bridge-client.mjs test/webmcp-bridge-client.test.mjs && git commit -m "feat: WebMCP bridge client — adapter, manifest reader, rights-terms tool"`

### Task 2: `verify-page` orchestration (port of the docket's four checks)

**Files:**
- Modify: `src/webmcp-bridge-client.mjs`
- Test: `test/webmcp-bridge-client.test.mjs`
- Read-only sources for the port: plugin repo `assets/js/prov-verify.js:304-479` (checkSignature, checkContentHash, checkLiveMatch, checkAnchor and their `fetchJSON`), `assets/js/prov-verify-core.js` (the `window.SNProvVerifyCore` decision API), `tests/js/prov-verify-core.test.mjs` (fixture shapes).

The bridge is the decision core's second orchestrator. Decisions stay in ONE place (`prov-verify-core.js`, loaded at runtime from `https://juanlentino.com/wp-content/plugins/signal-and-noise-tools/assets/js/prov-verify-core.js` — confirmed live 200); the bridge only fetches inputs and hands them to the core's `derive*` functions, exactly as the docket page does, minus all DOM.

**Return contract (pinned by the tests below):**

```js
// signed page:
{ signed: true,
  subject: { uid, kind, version, url },
  checks: { signature: {state, detail}, contentHash: {state, detail},
            liveMatch: {state, detail}, anchor: {state, detail} },
  overall: { state, detail },            // core.deriveOverallVerdict
  ledger_record: <manifest.calls.record.url>,
  docket: <manifest.spec> }
// unsigned page:
{ signed: false, rights: snRightsPointers(),
  note: "This page is not a signed subject; nothing to verify." }
// any leg's fetch failure surfaces as that leg's UNREACHABLE verdict (docket
// convention), never a thrown error.
```

- [ ] **Step 1: Write failing tests** — dependency-injected: `snVerifyPage(deps)` where `deps = { doc, fetchFn, loadCore }`, defaults to real globals inside the function body.

```js
// append to test/webmcp-bridge-client.test.mjs
import { snVerifyPage } from "../src/webmcp-bridge-client.mjs";

describe("snVerifyPage", () => {
  it("returns the honest absence on an unsigned page", async () => {
    const out = await snVerifyPage({ doc: { getElementById: () => null } });
    expect(out.signed).toBe(false);
    expect(out.rights.human_policy).toContain("/tdm-policy/");
  });
  it("names an unreachable credential as the signature leg's UNREACHABLE, not a throw", async () => {
    const manifest = JSON.stringify({
      spec: "https://juanlentino.com/verify",
      subject: { uid: "u1", kind: "note", version: 1, url: "https://juanlentino.com/notes/x/" },
      calls: {
        credential: { url: "https://example.test/cred/u1" },
        record: { url: "https://example.test/notes/u1/v1.json" },
        key_history: { url: "https://example.test/keys.json" },
        did: { url: "https://example.test/did.json" },
        block_header: { url_template: "https://example.test/block-height/{height}" },
      },
    });
    const doc = { getElementById: (id) => (id === "sn-verification-manifest" ? { textContent: manifest } : null) };
    const out = await snVerifyPage({
      doc,
      fetchFn: async () => { throw new Error("network down"); },
      loadCore: async () => globalThis.__fakeCore, // set in Step 3's fixture
    });
    expect(out.signed).toBe(true);
    expect(out.checks.signature.state).toBe("UNREACHABLE");
    expect(out.overall.state).toBeDefined();
  });
});
```

- [ ] **Step 2: Run to verify failure** — `npx vitest run test/webmcp-bridge-client.test.mjs` → FAIL (`snVerifyPage` not exported).

- [ ] **Step 3: Implement `snLoadCore` + `snVerifyPage`.** Draft below; then the **mandatory reconciliation pass**: open `assets/js/prov-verify.js:304-479` in the plugin repo and mirror each `check*` function's exact use of `SNProvVerifyCore` — argument shapes, key-agreement inputs (`deriveKeyAgreement(didDoc, siteKeys, ledgerKeys)`), the anchor plan branching (`deriveAnchorPlan` → block-only / ledger-tx / tx paths), and the fetch fallbacks — dropping only DOM calls (`setCheck`, `renderProofWalk`, `announce`, `paintVerdict`). Where the draft and the docket disagree, the docket wins; adjust and extend the fake-core fixture (`globalThis.__fakeCore`) to pin whatever shapes you confirmed.

```js
// append to src/webmcp-bridge-client.mjs

export function snLoadCore(doc, url) {
  return new Promise(function (resolve, reject) {
    if (typeof window !== "undefined" && window.SNProvVerifyCore) return resolve(window.SNProvVerifyCore);
    var s = doc.createElement("script");
    s.src = url;
    s.onload = function () {
      if (window.SNProvVerifyCore) resolve(window.SNProvVerifyCore);
      else reject(new Error("verifier core loaded but exposed no API"));
    };
    s.onerror = function () { reject(new Error("verifier core failed to load")); };
    doc.head.appendChild(s);
  });
}

export async function snVerifyPage(deps) {
  var d = deps || {};
  var doc = d.doc || document;
  var f = d.fetchFn || function (u) { return fetch(u, { credentials: "omit" }); };
  var load = d.loadCore || function () {
    return snLoadCore(doc, "https://juanlentino.com/wp-content/plugins/signal-and-noise-tools/assets/js/prov-verify-core.js");
  };
  var manifest = snReadManifest(doc);
  if (!manifest) {
    return { signed: false, rights: snRightsPointers(), note: "This page is not a signed subject; nothing to verify." };
  }
  var UNREACHABLE = function (what) { return { state: "UNREACHABLE", detail: what + " could not be fetched." }; };
  var getJSON = async function (url) {
    try { var r = await f(url); return r && r.ok ? { ok: true, json: await r.json(), status: r.status } : { ok: false, json: null, status: r && r.status }; }
    catch (e) { return { ok: false, json: null, status: 0 }; }
  };
  var core;
  try { core = await load(); }
  catch (e) { return { signed: true, subject: manifest.subject, error: "verifier core unavailable: " + e.message }; }

  var cred = await getJSON(manifest.calls.credential.url);
  var checks = {};
  if (!cred.ok) {
    checks.signature = UNREACHABLE("the signed credential");
    checks.contentHash = UNREACHABLE("the signed credential");
    checks.liveMatch = UNREACHABLE("the signed credential");
    checks.anchor = UNREACHABLE("the signed credential");
  } else {
    // ==== RECONCILE THIS BLOCK against prov-verify.js:304-479 (see Step 3 text) ====
    var keyFetches = await Promise.all([
      getJSON(manifest.calls.did.url),
      getJSON(manifest.calls.key_history.url),
      getJSON(core.ledgerKeysUrl(manifest.calls.record.url)),
    ]);
    checks.signature = await (async function () {
      try {
        var agreement = core.deriveKeyAgreement(keyFetches[0].json, keyFetches[1].json, keyFetches[2].json);
        var decoded = core.decodeSignedPayloadBytes(cred.json);
        var key = await crypto.subtle.importKey("raw", agreement.publicKeyBytes, { name: "Ed25519" }, false, ["verify"]);
        var valid = await crypto.subtle.verify({ name: "Ed25519" }, key, decoded.signatureBytes, decoded.payloadBytes);
        return core.deriveSignatureVerdict(valid, agreement);
      } catch (e) { return UNREACHABLE("the signature inputs"); }
    })();
    checks.contentHash = await (async function () {
      try {
        var decoded = core.decodeSignedPayloadBytes(cred.json);
        var digest = await crypto.subtle.digest("SHA-256", decoded.payloadBytes);
        return core.deriveContentHashVerdict(digest, core.claimedContentHash(cred.json));
      } catch (e) { return UNREACHABLE("the payload bytes"); }
    })();
    checks.liveMatch = await (async function () {
      var twin = await getJSON(core.liveMatchTwinUrl(cred.json));
      return core.deriveLiveMatchVerdict(cred.json, twin);
    })();
    checks.anchor = await (async function () {
      var plan = core.deriveAnchorPlan(cred.json, manifest.subject.uid, manifest.subject.version);
      if (plan.verdict) return plan.verdict;
      var fetched = await Promise.all(plan.urls.map(getJSON));
      return core.deriveTxAnchor(cred.json, fetched);
    })();
    // ==== end reconcile block ====
  }
  return {
    signed: true,
    subject: manifest.subject,
    checks: checks,
    overall: core.deriveOverallVerdict(checks),
    ledger_record: manifest.calls.record.url,
    docket: manifest.spec,
  };
}
```

- [ ] **Step 4: Reconciliation done, fake-core fixture written, tests pass** — `npx vitest run test/webmcp-bridge-client.test.mjs` → PASS.
- [ ] **Step 5: Commit** — `git commit -am "feat: verify-page orchestration — second consumer of the docket's decision core"`

### Task 3: `main()` + the served asset — composition, SRI, tag, response

**Files:**
- Modify: `src/webmcp-bridge-client.mjs` (add `snWebmcpMain`)
- Create: `src/webmcp-bridge.mjs`
- Test: `test/webmcp-bridge.test.mjs`

- [ ] **Step 1: Write failing tests**

```js
// test/webmcp-bridge.test.mjs
import { describe, it, expect } from "vitest";
import { BRIDGE_SOURCE, BRIDGE_SRI, WEBMCP_SCRIPT_TAG, webmcpBridgeResponse } from "../src/webmcp-bridge.mjs";

describe("composed bridge asset", () => {
  it("is a valid standalone module that no-ops headless (the self-containment gate)", async () => {
    await import("data:text/javascript," + encodeURIComponent(BRIDGE_SOURCE)); // throws on syntax error or dangling reference at top level
  });
  it("carries the registration marker the sweep validator and live check key on", () => {
    expect(BRIDGE_SOURCE).toContain("registerTool");
  });
  it("SRI in the tag matches the served bytes (skew is impossible by construction)", async () => {
    const digest = await crypto.subtle.digest("SHA-384", new TextEncoder().encode(BRIDGE_SOURCE));
    const expected = "sha384-" + btoa(String.fromCharCode(...new Uint8Array(digest)));
    expect(BRIDGE_SRI).toBe(expected);
    expect(WEBMCP_SCRIPT_TAG).toContain(`integrity="${expected}"`);
    expect(WEBMCP_SCRIPT_TAG).toContain('src="https://juanlentino.com/webmcp/bridge.js"');
    expect(WEBMCP_SCRIPT_TAG).toContain('data-mcp-url="none"');
    expect(WEBMCP_SCRIPT_TAG).toContain('type="module"');
  });
  it("serves deterministic bytes with a JS content-type and nosniff", async () => {
    const res = webmcpBridgeResponse();
    expect(res.status).toBe(200);
    expect(res.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
    expect(res.headers.get("x-content-type-options")).toBe("nosniff");
    expect(res.headers.get("cache-control")).toBe("public, max-age=300");
    expect(await res.text()).toBe(BRIDGE_SOURCE);
  });
});
```

- [ ] **Step 2: Run to verify failure** → FAIL (module not found).
- [ ] **Step 3: Implement**

```js
// append to src/webmcp-bridge-client.mjs
export function snWebmcpMain() {
  if (typeof navigator === "undefined" || typeof document === "undefined") return;
  var api = snAgentApi(window);
  if (!api) return; // no agent surface: the page behaves exactly as before
  api.registerTool({
    name: "verify-page",
    description: "Verify this page's provenance: signature, content hash, live match, and anchor, computed in this browser from the page's own verification manifest. Returns the honest absence on unsigned pages.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
    execute: function () { return snVerifyPage(); },
  });
  api.registerTool({
    name: "get-rights-terms",
    description: "The machine-readable rights terms in force for this site: the ODRL policy (W3C TDMRep profile) plus pointers to license.xml, tdmrep.json, and the human-readable policy.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
    execute: function () { return snGetRightsTerms(); },
  });
}
```

Before committing, one **spec-pin step**: fetch the current WebMCP draft's registration shape (context7 or the spec repo) and adjust the `registerTool` argument shape and the adapter probe order in `snAgentApi` if the draft has moved past `navigator.modelContext` / `{name, description, inputSchema, execute}`. The adapter and these two calls are the ONLY spec-coupled lines in the system — record what you pinned in a comment above `snAgentApi`.

```js
// src/webmcp-bridge.mjs
//
// The served /webmcp/bridge.js asset: composed from the client module's
// functions via toString() — no bundler, and the unit-tested functions ARE
// the shipped bytes. SRI is computed here from the exact served source, so
// the tag (which rides inside anchored tdm-policy bytes) attests the exact
// executable it loads; tag/asset skew is impossible by construction and
// additionally pinned by rights-assertions.
//
// Path is deliberately NOT /.webmcp/ — that is Cloudflare's reserved
// namespace (the 2026-08-23 incident); /webmcp/ is dispatched by this
// worker like every other surface it owns.

import {
  snAgentApi, snReadManifest, snRightsPointers, snGetRightsTerms,
  snLoadCore, snVerifyPage, snWebmcpMain,
} from "./webmcp-bridge-client.mjs";

export const BRIDGE_URL = "https://juanlentino.com/webmcp/bridge.js";

const PARTS = [snAgentApi, snReadManifest, snRightsPointers, snGetRightsTerms, snLoadCore, snVerifyPage, snWebmcpMain];

export const BRIDGE_SOURCE =
  "// juanlentino.com WebMCP bridge. Source of truth: sn-rights-signals-worker\n" +
  "// src/webmcp-bridge-client.mjs. Anchored hourly as rights-signals/webmcp-bridge\n" +
  "// in https://github.com/juanlentino/signal-and-noise-provenance.\n\n" +
  PARTS.map((f) => f.toString()).join("\n\n") +
  "\n\nsnWebmcpMain();\n";

const digest = await crypto.subtle.digest("SHA-384", new TextEncoder().encode(BRIDGE_SOURCE));
export const BRIDGE_SRI = "sha384-" + btoa(String.fromCharCode(...new Uint8Array(digest)));

// Frozen shape: attribute order and quoting never change casually — these
// bytes live inside anchored tdm-policy bytes, so tag churn is anchor churn
// (accepted per design: once per bridge release).
export const WEBMCP_SCRIPT_TAG =
  `<script type="module" src="${BRIDGE_URL}" integrity="${BRIDGE_SRI}" data-mcp-url="none"></script>`;

export function webmcpBridgeResponse() {
  return new Response(BRIDGE_SOURCE, {
    status: 200,
    headers: {
      "content-type": "text/javascript; charset=utf-8",
      // Short-lived on purpose: the hourly anchor sweep hashes exact bytes.
      "cache-control": "public, max-age=300",
      "x-content-type-options": "nosniff",
    },
  });
}
```

- [ ] **Step 4: Run to verify pass** — `npx vitest run test/webmcp-bridge.test.mjs` → PASS. Also rerun the full suite (`npm test`) — nothing else should move yet.
- [ ] **Step 5: Commit** — `git commit -am "feat: served bridge asset — toString composition, SRI-pinned tag, /webmcp/bridge.js response"`

### Task 4: Route the bridge in the dispatch

**Files:**
- Modify: `src/index.mjs` (after the `/ns/tdm` block, before the `/tdm-policy` block)
- Test: `test/index.test.mjs` (follow its existing request-helper style)

- [ ] **Step 1: Failing test** — add to `test/index.test.mjs`, matching the file's existing worker-invocation helper:

```js
it("serves the WebMCP bridge with rights headers riding it", async () => {
  const res = await workerFetch("https://juanlentino.com/webmcp/bridge.js"); // use the file's existing helper name
  expect(res.status).toBe(200);
  expect(res.headers.get("content-type")).toBe("text/javascript; charset=utf-8");
  expect(res.headers.get("tdm-reservation")).toBe("1"); // v1.5.0 rule: the reservation rides EVERY response
  expect(await res.text()).toContain("registerTool");
});
```

- [ ] **Step 2: Run to verify failure** → FAIL (falls through to origin fetch).
- [ ] **Step 3: Implement** — in `src/index.mjs`:

```js
import { webmcpBridgeResponse } from "./webmcp-bridge.mjs";
// ... after the /ns/tdm block:
    // The self-hosted WebMCP bridge (design: signal-and-noise-tools
    // docs/webmcp-native-design.md). Wrapped like every owned surface: content
    // taken in ANY representation is content taken (v1.5.0), a script included.
    if (pathname === "/webmcp/bridge.js") return withTdmHeaders(webmcpBridgeResponse());
```

- [ ] **Step 4: Run to verify pass**, then full `npm test`.
- [ ] **Step 5: Commit** — `git commit -am "feat: /webmcp/bridge.js dispatched by the wildcard route"`

### Task 5: Tag emission on every HTML surface

**Files:**
- Modify: `src/html-injector.mjs`, `src/tdm-policy-page.mjs`, `src/ns-tdm.mjs` (its HTML branch only)
- Test: `test/html-injector.test.mjs`, `test/tdm-policy-page.test.mjs`, `test/ns-tdm.test.mjs`

- [ ] **Step 1: Failing tests** — in each of the three test files, following each file's existing style:

```js
// html-injector.test.mjs — alongside the existing meta-injection cases:
it("appends the SRI-pinned WebMCP tag next to the TDM meta tags", async () => {
  const out = await injectTdmMeta(htmlResponse("<html><head></head><body></body></html>")).text();
  expect(out).toContain('src="https://juanlentino.com/webmcp/bridge.js"');
  expect(out).toMatch(/integrity="sha384-[A-Za-z0-9+/=]+"/);
});
// tdm-policy-page.test.mjs:
it("the policy HTML carries the WebMCP tag exactly once", () => {
  const html = tdmPolicyHtml();
  expect(html.split('src="https://juanlentino.com/webmcp/bridge.js"').length).toBe(2);
});
// ns-tdm.test.mjs — HTML branch only; the JSON branch must stay tag-free:
it("HTML branch carries the tag; the negotiated JSON never does", async () => {
  expect(await nsTdmResponse(false).text()).toContain("/webmcp/bridge.js");
  expect(await nsTdmResponse(true).text()).not.toContain("/webmcp/bridge.js");
});
```

- [ ] **Step 2: Run to verify failure** → all three FAIL.
- [ ] **Step 3: Implement**

```js
// src/html-injector.mjs
import { TDM_META_TAGS } from "./constants.mjs";
import { WEBMCP_SCRIPT_TAG } from "./webmcp-bridge.mjs";

class HeadMetaInjector {
  element(el) {
    el.append(TDM_META_TAGS + WEBMCP_SCRIPT_TAG, { html: true });
  }
}
```

In `src/tdm-policy-page.mjs`: import `WEBMCP_SCRIPT_TAG` and add `${WEBMCP_SCRIPT_TAG}` on its own line directly after `<meta name="tdm-policy-status" content="${POLICY_STATUS}">` in the `<head>`. In `src/ns-tdm.mjs`: same import; add `${WEBMCP_SCRIPT_TAG}` inside the HTML template's `<head>` (locate it with `grep -n "<head" src/ns-tdm.mjs`); do not touch the JSON branch.

- [ ] **Step 4: Run to verify pass**, then full `npm test`. Expect collateral: any existing snapshot/substring test over policy HTML may now fail — update those assertions to include the tag, and treat each as confirmation the tag landed where intended.
- [ ] **Step 5: Commit** — `git commit -am "feat: WebMCP tag rides every HTML representation — pass-throughs, policy page, ns/tdm"`

### Task 6: Rights invariants + mutations

**Files:**
- Modify: `scripts/rights-assertions.mjs` (required list), `scripts/rights-checks-documents.mjs` (new checks), `scripts/check-rights-signals.mjs` (live collector), `test/rights-consistency.test.mjs` (static collector + mutations)

- [ ] **Step 1: Add `bridge` to the artifact bundle.** In `rights-assertions.mjs`, extend `required` with `"bridge"`. In the live collector (`check-rights-signals.mjs:162-178`), add `get(`${origin}/webmcp/bridge.js`)` to the `Promise.all` and `bridge` to the destructuring + bundle. In the static collector (`test/rights-consistency.test.mjs:74-87`), add `artifact("/webmcp/bridge.js")` identically.
- [ ] **Step 2: New checks in `rights-checks-documents.mjs`** — inside `documentChecks`, following the existing `check(name, fn)` idiom:

```js
results.push(
  check("the WebMCP tag rides the policy HTML exactly once", () => {
    const n = a.policy.body.split('src="https://juanlentino.com/webmcp/bridge.js"').length - 1;
    if (n !== 1) throw new Error(`found ${n} occurrences`);
  }),
  check("the WebMCP tag rides pass-through HTML", () => {
    if (!a.html.body.includes('src="https://juanlentino.com/webmcp/bridge.js"')) throw new Error("tag missing from html artifact");
  }),
  check("no non-HTML representation carries the tag", () => {
    const clean = ["policyOdrl", "license", "tdmrep", "robots", "nsTdmJson", "llms"];
    const dirty = clean.filter((k) => a[k].body.includes("/webmcp/bridge.js"));
    if (dirty.length) throw new Error(`tag leaked into: ${dirty.join(", ")}`);
  }),
  check("the bridge serves executable JS carrying the registration marker", () => {
    if (!header(a.bridge, "content-type").includes("text/javascript")) throw new Error("wrong content-type");
    if (!a.bridge.body.includes("registerTool")) throw new Error("registration marker absent");
    new Function(a.bridge.body); // syntax gate: a WAF page or truncation fails to compile
  }),
  check("SRI parity: the tag's integrity equals the sha384 of the served bridge", async () => {
    const m = a.policy.body.match(/integrity="(sha384-[^"]+)"/);
    if (!m) throw new Error("no integrity attribute in the policy HTML tag");
    const digest = await crypto.subtle.digest("SHA-384", new TextEncoder().encode(a.bridge.body));
    const expected = "sha384-" + btoa(String.fromCharCode(...new Uint8Array(digest)));
    if (m[1] !== expected) throw new Error(`tag says ${m[1]}, served bytes hash to ${expected}`);
  }),
);
```

**Async caveat:** the existing `check()` runner is synchronous. If it does not already await, either make the SRI check compute the digest before the `check()` call (compute in `documentChecks`'s caller and pass the digest in), or extend `check()` to await a returned promise — pick whichever the existing runner structure makes smaller, and keep every current check's behavior identical.

- [ ] **Step 3: Mutations** — in `test/rights-consistency.test.mjs`'s mutation table, following the existing `[label, mutate]` style:

```js
["the WebMCP tag is stripped from the policy page", (a) => (a.policy.body = a.policy.body.replace(/<script type="module"[^>]*webmcp[^>]*><\/script>/, ""))],
["the tag's integrity attribute is corrupted", (a) => (a.policy.body = a.policy.body.replace(/integrity="sha384-/, 'integrity="sha384-AAAA'))],
["the served bridge no longer registers tools", (a) => (a.bridge.body = a.bridge.body.replace(/registerTool/g, "registerT00l"))],
["the tag leaks into the ODRL representation", (a) => (a.policyOdrl.body += '<script src="/webmcp/bridge.js"></script>')],
```

- [ ] **Step 4: Run** — `npm test` → static suite green, every mutation red. Then `node scripts/check-rights-signals.mjs` (live) — expect FAIL right now (nothing deployed): confirm it fails on the *bridge* checks specifically, which is this guard proving it can go red before it ever gates a deploy.
- [ ] **Step 5: Commit** — `git commit -am "test: bridge joins the rights invariant set — presence, exclusivity, syntax, SRI parity, four mutations"`

### Task 7: Release PR — Phase A checkpoint

- [ ] **Step 1:** CHANGELOG entry; version bump per this repo's convention (minor — new surface); remember `git add package.json package-lock.json CHANGELOG.md`.
- [ ] **Step 2:** `git push -u origin feat/webmcp-bridge && gh pr create` — PR body: what ships, the SRI/anchoring consequence (tdm-policy v4 mints automatically within an hour of deploy), and the three-repo rollout order.
- [ ] **CHECKPOINT (owner):** merge + deploy. Then verify live, with per-colo patience: `curl -s https://juanlentino.com/tdm-policy/ | grep -c webmcp` → 1; `curl -s -o /dev/null -w "%{http_code}" https://juanlentino.com/webmcp/bridge.js` → 200; postdeploy live gate green. **Within ~1h:** `rights-signals/tdm-policy/v4.json` appears in the ledger (the existing sweep, unmodified, anchors the new policy bytes). Do not start Phase B until v4 exists — it proves the sweep sees the deployed bytes.

---

## Phase B — sn-provenance-worker (branch `feat/webmcp-bridge-surface`)

Setup: `cd /Users/juanlentino/Projects/sn-provenance-worker && git fetch origin && git checkout -b feat/webmcp-bridge-surface origin/main && npm ci && npm test`.

### Task 8: Fifth sweep row

**Files:**
- Modify: `src/rights-signals.mjs` (RIGHTS_SIGNALS array), `test/rights-signals.test.mjs`

- [ ] **Step 1: Failing test** — the suite's fixture map (test file line ~17) is keyed by pathname with content shaped to pass each validator; counts already derive from `RIGHTS_SIGNALS.length`. Add the fixture row and one explicit validator case:

```js
// in the fixture map: "/webmcp/bridge.js": 'function snWebmcpMain(){api.registerTool({name:"verify-page"})}'
it("webmcp-bridge validator demands the registration marker, refusing a challenge page", () => {
  const row = RIGHTS_SIGNALS.find((f) => f.slug === "webmcp-bridge");
  expect(row.validate('api.registerTool({name:"verify-page"})')).toBe(true);
  expect(row.validate("<html>Just a moment...</html>")).toBe(false);
  expect(row.validate("")).toBe(false);
});
```

- [ ] **Step 2: Run to verify failure** → FAIL (no such slug).
- [ ] **Step 3: Implement** — in `RIGHTS_SIGNALS`:

```js
  { slug: "webmcp-bridge", url: "https://juanlentino.com/webmcp/bridge.js", validate: (t) => t.includes("registerTool") },
```

- [ ] **Step 4: `npm test`** → PASS, including the pre-existing length-derived assertions now counting 5.
- [ ] **Step 5: Commit, CHANGELOG, version bump (minor), push, PR.**
- [ ] **CHECKPOINT (owner):** merge + deploy. Next hourly sweep mints `rights-signals/webmcp-bridge/v1` (pending → confirmed on the later tick). Verify: `curl -s https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/index.json | python3 -c "import json,sys; print([r for r in json.load(sys.stdin)['rights_signals'] if r['slug']=='webmcp-bridge'])"` → one row. Do not start Phase C until it exists.

---

## Phase C — signal-and-noise-tools (this branch)

### Task 9: Fifth check target

**Files:**
- Modify: `inc/health-check-rights-anchored.php:130-135` (`$targets`)
- Test: `tests/health-check-rights-anchored.php`

The evaluator is slug-agnostic; only the fetch-target table grows. Tests drive the pure evaluator, following the file's `ok()` + `h()` fixture idiom.

- [ ] **Step 1: Failing tests** — append before the file's summary line:

```php
// v5 surface: webmcp-bridge rides the same evaluator unchanged.
$bridgeBody = 'function snWebmcpMain(){api.registerTool({name:"verify-page"})}';
$anchors5   = array(
	array( 'slug' => 'tdm-policy', 'content_hash' => h( 'policy-bytes' ) ),
	array( 'slug' => 'webmcp-bridge', 'content_hash' => h( $bridgeBody ) ),
);
$r = snt_rights_anchor_evaluate( array( 'webmcp-bridge' => $bridgeBody ), $anchors5, array(), $NOW );
ok( array() === $r['findings'], 'webmcp-bridge: anchored match raises nothing' );

$r = snt_rights_anchor_evaluate( array( 'webmcp-bridge' => $bridgeBody . '/*drift*/' ), $anchors5, array(), $NOW );
$r = snt_rights_anchor_evaluate( array( 'webmcp-bridge' => $bridgeBody . '/*drift*/' ), $anchors5, $r['state'], $NOW + 3 * $HOUR );
ok( 1 === count( $r['findings'] ) && 'webmcp-bridge' === $r['findings'][0]['subject'], 'webmcp-bridge: drift past grace accuses the right slug' );

$r = snt_rights_anchor_evaluate( array( 'webmcp-bridge' => $bridgeBody ), array( $anchors5[0] ), array(), $NOW );
$r = snt_rights_anchor_evaluate( array( 'webmcp-bridge' => $bridgeBody ), array( $anchors5[0] ), $r['state'], $NOW + 3 * $HOUR );
ok( 1 === count( $r['findings'] ) && false !== strpos( $r['findings'][0]['note'], 'no record' ), 'webmcp-bridge: missing ledger row is the no-record finding' );

ok( isset( sn_rights_anchor_targets_for_test()['webmcp-bridge'] ), 'the fetch-target table names webmcp-bridge' );
```

**Note on the last assertion:** `$targets` is currently a local inside `snt_health_check_rights_anchored()`. Extract it into `sn_rights_anchor_targets_for_test()`? No — follow the file's existing minimalism instead: extract a pure `snt_rights_anchor_targets()` function returning the array (the check calls it), and assert on that name. Pure-function extraction, no behavior change.

- [ ] **Step 2: Run to verify failure** — `php tests/health-check-rights-anchored.php` → the new `ok()` lines FAIL.
- [ ] **Step 3: Implement** — extract and extend:

```php
/**
 * The fixed own-domain fetch targets, one per anchored surface. Extracted
 * pure so the suite can assert the table itself; still HARDCODED paths on
 * home_url, never configurable input.
 *
 * @return array<string,string>
 */
function snt_rights_anchor_targets() {
	return array(
		'robots-txt'    => home_url( '/robots.txt' ),
		'tdmrep-json'   => home_url( '/.well-known/tdmrep.json' ),
		'license-xml'   => home_url( '/license.xml' ),
		'tdm-policy'    => home_url( '/tdm-policy/' ),
		// v5 (2026-08): the WebMCP bridge — the one script agents execute,
		// anchored like the terms it acts under. Design:
		// docs/webmcp-native-design.md.
		'webmcp-bridge' => home_url( '/webmcp/bridge.js' ),
	);
}
```

…and in `snt_health_check_rights_anchored()` replace the inline `$targets = array( … );` with `$targets = snt_rights_anchor_targets();`. Adjust the test's last assertion to the real name `snt_rights_anchor_targets` (the tests file stubs `home_url` the way the sibling fixtures do — mirror them).

- [ ] **Step 4: Run to verify pass** — `php tests/health-check-rights-anchored.php` → all green. Run the repo's full test entrypoint too.
- [ ] **Step 5: Commit** — `git commit -am "feat: webmcp-bridge joins the rights-anchoring watch as the fifth surface"`

### Task 10: Close the arc

- [ ] **Step 1:** CHANGELOG entry (plugin). Version bump per end-of-session rules (minor — new watched surface); `git add CHANGELOG.md package.json package-lock.json` if `npm version` is used here.
- [ ] **Step 2:** Live verification sweep, end to end: tag present on a note page AND the policy page; SRI parity by hand (`curl -s https://juanlentino.com/webmcp/bridge.js | openssl dgst -sha384 -binary | base64` vs the tag); ledger has `webmcp-bridge/v1` and `tdm-policy/v4`; provenance worker `/_sn/status` shows `rights_signals: checked 5, failures 0`; plugin health scan re-run → Trust checks board green with the fifth surface under watch.
- [ ] **Step 3:** Update memory `webmcp-injection-sits-above-the-anchor-vantage.md`: append that the native build SHIPPED (versions, date), superseding the revisit condition — Cloudflare's toggle is now permanently moot here.
- [ ] **Step 4:** PR for this branch (design doc + plan + plugin change ride together).

---

## Self-review notes (already applied)

- Spec coverage: tag-everywhere ✓ (Task 5 covers pass-throughs + both worker HTML pages), SRI + parity invariant ✓ (Tasks 3, 6), fifth surface ✓ (Task 8), plugin watch ✓ (Task 9), rollout order + gates ✓ (checkpoints), failure modes ✓ (Tasks 1-3 tests), out-of-scope respected (no MCP door, no third tool, no upstream asks).
- The one deliberately non-verbatim block is the `verify-page` reconcile block in Task 2: its source files, line range, drop-list, target contract, and pinning tests are all named — the docket is the authority, and hand-porting it blind into this plan would be the riskier move.
- Type consistency: `WEBMCP_SCRIPT_TAG` / `BRIDGE_SOURCE` / `BRIDGE_SRI` / `webmcpBridgeResponse` / `snt_rights_anchor_targets` used identically across tasks.
