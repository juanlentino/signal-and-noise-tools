# CodeQL triage — 2026-08-14

Portfolio-wide sweep of GitHub code-scanning alerts. GitHub caps dismissal
comments at 280 characters, so the full reasoning for each dismissal lives
here and the alert carries a pointer.

## Inventory

Four repos have code scanning enabled and open alerts:

| Repo | High | Medium |
|---|---|---|
| `signal-and-noise-tools` (plugin) | 2 | 2 |
| `signal-and-noise` (theme) | 1 | 1 |
| `signal-and-noise-provenance` (ledger) | 2 | 0 |
| `sn-rights-signals-worker` | 3 | 3 |

**~20 other repos have code scanning DISABLED**, including
`sn-provenance-worker`, `signal-and-noise-analytics-worker`,
`sn-remote-mcp-worker`, and `signal-and-noise-login-guard-worker`. Those are
**unscanned, not clean** — the distinction matters, and an empty alert list on
a repo without scanning is not a result. See *Coverage gap* below.

## Dismissed — false positives

### plugin #4 — `js/incomplete-multi-character-sanitization`, `assets/js/prov-verify-core.js:134`

Flags the tag-stripping regex `s.replace(/<[^>]*>/g, '')` inside
`roughNormalize()`, on the premise that its output reaches an HTML sink.

**It does not.** `roughNormalize()` is a *comparison heuristic*, not a
sanitizer. Its only consumers are lines 411–412:

```js
var liveNormalized = roughNormalize( liveRaw );
var matches = liveNormalized === roughNormalize( signedContent );
```

— a **string equality test** answering "unchanged vs edited". The file
contains **zero** `innerHTML`, `outerHTML`, `insertAdjacentHTML`, or direct
document-write calls, so the stripped string is never parsed as markup.

It is also **not** the cryptographic input: the signature check verifies the
signed bytes exactly as fetched, never this approximation. A bypass of the
regex produces, at worst, a wrong *"edited"* verdict — a correctness question,
not an injection one.

### plugin #3 — `js/xss-through-dom`, `assets/provenance-admin.js:81`

Flags `a.href = ledgerUrl(full)`.

```js
function ledgerUrl( uid ) {
  if ( ! ledgerBase ) return '';
  return ledgerBase + encodeURIComponent( String( uid ) );
}
```

The scheme is **fixed and server-controlled**: `ledgerBase` is read from a
data attribute that `inc/provenance-verify.php` renders as
`https://raw.githubusercontent.com/{owner}/{repo}/main/`, escaped through
`esc_attr()`. The `uid` is percent-encoded, so it cannot contribute a colon, a
scheme, or a path escape. There is no reachable route to a `javascript:` or
`data:` URL.

### provenance #1 and #2 — `js/incomplete-multi-character-sanitization`, `normalize/sn-normalize-v1.mjs:19-20`

**These are the ones worth reading carefully, because the suggested fix would
be actively harmful.**

```js
s = s.replace(/<(script|style)[^>]*?>[\s\S]*?<\/\1>/gi, "");  // 2a
s = s.replace(/<[^>]*>/g, "");                                 // 2b
```

This is not a sanitizer. It is a **canonicalization function for signing**,
and the file's own header states the binding constraint:

> MUST produce byte-identical output to `inc/provenance-core.php`
> `sn_prov_normalize_v1()`. … DO NOT reorder without bumping the algo version.

The security property here is **byte-parity with PHP's `wp_strip_all_tags()`**,
not XSS-safety. The output is hashed and signed; it is never rendered.

Hardening the regex against `<<script>script>` would change normalized output
for some inputs, **diverge from the PHP implementation, and invalidate every
existing signature in the ledger.** A well-meaning automated fix here breaks
provenance verification silently and retroactively.

Dismissed as false positive with that reasoning recorded, precisely so a
future contributor (or an autofix bot) does not "correct" it. If the algorithm
is ever genuinely revised, it must be a coordinated PHP+JS change **with an
algo-version bump**, not a one-sided regex tweak.

## Not dismissed — real, to be fixed

### `actions/missing-workflow-permissions` × 4 (all MEDIUM)

- `signal-and-noise-tools`: `.github/workflows/deploy.yml:59`,
  `.github/workflows/cron-orphan-cleanup.yml:43`
- `signal-and-noise`: `.github/workflows/deploy.yml:53`
- `sn-rights-signals-worker`: `.github/workflows/test.yml:29`

Legitimate hardening: an unset `permissions:` block gives the `GITHUB_TOKEN`
the repository default, which is broader than any of these jobs needs. Low
risk to fix, uniform across all four. Add an explicit least-privilege block
per job.

### theme — `js/xss-through-dom`, `assets/js/contact-aliases.js:41` (HIGH)

**Not yet triaged.** Must be read against actual data flow before judging —
the two plugin XSS alerts both dissolved on inspection, but that is not a
reason to assume this one does. Pending.

### `sn-rights-signals-worker` — 3 HIGH, 2 MEDIUM (beyond the workflow one)

**Not yet triaged.** Notably `js/incomplete-url-substring-sanitization` at
`scripts/rights-checks-documents.mjs:231,234` — `'creativecommons.org' can be
anywhere in the URL`. That pattern is a **classic real** finding (a
`String.includes()` host check that `evil.com/?x=creativecommons.org`
satisfies), and this worker is the rights-signal surface, so a spoofed licence
host would be a meaningful defect. Treat as probably-real until proven
otherwise — the opposite prior from the normalizer alerts.
Also `js/stack-trace-exposure` ×2 at `src/crawler-list-status.mjs:73,112`.

## Coverage gap — the bigger finding

Severity triage is the small half. The larger issue is that scanning is off on
~20 repos, several of which carry more risk than the ones being scanned: the
provenance worker signs, the analytics worker ingests untrusted request data,
the login-guard worker sits in the auth path, and the remote-MCP worker is an
internet-facing door.

An empty alert list there is **absence of measurement, not absence of
findings** — the same never-measured-vs-measured-zero distinction that applies
to the telemetry sinks.

### CORRECTION — "just enable CodeQL there" is not available

An earlier draft of this section said enabling default CodeQL on the worker
repos was cheap. **That was wrong, and the reason matters.** Tested
empirically 2026-08-14:

```
PUT /repos/juanlentino/sn-provenance-worker/code-scanning/default-setup
→ 404 Not Found
```

The correlation is exact, and it is not a coincidence:

| Repo | Visibility | Code scanning |
|---|---|---|
| `signal-and-noise-tools` | public | **ON** |
| `signal-and-noise` | public | **ON** |
| `signal-and-noise-provenance` | public | **ON** |
| `sn-rights-signals-worker` | public | **ON** |
| `sn-provenance-worker` | private | off |
| `signal-and-noise-analytics-worker` | private | off |
| `sn-remote-mcp-worker` | private | off |
| `signal-and-noise-login-guard-worker` | private | off |

**Every public repo has it; every private repo cannot.** `security_and_analysis`
returns `null` on the private repos and a full feature set on the public ones.
Code scanning on private repos requires GitHub Advanced Security / Code
Security, which is not included in Pro. The blocker is **entitlement, not
Actions minutes** — so the earlier framing ("sits inside the Actions budget")
diagnosed the wrong constraint entirely.

### What would actually work

**Port the existing Semgrep workflow to the private workers.** It is already
the documented free bridge for exactly this gap — `.github/workflows/semgrep.yml`
in this repo, blocking since 2026-08-14 — and it runs as an ordinary CI step,
so no entitlement is involved.

The one caveat is already written into that workflow's own header and applies
directly here:

> This repo is PUBLIC, so this job bills no Actions minutes. If the repo ever
> goes private, this becomes ~1-2 billed minutes per run — fold it into
> `ci.yml` as a **step** (not a job) that day, per the per-job rounding rule.

The workers are private, so that day is now: add Semgrep as a **step inside
each worker's existing CI job**, never as a separate job. Four separate jobs
would bill four rounded-up minutes per run against a quota the org rules
already measure at ~99% consumed, and exhaustion blocks Actions
account-wide — including for unrelated client work.

Other options, for completeness: make a worker public (they are private for
real reasons — secrets and business logic), or pay for Code Security (the
owner decision on 2026-08-14 was that Aikido gets paid for when budget
allows, so a second paid scanner is unlikely to be the answer).
