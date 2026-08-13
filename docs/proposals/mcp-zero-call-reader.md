# Spec: the zero-call reader — making `sn_tool_call` legible

**Status:** draft spec, not built. Written 2026-08-12.

**Does NOT block R3 §3D.** The phone door and this reader are independent; build
them in parallel or in either order. This gates only the **legacy tool
retirement** (§A5), which was earmarked for v11.0.0 and cannot ride it on
evidence until this exists.

---

## 1. The problem: the instrument was built and never read

`inc/mcp/mcp-telemetry.php` has exactly three code paths against its own table:

| Path | Line |
|---|---|
| `sn_mcp_telemetry_install()` | `:112` |
| `sn_mcp_telemetry_record()` | `:406` |
| `sn_mcp_telemetry_maybe_prune()` | `:445` |

Install, insert, delete. **No `SELECT` anywhere in the codebase.** The module's
own docblock (`:9`) says it feeds "the six telemetry metrics (zero-call set,
misroute rate, schema-error rate, candidate acceptance, gate refusals)" — and
nothing consumes any of them. The tests assert the insert and the prune; there is
no read to assert.

So the retirement program's gate — *"nothing retires until usage data justifies
it"* — is not merely unmet. It is **unmeetable from inside the plugin**: the
evidence accrues into a table with no reader, and is deleted at 90 days whether
or not anyone looked.

This is the session's recurring shape at program scale: a measurement that exists
and a readout that does not.

---

## 2. What it must report, and the three traps it must not fall into

The table (`:87`) already carries everything needed: `ts`, `door`, `actor`,
`tool_name`, `outcome`, `refusal_gate`, `latency_ms`, `result_count`.

### Trap 1 — zero rows is not "unused"

**Proxy `-32602` refusals are invisible to every telemetry layer.** A call the
MCP proxy rejects on schema grounds never reaches `sn_mcp_telemetry_record()`.
So a tool with no rows is one of:

- nobody used it, or
- nobody **could** use it — its projected schema is rejected before the call
  site is reached.

These are opposite conclusions with the same evidence. The reader MUST report
them as separate facts and refuse to collapse them:

```
{ tool: 'x', calls: 0, reachable: true|false|unknown, verdict: 'unused'|'unreachable'|'undetermined' }
```

`reachable` comes from projecting the tool through the same path `tools/list`
uses and asserting the schema survives — the projection round-trip test class
from v10.41.1 already does this and is the natural source.

**A tool may only be retired on `verdict: 'unused'`.** `'unreachable'` is a bug
report, not a retirement candidate — retiring it would delete the evidence of a
defect.

### Trap 2 — the nominal window is not the measured window

The retention constant is 90 days (`SN_MCP_TELEMETRY_RETENTION_DAYS`, `:57`),
but the table began writing at **v10.25.0 (2026-08-01)**. Any report today
covers ~11 days, not 90, and nothing in the query says so.

**Report `measured_since = MIN(ts)` from the data itself**, never the retention
constant. Same fix as the IPv6 gauge and the fail-open gauge shipped earlier
today: put `MIN(ts)` in the same query as the aggregate, derive the span, and
name it in the output. A "90-day zero-call set" computed over 11 days of data is
a confident wrong answer.

**Per-tool birth matters too.** A tool registered three days ago with zero calls
is not evidence of anything. Registration dates are not stored, so the reader
cannot compute this — it must instead **state the corpus-wide `measured_since`
prominently** and leave the per-tool judgement to a human who knows when a slug
shipped. Do not invent a per-tool birth date.

### Trap 3 — the prune will eventually lie

At 90 days the prune deletes. A tool used 91 days ago reads as zero forever
after. Harmless today (the sensor is younger than the window); a real hazard the
first time a retirement decision is made after the table has been full for a
while. The output must carry the window so this is visible rather than implicit.

---

## 3. Shape

**A read accessor plus an owner-only admin block. NOT an ability, NOT an MCP
tool.**

Two reasons, and the second is the interesting one:

1. The precedent is already set — `inc/ai-tool-invocation-log.php:22` declines to
   expose its log as a Copilot tool, because "a read-only ability would re-add
   the per-turn rent this whole initiative removed." Same argument.
2. **Observer effect.** A tool that reads the call log writes rows to the call
   log every time it is called. The reader would appear in its own zero-call
   analysis, always non-zero, and would inflate the very corpus it measures.

Accessor, roughly:

```
sn_mcp_telemetry_usage( $days = SN_MCP_TELEMETRY_RETENTION_DAYS )
  → array{
      measured_since: string|null,   // MIN(ts) — the REAL span, not $days
      measured_days:  int|null,
      window_days:    int,           // what was asked for
      complete:       bool,          // measured_days >= window_days
      by_tool: array<string, array{
        calls: int, last_seen: string|null, doors: string[],
        outcomes: array<string,int>,  // success / refusal flavours
      }>,
      zero_call: string[],            // allowlisted tools with NO rows
      total_rows: int,
    }
```

`zero_call` is computed in PHP by diffing the allowlist against the grouped
result — not by SQL, because the allowlist is the source of truth for *what
should have been callable* and it lives in `sn_mcp_allowlist()`
(`inc/mcp/mcp-capabilities.php:63`, **28 entries**) and the rw door's equivalent.

**Null vs zero, end to end.** A missing table returns `null`, never an empty
report. A table that exists with no rows returns `total_rows: 0` and
`measured_since: null` — "the sensor is installed and has recorded nothing" is a
different statement from "the sensor is absent", and both differ from "these
tools went unused".

Surface: the MCP Clients tab (where the doors already are), folded per the house
pattern, headline carrying the measured window and the zero-call count.

---

## 4. Tests to pin (RED first)

- `measured_since` comes from `MIN(ts)`, not from the retention constant —
  mutation: return the constant instead and the pin must fire.
- A window with 11 days of data reports `complete: false`, and the headline says
  so rather than claiming 90.
- Missing table → `null`. Empty table → `total_rows: 0`, `measured_since: null`.
  Three distinct outcomes, three distinct assertions.
- A tool in the allowlist with no rows lands in `zero_call`; a tool with rows
  does not.
- **The reachability split:** a tool that is zero-call AND unreachable must NOT
  be reported as `unused`. Mutation: mark a fixture tool unreachable and assert
  its verdict changes from `unused` to `unreachable`.
- `wpdb` failure returns `false`/`[]`, not `null` — the stub must model that
  shape (project memory: a failed wpdb query returns `false`, and a stub that
  models only the success shape has tested nothing).

---

## 5. Sequencing

1. **Reader ships as a normal MINOR.** It is additive, owner-only, no schema
   change, no new door surface.
2. It reports against real data for a window the owner judges sufficient — the
   reader names the span, the owner names "enough".
3. **§A5 retirement then lands on that evidence**, in its own MAJOR. It cannot
   honestly ride v11.0.0 unless the reader ships first and the window is judged
   adequate before that release.
4. v11.0.0 therefore carries the **phone door** and the **deprecated legacy
   quartet removal** — both ready — with the retirement following in a later
   major once the evidence exists.

The alternative is retiring tools on a date, which is exactly the failure the
telemetry-first design was built to prevent — made sharper by the fact that the
instrument was built, then never read.
