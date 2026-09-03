# Signal & Noise AI Abilities Catalog

This is the canonical reference for the 81 Signal & Noise WordPress 7.0 Abilities (66 plugin + 15 theme) consumed by `wp ability run`, the REST endpoint `/wp-json/wp-abilities/v1/abilities/<slug>/run`, and MCP-enabled clients.

**Machine-readable source (v9.50.0+):** The live registry is now surfaced as an MCP resource at `sn://abilities-catalog` (on both read and read-write doors). This document provides human-readable context and use-case guidance; for schema details and programmatic access, query the resource directly.

**Verified** against theme v11.4.5 + plugin v10.44.0. Last regenerated **2026-08-04** (counts recomputed from source: 66 plugin registrations, 37 read-door slugs, 36 read-write slugs; added the 15 consolidated/corpus abilities listed below; removed `draft-release-notes`, which was deleted in plugin v10.0.0 but stayed documented as live). Rendered slugs are stable; capability requirements (permissions) are read from the active registrations at query time.

## Quick reference

| Slug | Capability | Category | Allowlisted | Recommendation |
|---|---|---|---|---|
| **THEME ABILITIES** | | | | |
| `signal-and-noise/get-design-tokens` | `read` | diagnostics | ✓ | READ-DOOR |
| `signal-and-noise/get-theme-version` | `read` | diagnostics | ✓ | READ-DOOR |
| `signal-and-noise/get-design-system-summary` | `read` | diagnostics | ✓ | READ-DOOR |
| `signal-and-noise/list-block-patterns` | `read` | content | ✓ | READ-DOOR |
| `signal-and-noise/get-active-template-structure` | `read` | diagnostics | ✓ | READ-DOOR |
| `signal-and-noise/get-latest-theme-tag` | `read` | diagnostics | ✓ | READ-DOOR |
| `signal-and-noise/get-page-notes-pillars` | `read` | content | — | READ-DOOR |
| `signal-and-noise/get-reading-time-for-slug` | `read` | content | — | READ-DOOR |
| `signal-and-noise/get-seo-route-meta` | `read` | diagnostics | — | READ-DOOR |
| `signal-and-noise/get-llms-txt` | `read` | diagnostics | — | READ-DOOR |
| `signal-and-noise/ai-generate-page-note-summary` | `edit_posts` | ai-generation | — | RW-DOOR |
| `signal-and-noise/ai-suggest-block-pattern` | `edit_posts` | ai-generation | — | RW-DOOR |
| `signal-and-noise/ai-validate-brand-alignment` | `edit_posts` | ai-generation | — | RW-DOOR |
| `signal-and-noise/ai-generate-pattern-content` | `edit_posts` | ai-generation | — | RW-DOOR |
| `signal-and-noise/ai-rewrite-in-brand-voice` | `edit_posts` | ai-generation | — | RW-DOOR |
| **PLUGIN ABILITIES** | | | | |
| `signal-noise/get-analytics-summary` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-analytics-events` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-rss-stats` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/list-cron-events` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-cron-history` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-health-scan` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/anchor-status` | `manage_options` | diagnostics | — | READ-DOOR (v9.82.0) |
| `signal-noise/provenance-integrity-status` | `manage_options` | diagnostics | — | READ-DOOR (v9.82.0) |
| `signal-noise/get-404-log` | `manage_options` | diagnostics | — | NOT YET DOORED |
| `signal-noise/get-collector-status` | `manage_options` | diagnostics | — | NOT YET DOORED |
| `signal-noise/get-insights` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-narration` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/ai-cache-probe-status` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/purge-verification-log` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-deploy-status` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/uptime-status` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/block-migrations-scan` | `manage_options` | diagnostics | — | READ-DOOR |
| `signal-noise/pattern-adoption-scan` | `manage_options` | diagnostics | — | READ-DOOR |
| `signal-noise/list-template-overrides` | `manage_options` | diagnostics | — | READ-DOOR |
| `signal-noise/get-audit-log` | `manage_options` | diagnostics | — | READ-DOOR ⚠ PII |
| `signal-noise/export-audit-log` | `manage_options` | diagnostics | — | READ-DOOR ⚠ PII |
| `signal-noise/ai-alt-suggest` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-alt-apply` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-drift-suggest` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-drift-apply` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-alt-inline-suggest` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-orphan-suggest` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-orphan-apply` | `delete_post` | ai-generation | — | ⛔ EXCLUDED (no-undo) |
| `signal-noise/ai-link-suggest` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-link-apply` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-pair-suggest` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-generate-excerpt` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-generate-meta-description` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/ai-generate-og-card-title` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/pattern-adoption-suggest` | `edit_post` | diagnostics | — | RW-DOOR |
| `signal-noise/pattern-adoption-apply` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/block-migrations-suggest` | `edit_post` | diagnostics | — | RW-DOOR |
| `signal-noise/block-migrations-apply` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/suggest-tags` | `edit_post` | ai-generation | — | RW-DOOR |
| `signal-noise/regenerate-og-card` | `edit_post` | content | — | RW-DOOR |
| `signal-noise/dismiss-candidate` | `edit_post` | maintenance | — | RW-DOOR |
| `signal-noise/prepop-dismiss` | `edit_post` | maintenance | — | RW-DOOR |
| `signal-noise/run-audit-prune` | `manage_options` | maintenance | — | RW-DOOR |
| `signal-noise/run-insights-scan` | `manage_options` | diagnostics | — | RW-DOOR |
| `signal-noise/run-narration` | `manage_options` | diagnostics | — | RW-DOOR |
| `signal-noise/merge-tags` | `manage_options` | maintenance | — | ⛔ EXCLUDED (blast radius) |
| `signal-noise/prune-unused-tags` | `manage_options` | maintenance | — | RW-DOOR |
| `signal-noise/unschedule-cron-event` | `manage_options` | maintenance | — | RW-DOOR |
| `signal-noise/clear-template-overrides` | `manage_options` | maintenance | — | ⛔ EXCLUDED (Site Editor regression risk) |
| `signal-noise/purge-all-caches` | `manage_options` | maintenance | — | RW-DOOR |
| `signal-noise/run-cron-event` | `manage_options` | maintenance | — | ⛔ EXCLUDED (unbounded dispatch) |
| `signal-noise/run-health-scan` | `manage_options` | maintenance | — | ⛔ EXCLUDED (too slow for a synchronous call) |
| `signal-noise/anchor-sweep` | `manage_options` | maintenance | — | RW-DOOR (v9.82.0) |

**Totals (recomputed from source 2026-08-08):** 82 abilities (15 theme + 67 plugin); **38** on the read door; **36** on the read-write door (owner-approved safe subset, incl. the 2 PII-gated audit-log reads). Doors overlap by design — a slug may appear on both — so the door counts do not sum to the ability count. Off both doors: 2 hard-excluded (run-cron-event, run-health-scan) + 3 owner-held (ai-orphan-apply, merge-tags, clear-template-overrides) + the still-uncurated v9.81.0 pair (get-404-log, get-collector-status).

### Consolidated + corpus tools (tabled 2026-08-04)

These 15 were registered across v9.x–v10.x but never entered this catalog, so the document under-reported the plugin surface by 15 abilities. The `sn-*` family are the consolidated tools that absorb several older single-purpose abilities each (see their own docblocks for what each one supersedes).

| Ability | Label | Source |
| --- | --- | --- |
| `signal-noise/cadence-flags` | Scan operational rhythms for cadence deviations | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/duplicate-body-scan` | Scan the corpus for posts with identical bodies | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/get-machine-readers-summary` | Get Machine Readers Summary | [`abilities-machine-readers.php`](../inc/abilities-machine-readers.php) |
| `signal-noise/get-post-content` | Fetch full bodies for a bounded set of posts | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/keyword-candidates` | Rank a post's own terms as keyword candidates (TF-IDF) | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/link-candidates` | Suggest related notes the post does not link to yet | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/list-posts` | List corpus metadata for every post | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/near-duplicate-scan` | Scan the corpus for near-duplicate (cousin) post pairs | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/sn-apply` | Apply a change to a post (consolidated write tool) | [`abilities-sn-apply.php`](../inc/abilities-sn-apply.php) |
| `signal-noise/sn-posts` | List or fetch corpus posts (consolidated) | [`abilities-sn-posts.php`](../inc/abilities-sn-posts.php) |
| `signal-noise/sn-scan` | Scan the corpus for actionable candidates (consolidated) | [`abilities-sn-scan.php`](../inc/abilities-sn-scan.php) |
| `signal-noise/sn-site-facts` | Batch-read site facts (consolidated) | [`abilities-sn-site-facts.php`](../inc/abilities-sn-site-facts.php) |
| `signal-noise/sn-validate` | Validate proposed content before writing (consolidated, deterministic) | [`abilities-sn-validate.php`](../inc/abilities-sn-validate.php) |
| `signal-noise/topic-clusters` | Read the corpus topic partition | [`abilities-corpus.php`](../inc/abilities-corpus.php) |
| `signal-noise/update-post-surfaces` | Write reviewed excerpt / meta description / OG card title to a post | [`abilities-update-post-surfaces.php`](../inc/abilities-update-post-surfaces.php) |


## How to use this catalog

**WP-CLI** — pass JSON input via `--input`:
```bash
wp ability run <slug>
wp ability run <slug> --input='{"post_id": 42}'
```

**REST API** — POST to `/wp-json/wp-abilities/v1/abilities/<slug>/run` with `wordpress_logged_in_*` session cookie and `X-WP-Nonce` header for write operations. The MCP doors expose subsets of these abilities via their respective allowlists.

**MCP client (v9.50.0+)** — Query `sn://abilities-catalog` resource on either door (read or read-write) for the live registry snapshot. The read door offers 37 tools (read-only); the read-write door offers 36 tools (includes state-modifying actions). Same credentials; different risk profile.

## Detailed reference (selected abilities)

### Currently allowlisted (both READ and WRITE doors, v9.49.1+)

Read-only abilities are safe to expose to unattended AI clients:

#### `signal-noise/get-analytics-summary`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Views, visits, scroll rate, time-on-page totals for a configurable window (24h/7d/30d). Backed by Analytics Engine. Read-only.

#### `signal-and-noise/get-design-tokens`
**Capability:** `read` | **Category:** diagnostics

Color palette, typography (font families + sizes), and spacing scale from theme.json. Useful for providing design context to AI generation abilities.

#### `signal-and-noise/get-theme-version`
**Capability:** `read` | **Category:** diagnostics

Active theme name + version + WP version. Use to detect version drift between published docs and live site.

#### `signal-noise/get-deploy-status`
**Capability:** `manage_options` | **Category:** diagnostics

Current theme + plugin versions + latest GitHub releases. Confirms deploys landed before announcing.

#### `signal-noise/get-health-scan`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object|null

Cached Content-Health scan (all posts for link/attachment/formatting issues). Regenerates on-demand if stale. Returns null if never scanned. Pair with `run-health-scan` to force a fresh scan.

#### `signal-noise/anchor-status`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Aggregates every Note's latest anchor state: pending anchors with their in-flight Bitcoin transaction and confirmation count, plus confirmed/total counts. Readonly, idempotent; input is the `[object,null]` union (GET run-path safe). On the **read door** since v9.82.0.

#### `signal-noise/provenance-integrity-status`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object|null

Latest server-side provenance integrity sweep: summary counts (fleet, checked, clean, failed, unreachable, ledger-key verdict) plus every Note failing a triangle leg, each naming WHICH leg (hash mismatch, twin drift, twin unreachable, ledger missing, ledger contradiction). Returns null before the first sweep. Read-only — never triggers a sweep (the Content-Health scan owns that). On the **read door** since v9.82.0.

#### `signal-noise/get-404-log`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Recent actionable front-end 404 log (bot/probe noise filtered), most-recently-seen first, capped at 50: path, hit count, first/last seen, latest referring host, and a deterministic redirect-target suggestion from published slugs (classical string distance, similarity-floored). Read-only — redirects are still created through the audited admin form. Input is the `[object,null]` union. Not yet on an MCP door.

#### `signal-noise/get-collector-status`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Fetches the analytics worker's public `/_sn/version` and evaluates NAMED invariants: `config_bindings` (every self-reported binding true), `salt_window` (today's rotating identity salt present), `version_present`, `cron_fresh` (scheduled refresh ok within ~2h). Returns `{healthy, worker, invariants:[{name, ok, detail}]}`; optional `worker` input (enum, default `analytics`) reserves room for sibling workers. Not yet on an MCP door.

#### `signal-noise/get-insights`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object|null

Cached synthesis scan: Plausible analytics + publish history + webhook delivery + cron freshness → 5 actionable recommendations. Returns null if never scanned.

#### `signal-noise/ai-cache-probe-status`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Prompt-cache probe verdict: whether enabling Anthropic prompt caching would pay, and on which model. Thin read over `snt_ai_cache_probe_verdict()` (inc/ai-cache-probe.php, v10.50.0) — the same derive layer the Insights admin panel renders, so the two cannot disagree. `state` is one of `candidate`, `no_repeats`, `below_floor`, `unknown_floor`, `caching_active`, `no_data`. Read-only; makes no AI call and never enables caching. Added in v10.69.0 because the verdict was previously readable only in wp-admin.

#### `signal-noise/purge-verification-log`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

The per-row edge-freshness trail as data. The rows are already rendered for a human under **Post-purge probes** in the Cloudflare admin tab; what was missing is a machine reader, since the two glance widgets carry only the five aggregate numbers — and those are what an agent gets asked about. After each post save the plugin waits `SN_CF_PROBE_DELAY` seconds, fetches the post URL a reader would get plus the same URL cache-busted, and compares the normalized `<main>` region.

**Read `window` and `cap` before `counts`.** The log is a rolling buffer capped at `SN_CF_PROBE_LOG_CAP` (20), so `counts.total` pins at 20 once full and is **not** a lifetime figure — it is the size of the recent window. A rising `counts.stale` against that fixed denominator therefore means the recent failure *rate* is rising. Read as a cumulative tally it says the opposite ("this can only go up"), which is the misreading that motivated this ability on 2026-09-02.

**Read `source` before drawing any conclusion.** Two writers share this log. `post_save_probe` means a per-post purge failed to clear the edge — a fault. `manual_zone_purge` is written by `purge-all-caches`, which probes *immediately* after dispatching the zone purge and so races per-colo propagation: pressing Purge twice in a minute can add two `stale` rows describing impatience rather than a stale edge, and the counter visibly climbs per press. `counts.by_source` splits the totals; only the `post_save_probe` share is actionable. (Observed exactly that way on 2026-09-03.)

Correlate `rows[].time_iso` against deploy times before blaming the edge: every deploy rewrites site-wide HTML, so a probe whose window straddles a deploy reports `stale` correctly and transiently. `counts` cover current-detector rows only (`algo >= SN_CF_PROBE_ALGO`); older rows are returned but excluded, and `counts_excluded_rows` says how many.

`state: never_probed` is an absence of evidence, never a clean edge. There is no recheck loop — each probe records one verdict and escalates at most once — so a `stale` row describes a past instant, not an ongoing condition. Read-only: probes nothing, purges nothing. Added in v13.86.0.

#### `signal-noise/get-narration`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object|null

Cached weekly analytics digest (AI-synthesized). Returns null if never generated. Pair with `run-narration` to trigger generation.

#### `signal-and-noise/list-block-patterns`
**Capability:** `read` | **Category:** content

Registered block patterns + categories + keywords + viewport hints. Filter by category if needed.

#### `signal-noise/list-cron-events`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** array

All scheduled WP-Cron events with next-run, recurrence, last-fired, args, and handler status. Pass `sn_only=true` to filter to SN-owned hooks.

#### `signal-noise/get-cron-history`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** array

Last N firings of a cron hook (success/elapsed/error). Backed by snt_cron_history table (30-day rolling retention).

#### `signal-and-noise/get-design-system-summary`
**Capability:** `read` | **Category:** diagnostics

Design tokens formatted for AI embedding: markdown (default, human-readable), compact-text (70-80% token reduction), or json passthrough.

#### `signal-and-noise/get-page-notes-pillars`
**Capability:** `read` | **Category:** content

Metadata for /notes catalog pillar essays: slug, title, URL, summary, reading time, last modified. Useful for content-generation context.

#### `signal-and-noise/get-reading-time-for-slug`
**Capability:** `read` | **Category:** content

Computed reading time (minutes) for a post by slug. Returns 0 if slug does not resolve.

#### `signal-noise/get-rss-stats`
**Capability:** `manage_options` | **Category:** diagnostics

RSS feed request totals + unique counts (24h/7d/30d). Detect subscriber base size and crawler anomalies.

#### `signal-noise/uptime-status`
**Capability:** `manage_options` | **Category:** diagnostics

Better Stack monitor status + heartbeat response time. Detect degradation before customers report it.

#### `signal-and-noise/get-latest-theme-tag`
**Capability:** `read` | **Category:** diagnostics

Latest GitHub release tag for the theme. Compare to `get-theme-version` for update availability.

---

### New READ-DOOR candidates (v9.50.0+)

These are read-only and safe to expose to unattended clients:

#### `signal-noise/get-analytics-events`
**Capability:** `manage_options` | **Output root:** array

Top custom events for a time window. Wrapped by the envelope rule (array root → object wrapper). Useful for cross-system diagnostics.

#### `signal-noise/block-migrations-scan`
**Capability:** `manage_options` | **Output root:** object

Scan posts for heading-hierarchy issues (cached 1h). Preview findings before applying `block-migrations-apply`.

#### `signal-noise/pattern-adoption-scan`
**Capability:** `manage_options` | **Output root:** object

Scan for pattern-adoption candidates (cached). Pairs with `pattern-adoption-suggest` + `pattern-adoption-apply`.

#### `signal-noise/list-template-overrides`
**Capability:** `manage_options`

Lists any wp_template, wp_template_part, or wp_navigation DB rows overriding theme files. Inspect before calling `clear-template-overrides` (destructive).

#### `signal-and-noise/get-seo-route-meta`
**Capability:** `read`

SEO meta map for template Pages (canonical URLs, og:image fallbacks, structured data hints). Useful for SEO audits.

#### `signal-and-noise/get-llms-txt`
**Capability:** `read`

Rendered llms.txt manifest (machine-readable site capability declaration). Expose to LLM discovery clients.

#### `signal-noise/get-audit-log` ⚠️
**Capability:** `manage_options` | **⚠️ PII — username audit log**

Login-audit summary: counters, last-access timestamps, login method breakdown. Contains plaintext usernames. Requires owner sign-off before exposure.

#### `signal-noise/export-audit-log` ⚠️
**Capability:** `manage_options` | **⚠️ PII bulk — CSV/JSON audit export**

Full audit-log export (CSV or JSON). Contains plaintext usernames in bulk. Requires owner sign-off before exposure.

---

### Selected RW-DOOR abilities (state-modifying, AI-billed)

These abilities spend AI budget and/or modify content. Exposed on write door only (owner-credentials required).

#### AI Alt Text (2-ability pair)
- `signal-noise/ai-alt-suggest` | `edit_post` — Suggest alt text for attachment
- `signal-noise/ai-alt-apply` | `edit_post` — Write alt text to attachment

#### AI Drift Detection (2-ability pair)
- `signal-noise/ai-drift-suggest` | `edit_post` — Suggest replacement for stale time-phrase
- `signal-noise/ai-drift-apply` | `edit_post` — Splice phrase replacement into post_content

#### AI Inline Images (1 ability)
- `signal-noise/ai-alt-inline-suggest` | `edit_post` — Suggest alt text for inline img

#### Attachment Orphan Handling
- `signal-noise/ai-orphan-suggest` | `edit_post` — Verdict on orphaned attachment
- `signal-noise/ai-orphan-apply` | **⛔ EXCLUDED** — Force-delete, skips trash, no undo

#### Mention-to-Link Conversion (2-ability pair)
- `signal-noise/ai-link-suggest` | `edit_post` — Verdict on unlinked-mention → link
- `signal-noise/ai-link-apply` | `edit_post` — Wrap mention in anchor

#### Related-Note Pairing
- `signal-noise/ai-pair-suggest` | `edit_post` — Verdict on related-note link pair

#### Block Pattern Adoption (2-ability pair)
- `signal-noise/pattern-adoption-suggest` | `edit_post` — Preview pattern-upgrade markup
- `signal-noise/pattern-adoption-apply` | `edit_post` — Replace block with upgraded pattern

#### Heading Hierarchy Fixes (2-ability pair)
- `signal-noise/block-migrations-suggest` | `edit_post` — Preview heading fix markup
- `signal-noise/block-migrations-apply` | `edit_post` — Apply heading-hierarchy fix

#### SEO + Social Card Generation (3 abilities)
- `signal-noise/ai-generate-excerpt` | `edit_post` — 50–75 word, 2–3 sentence excerpt
- `signal-noise/ai-generate-meta-description` | `edit_post` — 140–160 char SEO description
- `signal-noise/ai-generate-og-card-title` | `edit_post` — 60–90 char social-share variant

#### Tag Suggestions + Pruning (2 abilities)
- `signal-noise/suggest-tags` | `edit_post` — Suggest tags from vocabulary
- `signal-noise/prune-unused-tags` | `manage_options` — Delete all zero-post tags (destructive)

#### Tag Consolidation
- `signal-noise/merge-tags` | **⛔ EXCLUDED** — Fold tags into canonical + 301s (sitewide blast radius)

#### Cron Management (1 ability, refusal-safe)
- `signal-noise/unschedule-cron-event` | `manage_options` — Remove scheduled cron (refuses SN-owned hooks)

#### Template + Cache Maintenance
- `signal-noise/list-template-overrides` | `manage_options` — Inspect DB template overrides
- `signal-noise/clear-template-overrides` | **⛔ EXCLUDED** — Delete template overrides (Site Editor regression risk)
- `signal-noise/purge-all-caches` | `manage_options` — Object cache + Breeze + Varnish + CF purge (v10.4.1: CF leg runs verified/blocking; response carries a `cloudflare` verdict and `ok:false` when the CF purge could not run or was rejected)

#### Content Scans (2 abilities)
- `signal-noise/run-audit-prune` | `manage_options` — Drop old audit counters (destructive)
- `signal-noise/run-insights-scan` | `manage_options` — Trigger cross-system synthesis scan (AI call, cached 7d)
- `signal-noise/run-narration` | `manage_options` — Generate weekly analytics digest (AI call, cached)

#### Health + Provenance Actions (2 abilities, v9.78.0)
- `signal-noise/run-health-scan` | `manage_options` — Run the full site-health check suite now (bypasses the 24h cache; stores the scan for the Health tab, widget, and attention badge; returns `{ok,total,flagged}`). **Off both doors on purpose:** MCP dispatches synchronously with no execution budget, the scan runs ~35s (up to ~105s during an outage), and Cloudflare's ~100s edge cap would kill the request — so an agent would get a hang and then a 524. Read the cached result through `get-health-scan` instead; the scan runs on cron regardless.
- `signal-noise/anchor-sweep` | `manage_options` — Ask the provenance Worker to upgrade pending OpenTimestamps proofs now instead of waiting for the hourly cron (idempotent: only genuinely Bitcoin-confirmed proofs flip). On the **read-write door** since v9.82.0 — one bounded `wp_remote_post` (timeout 20) inside the rw door's kill switch, app-password binding, rate limit, and audit trail.

#### Post Metadata Maintenance (1 ability)
- `signal-noise/dismiss-candidate` | `edit_post` — Dismiss a scan candidate (idempotent postmeta write)
- `signal-noise/prepop-dismiss` | `edit_post` — Clear AI-prepopulation sentinels (idempotent)

#### Social Share (1 ability)
- `signal-noise/regenerate-og-card` | `edit_post` — Rebuild social-share PNG (idempotent file write)

#### Release Automation

#### Theme AI Abilities (5)
- `signal-and-noise/ai-generate-page-note-summary` | `edit_posts` — Brand-voiced summary of a post
- `signal-and-noise/ai-suggest-block-pattern` | `edit_posts` — Recommend patterns for a draft
- `signal-and-noise/ai-validate-brand-alignment` | `edit_posts` — Score content for brand fit (0–100)
- `signal-and-noise/ai-generate-pattern-content` | `edit_posts` — Fill a pattern with brand copy
- `signal-and-noise/ai-rewrite-in-brand-voice` | `edit_posts` — Rewrite copy in brand voice (intensity: light/medium/full)

---

### Hard excludes (safety/risk profile)

These 4 abilities are NOT exposed on any MCP door:

#### `signal-noise/run-cron-event` ⛔
**Reason:** Unbounded `do_action()` dispatch on any non-`sn_*` hook, including third-party cron/uninstall routines. High risk of unintended side effects.

#### `signal-noise/ai-orphan-apply` ⛔
**Reason:** Force-deletes attachments, skips trash, no undo. High no-recovery risk.

#### `signal-noise/merge-tags` ⛔
**Reason:** Sitewide term reassignment + deletion with large blast radius. Bounded to declared inputs but high consequence on error.

#### `signal-noise/clear-template-overrides` ⛔
**Reason:** Deletes wp_template/wp_template_part/wp_navigation DB rows. Can regress the Site Editor if run without inspection. Pair with `list-template-overrides` for manual review before deletion (not exposed automatically).

---

## Per-door visibility (v9.50.0+)

**READ door** (`/wp-json/signal-noise/v1/mcp`) — 37 tools: the v9.50.0 twenty-three plus the two v9.82.0 operational-status reads (anchor-status, provenance-integrity-status). Same `manage_options` permission floor. All tools advertise `readOnlyHint: true`.

**READ-WRITE door** (`/wp-json/signal-noise/v1/mcp-rw`) — 35 tools: all 25 from the read door EXCLUDED; only the RW-approved subset, plus anchor-sweep from v9.82.0. Same `manage_options` permission floor; edit_post abilities require scoped post edit capability. No annotations in v1.

Both doors share the same JSON-RPC plumbing, wrap rule (handles array-rooted output), and envelope contract. The door context is resolved per-request and flows through dispatch — never global state.

---

## Cross-references

- **Live registry source:** [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise-tools/blob/main/inc/abilities-registration.php) (plugin) + [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise/blob/main/inc/abilities-registration.php) (theme)
- **MCP resource:** `sn://abilities-catalog` (read and read-write doors; live registry snapshot)
- **Future AI harvester:** [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) (Agents framework, step 3 = Abilities-as-tools bridge)
- **Upstream issue:** [WordPress/desktop-mode#271](https://github.com/WordPress/desktop-mode/issues/271)
