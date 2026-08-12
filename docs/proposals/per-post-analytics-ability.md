# Spec: `signal-noise/get-analytics-posts` — per-post analytics as an ability

**Status:** draft spec, not built. Written 2026-08-12 for the phone-reachable
read door (R3 §3D). Companion to
[remote-mcp-transport.md](remote-mcp-transport.md).

**Why now:** "how is the new note doing?" is the phone-shaped question, and it
is the one the current door cannot answer. `get-analytics-summary` gives site
totals; `get-analytics-events` gives custom events. Neither says anything about
an individual post.

---

## 1. The cheap part: the computation already exists

This is an **ability wrapper over shipped code**, not new analytics. Already in
`inc/analytics-posts.php`:

| Function | What it gives |
|---|---|
| `sn_analytics_post_path( $id )` `:40` | post → path, the rollup key |
| `sn_analytics_posts_daily_by_dol( $series, $publish_ts )` `:57` | daily views re-indexed by **day-of-life** |
| `sn_analytics_posts_cumulative_at( $by_dol, $age )` `:82` | views by age N — the fair comparison |
| `sn_analytics_posts_velocity( $by_dol, $n )` `:120` | recent views/day |
| `sn_analytics_posts_decay( $by_dol, $early_days )` `:138` | fall-off from the early window |
| `sn_analytics_posts_rank( $subject, $cohort )` `:165` | rank against a cohort |
| `sn_analytics_posts_recent( $limit )` `:230` | the cohort, **already `post_status => publish`** |

Plus `inc/analytics-posts-lifecycle.php` composing them into rows. The rollups
key by path and `sn_analytics_top_paths()` (`inc/analytics-read.php:37`) is the
read primitive.

**Day-of-life is the reason this is worth exposing at all.** Raw views rank an
old post above a good new one forever. Day-of-life asks "how is this doing *for
its age*", which is the question actually being asked.

---

## 2. Registration

Mirrors `inc/abilities-analytics.php` exactly — same category, same annotations,
same permission shape.

```
wp_register_ability( 'signal-noise/get-analytics-posts', array(
  'label'               => 'Get per-post analytics',
  'description'         => <see §3 — every denominator named>,
  'category'            => 'analytics',
  'permission_callback' => 'snt_ability_perm_manage_options',
  'execute_callback'    => 'sn_ability_get_analytics_posts',
  'input_schema'        => array(
    'type'       => array( 'object', 'null' ),
    'properties' => array(
      'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
      'class' => array( 'type' => 'string', 'default' => 'human' ),
      'limit' => array( 'type' => 'integer', 'default' => 12 ),
      'path'  => array( 'type' => array( 'string', 'null' ), 'default' => null ),
    ),
    'additionalProperties' => false,
  ),
  'output_schema'       => array( 'type' => 'object' ),
  'meta'                => array(
    'show_in_rest' => true,
    'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
  ),
) );
```

`path` present → that one post. Absent → the recent cohort, ranked.

**Deliberately NOT accepting `post_id`.** An id is a handle to *any* post
including a draft; a path is a handle to something that was served. Taking only
the path keeps the drafts boundary at the input, not just the output. A caller
who wants "my newest note" gets it from the cohort, which is publish-filtered
at source.

---

## 3. Output, with every denominator named

House rule from `get-analytics-summary`: the description must let an AI caller
pick the right field **from the text alone**. Per row:

| Field | Meaning, and its trap |
|---|---|
| `path` | the rollup key; always a live published permalink (§4) |
| `title`, `published` | from the resolved post |
| `age_days` | whole days since publish, UTC |
| `views_lifetime` | all views ever recorded for the path — **not** range-bounded |
| `views_in_range` | views inside the requested window; differs from lifetime and the two are never subtracted |
| `views_at_age_7` / `_30` | cumulative by **day-of-life**, `null` when the post is younger than that age — never 0 |
| `velocity_7` | mean views/day over the last 7 days of life |
| `decay` | ratio of recent to early-window rate; `null` below the sample floor |
| `rank_in_cohort` / `cohort_size` | rank by `views_at_age_N` among posts old enough to compare |
| `measured_since` | first day this path has data; a path older than the sensor reports the sensor's birth |
| `unmeasured` | `true` when the read succeeded and found nothing; distinct from a failed read |

**Three-valued, end to end.** A measured 0 is never conflated with
never-measured or with a failed read. A failed AE read returns
`{ ok: false, reason: 'could not be read' }` — never an empty cohort, which
would read as "nothing is performing".

**Nominal vs measured window.** A post younger than the requested range, or a
path whose data starts after the window opens, carries `measured_since` so the
caller can see the window it actually got. This is the trap that bit the
IPv6 gauge and the fail-open gauge earlier today: the query bound is what was
*asked for*, never what was *delivered*.

---

## 4. The gate that matters: published-only, enforced at the door

Analytics is keyed by **path**, and a path can name something unpublished — a
preview URL that got traffic, a post since reverted to draft, a private page.
The rollup does not know or care about post status.

**Rule: every emitted row must resolve, at response time, to a post that is
`publish` right now.** Not "was published when the data was written" — *now*.
Anything that fails to resolve is dropped, and the drop is counted:

```
{ rows: [...], dropped_unresolved: 2 }
```

Counted, not silent — a sudden jump in `dropped_unresolved` is the signal that
something is being unpublished, and a silent filter would hide exactly the case
the boundary exists for.

**Why at the door and not upstream:** `sn_analytics_posts_recent()` already
filters `publish`, and it would be easy to lean on that. But the `path` input
path bypasses the cohort entirely, and abilities are **REST-reachable
independently of MCP** — each `permission_callback` is the real gate. A filter
that only exists in the cohort builder is a filter that a direct call skips.
Enforce it where the response is assembled.

**This is the whole reason per-post analytics is safe to put on a phone** while
`topic-clusters`, `keyword-candidates` and `link-candidates` are not. Those are
derived from `SNT_CORPUS_STATUSES` (`inc/corpus-inspect.php:38`), which includes
`draft`, `pending`, `private` and `future` by design — `ml-draft-echoes.php`
requires it. A keyword shifting is a signal about unpublished work. Per-post
analytics, gated as above, can only ever describe what a reader could already
visit.

---

## 5. Remote-door consequence

For the local door, `snt_ability_perm_manage_options` is correct and unchanged.

For the phone slice, this ability's permission callback is the boundary a
brokered token has to satisfy — see
[remote-mcp-transport.md](remote-mcp-transport.md) §"The REST flank". Two
things must hold and neither is this spec's to decide:

1. The remote identity must map to a capability that satisfies the callback
   **without** granting `manage_options` wholesale.
2. The published-only gate in §4 must not be reachable-around via the REST
   route, since that route exists whether or not MCP exposes the tool.

If either cannot be met, this ability stays desktop-only. It is still worth
building for the local door — the wp-admin lifecycle table already shows this
data, and an agent that can read it answers "how is the new note doing" without
a browser.

---

## 6. Tests to pin (RED first)

- Day-of-life alignment: a post published mid-window ranks by age, not by
  calendar date.
- `views_at_age_30` is **`null`** for a 10-day-old post, never `0`.
- A failed read returns `ok: false`, never an empty cohort.
- Measured-zero (path exists, no views) renders `0` with `unmeasured: false`;
  never-measured renders `unmeasured: true`.
- **The gate:** a path resolving to a `draft` / `private` / `pending` post is
  dropped, and `dropped_unresolved` increments. Mutation-check by flipping a
  fixture post's status and asserting the row disappears **and** the counter
  moves — a drop that does not increment the counter is the silent filter this
  spec forbids.
- `measured_since` reports the path's first data day, not the window start.
- The cohort filter is not the only publish gate: a direct `path` call for an
  unpublished post is dropped too.

---

## 7. Open questions

1. **Cohort definition.** `sn_analytics_posts_recent()` uses `post_type => post`
   and a 12-post limit. Should pages be includable (the `/stats/` page itself
   has traffic), or is "posts" the honest scope of a note-shaped question?
2. **Is `views_lifetime` affordable?** It implies an unbounded window per path.
   AE's default 10,000-row cap (`SN_ANALYTICS_AE_ROW_CAP`) applies; a very old
   path may need a stated ceiling rather than a true lifetime.
3. **Does this belong in the phone slice at all on day one**, or does the
   transport ship first with the existing summary/events, and this land second?
   Shipping it second means the transport's blast radius is smaller while it is
   least proven.
