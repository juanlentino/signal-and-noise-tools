# Defense numbers — implementation prep

Status: **Planned** (Operations, promoted 2026-08-09). Gate: *each gauge proven to
move when the failure it watches occurs*. Surface: **owner-only**, the existing
Analytics → Login defense view. Plugin-only work — no worker deploy needed; every
number below already lands in the `sn_login_guard` Analytics Engine dataset.

## Gauge 1: fail-open visibility

The guard's philosophy is "never lock the owner out", so its failure mode is
silent permissiveness. Count it per week:

```sql
SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,
  sum(if(blob2 = 'failopen', _sample_interval, 0)) AS failopen,
  sum(if(blob2 = 'degraded', _sample_interval, 0)) AS degraded
FROM sn_login_guard
WHERE timestamp > now() - INTERVAL '7' DAY
GROUP BY day ORDER BY day
```

- `failopen` = handler threw, request passed. `degraded` = corrupted denylist
  KV, guard enforcing an empty list. Both are "the door was open" states.
- Healthy is **zero**; render zero as an explicit "0 fail-opens (7d)" line, not
  by omission — absence of the gauge must be distinguishable from a zero read.

## Gauge 2: IPv6 share vs the pre-committed criterion

The decision rule is already written down (worker `src/index.js`, `ipFamily()`
docblock): **build 128-bit denylist ranges when the IPv6 share of block-eligible
traffic exceeds 5% sustained over 30 days.** The gauge automates that query and
draws the 5% line:

```sql
SELECT blob8 AS family, sum(_sample_interval) AS hits
FROM sn_login_guard
WHERE timestamp > now() - INTERVAL '30' DAY
GROUP BY family
```

- Share = v6 / (v4 + v6 + unknown). The denominator is complete (every decision
  path logs), so this is the real share.
- Render the current share against the 5% threshold; when crossed, the label
  says the decision it triggers ("build 128-bit ranges"), not just the number.

## Wiring notes (follow the existing pattern)

- Query builders in `inc/login-defense.php`, render in
  `inc/login-defense-analytics.php` via `snt_an_kpi_row()` / the shared trend
  primitive. Ride `sn_login_defense_headline()`'s 10-minute transient if either
  gauge joins the dashboard widget; otherwise query per panel view like the
  existing ASN/country tables.
- AE dialect: `count(*)` 422s — always `sum(_sample_interval)`. Blobs 1–7 are a
  positional contract; the family is **blob8**.

## Satisfying the gate (the proven-to-move test)

CLI fixtures can pin SQL strings and reducer math, but the gate asks more: a
fixture that feeds synthetic rows (`failopen` day, `degraded` day, v6-heavy
30d) and asserts the rendered value changes. The live throttle-fire method from
2026-08-09 (POSTs on one connection until 429) is the template for a manual
end-to-end check; a fail-open cannot be fired on demand — the synthetic-row
fixture is the honest substitute, and that limit should be stated in the PR.
