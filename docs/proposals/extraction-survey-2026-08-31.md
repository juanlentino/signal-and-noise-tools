# Plugin-wide extraction survey — what outside work is worth taking

> Covers `inc/` (420 files) clustered by prefix. Method: ground each cluster in the code
> first, then search GitHub only where an external standard or corpus plausibly exists.
> Verdicts are per-cluster and most of them are "no".

**The headline is the low yield, and that is the finding.** Across the whole plugin, three
things were worth taking and one defect was worth fixing. Everything else is either already
done one layer up, bespoke by necessity, or a closed arc.

---

## Verdicts

| Cluster | Files | Verdict | Basis |
|---|---|---|---|
| `analytics` | 62 | **Surveyed — see [measurement-weave](measurement-weave-2026-08-31.md)** | Mature and bespoke (Theil-Sen, Holt, MAD, lifecycle, funnels). The gap was wiring, not capability |
| `health` | 37 | No | WP Site Health is core; the 37 checks encode estate-specific invariants no external project models |
| `abilities` | 37 | **Two takes — see [measurement-weave](measurement-weave-2026-08-31.md)** | MCP text/`structuredContent` parity and output-schema re-validation, from OpenSEO |
| `dash` + `admin` | 37 | No | UI against our own data. Nothing to import |
| `ai` | 20 | No | Constrained generation with our own fences and budget. Eval frameworks target model selection, not a fixed single-model pipeline |
| `sn` | 18 | No | The consolidated tools. Shape is ours; the parity fix above applies to their output |
| `ml` | 17 | **CLOSED — do not re-open** | [context7-audit-estate-2026-08-25](context7-audit-estate-2026-08-25.md) already put every hand-roll to the question and kept them all |
| `provenance` | 16 | **Already explored** | Ed25519, `did:`, RFC 7033, RFC 6962 Merkle, RFC 8785 JCS, OpenTimestamps are all implemented. C2PA has its own prior proposal, [c2pa-text-related-work-2026-08-17](c2pa-text-related-work-2026-08-17.md), plus [context7-audit-sn-provenance-2026-08-25](context7-audit-sn-provenance-2026-08-25.md) |
| `machine` | 14 | **One take — Phase 5 of [measurement-weave](measurement-weave-2026-08-31.md)** | Two MIT corpora to diff a hand-maintained enum against |
| `desktop` | 11 | No | Bespoke against one host application |
| `search` | 7 | **Surveyed** | See the weave — the gap was door exposure, not data |
| `login` | 5 | **One take — see [breached-credential-check](breached-credential-check-2026-08-31.md)** | Perimeter and second factor covered; the credential itself was not |
| `block` `tag` `content` `seo` `rest` `cron` `maturity` `insights` | 30 | No | Estate-specific. `seo` is three files because the schema layer is already ours |
| `ssrf-guard` | 1 | **DEFECT — see [ssrf-guard-multi-address](ssrf-guard-multi-address-2026-08-31.md)** | Guard validates one address from an rrset it never enumerates |
| singles (`websub`, `og-card`, `spotify-api`, `spend-watch`, `word-count`, …) | ~20 | No | Single-purpose integrations against fixed upstreams |

---

## The pattern in the "no" verdicts

Three distinct reasons keep recurring, and they are worth separating because they have
different half-lives:

1. **Already handled one layer up.** Rate-limiting, slug-hiding, geo-blocking and bad-bot
   filtering all arrived in the login survey as GitHub results; all are done at the edge,
   which blocks *before* the origin. An origin-side implementation would be strictly worse.
   This reason is stable — it will still hold next year.
2. **Bespoke by necessity.** The health checks, the ML kernel, the provenance chain and the
   analytics derives encode invariants specific to this estate. No external project models
   them because no external project has the problem. Also stable.
3. **Closed by a prior audit.** The ML hand-rolls and the provenance library surface were
   both examined in August 2026. Re-surveying them would repeat work whose conclusion is on
   disk. This reason **expires** — it is only as good as the audit's date, and a re-read is
   warranted when an upstream ecosystem shifts, not on a calendar.

---

## What survived, in priority order

| Item | Where | Size | Why it survived |
|---|---|---|---|
| **SSRF multi-address gap** | [ssrf-guard-multi-address](ssrf-guard-multi-address-2026-08-31.md) | S | A real defect in a security guard, found by testing rather than reading |
| **Breached-credential rejection** | [breached-credential-check](breached-credential-check-2026-08-31.md) | M | The only auth surface neither the edge nor the second factor can see |
| **GSC on the doors + the disagreement scan** | [measurement-weave](measurement-weave-2026-08-31.md) ph. 0–3 | M | Data already synced daily, readable by no agent |
| **Enum drift check** | [measurement-weave](measurement-weave-2026-08-31.md) ph. 5 | S | A hand-maintained list mirrored across two repos with nothing watching either copy |
| **MCP output parity + schema re-validation** | [measurement-weave](measurement-weave-2026-08-31.md) | S | Unblocks the `cron_*` twins deferred on output quality |

---

## Method notes — read before re-running this

- **`gh search repos` returns empty for subjects that demonstrably exist.** Six times across
  this survey and the analytics one. `"pwned passwords wordpress"` found nothing while
  `"pwned password"` returned HIBP's own organisation immediately. **Re-run every empty
  result with different phrasing before recording an absence.**
- **`gh repo view --json licenseInfo` returned null for every licensed repo.** Use
  `gh api repos/<owner>/<repo> --jq .license.spdx_id` instead. Licence is the single most
  common disqualifier — AGPL-3.0 and LGPL-3.0 both excluded otherwise-attractive candidates —
  so measuring it wrong wastes the whole evaluation.
- **Test the code, do not read it.** Both real findings this round came from execution, not
  inspection: the HIBP corpus at ~84.2 GB reversed a design, and `gethostbyname()` returning
  one of two records exposed the SSRF gap. Neither was visible in the source.
- **Star count is anti-correlated with usability here.** The two loudest results in the whole
  survey — `ua-parser-js` (10,185) and `nginx-ultimate-bad-bot-blocker` (4,783) — were both
  unusable, on licence and on layer respectively.

## Unresolved, not clear

Recorded so nobody reads them as settled negatives: Core Web Vitals / CrUX field data,
WordPress session and concurrent-login management, application-password auditing. All three
returned empty against the artifact above and were not re-run to exhaustion.
