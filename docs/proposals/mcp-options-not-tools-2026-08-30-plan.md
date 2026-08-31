# MCP: options, not tools — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the two DESKTOP doors complete — every registered ability either reachable through an option on a tool that already exists, or excluded for a reason that survives scrutiny — and stop the remote door from drifting behind them without ever letting it silently widen.

**Architecture:** No new tools anywhere. The read door's consolidated tools hold `section → ability` maps, so an option is one map line. The write door consolidated into `sn-apply`'s `change.type` enum, whose four gates change the risk profile of several abilities previously held off the door as standalone tools. The remote door stays a hand-curated allowlist, with a **totality test** that forces an explicit in/out verdict for every local section.

**Tech Stack:** PHP 8.3 (production runs 8.4; CI pinned 8.3), WordPress Abilities API, standalone `tests/*.php` fixture suites swept by `bash tests/run.sh`. Worker half is JS on Cloudflare Workers, vitest.

**Baseline before starting:** `bash tests/run.sh` → `-- swept 524 suites, 21103 assertions passed, 0 failed, 1 skipped --`

---

## Phases

| Phase | Door | Ships | Risk | Gated on |
| --- | --- | --- | --- | --- |
| **0** | **telemetry** | **two blind spots closed** | **Low — one line + one column** | **nothing; MUST precede 1–3** |
| 1 | read | 6 sections | Low — map lines, no permission change | Phase 0 |
| 2 | rw | the dismissal blocker | Medium — one change type | nothing |
| 3 | rw | 4 newly-viable change types | Medium-high — new write capability | Phase 2 merged |
| 4 | remote | verdict map + ratified twins | High — credentialed path, contract bump | **two preconditions, both verified unmet** |

**Phases 2 and 3 are separate on purpose.** Phase 2 unblocks work already recorded as blocked (phase 10 of the consolidation program). Phase 3 is new capability that reverses four previously-recorded exclusions — it deserves its own review round rather than riding in on an unblocking change.

**Phase 4 must not precede Phase 1.** Its `machine_readers` twin covers the same ability Phase 1 sections; building both at once is how the two halves drift.

---

## Orientation — six things that will otherwise look arbitrary

**1. Tests are standalone scripts, not PHPUnit.** Each `tests/*.php` defines its own WP stubs, `require`s the `inc/` file, uses a local `ok( $condition, $message )`. Run one with `php tests/<name>.php`. `tests/run.sh` gates on each suite's **summary line**, never absence of FAIL — a fataled suite prints no summary and contributes zero silently.

**2. A section map entry is the whole feature.** `snt_sn_status_map()` / `snt_sn_metrics_map()` / `snt_sn_site_facts_map()` map a section name to an ability slug. `internal:` entries call a local function instead — a section does not require a registered ability.

**3. Two permission tiers, and a section may never cross them.**

| Tool | Gate |
| --- | --- |
| `sn-status`, `sn-metrics`, `sn-site-facts` | `snt_ability_perm_manage_options` |
| `sn-posts`, `sn-scan`, `sn-validate` | `snt_ability_perm_read_corpus` |

Placing an ability under a tool with a different gate changes who can reach it. That is a scope change wearing a refactor's clothes.

**4. Declare in `output_schema` in the same change that adds to the payload.** The v13.33.0 lesson: four fields were returned but undeclared since v10.79.0, so no agent could know the axis existed.

**5. The door allowlist gates the DOOR only.** `sn_mcp_allowlist()` / `sn_mcp_rw_allowlist()` in `inc/mcp/mcp-capabilities.php`. An ability removed there still exists, still answers `wp_get_ability()->execute()`, stays REST-reachable. Exposure and retirement are both one line.

**6. A new `change.type` needs a client restart.** The MCP connector caches the enum. "Type not supported" in a warm session is the cache, not the code.

---

# PHASE 0 — Telemetry: close the blind spots BEFORE adding options

**This program is telemetry-first — nothing retires until usage data justifies it — so every option added here must be countable individually.**

## The good news: recording is already automatic

Verified 2026-08-30. Nothing in Phases 1–3 needs any telemetry code:

| Dimension | Where | Auto-extends? |
| --- | --- | --- |
| section / fact | `sn_tool_call.change_type`, via `sn_mcp_telemetry_change_type()` | **Yes** — the extractor sources `snt_sn_*_map()` **live, never a local copy**, so a new map line is recorded the moment it exists |
| `change.type` | same column | **Yes** — allowlisted live against `SNT_SN_APPLY_CHANGE_TYPES` |
| `scan_type` | separate `sn_scan_run` table | **Yes** — records whatever type ran |

Rollups already surface through `sn-site-facts{tool_telemetry}` (`by_tool`, `by_change_type`, `by_error_code`) and `{scan_telemetry}` (per `scan_type`: runs, duration, yield, apply-hint coverage). **Adding an option extends the telemetry for free.** That is the consolidation paying off.

## The bad news: two blind spots, and Phase 1 makes one of them worse

### Blind spot 1 — multi-entry calls record NOTHING

`sn_mcp_telemetry_change_type()`:

```php
	if ( 1 !== count( $requested ) ) {
		return null;
	}
```

Deliberate and honest — *"a fabricated 'first of three' dimension is not"* honest. But the consequence is that **the more the consolidated tools are used as designed, the blinder the per-section record gets.** Phase 1 adds six sections, which makes batching *more* attractive, which raises the NULL share. The blindness scales with the feature.

### Blind spot 2 — the agent door records no dimension at all

`inc/mcp/mcp-telemetry-agents.php:266` passes a literal `null` where `change_type` goes:

```php
		$result_count,
		null,                          // <-- the dimension, never extracted
		$classified['error_code'] ?? null
```

The file's own comment calls this pre-existing, sn-apply included. And per the standing habit note, **the dominant caller of these tools is agent sessions** — so the door whose traffic decides wave 4 is the one recording no dimension.

**Together these mean the wave-4 retirement read (~Sep 25) cannot answer its own question.** It decides on "collapsed traffic" per section, from data where the dominant caller contributes no section and batched calls contribute none either.

### Task 0.1: The agent door extracts its dimension

**Files:** Modify `inc/mcp/mcp-telemetry-agents.php:266`; Test `tests/mcp-telemetry-agents.php`

- [ ] **Step 1: Write the failing test**

```php
echo "\nGroup: the agent door records its dimension (v13.44.0)\n";
$GLOBALS['__rows'] = array();
sn_mcp_telemetry_agents_record( 'signal-noise/sn-metrics', array( 'sections' => array( 'rss_stats' ) ), /* … */ );
$row = end( $GLOBALS['__rows'] );
ok( 'rss_stats' === ( $row['change_type'] ?? null ),
	'a single-section agent-door call records the section, not null' );

// The honest-null rule still holds — this fixes WHO extracts, not WHAT counts.
$GLOBALS['__rows'] = array();
sn_mcp_telemetry_agents_record( 'signal-noise/sn-metrics', array( 'sections' => array( 'rss_stats', 'analytics_events' ) ), /* … */ );
$row2 = end( $GLOBALS['__rows'] );
ok( null === ( $row2['change_type'] ?? 'x' ),
	'and a multi-section call still records null rather than a fabricated first-of-N' );
```

- [ ] **Step 2: Run to verify it fails** — the first assertion reds; the row carries `null`.

- [ ] **Step 3: Implement** — replace the literal `null` with the extractor, guarded like every other optional seam in that file:

```php
		$result_count,
		// v13.44.0. Was a literal null since this door shipped. The agent door
		// is the DOMINANT caller of the consolidated tools, so a dimension-less
		// row here is most of the evidence wave 4 needs, missing.
		function_exists( 'sn_mcp_telemetry_change_type' )
			? sn_mcp_telemetry_change_type( $args_arr, (string) $slug )
			: null,
		$classified['error_code'] ?? null
```

The extractor already keys on **both** name formats (`slug` and projected `slug__`), so the raw ability slug this door passes resolves correctly.

- [ ] **Step 4: Run to verify it passes.**
- [ ] **Step 5: Negative-control** — revert to `null`; the first pin must red while the second still passes. Restore.
- [ ] **Step 6: Commit** — `git commit -m "fix: the agent door records its telemetry dimension"`

### Task 0.2: Multi-entry calls stop being invisible

**Design:** leave `change_type` **exactly as it is** — its "sole dimension of this call" meaning is honest and historical rows depend on it. Add a sibling `dimensions` column (TEXT, sorted comma-joined) recording **every** entry requested. Two questions, two columns, neither fabricated:

- `change_type` — "was the sole dimension of N calls"
- `dimensions` — "appeared in N calls"

- [ ] **Step 1: Write the failing test** — a two-section call records both entries in `dimensions`, sorted, while `change_type` stays null; a one-section call records both columns consistently.
- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Implement** — extractor returns the full sorted list; add the column with the table's existing install/upgrade path; **fail-open** like every other telemetry write.
- [ ] **Step 4: Extend `sn-site-facts{tool_telemetry}`** with a `by_dimension` rollup beside `by_change_type`, and **declare it in the ability's `output_schema` in the same change** (the v13.33.0 lesson).
- [ ] **Step 5: Negative-control** — a call whose sections are recorded out of order must still produce a stable sorted value, or the rollup double-counts the same pair.
- [ ] **Step 6: Commit.**

### Task 0.3: Record the hole that stays open

- [ ] **Step 1:** `-32602` proxy refusals remain invisible to **every** telemetry layer — the client rejects against a cached schema before the request reaches us. Nothing in this phase changes that. State it beside the wave-4 verdict sheet so a zero is never read as "nobody called it" when it may be "the proxy refused it".

**After Phase 0, every option added in Phases 1–3 is countable from the day it ships, on every door, batched or not.**

---

# PHASE 1 — Read door: six sections

## File Structure

| File | Responsibility |
| --- | --- |
| **Modify** `inc/abilities-sn-metrics.php` | 3 map entries + `range` clamp note |
| **Modify** `inc/abilities-sn-status.php` | 2 map entries |
| **Modify** `inc/abilities-sn-scan.php` | 1 scan type (`draft_echoes`) |
| **Modify** `tests/abilities-sn-metrics.php`, `tests/abilities-sn-status.php`, `tests/abilities-sn-scan.php` | Section pins |

### Task 1.1: `sn-metrics{machine_readers}`

The gap that forced a `wp eval` dead-end on 2026-08-30: **no MCP tool exposes machine reads at all**, the `identity` fold (v13.43.0) included.

- [ ] **Step 1: Write the failing test** — append to `tests/abilities-sn-metrics.php`:

```php
echo "\nGroup: machine_readers section\n";
$map = snt_sn_metrics_map();
ok( isset( $map['machine_readers'] ), 'machine_readers is a declared section' );
ok( 'signal-noise/get-machine-readers-summary' === $map['machine_readers'],
	'and it routes to the machine-readers summary ability' );

// The sensor clamps 1..90 and holds ~32 days; sn-metrics' range accepts
// 7|14|30|90|365|all, so 365 and all are UNREACHABLE here. A caller must learn
// that from the schema, not from a surprising number.
$range_desc = (string) ( $GLOBALS['__ab']['signal-noise/sn-metrics']['input_schema']['properties']['range']['description'] ?? '' );
ok( false !== strpos( $range_desc, 'machine_readers' ), 'the range description names machine_readers and its clamp' );
ok( false !== strpos( $range_desc, '90' ), 'and states the 90-day ceiling the sensor enforces' );
```

- [ ] **Step 2: Run to verify it fails** — `php tests/abilities-sn-metrics.php` → FAIL, `machine_readers is a declared section`.

- [ ] **Step 3: Add the map entry** in `snt_sn_metrics_map()`:

```php
		// v13.44.0. "How the site is read" is exactly what the machine-readers
		// sensor measures, and until now NO MCP tool exposed it — the identity
		// fold (v13.43.0) included, which is why answering "one agent or
		// fifteen?" needed shell access to the origin.
		'machine_readers'       => 'signal-noise/get-machine-readers-summary',
```

- [ ] **Step 4: Extend the `range` description**:

```php
					'description' => 'Window for analytics_summary and analytics_events (source-validated: 7|14|30|90|365|all). Ignored by rss_stats. For machine_readers the sensor clamps to 1-90, so 365 and all are refused there, and the payload\'s own days_covered reports how many days it actually holds — asking for 90 and being handed 32 is not an error.',
```

- [ ] **Step 5: Run to verify it passes** — `0 failed`.
- [ ] **Step 6: Commit** — `git commit -m "feat: sn-metrics gains a machine_readers section"`

### Task 1.2: `sn-metrics{analytics_top_content}` and `sn-metrics{404_log}`

`analytics_summary` and `analytics_events` were already sections; the third sibling was omitted.

- [ ] **Step 1: Write the failing tests**:

```php
ok( isset( $map['analytics_top_content'] ) && 'signal-noise/get-analytics-top-content' === $map['analytics_top_content'],
	'analytics_top_content is a declared section' );
ok( isset( $map['404_log'] ) && 'signal-noise/get-404-log' === $map['404_log'],
	'404_log is a declared section' );
```

- [ ] **Step 2: Run to verify they fail** — two FAILs.
- [ ] **Step 3: Add both map entries**:

```php
		// v13.44.0. The third analytics sibling, simply omitted until now.
		'analytics_top_content' => 'signal-noise/get-analytics-top-content',
		// How the site is read, and fails to be read.
		'404_log'               => 'signal-noise/get-404-log',
```

- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat: analytics_top_content and 404_log sections"`

### Task 1.3: `sn-status{collector}` and `sn-status{corpus_integrity}`

`corpus-integrity-scan` is `manage_options` and has **no recorded verdict anywhere** — not retired, not absorbed, never accounted for. It belongs on `sn-status`, not `sn-validate`: `sn-validate` is `read_corpus`, and placing it there would cross permission tiers.

- [ ] **Step 1: Write the failing tests** — append to `tests/abilities-sn-status.php`:

```php
echo "\nGroup: collector + corpus_integrity sections\n";
$smap = snt_sn_status_map();
ok( isset( $smap['collector'] ) && 'signal-noise/get-collector-status' === $smap['collector'],
	'collector is a declared section' );
ok( isset( $smap['corpus_integrity'] ) && 'signal-noise/corpus-integrity-scan' === $smap['corpus_integrity'],
	'corpus_integrity is a declared section' );
```

- [ ] **Step 2: Run to verify they fail** — two FAILs.
- [ ] **Step 3: Add both entries** to `snt_sn_status_map()`:

```php
		// v13.44.0. Operational state, beside deploy and health_scan.
		'collector'            => 'signal-noise/get-collector-status',
		// manage_options, so it belongs here and NOT on sn-validate, which is
		// read_corpus — that placement would cross permission tiers.
		'corpus_integrity'     => 'signal-noise/corpus-integrity-scan',
```

- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat: collector and corpus_integrity sections"`

### Task 1.4: `sn-scan{draft_echoes}`

`draft-echoes` is `read_corpus` and readonly, with no recorded verdict. `sn-scan` is the same gate and the corpus-analysis tool — the natural home.

- [ ] **Step 1: Write the failing test** in `tests/abilities-sn-scan.php`:

```php
echo "\nGroup: draft_echoes scan type\n";
ok( in_array( 'draft_echoes', SNT_SN_SCAN_TYPES, true ), 'draft_echoes is a declared scan type' );
```

- [ ] **Step 2: Run to verify it fails.**
- [ ] **Step 3: Add `'draft_echoes',` to `SNT_SN_SCAN_TYPES`** and wire its adapter to `signal-noise/draft-echoes`.

> **Do not repeat the v10.52.1 mistake:** `emdash` shipped with an adapter but WITHOUT its enum entry, so the ability rejected the type before dispatch and the feature was dead on arrival. Add the enum entry and the adapter in the same commit, and assert both.

- [ ] **Step 4: Run to verify it passes**, then run `bash tests/run.sh` → `0 failed`.
- [ ] **Step 5: Commit** — `git commit -m "feat: sn-scan gains the draft_echoes type"`

### Task 1.5: Ship Phase 1

- [ ] **Step 1: Verify `origin/main` before choosing a version**

```bash
git fetch origin && git log --oneline -1 origin/main
grep -m1 "Version:" signal-and-noise-tools.php
```

**If origin is ahead, rebase before versioning.** This plan's `v13.44.0` assumes origin is at `13.43.0`. Picking a number against a stale main produced a wrong version on 2026-08-30 — eleven releases behind.

- [ ] **Step 2:** Bump + CHANGELOG + PR + squash-merge + annotated tag + draft release. Drafts stay drafts.

---

# PHASE 2 — The dismissal blocker

**Ships:** one `sn-apply` change type. Closes a gap the allowlist itself flags: *"WATCH: dismiss-candidate backed sn-scan's `dismissed` flow"* — and it **gates phase 10** of the consolidation program.

**The problem in one line:** every scan surfaces candidates you can apply but cannot dismiss, so a candidate list can never converge from an agent session.

## Design decisions, made here so the tasks are unambiguous

- **A change type, not a tool.** `sn_dismiss` was deferred *as a tool*. Options-not-tools puts it on `sn-apply`.
- **`sn-apply` already hosts non-post side effects** — `og_card` writes a PNG, `anchor_sweep` makes a live HTTP call, `roadmap_board` writes an option. A candidate-scoped type is consistent with what the tool is.
- **PUBLISH-ONLY.** There is no revision to stage, exactly as `roadmap_board` and `og_card`. `mode:"revision"` refuses by name rather than fabricating a staged version of a side effect that cannot be staged.
- **`change.fingerprint` is NOT required, and this is the interesting part.** `sn-scan` mints `candidate_id = sha256(scan_type + target identity + content fingerprint)` — **stable across runs on unchanged content**, per its own description. If the content moves, the id moves. The candidate_id *is* the binding, and a second fingerprint gate could never fail independently of it. **A gate that cannot fail is not a gate**; it would mislead a caller into thinking staleness is checked twice.
- **Idempotency: the standard auto-key applies.** A repeated dismissal is not a legitimate force-repeat (unlike `og_card`), so it replays rather than executing twice.

### Task 2.1: The dismiss change type

- [ ] **Step 1: Write the failing test** — `tests/sn-apply-dismiss.php`, WP + abilities stubs copied from `tests/abilities-sn-apply.php`:

```php
echo "Group: dismiss is a declared change type\n";
ok( in_array( 'dismiss', SNT_SN_APPLY_CHANGE_TYPES, true ), 'dismiss is in the change-type enum' );

echo "\nGroup: dismiss is PUBLISH-ONLY\n";
$res = snt_ability_sn_apply( array(
	'target'  => array( 'candidate_id' => 'abc123' ),
	'change'  => array( 'type' => 'dismiss' ),
	'mode'    => 'revision',
	'dry_run' => true,
) );
ok( is_wp_error( $res ), 'mode:revision refuses' );
ok( in_array( $res->get_error_code(), array( 'snt_sn_apply_mode_not_granted', 'snt_sn_apply_bad_mode' ), true ),
	'and refuses BY NAME, never by fabricating a staged dismissal' );

echo "\nGroup: the candidate_id IS the binding\n";
$res2 = snt_ability_sn_apply( array(
	'target'  => array( 'candidate_id' => 'abc123' ),
	'change'  => array( 'type' => 'dismiss' ),
	'mode'    => 'publish',
	'dry_run' => true,
) );
ok( ! is_wp_error( $res2 ), 'a dismissal with no fingerprint is accepted' );
ok( 'no_fingerprint_scheme' === ( $res2['gates']['fingerprint']['skipped'] ?? null ),
	'and gate 1 reports no_fingerprint_scheme — the same honest skip alt_text/og_card/anchor_sweep already use, never a fabricated pass' );
```

- [ ] **Step 2: Run to verify it fails** — `php tests/sn-apply-dismiss.php` → FAIL, `dismiss is in the change-type enum`.

- [ ] **Step 3: Implement** — add `dismiss` to `SNT_SN_APPLY_CHANGE_TYPES`, accept `target {candidate_id}`, refuse `mode:"revision"` by name alongside the existing publish-only types, report gate 1 as `no_fingerprint_scheme`, and route the write to `signal-noise/dismiss-candidate`.

- [ ] **Step 4: Run to verify it passes** — `0 failed`.

- [ ] **Step 5: Negative-control every guard**

```bash
cp inc/abilities-sn-apply.php /tmp/apply.bak
# (a) allow mode:revision      -> the publish-only pins must go red
# (b) fabricate a passing gate 1 -> the no_fingerprint_scheme pin must go red
cp /tmp/apply.bak inc/abilities-sn-apply.php   # restore, confirm green
```

Each mutation must red **exactly its own pin**. A guard never watched failing is not a guard.

- [ ] **Step 6: Commit** — `git commit -m "feat: sn-apply gains the dismiss change type, closing the wave-1 dismissal gap"`

### Task 2.2: Re-open the phase-10 gate

- [ ] **Step 1:** Record in `docs/mcp-consolidation/` that the dismissal path now exists, so phase 10's blocker is lifted. The blocker was written down; the unblocking must be too.
- [ ] **Step 2:** After install, **restart the MCP client** before testing (orientation note 6).

---

# PHASE 3 — Four exclusions the options framing reverses

**Ships:** four `sn-apply` change types, each reversing a recorded "held OUT on purpose" decision — *because the reasoning behind those decisions was written against DOORED TOOLS, and an option inherits `sn-apply`'s gates.*

`sn-apply` brings `dry_run: true` by default, four gates reported even when an earlier one failed, idempotency, and the rw audit trail. That changes the risk object.

| Held out | Stated reason | Verdict under the options framing |
| --- | --- | --- |
| `merge-tags` | "sitewide term reassign + delete" | **Reversed.** Dry-run-first, fingerprint gate, audit row. |
| `clear-template-overrides` | "wipes Site Editor template rows" | **Reversed.** Same, plus `roadmap_board`'s precedent for a non-post scope target. |
| `run-cron-event` | "**unbounded** `do_action()` dispatch" | **Reversed, conditionally** — the objection is *unbounded*. An allowlisted hook set makes it bounded. |
| `run-health-scan` | ~35s, up to ~105s, behind Cloudflare's ~100s edge cap | **Reversed by redesign** — the type SCHEDULES the scan and returns immediately. The verdict is already readable via `get-health-scan`. |
| `ai-orphan-apply` | "permanent delete, skips trash, no undo" | **NOT reversed.** `sn-apply`'s precedent is trash-only (`delete_draft` is explicitly `wp_trash_post`). Irreversibility is the one thing the gates cannot fix. **Stays out.** |

- [ ] **Task 3.1:** `merge_tags` — target `{scope:"tags"}`, payload `{from, into}`; PUBLISH-ONLY; fingerprint binds the current term set.
- [ ] **Task 3.2:** `clear_template_overrides` — target `{scope:"template_overrides"}`; PUBLISH-ONLY; fingerprint binds the current override set; dry run reports exactly which rows would go.
- [ ] **Task 3.3:** `run_cron_event` — payload `{hook}`, validated against an **allowlist constant**, not free text. A hook outside it refuses by name. Pin the allowlist's contents and pin that an unlisted hook refuses.
- [ ] **Task 3.4:** `schedule_health_scan` — schedules and returns; **never** runs synchronously. Pin that the call returns without waiting, and that the type does not appear in any synchronous dispatch path.
- [ ] **Task 3.5:** Update the "Held OUT on purpose" block in `inc/mcp/mcp-capabilities.php` to record **which** exclusions were reversed, **why** (the options framing), and that `ai-orphan-apply` was re-examined and **stays out**. A reversed decision with no record reads as an oversight later.

Every task follows the Phase 2 rhythm: failing test → verify red → minimal implementation → verify green → **negative-control each guard** → commit.

---

# PHASE 4 — The remote door

**Ships:** nothing until two preconditions clear. Both are verified **currently unmet**.

## The invariant this phase may never touch: the remote door is READ-ONLY

The read and rw doors are the **desktop** surface. The remote door is analytics-scope reads only — owner direction 2026-08-11: *"for writing and doing more than that, it's the computer with the Claude app,"* restated 2026-08-30.

Enforced three independent ways, all verified in code:

1. **Capability.** `sn_bridge_grant_capability()` (`inc/mcp/mcp-bridge-route.php:126`) grants **exactly one** capability, `sn_read_remote_analytics` — "never a role, never manage_options, and never anything derived from the request" — only while a verified request is in flight, removed in a `finally`. Every write ability is `manage_options`-gated, so writes are **structurally unreachable**.
2. **Allowlist.** `sn_mcp_remote_slugs()` (`inc/mcp/mcp-remote-guard.php:61`) — eight read twins by name, **deliberately disjoint** from `sn_mcp_allowlist()`.
3. **Pinned.** `tests/mcp-remote-guard.php:80` — `a write slug -> refused`, against `sn-apply` by name.

**Any task that would grant a second capability, or route a remote section to a `manage_options` ability, is out of scope by definition.**

## Why remote does NOT inherit the read door

Considered and rejected 2026-08-30. The read door contains:

- **`sn-posts`** — its own description: "all five non-trash statuses (**publish/future/draft/pending/private**)", with `include_content` attaching full bodies.
- **`sn-scan`** — walks the corpus across those same statuses.
- **`topic-clusters` / `keyword-candidates` / `link-candidates`** — derive from `SNT_CORPUS_STATUSES` = `publish, future, draft, pending, private`.

Inheritance puts **unpublished draft bodies on a phone-reachable credentialed path** — the exact asset §8.8 declined over.

The structural reason is deeper: the threat model requires an allowlist-of-an-allowlist, *"curated by name, **never 'read door minus some'**"*. Inheritance makes remote an **exclusion list**, which **fails open** — the next person to add a local section silently widens the phone's reach and nothing goes red. An allowlist fails closed.

## The real problem inheritance was trying to solve — and the fix

Hand-curation doesn't lag safely; it lags **silently**. Today a new local section simply never gets a remote decision, and nothing flags that.

**Fix: a totality test, not inheritance.**

### Task 4.1: The verdict map

- [ ] **Step 1: Write the failing test** — `tests/mcp-remote-verdicts.php`:

```php
echo "Group: every local section has an explicit remote verdict\n";
$verdicts = sn_mcp_remote_verdicts();
$local = array_merge(
	array_keys( snt_sn_status_map() ),
	array_keys( snt_sn_metrics_map() ),
	array_keys( snt_sn_site_facts_map() )
);
$missing = array_values( array_diff( $local, array_keys( $verdicts ) ) );
ok( array() === $missing,
	'no local section lacks a remote verdict — missing: ' . implode( ', ', $missing ) );

echo "\nGroup: a verdict of true must name a twin that actually exists\n";
foreach ( $verdicts as $section => $v ) {
	if ( empty( $v['remote'] ) ) {
		ok( '' !== (string) ( $v['reason'] ?? '' ), "section $section says why it is NOT remote" );
		continue;
	}
	ok( in_array( (string) ( $v['twin'] ?? '' ), sn_mcp_remote_slugs(), true ),
		"section $section names a twin that is on the remote allowlist" );
}

echo "\nGroup: no verdict may name a corpus-reaching ability\n";
// The deny half, enumerated rather than assumed: these span
// SNT_CORPUS_STATUSES (draft/pending/private) and can never be remote.
foreach ( array( 'signal-noise/sn-posts', 'signal-noise/sn-scan', 'signal-noise/topic-clusters', 'signal-noise/keyword-candidates', 'signal-noise/link-candidates' ) as $forbidden ) {
	ok( ! in_array( $forbidden, sn_mcp_remote_slugs(), true ),
		"$forbidden is not reachable remotely" );
}
```

- [ ] **Step 2: Run to verify it fails** — `sn_mcp_remote_verdicts()` undefined.

- [ ] **Step 3: Implement `sn_mcp_remote_verdicts()`** in `inc/mcp/mcp-remote-guard.php` — every local section keyed to `array( 'remote' => bool, 'reason' => string, 'twin' => string|null )`. Default `false`; exposure requires a twin **and** a reason.

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Negative-control BOTH directions**
  - Add a section to `snt_sn_metrics_map()` without a verdict → the totality pin must red, **naming the section**.
  - Mark a verdict `remote => true` with a twin absent from `sn_mcp_remote_slugs()` → the twin pin must red.

  Restore and confirm green. **This test is the whole mechanism; if it cannot fail in both directions it is decoration.**

- [ ] **Step 6: Commit** — `git commit -m "feat: remote verdicts are total — a local section cannot ship without a remote decision"`

## Precondition A — F1 must fail CLOSED (VERIFIED UNMET)

`sn_mcp_read_rate_limit_check()`, `inc/mcp/mcp-read-guard.php:163`:

```php
	$count = sn_mcp_read_rate_limit_store_get( $key );
	$count = null === $count ? 0 : $count;   // a failed store read ALLOWS
```

Deliberate locally — a ceiling, not a boundary — and **not acceptable on a credentialed path**.

- [ ] **Task 4.A:** Make the ceiling fail closed **on the remote path only**, leaving local fail-open intact. Pin both behaviours in one suite and negative-control both — a change that made the local path fail closed too would be a silent availability regression.

## Precondition B — the scope must be ratified

The candidate partition was discussed 2026-08-11 and **never ratified**. Not a decision this plan can make.

| Candidate | Status | Note |
| --- | --- | --- |
| `provenance_integrity`, `anchor` | partition says IN | "ledger is public anyway" |
| cron freshness | IN, with a pass | hook names are recon — needs a "model, never levers" output review |
| `machine_readers` | new | aggregate counts, no post bodies, no UA samples in the summary payload |
| `analytics_top_content` | new | request-derived — **can a draft/preview slug surface?** Check before ratifying |
| `404_log` | new | request-derived and arguably recon. Leaning OUT |
| `topic_clusters`, `keyword_candidates`, `link_candidates`, `sn-posts`, `sn-scan` | **OUT by construction** | span `SNT_CORPUS_STATUSES`. Not proposable |

## The twin mechanism, and the contract cost

A remote section may **never** reuse `snt_sn_metrics_map()` — that routes to laptop abilities the remote capability cannot satisfy. It fails closed, correctly; the hazard is that the obvious "fix" is to widen the capability.

Remote reaches data through **twins**: a twin shares the local ability's `execute_callback` but has its **own slug, own permission callback, own `output_schema`** (`inc/abilities-remote-analytics.php:75-76`). Same data-producing code, never a shared gate.

Adding a remote section is therefore **two steps** — register the twin and add it to `sn_mcp_remote_slugs()`, *then* map the section. **That extra step is the boundary.**

Changing any twin's `output_schema` — or the worker envelope — requires bumping **three** things together:

1. worker `CONTRACT_VERSION` (`src/mcp.mjs`)
2. plugin `SN_REMOTE_CONTRACT_VERSION` (`inc/mcp/mcp-remote-contract.php`)
3. a new **distinct** sha256 in `SN_REMOTE_CONTRACT_VERSION_HASHES`

Run the failing test to get the computed hash — RED→pin is the intended workflow, not an error. A bump without a shape change is refused. Cross-repo coupling is observed at **install time** via the deploy probe's `contract_live` / `contract_expected` / `contract_match`, never refused.

- [ ] **Task 4.2:** (gated) Register each ratified twin, add to `sn_mcp_remote_slugs()`, extend the write-slug refusal pin to the new surface.
- [ ] **Task 4.3:** (gated) Consolidate the remote door over a **twin map** — `sn_remote_metrics(sections)`, `sn_remote_status(sections)` — bumping all three contract points in one change. The remote door is currently nine singles, the pre-consolidation shape; this is what stops each future addition from being a new tool.
- [ ] **Task 4.4:** (gated) Retire superseded remote singles **only** on telemetry showing the consolidated tools carrying the traffic — the same evidence rule wave 4 uses locally. Never on taste.

---

## Self-review notes

- **Every registered ability is now accounted for**: reachable via an option, deliberately retired (AI generation family, audit-log trio, `get-insights`/`get-narration`/`run-insights-scan`/`run-narration` — retired outright in wave 2, *not* pending a prompt rewrite), or excluded with a reason re-examined under the options framing (`ai-orphan-apply` alone).
- **Phase 3 reverses recorded decisions**, so Task 3.5 makes the reversal and its reasoning part of the record. A reversed decision with no record reads as an oversight later.
- **Phase 4 ships nothing** until two verified-unmet preconditions clear. That is the honest state, not a hedge — and Task 4.1 (the verdict map) is the one piece that can land *before* them, because it only forces decisions, never exposure.
