# Remote analytics MCP — Increment 1, origin half (permission boundary)

**Date:** 2026-08-13
**Parent proposal:** [`remote-mcp-transport.md`](remote-mcp-transport.md) §"Increment 1"
**Scope:** plugin (`signal-and-noise-tools`) only. No Worker changes. No origin bridge.
**Version impact:** none yet — nothing user-visible activates. See [Versioning](#versioning).

---

## Why this is the origin half only, and not Increment 1 entire

Increment 1 as written in the proposal ships two things at once: the origin-side
permission boundary, and the Worker→origin bridge that gives a remote caller a way
to reach it. I am building only the first.

The reason is the gate the increments exist to create. Increment 0's exit criterion
is *"connector works end-to-end on phone; disconnect + edge revoke both stop further
calls within one access-token TTL."* That criterion is **not met**. Increment 0 is
deployed and works — `https://mcp.juanlentino.com/mcp` answers and `sn_remote_ping`
is live — but only from the **Claude Code CLI**, via Cloudflare Access service-token
headers. claude.ai web and mobile cannot send custom headers, so they require browser
OAuth, and that path is blocked at the zone layer.

Two consequences follow, and both argue against shipping a data path today:

1. **The product rationale is the phone.** §8.8's reopening condition was a repeated
   phone task requiring an agent. If the only client that can connect is the CLI, the
   caller is the laptop, which already reaches the full 38-tool read door via
   application password. A data path reachable only from the laptop adds a second,
   weaker route to data its caller already holds.
2. **Kill criterion 2 is unsatisfied** — no phone-reachable revoke stopping traffic
   within one access-token TTL. Increment 0 could tolerate that, because
   `sn_remote_ping` returns no site data. Increment 1 is the step that would put real
   site data behind that missing control.

The origin-side permission boundary, by contrast, is needed no matter which client
eventually connects, and is the part whose failure modes tests can actually catch.
So it goes first, and it goes in without a data path.

---

## Design decisions carried in from the proposal

Two were already settled by the owner on 2026-08-12 and are inputs here, not choices:

- **Dedicated capability AND remote-only callback — both halves, not either.** A
  capability alone is a bearer claim whose reach grows silently as new abilities
  register; a callback alone has no principal to test. Together the capability
  answers *who* and the callback answers *which*.
- **Durable Object for the F1 brokered counter.** Edge-side, and therefore out of
  scope for this document. Named so nobody re-decides it here.

Three were decided during this session's brainstorm and are recorded below with their
reasoning, because each rejected a plausible alternative:

- Per-slug callbacks over ambient slug resolution ([§3](#3-predicate-and-per-slug-callbacks)).
- A separate remote ability slug over a union callback ([§4](#4-the-remote-ability)).
- A default-off kill switch with inverted absence semantics ([§1](#1-the-kill-switch-fail-closed-on-absence)).

---

## Architecture

Five units, each independently testable. Nothing here has a network dependency.

### 1. The kill switch — fail-CLOSED on absence

A new option `sn_mcp_remote_enabled`, plus a `SN_MCP_REMOTE_DISABLED` wp-config
constant that wins unconditionally, matching the shape of both existing doors.

The absence semantics are **inverted** relative to `sn_mcp_read_enabled`. On the read
door, an untouched option means "the owner never turned it off" and the door is open.
Here, an untouched option means "the owner never turned it **on**" and the door is
shut. That inversion is the proposal's own requirement for any brokered path
(Constraint 1, and the §"F1 fail closed — concrete" table), and it is what makes this
increment safe to ship with no bridge: the entire remote surface is inert in
production the moment it lands.

Inert code is normally a hazard — a mechanism nobody exercises is a guess nobody has
checked. The switch is what converts this from *inert by accident* to *inert by
configuration*: the tests flip it on and exercise every path for real, and both
states are pinned. The production default and the tested behaviour are then two
observable things rather than one unobservable one.

### 2. The capability

`sn_read_remote_analytics`. Granted to **no role** at activation and by no migration.
Nothing holds it until a bridge or the owner deliberately grants it.

It is never `manage_options` and never implied by a role that carries
`manage_options`. The callback additionally refuses a `manage_options` administrator
who lacks the remote capability: the remote slug is remote-only in both directions,
so an admin cannot accidentally exercise the remote path and conclude it works.

### 3. Predicate and per-slug callbacks

```php
sn_remote_analytics_allows( string $slug ): bool
snt_ability_perm_remote_analytics_summary(): bool   // passes its own literal slug
```

`sn_remote_analytics_allows()` evaluates three gates in this order:

1. **Kill switch** — constant, then option, fail-closed on absence.
2. **Capability** — `current_user_can( 'sn_read_remote_analytics' )`.
3. **Allowlist** — `$slug` is a member of `SN_MCP_REMOTE_SLUGS`.

Switch first, so "closed" beats "you hold the capability" — the same precedence the
read guard achieves by putting the kill switch at `rest_pre_dispatch` priority 10 and
the rate limit at 11.

**Why per-slug callbacks rather than one ambient-resolving callback.**
`$ability->check_permissions( $args )` (`inc/mcp/mcp-tools.php:467`) passes only the
arguments; a permission callback never learns its own slug. A single callback would
have to infer it from ambient request state — the REST route, or a dispatch-scoped
variable. Both infer wrongly under nesting: an ability that internally executes
another ability hands the inner callback the **outer** slug, and the inner gate then
approves itself against a name that was never its own. It would look healthy, because
the allowlist check passed.

A callback that names its own slug as a literal cannot be confused that way. The cost
is roughly four lines per remote tool at Increment 2, and that cost is partly a
benefit: widening the remote surface requires writing and attaching a function, which
is a reviewable act, rather than appending a string to a list.

### 4. The remote ability

`signal-noise/remote-get-analytics-summary`, sharing the existing
`sn_ability_get_analytics_summary` execute callback and the existing input/output
schemas, gated **only** by `snt_ability_perm_remote_analytics_summary`.

The alternative was a union callback on the existing `get-analytics-summary`
(`manage_options` OR remote scope). A union can only ever add allow paths, so a bug
in the remote branch could not break the admin caller — but it would place remote
logic inside an admin-facing gate, where any future widening of the remote branch
widens an admin surface too. Registering a separate slug keeps
`snt_ability_perm_manage_options` on the existing ability **byte-identical**, which
is the same isolation `mcp-read-guard.php` maintains when its header states it never
calls into `mcp-rw-guard.php`.

The new slug is deliberately **absent** from `sn_mcp_allowlist()`. The laptop read
door gains nothing from this increment.

### 5. Guard coverage — closing the F2-shaped gap this opens

Keeping the slug off the read allowlist has a consequence that must be handled
explicitly rather than discovered later.

`sn_mcp_read_guard` applies the read kill switch and the 120/min ceiling to native
run routes **only for slugs on the read allowlist** (`inc/mcp/mcp-read-guard.php`,
`rest_pre_dispatch` priorities 10 and 11). A slug off that list reaches
`POST /wp-abilities/v1/abilities/<slug>/run` with **no ceiling and no kill switch** —
which is precisely the single-route gap F2 was closed to eliminate.

So the guard needs a **second coverage set**. `SN_MCP_REMOTE_SLUGS` is honoured by
the guard for kill-switch and rate-limit coverage, and is **not** honoured by
`sn_mcp_is_allowed()` for exposure.

Two lists, because they answer two different questions:

| List | Question it answers |
| --- | --- |
| `sn_mcp_allowlist()` | What is **reachable** through the MCP read door? |
| `SN_MCP_REMOTE_SLUGS` | What is **governed** by the guard, and remote-permitted? |

Conflating them is what would reopen F2. The remote slug is governed without being
reachable from the laptop door.

Which kill switch governs the remote slug's run route is a sub-decision worth stating:
the **remote** switch, not `sn_mcp_read_enabled`. A remote slug darkened by the read
door's switch would inherit fail-open-on-absence, which is the exact inversion this
design exists to avoid.

**The ceiling, however, is the existing fail-OPEN one, and that is deliberate.** The
guard's rate limiter maps an unavailable store to `null → 0 → allow`
(`inc/mcp/mcp-read-guard.php:151-163`). The proposal requires the *brokered* counter
to fail closed — and that counter is the edge Durable Object, which is Worker-side
and out of scope here. Reimplementing a fail-closed counter at the origin now would
build a second limiter that the real remote path will not use, and whose agreement
with the edge nothing would test.

What this means concretely: **the origin's ceiling is not the remote path's F1
control, and this increment must not be read as closing F1.** It is the same
fail-open ceiling the laptop already has, applied to one more slug. F1 for the remote
path closes at the edge, in the increment that builds the bridge. The kill switch is
the control this increment does close, and it closes fail-shut.

---

## Data flow

There is no network data flow in this increment. The only paths that reach the new
ability are:

1. `POST /wp-abilities/v1/abilities/signal-noise/remote-get-analytics-summary/run` —
   the native run route, governed by the guard per §5.
2. Direct `wp_get_ability( … )->execute()` from PHP, which is what the tests drive.

The MCP read door cannot reach it (not on the allowlist). The rw door cannot reach it
(different allowlist, different guard). No Worker can reach it — there is no bridge.

---

## Error handling

Every gate denies by returning `false` from the permission callback, which the
abilities layer and the REST controller both render as a 403. No gate throws, and no
gate returns a `WP_Error` carrying a reason — a denial that explains *which* of the
three gates refused would tell an unauthenticated caller whether the capability
exists and whether the switch is on.

The guard's run-route coverage returns its existing responses unchanged: the kill
switch's refusal and the ceiling's 429.

Absence is denial at every gate this increment owns: missing option, missing
capability, non-member slug. No gate in `sn_remote_analytics_allows()` maps an absent
or errored input to "allow".

The one exception is inherited, not introduced, and is named in §5: the run-route
rate ceiling remains fail-open on an unavailable store, because the remote path's
fail-closed counter belongs to the edge.

---

## Testing

The suite is the *only* live proof this increment works, so the tests carry more
weight than usual. Each bullet is an assertion group, not a single assertion.

**Permission matrix**

- Capability-holding non-admin: **allowed** on the remote slug.
- Same principal: **refused** on `get-post-content`, on any `sn-apply` slug, and at
  the `mcp-rw` door.
- `manage_options` admin **without** the capability: **refused** on the remote slug.
- Anonymous: refused everywhere.

**Switch semantics**

- Option absent + capability held → **refused** (fail-closed on absence).
- Option on + capability absent → refused.
- `SN_MCP_REMOTE_DISABLED` constant set + option on + capability held → refused
  (constant wins unconditionally).

**Scope stability — the owner's stated test obligation**

- Register a fixture ability mid-test, then assert the remote callback still refuses
  it. A gate whose scope grows when a new ability registers is the failure this whole
  design exists to prevent, and it is the assertion that would catch it.

**Guard coverage**

- The remote slug's run route is covered by the kill switch and by the ceiling.
- The existing read-allowlist slugs' coverage is unchanged.

**Isolation**

- `snt_ability_perm_manage_options` is byte-identical; the existing
  `get-analytics-summary` registration is unchanged.
- `sn_mcp_allowlist()` membership is unchanged — pin the count and the set.

**Mutation verification** (run each, confirm the named pins red, revert)

- Delete the kill-switch check from `sn_remote_analytics_allows()` → the fail-closed
  pins red.
- Delete the capability check → the admin-without-capability pin reds.
- Delete the allowlist check → the fixture-ability pin reds.
- Add the remote slug to `sn_mcp_allowlist()` → the allowlist-set pin reds.

No mutation is proposed against the rate ceiling. Its fail-open behaviour is
inherited and intentional here (§5), so a mutation asserting fail-closed would pin a
property this increment does not have — and a pin that cannot red for the right
reason is worse than no pin.

Commit before mutating, and verify each mutation actually applied before believing a
"no pins fired" result.

---

## Versioning

No version bump. Nothing user-visible activates: no UI, no new reachable surface, and
the remote path is off by default with nothing holding its capability. The CHANGELOG
gets an `[Unreleased]` entry; the bump rides whichever release train carries the next
user-visible change.

The proposal's `v11.0.0` MAJOR target still stands for the increment that requires
owner setup action. This is not that increment.

---

## Explicitly out of scope

Named so they are not silently assumed done:

- The Worker→origin bridge, and the binding α vs β decision.
- Principal establishment — how a request *becomes* the remote principal.
- Session identity in telemetry (threat model §8.3 precondition 5).
- Phone-reachable revoke (Increment 3).
- Any change to the Worker repo.
- Any change to the read door, the rw door, or their allowlists.

---

## A gap that opens at Increment 2, not now

`snt_ability_perm_remote_analytics_summary()` passes its own slug as a literal, and
the whole point of that choice is that the literal cannot be confused by nested
execution. **Nothing currently proves the literal is the right one.**

The reason is structural rather than an oversight: `sn_mcp_remote_slugs()` has exactly
one member, so a mutation replacing the literal with a *different* remote slug has no
other value to use. Every wrong literal is either the same string or a non-member,
and the non-member case is already covered. A test written today would assert
something the type system effectively guarantees.

This stops being true the moment Increment 2 adds a second remote slug. At that point
a per-slug callback carrying the *wrong* member's literal would pass every existing
assertion — the gates all fire correctly, just for the wrong ability. **The test to
add then:** each per-slug callback is allowed for its own slug and refused for every
other member of the remote list.

Recorded here rather than only in a code comment, because the moment it matters is
the moment nobody is re-reading Increment 1's tests.

## Open question deliberately left open

**Does the remote principal end up as a real WordPress user, or a synthetic
request-scoped identity?** This design does not decide it, because it does not need
to: `current_user_can()` answers correctly for either, and the choice belongs with
the bridge that establishes the principal. Recording it here so the next session
knows it was left open on purpose rather than overlooked.

---

## Mutation verification

Run 2026-08-13 against the completed increment. Every mutation was applied to source,
confirmed landed with `git diff` on the exact path, run, then reverted. The Verified
column quotes the assertion names as the suites printed them.

| Mutation | Pins that red | Verified |
| --- | --- | --- |
| Remove the kill-switch check from `sn_remote_analytics_allows()` | switch-absent refusal | `FAIL - THE ONE THAT MATTERS: capability held, slug listed, switch ABSENT -> refused` and `FAIL - and the gate refuses a fully-credentialled caller` (2 failed / 20 passed) |
| Flip the `get_option()` default to `true` (fail-open) | absent-option engaged + switch-absent refusal | `FAIL - absent option -> engaged (fail CLOSED)`, `FAIL - THE ONE THAT MATTERS: capability held, slug listed, switch ABSENT -> refused`, plus `FAIL - switch engaged -> the remote run route is refused as sn_mcp_remote_disabled` (3 failed / 19 passed) |
| Remove the capability check | capability-absent + admin-without-capability | `FAIL - switch on, capability absent -> refused` and `FAIL - a manage_options admin WITHOUT the remote capability -> refused` (2 failed / 20 passed) |
| Replace slug membership with `return true` | scope-stability fixture + corpus + write slug | `FAIL - a brand-new ability slug is out of remote scope BY DEFAULT`, `FAIL - a corpus slug is not on the remote list -> refused`, `FAIL - a write slug -> refused`, plus `FAIL - an empty slug -> refused` (4 failed / 18 passed) |
| Add the remote slug to `sn_mcp_allowlist()` | allowlist absence, in both suites | `mcp-capabilities.php`: `FAIL: signal-noise/remote-get-analytics-summary is absent from the READ allowlist`, plus both cardinality pins (`read-door allowlist has exactly 38 slugs`, `read door carries exactly 28 plugin slugs`) — 3 failed / 117 passed. `abilities-remote-analytics.php`: `FAIL - the remote slug is ABSENT from the MCP read allowlist` — 1 failed / 17 passed |

No mutation reddened fewer pins than expected; three reddened more. The extras are
real coverage, not noise:

- The fail-open mutation also reds the **run-route** assertion, because the
  `rest_pre_dispatch` guard reads the same predicate. The switch is therefore pinned
  at two independent call sites, not one — the ability gate and the native run route.
- The kill-switch removal also reds the constant-wins group's live assertion, which
  confirms `SN_MCP_REMOTE_DISABLED` reaches the gate through the same path rather
  than through a second mechanism that would need its own pin.
- The `return true` mutation also reds the empty-slug refusal, so the string guard is
  pinned separately from the membership test.

After the sweep with all five reverted: `-- swept 426 suites, 16953 assertions passed, 1 skipped --`.
