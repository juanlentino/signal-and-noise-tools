# "Test the bridge" Control — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the owner a wp-admin button that tests whether the two `SN_BRIDGE_TOKEN` halves (wp-config constant, Worker secret) AGREE — closing the rotation blind spot that is otherwise unobservable by construction.

**Architecture:** Two repos. In `sn-remote-mcp-worker` (ships v0.4.0): the status endpoint advertises `bridge_test_available`, and a new authenticated `POST /_sn/remote-mcp/bridge-test` makes one real off-list bridge call back to the origin and reports facts. In the `signal-and-noise-tools` plugin: an admin-post action calls that endpoint, and a PURE verdict function joins (status capability + test accept/refuse + door state) into an unambiguous message. Authentication IS the test — the origin presents its own token half, the Worker compares against its half, so acceptance proves agreement.

**Tech Stack:** Cloudflare Workers (ES modules, `vitest` + `cloudflare:test`), WordPress plugin PHP (the repo's standalone `tests/*.php` harness run by `bash tests/run.sh`).

**Spec:** `docs/proposals/remote-mcp-bridge-test-control.md`

---

## Conventions

- **Two repos, two suites.** Worker: `cd ~/Projects/sn-remote-mcp-worker && npm test` (vitest). Plugin: `php tests/<name>.php` for one suite, `bash tests/run.sh` for the sweep — **gate on `$?`, not the summary line** (`run.sh` prints a clean summary then exits 1 on failure; also `grep -c "  FAIL"`).
- **Never `git stash` / `git checkout --`** to revert a probe — copy to `/tmp` and `cp` back.
- **Commit after every task.**
- **Either repo can land first.** Phase B's verdict has a dedicated "update the Worker" row, so the plugin degrades gracefully against a pre-v0.4.0 Worker. Build Phase A first anyway — the plugin's verdict table is written against its contract.

## File Structure

| File | Repo | Responsibility |
| --- | --- | --- |
| `src/status.mjs` | worker | +1 presence boolean `config.bridge_test_available` |
| `src/bridge-test.mjs` *(create)* | worker | the test-endpoint handler: auth, one probe call via `callBridge`, fact-only report |
| `src/index.mjs` | worker | route `POST /_sn/remote-mcp/bridge-test` |
| `test/bridge-test.test.mjs` *(create)* | worker | endpoint auth, probe-call, no-secret/no-verdict pins |
| `test/status.test.mjs` | worker | +1 pin: `bridge_test_available` present |
| `package.json` | worker | version → 0.4.0 |
| `inc/mcp/mcp-bridge-test.php` *(create)* | plugin | the PURE `sn_bridge_test_verdict()`, the `sn_bridge_test_probe()` I/O, the `sn_handle_bridge_test()` handler |
| `inc/admin-post-handler.php:39` | plugin | register `'bridge_test' => 'sn_handle_bridge_test'` |
| `inc/admin-flash-messages.php` | plugin | verdict flash strings → notices |
| `inc/admin-forms/mcp-connect-status.php` | plugin | the "Test the bridge" button on the remote card |
| `signal-and-noise-tools.php` | plugin | require the new module |
| `tests/bridge-test-control.php` *(create)* | plugin | verdict function pinned exhaustively, both stages |
| `tests/admin-post-actions.php:480` | plugin | registry count 55 → 56 |

---

# PHASE A — the Worker (sn-remote-mcp-worker, v0.4.0)

Work from `~/Projects/sn-remote-mcp-worker`. Branch: `git checkout -b feat/bridge-test-endpoint`.

## Task A1: status advertises the capability

**Files:**
- Modify: `src/status.mjs`
- Modify: `test/status.test.mjs`

- [ ] **Step 1: Write the failing test** — append inside the `describe("status — bridge readouts", ...)` block in `test/status.test.mjs`:

```js
  it("advertises bridge_test_available as a PRESENCE boolean", async () => {
    const body = await (await statusResponse({ ...BASE_ENV, SN_BRIDGE_TOKEN: "test-bridge-secret" })).json();
    // The plugin gates its verdict on this: a test refusal is only MISMATCH
    // once this has confirmed the endpoint exists. It is a capability advert,
    // not a secret — a plain true, same posture as bridge_secret_bound.
    expect(body.config.bridge_test_available).toBe(true);
  });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm test -- status`
Expected: FAIL — `expected undefined to be true`.

- [ ] **Step 3: Implement** — in `src/status.mjs`, inside the `config: { ... }` object (after `bridge_secret_bound`), add:

```js
          // Capability advert (v0.4.0), not a secret: the plugin reads this to
          // tell a MISMATCH (test endpoint refused a matching-looking Bearer)
          // from a MISSING endpoint (old Worker). Both are a uniform 404 at the
          // test endpoint by design, so the disambiguation lives here.
          bridge_test_available: true,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm test -- status`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/status.mjs test/status.test.mjs
git commit -m "feat: status advertises bridge_test_available (v0.4.0 capability advert)"
```

## Task A2: the test endpoint handler

**Files:**
- Create: `src/bridge-test.mjs`
- Create: `test/bridge-test.test.mjs`

Context you need from the existing code:
- `src/http.mjs` exports `timingSafeEqual(a, b)` and `json(body, status, extraHeaders)`.
- `src/bridge.mjs` exports `callBridge({ url, secret, slug, args, fetchImpl, timeoutMs })` → returns `{ outcome, status, message }`, where `outcome` is a normalized string (e.g. `"origin_unavailable"`) — but for our probe we want the origin's raw error CODE, so this handler calls the origin directly with `fetchImpl` rather than through `callBridge`'s outcome-mapping (we need `code`, which `callBridge` does not surface). Read `mapOriginResponse` in `src/bridge.mjs` to confirm it drops `code`; if a future refactor surfaces `code`, prefer reusing `callBridge`.
- `readConfig(env)` / `env.SN_BRIDGE_TOKEN` / `env.BRIDGE_ORIGIN_URL` (default in `bridge.mjs` `DEFAULT_BRIDGE_URL`).

- [ ] **Step 1: Write the failing test** — create `test/bridge-test.test.mjs`:

```js
import { describe, expect, it, vi } from "vitest";
import { handleBridgeTest } from "../src/bridge-test.mjs";

const SECRET = "matching-secret";
const ENV = { SN_BRIDGE_TOKEN: SECRET, BRIDGE_ORIGIN_URL: "https://origin.example/signal-noise/v1/bridge" };

function req(bearer) {
  const headers = new Headers();
  if (bearer !== null) headers.set("authorization", `Bearer ${bearer}`);
  return new Request("https://mcp.example/_sn/remote-mcp/bridge-test", { method: "POST", headers });
}

describe("bridge-test endpoint", () => {
  it("THE NO-ORACLE PIN: a wrong Bearer makes NO origin call and returns a uniform 404", async () => {
    const fetchImpl = vi.fn();
    const res = await handleBridgeTest(req("wrong-secret"), ENV, { fetchImpl });
    expect(res.status).toBe(404);
    expect(fetchImpl).not.toHaveBeenCalled(); // no origin call, no counter spent
  });

  it("a missing Authorization header is the same uniform 404, no origin call", async () => {
    const fetchImpl = vi.fn();
    const res = await handleBridgeTest(req(null), ENV, { fetchImpl });
    expect(res.status).toBe(404);
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it("THE AGREEMENT PIN: a matching Bearer makes exactly ONE origin call with the off-list probe slug", async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ code: "sn_bridge_not_found" }), { status: 404 }));
    const res = await handleBridgeTest(req(SECRET), ENV, { fetchImpl });
    expect(res.status).toBe(200);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toBe(ENV.BRIDGE_ORIGIN_URL);
    expect(init.headers.authorization).toBe(`Bearer ${SECRET}`);
    expect(JSON.parse(init.body).slug).toBe("signal-noise/bridge-probe");
  });

  it("reports the origin's code and status as FACTS — no verdict, no secret", async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ code: "sn_bridge_not_found" }), { status: 404 }));
    const body = await (await handleBridgeTest(req(SECRET), ENV, { fetchImpl })).json();
    expect(body.probe).toBe("sn_bridge_not_found");
    expect(body.origin_status).toBe(404);
    expect(typeof body.ms).toBe("number");
    // No verdict field, and the secret never appears anywhere in the body.
    expect(body.verdict).toBeUndefined();
    expect(JSON.stringify(body)).not.toContain(SECRET);
  });

  it("a null-coded origin body reports probe:null rather than inventing one", async () => {
    const fetchImpl = vi.fn(async () => new Response("not json", { status: 502 }));
    const body = await (await handleBridgeTest(req(SECRET), ENV, { fetchImpl })).json();
    expect(body.probe).toBeNull();
    expect(body.origin_status).toBe(502);
  });

  it("only POST is accepted", async () => {
    const res = await handleBridgeTest(
      new Request("https://mcp.example/_sn/remote-mcp/bridge-test", { method: "GET" }), ENV, { fetchImpl: vi.fn() });
    expect(res.status).toBe(405);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm test -- bridge-test`
Expected: FAIL — `handleBridgeTest` is not exported / file missing.

- [ ] **Step 3: Implement** — create `src/bridge-test.mjs`:

```js
// POST /_sn/remote-mcp/bridge-test — the AGREEMENT check.
//
// Authentication IS the test. The origin calls here presenting ITS half of
// SN_BRIDGE_TOKEN as the Bearer; this compares it, constant-time, against the
// Worker's half. A match means the two halves AGREE — no separate value
// comparison exists to get wrong, and neither value is ever echoed.
//
// A wrong Bearer gets the uniform not-found shape, byte-identical to probing an
// unknown path: this endpoint must not be an oracle. Only the secret-holder can
// get past it, which is exactly why acceptance proves agreement. The plugin
// tells "refused (mismatch)" from "endpoint missing (old Worker)" via the
// status advert, not via this refusal — see the plan/spec.
//
// On a match, it makes ONE real bridge call with an OFF-LIST probe slug and
// reports the origin's code + status as FACTS. It renders no verdict: the
// plugin owns that (P-51).

import { timingSafeEqual, json, methodNotAllowed } from "./http.mjs";
import { DEFAULT_BRIDGE_URL } from "./bridge.mjs";

const PROBE_SLUG = "signal-noise/bridge-probe"; // deliberately OFF sn_mcp_remote_slugs()
const TIMEOUT_MS = 6000;

/**
 * The uniform not-found. Must match what the Worker returns for any unknown
 * path (see src/index.mjs's fall-through 404) so a wrong Bearer and an unknown
 * path are indistinguishable.
 */
function uniformNotFound() {
  return json({ error: "not_found" }, 404);
}

export async function handleBridgeTest(request, env, deps = {}) {
  const fetchImpl = deps.fetchImpl || fetch;

  if (request.method !== "POST") return methodNotAllowed("POST");

  const secret = typeof env?.SN_BRIDGE_TOKEN === "string" ? env.SN_BRIDGE_TOKEN : "";
  const header = request.headers.get("authorization") || "";
  const presented = header.startsWith("Bearer ") ? header.slice(7) : "";

  // Empty configured secret authenticates nobody; timingSafeEqual on unequal
  // lengths must return false without leaking length. (http.mjs's helper does.)
  if (secret === "" || !timingSafeEqual(presented, secret)) {
    return uniformNotFound();
  }

  const url = String(env?.BRIDGE_ORIGIN_URL ?? DEFAULT_BRIDGE_URL).trim();
  const startedAt = Date.now();
  let originStatus = null;
  let probeCode = null;
  try {
    const res = await fetchImpl(url, {
      method: "POST",
      headers: { authorization: `Bearer ${secret}`, "content-type": "application/json" },
      body: JSON.stringify({ slug: PROBE_SLUG }),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    originStatus = res.status;
    try {
      const parsed = await res.json();
      probeCode = typeof parsed?.code === "string" ? parsed.code : null;
    } catch {
      probeCode = null; // non-JSON body — a fact, not an error
    }
  } catch {
    return json({ probe: null, origin_status: null, ms: Date.now() - startedAt }, 200);
  }

  return json({ probe: probeCode, origin_status: originStatus, ms: Date.now() - startedAt }, 200);
}
```

> **Note on `Date.now()`:** allowed in Worker runtime code (this is not a workflow script). The `ms` field is diagnostic only.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm test -- bridge-test`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/bridge-test.mjs test/bridge-test.test.mjs
git commit -m "feat: the bridge-test endpoint — agreement by authentication, facts not verdicts"
```

## Task A3: route it, bump the version

**Files:**
- Modify: `src/index.mjs`
- Modify: `package.json`

- [ ] **Step 1: Add the route** — in `src/index.mjs`, in the `fetch()` handler, immediately after the status-route block (`if (path === "/_sn/remote-mcp/status" ...)`), add:

```js
    if (path === "/_sn/remote-mcp/bridge-test") {
      const { handleBridgeTest } = await import("./bridge-test.mjs");
      return await handleBridgeTest(request, env);
    }
```

- [ ] **Step 2: Confirm the uniform-404 match** — read the fall-through 404 at the end of `src/index.mjs`'s `fetch()`. `uniformNotFound()` in `bridge-test.mjs` must return the SAME body+status. If the fall-through uses a different shape, change `uniformNotFound()` to call the same helper the fall-through uses (import it) rather than hand-rolling `{ error: "not_found" }`. Adjust the test's expectation to match if needed. This is the no-oracle property; verify it, don't assume it.

- [ ] **Step 3: Bump version** — in `package.json`, set `"version": "0.4.0"`. Do NOT change the `increment: 2` field in `status.mjs` — that is the R3 §3D increment identifier, not the Worker semver.

- [ ] **Step 4: Run the full Worker suite**

Run: `npm test`
Expected: all green, including the new `bridge-test` and updated `status` files.

- [ ] **Step 5: Commit**

```bash
git add src/index.mjs package.json
git commit -m "feat: route /_sn/remote-mcp/bridge-test; v0.4.0"
```

> **Deploy is the owner's step**, via the repo's `deploy` script (`wrangler deploy --var SN_VERSION:...`). Do not deploy from the plan. After deploy, verify `curl -s https://juanlentino.com/_sn/remote-mcp/status | grep bridge_test_available` shows `true` — and expect the per-colo propagation race (the first curl may show the old version; retry).

---

# PHASE B — the plugin (signal-and-noise-tools)

Work from the plugin worktree. Branch: `git checkout -b feat/bridge-test-control`.

## Task B1: the pure verdict function

**Files:**
- Create: `inc/mcp/mcp-bridge-test.php`
- Create: `tests/bridge-test-control.php`

- [ ] **Step 1: Write the failing test** — create `tests/bridge-test-control.php`:

```php
<?php
/**
 * Tests: the "test the bridge" verdict is a two-stage PURE join.
 *
 * THE PROPERTY THAT MATTERS MOST: a test refusal is read as MISMATCH only after
 * Stage 1 confirmed the endpoint exists. Without that, a mismatch (wrong secret)
 * and a missing endpoint (old Worker) — both a uniform 404 — are indistinguishable,
 * which is the ambiguity this design exists to resolve.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }
function __( $s, $d = null ) { return (string) $s; }

require __DIR__ . '/../inc/mcp/mcp-bridge-test.php';

// sn_bridge_test_verdict( $status_ok, $capable, $accepted, $probe_code, $door_ready )
//   returns one of the flash-string constants below.

echo "Group: Stage 1 — the test cannot run\n";
ok( 'bridge_test_unreachable' === sn_bridge_test_verdict( false, false, null, null, true ), 'status unreachable -> unreachable, never mismatch' );
ok( 'bridge_test_incapable'  === sn_bridge_test_verdict( true, false, null, null, true ),  'reachable but capability absent -> update the Worker, never mismatch' );

echo "Group: Stage 2 — endpoint exists, accept/refuse is unambiguous\n";
ok( 'bridge_test_mismatch'   === sn_bridge_test_verdict( true, true, false, null, true ),  'THE ONE THAT MATTERS: capable + refused + door ready -> MISMATCH' );
ok( 'bridge_test_door_shut'  === sn_bridge_test_verdict( true, true, false, null, false ), 'capable + refused + door NOT ready -> inconclusive, fix the door' );
ok( 'bridge_test_healthy'    === sn_bridge_test_verdict( true, true, true, 'sn_bridge_not_found', true ),  'capable + accepted + off-list refusal + ready -> HEALTHY' );
ok( 'bridge_test_origin_shut'=== sn_bridge_test_verdict( true, true, true, 'rest_no_route', true ),        'capable + accepted + rest_no_route -> agree, origin door shut' );
ok( 'bridge_test_origin_odd' === sn_bridge_test_verdict( true, true, true, 'something_else', true ),       'capable + accepted + unexpected code -> agree, investigate' );

echo "Group: the discriminators\n";
// A healthy door must NEVER read as mismatch — the accept path and refuse path
// are disjoint on $accepted, so no probe_code on an accepted call is a mismatch.
ok( 'bridge_test_mismatch' !== sn_bridge_test_verdict( true, true, true, 'sn_bridge_not_found', true ), 'an ACCEPTED test is never mismatch, whatever the probe code' );
// The two-stage discriminator: the SAME refusal flips verdict on capability.
$refused_capable   = sn_bridge_test_verdict( true, true,  false, null, true );
$refused_incapable = sn_bridge_test_verdict( true, false, false, null, true );
ok( $refused_capable !== $refused_incapable, 'THE TWO-STAGE PIN: an identical refusal is MISMATCH when capable and NOT-mismatch when incapable' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): bridge-test-control.php\n"
	: "\nFAILURES ($pass passed, $fail failed): bridge-test-control.php\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/bridge-test-control.php`
Expected: PHP fatal — `require` of a file that does not exist.

- [ ] **Step 3: Implement** — create `inc/mcp/mcp-bridge-test.php` with the pure function only for now:

```php
<?php
/**
 * Signal & Noise — the "test the bridge" control (R3 §3D Increment 4).
 *
 * Closes the SN_BRIDGE_TOKEN rotation blind spot: the wp-config constant and
 * the Worker secret must AGREE, and nothing else can observe that they do not.
 * This control makes the check active and authenticated — one button, one real
 * round trip, a plain verdict.
 *
 * THE VERDICT IS A TWO-STAGE JOIN, and it is PURE (this function reads nothing
 * live). Stage 1 asks whether the test can run at all (status reachable and the
 * capability advertised). Only inside Stage 2 — where the endpoint is KNOWN to
 * exist — is a test refusal read as MISMATCH. That is what lets the Worker's
 * test endpoint keep its no-oracle uniform 404 while the owner still gets an
 * unambiguous answer.
 *
 * @package SignalNoiseTools
 * @since 11.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify the test outcome. PURE — no I/O, so every branch is a pin.
 *
 * @param bool        $status_ok  The status probe reached the Worker.
 * @param bool        $capable    status.config.bridge_test_available was true.
 * @param bool|null   $accepted   The test endpoint accepted the Bearer (true),
 *                                refused it (false), or was not called (null).
 * @param string|null $probe_code The origin's error code from the probe call,
 *                                or null. Only meaningful when $accepted.
 * @param bool        $door_ready The panel's own remote-door state is bridge_ready.
 * @return string A flash key (see sn_admin_flash_to_notice()).
 */
function sn_bridge_test_verdict( $status_ok, $capable, $accepted, $probe_code, $door_ready ) {
	// Stage 1 — can the test even run?
	if ( ! $status_ok ) {
		return 'bridge_test_unreachable';
	}
	if ( ! $capable ) {
		return 'bridge_test_incapable';
	}

	// Stage 2 — the endpoint exists, so accept/refuse is unambiguous.
	if ( true !== $accepted ) {
		// Refused, and we KNOW the endpoint exists: the halves disagree —
		// unless the origin door simply is not armed, which makes it moot.
		return $door_ready ? 'bridge_test_mismatch' : 'bridge_test_door_shut';
	}

	// Accepted: the halves AGREE. The probe code says whether the path is healthy.
	if ( 'sn_bridge_not_found' === (string) $probe_code ) {
		return 'bridge_test_healthy';
	}
	if ( 'rest_no_route' === (string) $probe_code ) {
		return 'bridge_test_origin_shut';
	}
	return 'bridge_test_origin_odd';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/bridge-test-control.php`
Expected: `OK (9 passed, 0 failed): bridge-test-control.php`.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-bridge-test.php tests/bridge-test-control.php
git commit -m "feat: the bridge-test verdict — a pure two-stage join"
```

## Task B2: the probe I/O + the handler

**Files:**
- Modify: `inc/mcp/mcp-bridge-test.php` (append)

Context: `sn_health_remote_mcp_status_probe()` (in `inc/health-edge-workers.php`) already fetches the status JSON, SSRF-guarded and cached, returning the decoded array or null. Reuse it for Stage 1. The remote door state comes from the same `remote_state` resolution the panel uses — read `inc/admin-forms/mcp-connect-status.php` for `sn_mcp_remote_kill_switch_engaged()` and the `bridge_ready` derivation; the handler needs only the boolean "is it bridge_ready".

- [ ] **Step 1: Append the probe caller + handler** to `inc/mcp/mcp-bridge-test.php`:

```php
/** The Worker's test endpoint. Same host as the status probe. */
const SN_BRIDGE_TEST_URL = 'https://juanlentino.com/_sn/remote-mcp/bridge-test';

/**
 * Call the Worker's test endpoint with the ORIGIN's token half as the Bearer.
 * Returns array{ accepted:bool, probe:?string } or null when unreachable.
 *
 * SSRF-guarded exactly as the status probe: fixed https host on our own zone.
 * The secret is sent as a Bearer to our OWN Worker only; it is never logged.
 *
 * @return array{accepted:bool,probe:?string}|null
 */
function sn_bridge_test_probe() {
	if ( ! function_exists( 'wp_remote_post' ) ) {
		return null;
	}
	$secret = defined( 'SN_BRIDGE_TOKEN' ) ? (string) SN_BRIDGE_TOKEN : '';
	if ( '' === $secret ) {
		return null; // no origin half configured — Stage 1 handles the message via capability/door
	}
	$response = wp_remote_post( SN_BRIDGE_TEST_URL, array(
		'timeout'     => 8,
		'redirection' => 0,
		'headers'     => array( 'Authorization' => 'Bearer ' . $secret ),
		'body'        => wp_json_encode( array() ),
	) );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	// 200 => accepted (halves agree); 404 => refused (uniform not-found).
	if ( 200 === $code ) {
		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$probe = is_array( $data ) && isset( $data['probe'] ) && is_string( $data['probe'] ) ? $data['probe'] : null;
		return array( 'accepted' => true, 'probe' => $probe );
	}
	return array( 'accepted' => false, 'probe' => null );
}

/**
 * admin-post handler for sn_action=bridge_test. Reads status (Stage 1), calls
 * the test endpoint (Stage 2) only if capable, and returns a verdict flash key.
 *
 * The dispatcher (inc/admin-post-handler.php) has already enforced
 * manage_options + the nonce, exactly as it does for remote_toggle.
 *
 * @param array $post Unused — this control takes no form fields beyond the action.
 * @return string A flash key.
 */
function sn_handle_bridge_test( $post ) {
	$status  = function_exists( 'sn_health_remote_mcp_status_probe' ) ? sn_health_remote_mcp_status_probe() : null;
	$ok      = is_array( $status );
	$capable = $ok && ! empty( $status['config']['bridge_test_available'] );

	$door_ready = function_exists( 'sn_mcp_remote_kill_switch_engaged' )
		&& ! sn_mcp_remote_kill_switch_engaged()
		&& '' !== ( function_exists( 'sn_bridge_secret' ) ? sn_bridge_secret() : '' );

	$accepted = null;
	$probe    = null;
	if ( $capable ) {
		$result = sn_bridge_test_probe();
		if ( null === $result ) {
			// Capable per status but the call itself did not complete — treat as
			// unreachable rather than inventing a mismatch.
			return sn_bridge_test_verdict( false, false, null, null, $door_ready );
		}
		$accepted = $result['accepted'];
		$probe    = $result['probe'];
	}

	return sn_bridge_test_verdict( $ok, $capable, $accepted, $probe, $door_ready );
}
```

- [ ] **Step 2: Verify no test regressed** (the pure function is unchanged; this only adds I/O functions the standalone suite does not call):

Run: `php tests/bridge-test-control.php`
Expected: `OK (9 passed, 0 failed)`.

- [ ] **Step 3: Commit**

```bash
git add inc/mcp/mcp-bridge-test.php
git commit -m "feat: the bridge-test handler — status probe, test call, verdict"
```

## Task B3: register the handler + require the module

**Files:**
- Modify: `inc/admin-post-handler.php:39`
- Modify: `signal-and-noise-tools.php`
- Modify: `tests/admin-post-actions.php:480`

- [ ] **Step 1: Update the registry-count pin FIRST (RED)** — in `tests/admin-post-actions.php` line 480, change `pa_eq( 55, count( $map ), 'map has 55 actions' );` to:

```php
pa_eq( 56, count( $map ), 'map has 56 actions' ); // + bridge_test (the "test the bridge" agreement control)
```

- [ ] **Step 2: Run it to confirm RED**

Run: `php tests/admin-post-actions.php`
Expected: FAIL — map has 55, expected 56.

- [ ] **Step 3: Register the handler** — in `inc/admin-post-handler.php`, in the map (line 39 is `'remote_toggle' => 'sn_handle_remote_toggle',`), add immediately after it:

```php
		'bridge_test'                => 'sn_handle_bridge_test',
```

- [ ] **Step 4: Require the module** — in `signal-and-noise-tools.php`, beside the other `inc/mcp/` requires (e.g. after `mcp-bridge-route.php`):

```php
require_once SNT_PATH . 'inc/mcp/mcp-bridge-test.php';
```

- [ ] **Step 5: Run to confirm GREEN**

Run: `php tests/admin-post-actions.php`
Expected: PASS — map has 56.

- [ ] **Step 6: Commit**

```bash
git add inc/admin-post-handler.php signal-and-noise-tools.php tests/admin-post-actions.php
git commit -m "feat: register the bridge_test admin-post handler (registry 55->56)"
```

## Task B4: the verdict flash notices

**Files:**
- Modify: `inc/admin-flash-messages.php`

Context: `sn_admin_flash_to_notice( $flash )` maps a flash key to `array( $level, $html )`. Find where the `remote_enabled` / `remote_disabled` keys are mapped (`grep -n "remote_enabled" inc/admin-flash-messages.php`; if absent there they are added via a filter — grep the repo for the string). Add the seven verdict keys in the same structure.

- [ ] **Step 1: Add the notices** — add these keys to the map in `sn_admin_flash_to_notice()` (levels: `error` red, `warning` yellow, `success` green):

```php
		'bridge_test_healthy'     => array( 'success', __( 'Bridge test passed: the two SN_BRIDGE_TOKEN halves agree and the path is healthy end to end.', 'signal-and-noise-tools' ) ),
		'bridge_test_mismatch'    => array( 'error',   __( 'Bridge test FAILED: the wp-config constant and the Worker secret disagree. Rotate the token per the revoke runbook.', 'signal-and-noise-tools' ) ),
		'bridge_test_door_shut'   => array( 'warning', __( 'Bridge test inconclusive: the remote door is not armed at the origin. Arm it, then test again.', 'signal-and-noise-tools' ) ),
		'bridge_test_origin_shut' => array( 'warning', __( 'The secrets agree, but the origin door answered as shut. Check the remote toggle.', 'signal-and-noise-tools' ) ),
		'bridge_test_origin_odd'  => array( 'warning', __( 'The secrets agree, but the origin answered unexpectedly. Check the bridge route.', 'signal-and-noise-tools' ) ),
		'bridge_test_incapable'   => array( 'warning', __( 'The remote Worker predates the bridge-test capability. Update it to v0.4.0 or later.', 'signal-and-noise-tools' ) ),
		'bridge_test_unreachable' => array( 'warning', __( 'The remote Worker did not answer, so the bridge could not be tested. Check its status.', 'signal-and-noise-tools' ) ),
```

- [ ] **Step 2: Verify parse + no regression**

Run: `php -l inc/admin-flash-messages.php && bash tests/run.sh > /tmp/bt.txt 2>&1; echo "EXIT=$?"; tail -1 /tmp/bt.txt; grep -c "  FAIL" /tmp/bt.txt`
Expected: `No syntax errors`, `EXIT=0`, `0`.

- [ ] **Step 3: Commit**

```bash
git add inc/admin-flash-messages.php
git commit -m "feat: verdict flash notices for the bridge test"
```

## Task B5: the button on the remote card

**Files:**
- Modify: `inc/admin-forms/mcp-connect-status.php`

Context: this file renders the remote card and (from Increment 1) the toggle form. Read how the toggle form posts (`sn_action=remote_toggle`, the `sn_theme_options_nonce` via `wp_nonce_field`, the page hidden field). Mirror it for a second submit that posts `sn_action=bridge_test`.

- [ ] **Step 1: Add the button** — after the remote toggle form in the remote card, add a second minimal form (match the file's existing markup/escaping for the toggle):

```php
		<form method="post" class="sn-remote-test-form">
			<?php wp_nonce_field( 'sn_theme_options_nonce' ); ?>
			<input type="hidden" name="page" value="sn-analytics" />
			<input type="hidden" name="sn_action" value="bridge_test" />
			<button type="submit" class="button">
				<?php esc_html_e( 'Test the bridge', 'signal-and-noise-tools' ); ?>
			</button>
			<span class="description">
				<?php esc_html_e( 'Makes one authenticated round trip to confirm the wp-config secret and the Worker secret agree. Counts as one refused call in the usage line below.', 'signal-and-noise-tools' ); ?>
			</span>
		</form>
```

Render it in every remote-card state (not only `bridge_ready`) — the verdict handles a not-armed door, and the owner needs the button most when something is wrong.

- [ ] **Step 2: Verify parse + no regression**

Run: `php -l inc/admin-forms/mcp-connect-status.php && bash tests/run.sh > /tmp/bt2.txt 2>&1; echo "EXIT=$?"; tail -1 /tmp/bt2.txt; grep -c "  FAIL" /tmp/bt2.txt`
Expected: `No syntax errors`, `EXIT=0`, `0`.

- [ ] **Step 3: Commit**

```bash
git add inc/admin-forms/mcp-connect-status.php
git commit -m "feat: the 'Test the bridge' button on the remote card"
```

## Task B6: documentation + final verification

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/proposals/remote-mcp-bridge-test-control.md` (status line)

- [ ] **Step 1: CHANGELOG** — under `## [Unreleased]`, add:

```markdown
### Added
- **A "Test the bridge" control in the MCP status panel** closes the `SN_BRIDGE_TOKEN` rotation blind spot: the wp-config constant and the Worker secret must agree, and nothing else could observe that they do not. One button makes an authenticated round trip — the origin presents its own token half to a new Worker endpoint, so *acceptance itself proves the halves agree* — then the Worker makes one real off-list bridge call and reports the result. The verdict is a pure two-stage join (status capability, then accept/refuse, then door state), so a refusal reads as MISMATCH only once the endpoint is known to exist; the Worker's endpoint keeps its no-oracle uniform 404. A test counts as one `refused_slug` in the usage line, deliberately. Needs the Worker at v0.4.0+ (the panel says so if not).
```

- [ ] **Step 2: Flip the spec status** — in `docs/proposals/remote-mcp-bridge-test-control.md`, change `**Status:** design, approved 2026-08-14. Not built.` to `**Status:** BUILT 2026-08-14 (plugin half; Worker half ships sn-remote-mcp-worker v0.4.0).`

- [ ] **Step 3: Final verification**

Run:
```bash
bash tests/run.sh > /tmp/btf.txt 2>&1; echo "EXIT=$?"; tail -1 /tmp/btf.txt; grep -c "  FAIL" /tmp/btf.txt
composer lint
composer phpstan
```
Expected: `EXIT=0`, `0` FAIL lines, lint clean, phpstan `[OK]` with the progress bar completed.

- [ ] **Step 4: Mutation-verify the two load-bearing pins** — confirm each mutation APPLIED (non-empty diff) before believing it; restore via `cp` from a `/tmp` copy, never `git checkout`:
  - In `sn_bridge_test_verdict`, delete the `if ( ! $capable )` block → the TWO-STAGE PIN and the incapable pin must red.
  - Change `$door_ready ? 'bridge_test_mismatch' : 'bridge_test_door_shut'` to always return `'bridge_test_mismatch'` → the door-shut pin must red.

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md docs/proposals/remote-mcp-bridge-test-control.md
git commit -m "docs: record the bridge-test control"
```

---

## Definition of done

- [ ] Worker: `npm test` green; `bridge_test_available: true` in status; the endpoint makes no origin call on a wrong Bearer and one on a matching one; version 0.4.0. **Owner deploys.**
- [ ] Plugin: `bash tests/run.sh` exits 0, zero `  FAIL`; `composer lint` + `composer phpstan` clean.
- [ ] The verdict function is pure and pinned over both stages; the two-stage discriminator and the accepted-never-mismatch discriminator both hold, mutation-verified.
- [ ] Registry count pin moved 55 → 56 in the same change that registered the handler.
- [ ] The button renders in every remote-card state; a press with a pre-v0.4.0 Worker says "update the Worker", not a confusing failure.
- [ ] CHANGELOG under `[Unreleased]`, no version bump (the release train owns versioning).
