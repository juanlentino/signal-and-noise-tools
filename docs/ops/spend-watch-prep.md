# Spend watched like uptime — implementation prep

Status: **Planned** (Operations, promoted 2026-08-09). Gate: *owner-only, and
every number read from what the platforms actually report — never estimated.*
Surface: the health dashboard's honesty contract ("unknown" when it does not
know) extended to two spend signals.

## Signal 1: GitHub Actions minutes

The quota is **3,000 min/month account-wide** (GitHub Pro = 50 hours, shared
across ALL private repos); exhaustion blocks Actions account-wide. Measured
July 2026 at ~99% of quota, so this signal is the difference between a
dashboard and an outage postmortem.

**The API lies — two traps already paid for:**

1. `/repos/{o}/{r}/actions/runs/{id}/timing` returns `total_ms: 0` on some
   accounts. Any reader trusting it reports zero usage. Do not use it.
2. Billing rounds up **per job**. The working method is per-job timestamps with
   the ceiling applied:

```bash
gh api "repos/OWNER/REPO/actions/runs/RUN_ID/jobs" \
  --jq '[.jobs[]|select(.conclusion!="skipped")
        |(((.completed_at|fromdate)-(.started_at|fromdate))/60|ceil)]|add'
```

3. The account-level readout `/users/{u}/settings/billing/actions` needs the
   `user` OAuth scope (`gh auth refresh -h github.com -s user`). Server-side
   from WP, that means a PAT with that scope stored like the other owner
   secrets — or scope the signal to this repo's runs only and label it so
   ("this repo", never "account total" it cannot see).

**Honesty rule:** a fetch failure renders "unknown", never 0 — zero and null
are different answers.

## Signal 2: AI budget

- The live total is **owner-only; do NOT estimate it.** The v9.59.0 cost
  premise was measured 2–5× overstated — estimates here have already been
  wrong once. If the platform total is not readable server-side, the tile says
  "unknown" with a link out; it never multiplies tokens by a price sheet.
- Whatever partial numbers the site's own AI plumbing records (rw-audit rows,
  per-call token counts if logged) may be shown as "recorded on-site calls" —
  labeled as the floor they are, never as the total.

## Wiring notes

- Home: the health dashboard (`inc/` health scan family) — same card chrome,
  same "unknown" posture as cron/uptime/deploy state.
- Cache: these are slow-moving numbers; a transient with hours-scale TTL is
  fine. Distinguish "cached at HH:MM" from "unknown".
- Keep secrets in options/env like existing worker tokens; presence-boolean in
  any status output, never values.

## Open decision for the build session

Repo-scoped (no new scope, honest label) vs account-scoped (new PAT scope,
the number that actually matches the quota). The gate accepts either — it only
forbids pretending one is the other.
