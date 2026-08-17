# "Test the bridge" — an authenticated agreement check (R3 §3D Increment 4, closing the rotation blind spot)

**Status:** design, approved 2026-08-14. Not built.
**Closes:** the open question in `docs/proposals/remote-mcp-increment1-client-half.md`
("Should the owner be able to test secret agreement from wp-admin?") and the rotation blind
spot the client-half spec named as unobservable by construction.
**Spans two repos:** a new Worker endpoint (`sn-remote-mcp-worker`, ships v0.4.0) and a
plugin admin action + panel button. Either side can land first — §5.

---

## 1. The problem this closes

`SN_BRIDGE_TOKEN` lives in two places that must agree: a `wp-config.php` constant (origin)
and a Worker secret (edge). **Nothing can observe that they disagree.** A mismatch answers
404 — byte-identical to a dark door, by #642's design — while wp-admin reports `bridge_ready`
and the Worker reports `bridge_secret_bound: true`. Each surface truthfully reports its own
half; the property that matters is the *agreement*, and it is unobservable from either side
alone. That is exactly the shape a botched rotation leaves: both halves look healthy, the
door is silently shut.

The observability slice (#645) gives a *passive* signal — `refused_auth` climbing while the
toggle is ON is the mismatch signature — but that requires an attacker or a real caller to
generate the refusals, and reading them means `wp option get`. This control makes the check
*active and authenticated*: one button, one real round trip, a plain verdict.

## 2. Why authentication IS the test

The elegant core: the origin calls the Worker's test endpoint presenting **its own** half of
the secret as the Bearer. The Worker compares it, timing-safe, against **its** half. So
**acceptance is itself proof the halves agree** — there is no separate "compare the two
values" step that could be implemented wrongly or leak a value. The values never travel
together and neither is ever echoed. Accept/refuse is the first verdict bit, and it is the
one that answers the rotation question.

A round trip then adds the second bit: on acceptance the Worker makes **one real bridge call
back to the origin** and reports what happened, so a single press proves both *the halves
agree* and *the production path works end to end* (route registered, handler ordering, origin
reachable).

## 3. The Worker changes — one capability advert, one test endpoint

### 3.1 Status advertises the capability (the disambiguator)

`/_sn/remote-mcp/status` gains one presence boolean under `config`:
`bridge_test_available: true`. The plugin already probes this endpoint
(`sn_health_remote_mcp_status_probe`, reading `{configured, killed, bridge_secret_bound,
version}`), so this is one more presence boolean in the same posture — no secret, no auth.

**This field is what resolves the mismatch-vs-missing ambiguity.** The test endpoint's refusal
must stay a uniform 404 (§3.2) so it is not an oracle — which means a refused test Bearer
(mismatch) and a missing endpoint (old Worker) look identical *at the test endpoint*. They are
told apart *before* the test call: the plugin reads status first, and only interprets a test
refusal as MISMATCH when status has already confirmed the endpoint exists. A version-gate
(`version >= 0.4.0`) is the fallback if the boolean is ever absent, but the boolean is
preferred — it says what it means.

### 3.2 `POST /_sn/remote-mcp/bridge-test`

Served under `/_sn/` alongside status, so Access does not sit in front of it (same reasoning:
Access swallowed `/mcp/*`).

1. **Auth.** `Authorization: Bearer <token>` compared constant-time (the Worker's existing
   compare in `bridge.mjs`) against `env.SN_BRIDGE_TOKEN`. No match → the Worker's **uniform
   not-found shape, byte-identical to any unauthenticated probe of an unknown path** — the
   endpoint must not become an oracle. Only the secret-holder (the origin whose constant
   matches the Worker's secret) can get past this, which is exactly why acceptance proves
   agreement.
2. **The probe call.** On a matching Bearer, the Worker POSTs to `BRIDGE_ORIGIN_URL`
   (`/signal-noise/v1/bridge`) with `Authorization: Bearer <same token>` and body
   `{ "slug": "signal-noise/bridge-probe" }` — a slug deliberately **off**
   `sn_mcp_remote_slugs()`. No ability executes, no capability is granted, no data moves. The
   expected healthy outcome is the authenticated-caller refusal `sn_bridge_not_found` (404),
   which per #642's asymmetry only a caller whose secret matches the origin's can ever see.
3. **The report.** `200` with a small authenticated-only JSON body:
   ```json
   { "probe": "sn_bridge_not_found", "origin_status": 404, "ms": 41 }
   ```
   `probe` is the origin's error `code` verbatim (`null` if the body carried none);
   `origin_status` its HTTP status. **No verdict, no secret.** The Worker reports facts; the
   plugin owns the verdict (§4) — the origin/edge never asserts a verdict about itself,
   extending §9.5 P-51.
4. **Rate limit.** The probe is one brokered call, keyed under a fixed `rate:bridge:__test__`
   (the caller is the owner's token, not a `sub`). A wrong Bearer costs only the constant-time
   compare — no counter, no origin call — so no anonymous caller can turn this into a probe or
   a drain. **No new anonymous compute** (P-52: the expensive path is secret-gated).

## 4. The verdict — a two-stage join, computed plugin-side

New admin-post action `sn_bridge_test` (dispatched through the existing handler registry;
`manage_options` + nonce enforced by the dispatcher, exactly as `sn_handle_remote_toggle`).
The handler reads status, then (only if the capability is present) calls the test endpoint,
and classifies with a **pure function**
`sn_bridge_test_verdict( $status, $tested, $test_accepted, $probe_code, $door_state )`:

**Stage 1 — is the test even runnable?** (reads status only)

| Status | Verdict — stop here |
| --- | --- |
| unreachable | ⚠️ Worker unreachable — read `/_sn/remote-mcp/status` |
| reachable, `bridge_test_available` false/absent | ⚠️ Worker predates the test capability — update to v0.4.0+ |

**Stage 2 — the endpoint exists, so accept/refuse is unambiguous** (join with door state)

| Test endpoint | probe outcome | panel door state | Verdict |
| --- | --- | --- | --- |
| **refused** (uniform 404) | — | `bridge_ready` | **❌ MISMATCH — the two `SN_BRIDGE_TOKEN` halves disagree. Rotate per the runbook.** |
| **refused** | — | not ready | ⚠️ Inconclusive: the door is not armed at the origin — fix door state, then retest |
| **accepted** (200) | `sn_bridge_not_found` | `bridge_ready` | **✅ Halves agree, path healthy end to end** |
| accepted | `rest_no_route` | any | ⚠️ Halves agree; the origin door is shut (switch off or unregistered) — check the toggle |
| accepted | anything else | any | ⚠️ Halves agree, but the origin answered unexpectedly (`<code>`) — investigate |

A test refusal is read as MISMATCH **only because Stage 1 already confirmed the endpoint
exists** — that is the whole reason the two stages are separate, and it is why the endpoint can
keep its no-oracle uniform 404. Every verdict is a join of authenticated surfaces (status AND
the test answer AND the panel's own door state); no single reading is ever an oracle, and the
wire #642 sealed stays sealed because the control is `manage_options`-gated end to end.

Result surfaces as a **flash notice** on the MCP status panel via the existing flash mechanism
(`sn_admin_flash_to_notice`). **No new storage** — the durable traces are the Worker's
`bridge_call` log line and the observability record; this is a point-in-time check.

## 5. Two deliberate side-effects, documented not suppressed

- **The observability counter gains +1 `refused_slug`** per test (the probe is an
  authenticated off-list call, and #642's handler is deliberately not special-cased). The docs
  say plainly: *a bridge test shows up as one `refused_slug` in the panel's counters.* Not a
  bug — the alternative (special-casing the probe slug in the frozen handler) is worse.
- **The Worker logs the probe as a `bridge_call` line** and it counts into the anomaly
  instrument. A test is a real call; it should look like one in telemetry.

## 6. Sequencing and failure tolerance

- Worker change ships **v0.4.0** (its own PR in `sn-remote-mcp-worker`, that repo's suite
  extended — status/probe/auth). Plugin change rides `[Unreleased]` → next MINOR (new
  user-visible capability).
- **Either side can land first.** The plugin's verdict table has a dedicated row for
  "endpoint 404 / unreachable" (pre-v0.4.0 Worker), so the button degrades to a clear
  "update the Worker" message rather than a confusing failure. No coordination window.
- No probe slug needs to exist at the origin — `signal-noise/bridge-probe` is chosen precisely
  *because* it is off the list; the origin's off-list refusal is the healthy signal.

## 7. Tests

**Plugin** (`tests/bridge-test-control.php`, new):
- The verdict function pinned **exhaustively** over both stages — every row a pin, with the
  "refused while ready → MISMATCH" row labelled as the one that matters.
- **The two-stage discriminator, pinned both directions:** a test refusal classifies as
  MISMATCH *only* when Stage 1 confirmed the capability; the same refusal with
  `bridge_test_available` false classifies as "update the Worker", never MISMATCH. Without
  this pin the mismatch-vs-missing ambiguity §3.1 exists to resolve would silently return.
- A healthy door never reads as mismatch: `accepted` + `sn_bridge_not_found` + ready → agree,
  never mismatch (the "agree" vs "disagree" discriminator).
- The handler is registered in the admin-post registry — `tests/admin-post-actions.php:480`
  pins the map at **55**; this moves it to **56** (that count has ridden a red release before,
  so the bump is expected and must be updated in the same change).
- The Worker call is stubbed; the handler never makes a real HTTP call in tests.
- `manage_options` + nonce enforced (the dispatcher already pins this; assert the action is
  dispatched through it, not registered as its own uncapped endpoint).

**Worker** (`sn-remote-mcp-worker`, its suite):
- `status` carries `config.bridge_test_available: true` (presence boolean, unauthenticated).
- Wrong Bearer on the test endpoint → uniform not-found, **no** origin call made (spy the
  fetch), no counter spent — the no-oracle property.
- Right Bearer → exactly one origin call with the off-list slug; the report shape returned.
- The report carries no secret value and no verdict field.

## 8. Out of scope

- **No stored history** of tests — point-in-time only. (The observability record already
  carries the durable trace.)
- **No scheduling / automated testing** — this is an owner-initiated button. An automated
  agreement monitor would need its own rate budget and is a different feature.
- **No second rate-limit window** — the client-half spec's other open question (per-hour /
  per-day drain bound) is independent and deferred there.
- **No verdict from the Worker** — the origin/edge report facts; the plugin owns the verdict
  (P-51).
