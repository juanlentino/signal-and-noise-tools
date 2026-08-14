# Increments 2 + 4 Implementation Plan (the named set, and the hardening around it)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Widen the remote MCP set from 1 tool to 8 (origin twins + Worker tool table), and land the hardening that makes the widening safe: DO-based volume anomaly detection (fail-open instrument), the fifth-worker health check, exact dependency pins, and the reconciliation diagnostic.

**Architecture:** Origin half — one new file `inc/abilities-remote-set.php` (7 twins + 7 per-slug callbacks) + `sn_mcp_remote_slugs()` growing to 8. Client half — `src/bridge.mjs` generalises to a tool table with one shared ordered path; `src/edge-state.mjs` gains a UTC-day counter + anomaly summary; `src/status.mjs` goes async and reports the anomaly block. Hardening — `inc/health-edge-workers.php` fifth worker, spec/runbook amendments. Spec: `docs/proposals/remote-mcp-increments-2-and-4.md` (approved 2026-08-14).

**Tech Stack:** WordPress plugin PHP (bespoke test harness, `bash tests/run.sh` gated on **exit code**) + Cloudflare Worker (vitest in real workerd).

**Repos:** Origin/hardening tasks run in a `signal-and-noise-tools` worktree; Worker tasks (prefix W) run in `~/Projects/sn-remote-mcp-worker`. Every task names its repo.

**House rules binding every task:** mutation sweeps are part of done; no real secret anywhere; comments carry the why at the repo's density; commit before mutating; restore by `cp`, never `git checkout`/`stash`; prove each mutation landed (`git diff --stat`) before believing its result; gate PHP suites on `bash tests/run.sh` **exit code**, never the summary line alone.

---

### Task 0: Gate on the peer, then branch (both repos)

**Files:** none

- [ ] **Step 1 (plugin):** `git fetch origin && git log --oneline origin/main -3`. **The peer's observability PR (`claude/remote-door-observability` — adds `inc/mcp/mcp-remote-observability.php`) must be merged.** If it is not on `origin/main` yet, STOP and report BLOCKED — `sn_mcp_remote_slugs()`'s neighbourhood and the CHANGELOG are contended, and this work rebases over the peer, never races it.
- [ ] **Step 2 (plugin):** from the worktree: `git checkout -b feat/increment-2-origin-half origin/main`. Run `bash tests/run.sh; echo "exit: $?"` — expect exit 0 (baseline ~430 suites; record the exact suite/assertion counts, they are the deltas' denominator).
- [ ] **Step 3 (worker):** `cd ~/Projects/sn-remote-mcp-worker && git checkout main && git pull --ff-only && git checkout -b feat/increment-2-client-half` — baseline `npx vitest run` = **100 green**.
- [ ] **Step 4 (plugin):** commit this plan file on the branch: `git add docs/plans/2026-08-14-increments-2-and-4.md && git commit -m "docs: implementation plan for Increments 2 + 4"`.

---

## Phase O — origin half (plugin repo)

### Task O1: `inc/abilities-remote-set.php` — seven twins, seven literal callbacks

**Files:**
- Create: `inc/abilities-remote-set.php`
- Modify: `inc/mcp/mcp-remote-guard.php` (the `sn_mcp_remote_slugs()` return array — read the file first; the peer's merge may have annotated it)
- Modify: whatever file `require`s `abilities-remote-analytics.php` (find with `git grep -n "abilities-remote-analytics"`) — add the sibling require beside it

- [ ] **Step 1: Write the failing tests first** — Task O2's `tests/abilities-remote-set.php` (below). Run `php tests/abilities-remote-set.php` → expect fatal/failures (file absent).

- [ ] **Step 2: The seven callbacks, verbatim** (top of the new file, after the ABSPATH guard). Each passes its own slug as a **literal** — the whole point, per the origin-half doc §3:

```php
function snt_ability_perm_remote_analytics_events() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-analytics-events' );
}
function snt_ability_perm_remote_insights() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-insights' );
}
function snt_ability_perm_remote_narration() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-narration' );
}
function snt_ability_perm_remote_uptime_status() {
	return sn_remote_analytics_allows( 'signal-noise/remote-uptime-status' );
}
function snt_ability_perm_remote_health_scan() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-health-scan' );
}
function snt_ability_perm_remote_rss_stats() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-rss-stats' );
}
function snt_ability_perm_remote_deploy_status() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-deploy-status' );
}
```

The file header comment must carry: the Increment 1 isolation rationale by reference (twin slugs so admin registrations stay byte-identical); `show_in_rest => false` **from birth** with a pointer to the #641 oracle; the duplication-plus-parity contract; and the `force_refresh` strip rationale (spec §3).

- [ ] **Step 3: One complete exemplar registration** — `remote-get-analytics-events`:

```php
	wp_register_ability( 'signal-noise/remote-get-analytics-events', array(
		'label'               => 'Get custom events (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-analytics-events. '
			. 'Returns top custom events (name → events/visitors) for a window '
			. '(range: 7|14|30|90|365|all; values validated by the origin). Read-only. '
			. 'NOTE: takes range ONLY — no class argument exists on this ability. '
			. 'Reachable only by a principal holding the sn_read_remote_analytics '
			. 'capability, and only while the remote door is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_analytics_events',
		'execute_callback'    => 'sn_ability_get_analytics_events',
		'input_schema'        => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
			),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-analytics.php — the parity pin in tests enforces ===.
		'output_schema'       => array( /* copy from admin registration */ ),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );
```

The `/* copy from admin registration */` is an instruction to the implementer, not shippable code: open the named admin file, copy its `output_schema` array literally. The parity test is what makes literal copying safe.

- [ ] **Step 4: The other six registrations**, derived from the exemplar by this table — every cell verified against `main` during scoping. `execute_callback` and `output_schema` come from the named source file; **input schemas follow the third column exactly**:

| Remote slug | Source file / execute_callback | Twin input schema |
| --- | --- | --- |
| `remote-get-insights` | `abilities-insights.php` / `snt_ability_get_insights` | empty: `type [object,null]`, `properties: array()`, `additionalProperties: false` |
| `remote-get-narration` | `abilities-narration.php` / `snt_ability_get_narration` | empty (as above) |
| `remote-uptime-status` | `uptime-status.php` / `snt_ability_uptime_status` | **empty — the admin's `force_refresh` key is DELIBERATELY OMITTED** (spec §3); add the strip comment |
| `remote-get-health-scan` | `abilities-health.php` / `snt_ability_get_health_scan` | empty |
| `remote-get-rss-stats` | `abilities-content.php` / `snt_ability_get_rss_stats` | empty |
| `remote-get-deploy-status` | `abilities-system.php` / `snt_ability_get_deploy_status` | **empty — `force_refresh` DELIBERATELY OMITTED** (spec §3); add the strip comment |

The strip comment, verbatim, on both stripped twins:

```php
		// The admin ability accepts force_refresh (a deliberate cache bypass
		// that hits an upstream API fresh). The twin DOES NOT CARRY THE KEY:
		// a phone caller must not spend the origin's upstream quotas, and the
		// 2am case needs the last known answer, not a fresh probe. The edge
		// gate refuses the key too — two layers, deliberately redundant.
		// Spec: remote-mcp-increments-2-and-4.md §3.
```

Descriptions for the six: follow the exemplar's shape (twin-of line, what it returns, the inline caveat that matters for THAT tool, the capability/door sentence). Insights/narration add: "Returns operational commentary in the owner's voice (R-3D-d accepted)." Uptime/deploy add: "Served from the origin's cache; there is no force_refresh remotely."

- [ ] **Step 5: Widen `sn_mcp_remote_slugs()`** in `inc/mcp/mcp-remote-guard.php` to the eight-member array (alphabetical after the summary, matching the registration order):

```php
	return array(
		'signal-noise/remote-get-analytics-summary',
		'signal-noise/remote-get-analytics-events',
		'signal-noise/remote-get-insights',
		'signal-noise/remote-get-narration',
		'signal-noise/remote-uptime-status',
		'signal-noise/remote-get-health-scan',
		'signal-noise/remote-get-rss-stats',
		'signal-noise/remote-get-deploy-status',
	);
```

- [ ] **Step 6:** add the `require` beside `abilities-remote-analytics.php`'s, run Task O2's suite → green, run the FULL sweep (`bash tests/run.sh; echo $?`) → exit 0. Expect the peer's suites and `tests/mcp-remote-guard.php` count pins to red until updated (Task O2 handles them — if a pin reds that O2 does not name, STOP and report it).

- [ ] **Step 7: Commit** — `feat: the remote set grows to eight — seven twins, each holding its own name (R3 §3D Increment 2, origin half)`

### Task O2: The matrix test, the parity pins, and the ripple

**Files:**
- Create: `tests/abilities-remote-set.php`
- Modify: `tests/mcp-remote-guard.php` (the remote-list count pin: 1 → 8; read the file for the pin's exact name)
- Modify: `tests/mcp-capabilities.php` ONLY IF its read-allowlist pins red — they must NOT move (the read list is untouched); if they red, the implementation is wrong, not the test

- [ ] **Step 1: The new suite.** Follow the harness idiom in `tests/abilities-remote-analytics.php` (stub bootstrap, `ok()` assertions, summary line). Assertion groups, each with real code in the file:

1. **THE MATRIX (the owed test):** for each of the 8 remote slugs × 8 per-slug callbacks: with switch on + capability granted, callback `i` returns true for its own slug's gate and — by construction of the literal — `sn_remote_analytics_allows(<slug j≠i>)` still gates slug `j` independently. Concretely: loop `foreach ($map as $slug => $callback)`, assert `$callback() === true`; then for every OTHER slug's callback assert it does not authorize when ITS OWN slug is removed from the allowlist while `$slug` remains — the assertion that reds when a callback carries a sibling's literal. Implementation sketch the file must realise: filter `sn_mcp_remote_slugs` (or the test-stub equivalent the Increment 1 suite uses) down to ONE member at a time; exactly one of the eight callbacks may return true per single-member list, and it must be the matching one. 8 iterations × 8 checks = 64 assertions, loop-generated.
2. **Parity, output:** for all 7 twins, `output_schema === ` the admin registration's (fetch both via the registry as `tests/abilities-remote-analytics.php:119-120` does).
3. **Parity, input:** `input_schema ===` admin's for `events` + the four empty ones. For `uptime-status` and `deploy-status`: assert the admin schema **HAS** `force_refresh` and the twin **does NOT** — two assertions each, named `THE STRIP PIN: <slug> — the twin refuses what the admin accepts`. (A bare `===` parity pin here would be WRONG and must not be written: the twins deliberately differ.)
4. **`show_in_rest` is false for all 7** — loop, one assertion each, named after #641.
5. **Read-door absence:** all 8 remote slugs absent from `sn_mcp_allowlist()`; the read list's cardinality pin unchanged.
6. **Execute sharing:** each twin's `execute_callback` string-equals the admin's (the table above, pinned).

- [ ] **Step 2:** update `tests/mcp-remote-guard.php`'s count pin to 8, preserving its name-style. Full sweep → exit 0.
- [ ] **Step 3: Commit** — `test: the 8x8 matrix — each remote callback answers for its own name and no other`

### Task O3: Origin mutation sweep

Backups to `/tmp/inc2-sweep/` by `cp`; per row: apply → `git diff --stat` proves it landed → run the NAMED suite + the full sweep exit code → confirm the named pin is IN the failures → restore → diff empty.

| # | Mutation | Must red |
| --- | --- | --- |
| 1 | In `snt_ability_perm_remote_insights()`, swap the literal for `'signal-noise/remote-get-narration'` | the MATRIX rows for insights (single-member-list iteration: insights callback true under narration's solo list, false under its own) |
| 2 | Add `force_refresh` back to the `remote-uptime-status` twin input schema | `THE STRIP PIN: remote-uptime-status` |
| 3 | Flip one twin's `show_in_rest` to `true` | the #641 pin for that twin |
| 4 | Add `'signal-noise/remote-get-insights'` to `sn_mcp_allowlist()` | read-door absence pin + the read list's cardinality pins (both suites, per the Increment 1 precedent) |
| 5 | Change `remote-get-deploy-status`'s execute callback to `snt_ability_get_health_scan` | the execute-sharing pin for deploy-status |
| 6 | Remove `remote-get-narration` from `sn_mcp_remote_slugs()` | narration's matrix row + the count pin (7 ≠ 8) |

- [ ] Record results in this plan under `## Mutation results — origin (measured)`, then commit: `test: origin sweep — six mutations, each redding its named pin`

## Mutation results — origin (measured)

Backups kept in the session scratchpad (not `/tmp`, per this harness's file-placement rule;
functionally identical — `cp` in, `cp` back, `git diff --stat` empty after every restore).
All six applied to a clean `git status`, confirmed landed via `git diff --stat`, confirmed
restored via `git diff --stat` again. Full sweep was exit 0 / 431 suites / 17291 assertions
both before row 1 and after the final restore.

| # | Mutation | Result |
| --- | --- | --- |
| 1 | `snt_ability_perm_remote_insights()` literal → narration's slug | RED as named. `tests/abilities-remote-set.php`: `solo list [signal-noise/remote-get-insights]: snt_ability_perm_remote_insights() is true` and `solo list [signal-noise/remote-get-narration]: snt_ability_perm_remote_insights() is false` — exactly insights' own-slug row and narration's-solo-list row, no other row moved (109/111 passed; full sweep 17289/17291 assertions). |
| 2 | `force_refresh` added back to `remote-uptime-status`'s twin schema | RED as named. `THE STRIP PIN: remote-uptime-status — the twin refuses what the admin accepts` (110/111 passed). |
| 3 | `remote-get-rss-stats`'s `show_in_rest` flipped to `true` | RED as named. `#641: signal-noise/remote-get-rss-stats carries no public run route (show_in_rest: false)` (110/111 passed). |
| 4 | `signal-noise/remote-get-insights` added to `sn_mcp_allowlist()` | RED as named, on BOTH suites. `tests/abilities-remote-set.php`: `signal-noise/remote-get-insights is absent from the READ allowlist`. `tests/mcp-capabilities.php`: both cardinality pins (`read-door allowlist has exactly 38 slugs`, `read door carries exactly 28 plugin slugs`) plus its own absence pin for the same slug. |
| 5 | `remote-get-deploy-status`'s `execute_callback` → `snt_ability_get_health_scan` | RED as named. `signal-noise/remote-get-deploy-status execute_callback string-equals signal-noise/get-deploy-status's` (110/111 passed). |
| 6 | `remote-get-narration` removed from the PRODUCTION `sn_mcp_remote_slugs()` (`inc/mcp/mcp-remote-guard.php`) | PARTIAL — deviation from the plan's expected surface, recorded here rather than silently reconciled. `tests/mcp-remote-guard.php`'s count pin reds as named (`the remote list holds exactly the eight Increment 1 + Increment 2 slugs`, 21/22 passed). **`tests/abilities-remote-set.php`'s matrix did NOT red**, because that suite's matrix runs against a self-contained test double of `sn_mcp_remote_slugs()`/`sn_remote_analytics_allows()` (`$GLOBALS['__remote_slugs']`), not the production function in `inc/mcp/mcp-remote-guard.php` — a design necessity documented in the test file's header: PHP has no runkit/uopz in this harness, so the ONLY way to narrow "the list" to one member per matrix iteration without redeclaring a global function is for the suite to own its own mutable copy. The double is exercised by the REAL production callbacks from `inc/abilities-remote-set.php` (so mutation #1, a bug in THIS file, is caught correctly), but it is deliberately decoupled from the production `sn_mcp_remote_slugs()` array, whose own correctness is covered instead by `tests/mcp-remote-guard.php`'s pins (count + scope-stability). Net coverage is intact — the production array's cardinality is pinned, and the matrix's sibling-literal property is pinned — but they are two different suites' pins where the plan describes one row hitting both. |

---

## Phase W — client half + anomaly (worker repo)

### Task W1: The tool table, and one shared path

**Files:**
- Modify: `src/bridge.mjs` (table + generalised validator/handler; `handleAnalyticsCall` becomes the table-driven `handleBridgeToolCall`)
- Modify: `src/mcp.mjs` (tools/list from the table; tools/call dispatches by table row)
- Modify: `test/analytics-tool.test.mjs`, `test/bridge.test.mjs`, `test/mcp.test.mjs` (the ripple: cardinality 2 → 9; the existing summary tests re-point at the table row)

- [ ] **Step 1: Failing tests first.** In `test/mcp.test.mjs`: tools/list returns **nine** tools, ping first, the eight bridge tools in table order; every bridge tool's schema pins shape-not-values (loop the existing two assertions over all 8: closed object, no `enum` substring, no `outputSchema`, allowed keys exactly the table's). In `test/analytics-tool.test.mjs`: a per-tool key matrix — for each of the 8, an unknown key refuses with `bad_request` naming the key, before any fetch; `force_refresh` against `sn_remote_uptime_status` is the named case (`THE STRIP PIN, edge half`); `sn_remote_analytics_events` accepts `range` and refuses `class` (the not-the-summary's-shape pin).

- [ ] **Step 2: The table in `src/bridge.mjs`:**

```js
/**
 * The eight brokered tools. ONE ordered path serves all rows — shape gate,
 * secret gate, target gate, rate-before-fetch, one log line per exit. The
 * table is the ONLY per-tool variation; adding a row is Increment work, not
 * a refactor. Keys are the EDGE's half of the contract (the origin owns the
 * VALUES); rows with keys: [] advertise and enforce a closed empty object.
 */
export const BRIDGE_TOOLS = [
  {
    name: "sn_remote_analytics_summary",
    slug: "signal-noise/remote-get-analytics-summary",
    keys: ["range", "class"],
    title: "Site analytics summary (remote)",
    description:
      "Returns range analytics totals from the Signal & Noise origin. Arguments: range " +
      "(7|14|30|90|365|all; default 30) and class (human|suspect|bot; default human) — values " +
      "are validated by the origin, which owns them. Engagement times are MILLISECONDS " +
      "(38690 is ~39 seconds). visits counts visitor-DAYS with no pageview gate, so " +
      "viewless_visits is expected, not a defect; treat views/visits as an estimate, never a " +
      "precise ratio. Read-only. When the remote door is off at the origin — its default state — " +
      "this returns an error result naming the possibilities rather than data.",
  },
  {
    name: "sn_remote_analytics_events",
    slug: "signal-noise/remote-get-analytics-events",
    keys: ["range"],
    title: "Top custom events (remote)",
    description:
      "Returns top custom events (name → events/visitors) for a window. Argument: range " +
      "(7|14|30|90|365|all; default 30; origin-validated). NO class argument exists on this " +
      "tool. Read-only; historical Plausible-imported data.",
  },
  {
    name: "sn_remote_insights",
    slug: "signal-noise/remote-get-insights",
    keys: [],
    title: "Content insights (remote)",
    description:
      "Returns the cached insights scan: signal summary and recommendations. Operational " +
      "commentary in the owner's voice. No arguments. Read-only, served from the origin's cache.",
  },
  {
    name: "sn_remote_narration",
    slug: "signal-noise/remote-get-narration",
    keys: [],
    title: "Analytics narration (remote)",
    description:
      "Returns the cached analytics narration: headline and prose commentary in the owner's " +
      "voice (may reference content titles). No arguments. Read-only, served from the origin's cache.",
  },
  {
    name: "sn_remote_uptime_status",
    slug: "signal-noise/remote-uptime-status",
    keys: [],
    title: "Uptime status (remote)",
    description:
      "Returns the origin's cached uptime snapshot. No arguments — there is deliberately no " +
      "force_refresh remotely: a phone caller reads the last known answer and cannot spend the " +
      "origin's upstream probe quota. Read-only.",
  },
  {
    name: "sn_remote_health_scan",
    slug: "signal-noise/remote-get-health-scan",
    keys: [],
    title: "Health scan summary (remote)",
    description:
      "Returns the cached content-health scan summary: finding and advisory counts with labels — " +
      "never post bodies. No arguments. Read-only.",
  },
  {
    name: "sn_remote_rss_stats",
    slug: "signal-noise/remote-get-rss-stats",
    keys: [],
    title: "RSS stats (remote)",
    description: "Returns cached RSS/feed reader statistics. No arguments. Read-only.",
  },
  {
    name: "sn_remote_deploy_status",
    slug: "signal-noise/remote-get-deploy-status",
    keys: [],
    title: "Deploy status (remote)",
    description:
      "Returns the origin's cached deploy/version state. No arguments — no force_refresh " +
      "remotely; the cached answer is the contract (see sn_remote_uptime_status). Read-only.",
  },
];
```

- [ ] **Step 3: Generalise.** `validateAnalyticsArgs(args)` → `validateBridgeArgs(args, keys)` (same semantics, keys injected; keep the old export as a one-line wrapper over the summary row's keys ONLY if a test still imports it — otherwise update the tests). `handleAnalyticsCall(args, deps)` → `handleBridgeToolCall(tool, args, deps)` where `tool` is a table row: the body is TODAY'S body with `ANALYTICS_SLUG` → `tool.slug`, the log line's hardcoded tool name → `tool.name`, the two arg fields sourced from validated args as today (they stay `range`/`class` — null for tools that lack them). In `src/mcp.mjs`: build the MCP tool defs from the table (`inputSchema` from `keys`, property types: `range: ["string","integer"]`, `class: "string"`; a row with `keys: []` gets `properties: {}` + `additionalProperties: false`); `tools/list` = `[PING_TOOL, ...bridgeToolDefs]`; `tools/call` finds the row by name, else the existing `-32602`.

- [ ] **Step 4:** full suite green; commit — `feat: eight brokered tools, one table, one path (Increment 2, client half)`

### Task W2: The day counter and the anomaly block

**Files:**
- Modify: `src/edge-state.mjs` (DO `/day` + `/day-summary` endpoints; `bumpBridgeDay`, `readBridgeAnomaly` helpers)
- Modify: `src/config.mjs` (+ `anomalyPerSub`, `anomalyTotal`, clamped [1, 100000], defaults 200 / 500)
- Modify: `wrangler.jsonc` (+ `BRIDGE_DAILY_ANOMALY_PER_SUB: "200"`, `BRIDGE_DAILY_ANOMALY_TOTAL: "500"`, with a comment naming them instruments-not-gates)
- Modify: `src/bridge.mjs` (`handleBridgeToolCall` bumps the day counter beside the rate spend)
- Modify: `src/status.mjs` (async; `anomaly` block), `src/index.mjs` (await statusResponse)
- Create/modify tests per Step 1

- [ ] **Step 1: Failing tests.** In `test/edge-state.test.mjs`: day counts accumulate per sub under `bridge_day:`; `/day-summary` computes `{ flagged, total_today, subjects_over }` against injected thresholds (per-sub breach flags; total breach flags; neither → false); **THE FAIL-OPEN PIN:** `bumpBridgeDay(BROKEN_EDGE_ENV, …)` resolves degraded WITHOUT throwing, and — in `test/analytics-tool.test.mjs` — a brokered call with a day-counter-only-broken store still returns data (build an env whose DO stub serves `/rate` but throws on `/day`). In `test/status.test.mjs`: the status body carries `anomaly: { flagged: false, … }` with a healthy DO, and `anomaly: null` with a broken one — degraded-to-unknown, never invented. **THE NO-IDENTITY PIN:** stringified status never contains a subject string seeded into the counter.
- [ ] **Step 2: Implement.** DO `/day`: key `bridge_day:${sub}`, `{ count, resetAt: nextUtcMidnight }` (compute from `Date.now()`; same fixed-window shape as `rate()`). `/day-summary`: `this.ctx.storage.list({ prefix: "bridge_day:" })`, skip expired windows, sum + count subjects over the per-sub threshold. Helpers mirror `checkRate`'s structure with the **inverted catch**:

```js
/**
 * INSTRUMENT, NOT GATE — the inverse of checkRate, and the inversion is the
 * design. checkRate's catch DENIES because it guards the origin; this catch
 * REPORTS DEGRADED and the call proceeds, because an observability failure
 * must never become an availability failure. One mutation pin guards each
 * direction; do not "harmonise" them.
 */
export async function bumpBridgeDay(env, subject) {
  try {
    return { ...(await callEdge(env, "/day", { sub: subject })), degraded: false };
  } catch {
    return { degraded: true };
  }
}
```

`readBridgeAnomaly(env, { perSub, total })` same posture → `null` on failure. `handleBridgeToolCall` calls `bumpBridgeDay` immediately after the rate check allows (fire-and-await; its result is never consulted for allow/deny — a comment says so). `statusResponse(env)` becomes async, awaits `readBridgeAnomaly` **only when `env.EDGE_STATE` is bound** (status must still answer when the binding is absent — that absence is already a reported condition), `src/index.mjs:41` gains the `await`.
- [ ] **Step 3:** full suite green; commit — `feat: the anomaly instrument — fail-open day counts, surfaced as counts and never names`

### Task W3: Worker mutation sweep + release

- [ ] **Sweep** (same discipline; backups, diff-proof, cp-restore):

| # | Mutation | Must red |
| --- | --- | --- |
| 1 | `bumpBridgeDay`'s catch → rethrow (or return a deny-shaped object consulted by the handler) | THE FAIL-OPEN PIN (both files) |
| 2 | `checkRate`'s catch → `allowed: true` | the existing fail-closed pins — re-run to prove the two postures stayed uncrossed |
| 3 | `keys: ["range"]` → `["range","class"]` on the events row | the not-the-summary's-shape pin |
| 4 | Add `"force_refresh"` to the uptime row's keys | THE STRIP PIN, edge half |
| 5 | Drop a row from `BRIDGE_TOOLS` | the nine-tools cardinality pin |
| 6 | `/day-summary` returns subject strings in its body | THE NO-IDENTITY PIN |

- [ ] Record under `## Mutation results — worker (measured)`; commit the plan row addendum in the PLUGIN repo if the plan lives there (it does — this file); the worker commit is the sweep-fix commits only if any pin was missing.
- [ ] **Release prep:** `package.json` → `0.3.0` AND devDependencies to **exact pins** (`wrangler`, `vitest`, `@cloudflare/vitest-pool-workers` — strip the carets, keep `overrides`; run `npm install` so the lockfile agrees; suite must stay green on the pinned versions). CHANGELOG entry in the repo's voice covering: the table, the strip, the aggregate-cap deviation, the instrument/gate inversion, the no-identity status block, the reconnect note (spec §5). README: tool list ×8, the two anomaly vars. Commit — `release: v0.3.0 — eight tools through one door, and the door starts counting`; push branch; open the PR (same body discipline as PR #1); **no tag, no deploy** — deploy is owner-gated and follows the merge.

---

## Phase H — hardening remainder (plugin repo)

### Task H1: The fifth worker in the health panel

**Files:**
- Modify: `inc/health-edge-workers.php`
- Modify: its test suite (find with `git grep -ln "sn_health_edge_worker_findings" tests/`)

- [ ] **Step 1:** read the file end to end (it changed at v10.62.0; the probe idiom to copy is `sn_health_prov_status_probe()` — SSRF-guarded, parses 200 AND 503 bodies, transient-cached). Write failing tests against the PURE findings builder first: new parameter `$remote_mcp` (array|string|null, `'unconfigured'`-style skip does NOT apply — the URL is fixed on our own zone; `null` = probe unreachable):

| Input | Finding? |
| --- | --- |
| `null` (unreachable) | YES — "sn-remote-mcp unreachable" |
| `configured: false` | YES — outage, `missing` keys in the note |
| `killed: true` | **NO** — a deliberately dark door is a state, not a failure (assert the absence) |
| `bridge_secret_bound` key ABSENT from config | YES — "a deploy lost the readout" (distinct note from unbound) |
| `anomaly.flagged: true` | YES — "volume anomaly", note carries `total_today`/`subjects_over` counts, **assert no email-shaped string in the note** |
| healthy body | NO findings |

- [ ] **Step 2:** implement — probe `https://juanlentino.com/_sn/remote-mcp/status` via the same guarded fetch + a 6h transient (mirror `SN_HEALTH_EDGE_LG_TRANSIENT`'s shape); thread the new param through `sn_health_check_edge_workers()`; extend the findings builder. Suite + full sweep green.
- [ ] **Step 3: Mutations** — flip the `killed` handling to flag (must red the state-not-failure pin); make the anomaly note echo a subject (must red the no-identity pin). Record, restore, commit — `feat: the remote door joins the health panel — outage, lost readouts and anomalies become findings; a dark door stays a state`

## Mutation results — H1 (measured)

Backup kept in the session scratchpad; `cp` in, `cp` back, `git diff --stat` confirmed empty
after restore. Full sweep was exit 0 / 431 suites / 17301 assertions both before row 1 and after
the final restore.

| # | Mutation | Result |
| --- | --- | --- |
| 1 | `killed: true` made to append a finding (folded into the checks instead of being deliberately excluded) | RED as named. `THE STATE-NOT-FAILURE PIN: killed:true -> NO finding (a deliberately dark door is a state)` (43/44 passed). No other row moved. |
| 2 | The anomaly note's `sprintf` format string made to embed an email-shaped literal (`owner@example.com`) alongside the counts | RED as named. `THE NO-IDENTITY PIN (health half): the anomaly note contains no email-shaped string` (43/44 passed). No other row moved. |

**Re-run after the quality-review fix commit** (worker-identity guard, degraded-instrument pins,
shared TTL constant, `configured`-absent pin, version-row docblock/spec note — see that commit's
message): both rows re-applied to the post-fix file, confirmed landed via `git diff --stat`,
confirmed restored via `git diff --stat` again. Full sweep was exit 0 / 431 suites / 17307
assertions both before row 1's re-run and after the final restore (the +6 over 17301 is the six
new pins the fix commit added: two degraded-instrument fixtures, the v0.2.0-era-body fixture, the
configured-absent fixture, and two worker-identity fixtures).

| # | Mutation (re-run) | Result |
| --- | --- | --- |
| 1 | `killed: true` made to append a finding | RED as named, same pin, same row count: `THE STATE-NOT-FAILURE PIN` (49/50 passed post-fix). |
| 2 | Anomaly note embeds an email-shaped literal | RED as named, same pin: `THE NO-IDENTITY PIN (health half)` (49/50 passed post-fix). |

**MERGE-BLOCKER fix (Grok adversarial pass, verified against the live endpoint, 2026-08-14):**
`sn_health_edge_worker_findings()` was indexing `configured`/`bridge_secret_bound` at the TOP
level while the live Worker nests both (and `killed`) under a `config` sub-object. Consequence on
a healthy door: the lost-readout finding fired ALWAYS (the top-level key is genuinely absent) and
`configured: false` NEVER fired (`false === null` is false) — a permanent false outage masking a
silent real one. `anomaly` is correctly top-level, so that branch was unaffected; `killed` was
never read at all, which accidentally preserved the state-not-failure behavior for the wrong
reason. The fixture in `tests/health-edge-workers.php` INVENTED a flat shape and every existing
pin passed against it — this estate's most-bitten trap (test-stub-drift), now at 10 occurrences.

Fix: read `$remote_mcp['config']` defensively (`is_array(...) ? ... : array()`), then index
`configured`/`bridge_secret_bound`/`killed` from that. THE REAL-SHAPE PIN — the healthy REAL
(nested) body yields zero findings, and `config.configured => false` yields the misconfig outage
— was verified to RED against the OLD code (9 of 51 pins failed, including both REAL-SHAPE PIN
assertions) and PASS against the fixed code (51/51). Full sweep after the fix: exit 0 / 431
suites / 17308 assertions.

Both H1 mutations re-run a second time against the corrected nested-shape code/fixture:

| # | Mutation (re-run 2, post nesting-fix) | Result |
| --- | --- | --- |
| 1 | `config.killed: true` made to append a finding | RED as named: `THE STATE-NOT-FAILURE PIN: config.killed:true -> NO finding` (50/51 passed). |
| 2 | Anomaly note embeds an email-shaped literal | RED as named: `THE NO-IDENTITY PIN (health half)` (50/51 passed). |

Two smaller items folded into the same fix commit: the two origin strip pins in
`tests/abilities-remote-set.php` are strengthened to assert `properties === array()` outright on
both stripped twins (subsuming `force_refresh`, `detail` — a bigger quota amplifier Grok noted
the old pin missed — and anything future), and both the runbook and the client-half amendment now
record the correlated `refused_auth` signature's false negative: a DELETED `SN_BRIDGE_TOKEN`
constant never registers the route, so `refused_auth` never climbs; the panel's `secret_missing`
state names that failure mode instead.

### Task H2: The reconciliation amendments (docs only)

**Files:**
- Modify: `docs/proposals/remote-mcp-increment1-client-half.md` (the "rotation blind spot" section)
- Modify: `docs/ops/remote-mcp-revoke-runbook.md` (the diagnosis table)

- [ ] In the client-half spec, after the "not fixable at the endpoint" paragraph, add: the peer's counters partially falsify "unobservable by construction" — pointer to `remote-mcp-increment4-observability.md` §11, with the signature spelled out (**origin `refused_auth` climbing + toggle ON ⇒ the two `SN_BRIDGE_TOKEN` halves disagree**; `refused_shut` is the distinct calls-arrived-while-off fact). In the runbook's "Verifying it actually stopped" area, add the row: door dark + both panels green → check the observability line's refused counts before anything else; `refused_auth` climbing names a botched rotation. Match each document's voice. Commit — `docs: the rotation blind spot gains its first observable symptom — the two readouts compose`

### Task H3: Close out (plugin)

- [ ] Full sweep, exit-code gated. CHANGELOG `[Unreleased]` entries for O and H phases (the spec's §1 "widens a LIVE surface" sentence goes in the Increment 2 entry verbatim — the CHANGELOG says it, not implies it). Push `feat/increment-2-origin-half`, open the PR (reference the spec; test plan lists the matrix, the strip pins, both sweeps). **No version bump decision here** — the train owns it per [[batch-prs-before-release]].
- [ ] **Adversarial review (Grok, verified available):** after both PRs are open, dispatch the delegate with the Increment 1 brief's shape. Attack targets, minimum: the 8×8 matrix (a callback holding a sibling's literal), the stripped `force_refresh` (can any path smuggle it to the origin?), the instrument/gate boundary (can `bridge_day:*` influence allow/deny?), threshold bypass space (many subs under per-sub cap vs the total cap), and the no-identity property on status + health notes. Fold findings before merge.

---

## Self-review notes (spec coverage)

| Spec section | Task |
| --- | --- |
| §1 set + live-surface warning | O1, H3 (CHANGELOG) |
| §2 twins, literals, parity, matrix | O1, O2, O3 |
| §3 force_refresh both layers | O1 (twin), O2 (strip pins), W1 (edge), sweeps both |
| §4 table, one path, aggregate-cap deviation | W1, W3 CHANGELOG |
| §5 reconnect note | W3 CHANGELOG/README |
| §6–7 anomaly, fail-open, reconciliation | W2, W3, H2 |
| §8 fifth worker | H1 |
| §9 dependency pin | W3 |
| §10 out-of-scope | untouched, by construction |
| Kill criteria | O2 group 5 (tripwire pins), W2 comment + sweep row 1/2 |
