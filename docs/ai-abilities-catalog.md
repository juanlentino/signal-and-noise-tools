# Signal & Noise AI Abilities Catalog

This is the canonical reference for the 65 Signal & Noise WordPress 7.0 Abilities (50 plugin + 15 theme) consumed by `wp ability run`, the REST endpoint `/wp-json/wp-abilities/v1/abilities/<slug>/run`, and MCP-enabled clients.

**Machine-readable source (v9.50.0+):** The live registry is now surfaced as an MCP resource at `sn://abilities-catalog` (on both read and read-write doors). This document provides human-readable context and use-case guidance; for schema details and programmatic access, query the resource directly.

**Verified** against theme v10.42.0 + plugin current. Last regenerated **2026-07-24** (added the v9.78.0–v9.81.0 abilities: anchor-status, anchor-sweep, run-health-scan, provenance-integrity-status, get-404-log, get-collector-status). Rendered slugs are stable; capability requirements (permissions) are read from the active registrations at query time.

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
| `signal-noise/anchor-status` | `manage_options` | diagnostics | — | NOT YET DOORED |
| `signal-noise/provenance-integrity-status` | `manage_options` | diagnostics | — | NOT YET DOORED |
| `signal-noise/get-404-log` | `manage_options` | diagnostics | — | NOT YET DOORED |
| `signal-noise/get-collector-status` | `manage_options` | diagnostics | — | NOT YET DOORED |
| `signal-noise/get-insights` | `manage_options` | diagnostics | ✓ | READ-DOOR |
| `signal-noise/get-narration` | `manage_options` | diagnostics | ✓ | READ-DOOR |
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
| `signal-noise/draft-release-notes` | `manage_options` | content | — | RW-DOOR |
| `signal-noise/purge-all-caches` | `manage_options` | maintenance | — | RW-DOOR |
| `signal-noise/run-cron-event` | `manage_options` | maintenance | — | ⛔ EXCLUDED (unbounded dispatch) |
| `signal-noise/run-health-scan` | `manage_options` | maintenance | — | NOT YET DOORED |
| `signal-noise/anchor-sweep` | `manage_options` | maintenance | — | NOT YET DOORED |

**Totals:** 67 abilities (15 theme + 52 plugin); 23 on the read door; 34 on the read-write door (owner-approved safe subset, incl. the 2 PII-gated audit-log reads); 10 off both doors — 1 hard-excluded (run-cron-event) + 3 owner-held (ai-orphan-apply, merge-tags, clear-template-overrides) + 6 registered v9.78.0–v9.81.0 but not yet door-curated (anchor-status, anchor-sweep, run-health-scan, provenance-integrity-status, get-404-log, get-collector-status). 23 + 34 + 10 = 67.

## How to use this catalog

**WP-CLI** — pass JSON input via `--input`:
```bash
wp ability run <slug>
wp ability run <slug> --input='{"post_id": 42}'
```

**REST API** — POST to `/wp-json/wp-abilities/v1/abilities/<slug>/run` with `wordpress_logged_in_*` session cookie and `X-WP-Nonce` header for write operations. The MCP doors expose subsets of these abilities via their respective allowlists.

**MCP client (v9.50.0+)** — Query `sn://abilities-catalog` resource on either door (read or read-write) for the live registry snapshot. The read door offers 23 tools (read-only); the read-write door offers 34 tools (includes state-modifying actions). Same credentials; different risk profile.

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

Aggregates every Note's latest anchor state: pending anchors with their in-flight Bitcoin transaction and confirmation count, plus confirmed/total counts. Readonly, idempotent; input is the `[object,null]` union (GET run-path safe). Not yet on an MCP door.

#### `signal-noise/provenance-integrity-status`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object|null

Latest server-side provenance integrity sweep: summary counts (fleet, checked, clean, failed, unreachable, ledger-key verdict) plus every Note failing a triangle leg, each naming WHICH leg (hash mismatch, twin drift, twin unreachable, ledger missing, ledger contradiction). Returns null before the first sweep. Read-only — never triggers a sweep (the Content-Health scan owns that). Not yet on an MCP door.

#### `signal-noise/get-404-log`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Recent actionable front-end 404 log (bot/probe noise filtered), most-recently-seen first, capped at 50: path, hit count, first/last seen, latest referring host, and a deterministic redirect-target suggestion from published slugs (classical string distance, similarity-floored). Read-only — redirects are still created through the audited admin form. Input is the `[object,null]` union. Not yet on an MCP door.

#### `signal-noise/get-collector-status`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Fetches the analytics worker's public `/_sn/version` and evaluates NAMED invariants: `config_bindings` (every self-reported binding true), `salt_window` (today's rotating identity salt present), `version_present`, `cron_fresh` (scheduled refresh ok within ~2h). Returns `{healthy, worker, invariants:[{name, ok, detail}]}`; optional `worker` input (enum, default `analytics`) reserves room for sibling workers. Not yet on an MCP door.

#### `signal-noise/get-insights`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object|null

Cached synthesis scan: Plausible analytics + publish history + webhook delivery + cron freshness → 5 actionable recommendations. Returns null if never scanned.

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
- `signal-noise/purge-all-caches` | `manage_options` — Object cache + Breeze + Varnish + CF purge

#### Content Scans (2 abilities)
- `signal-noise/run-audit-prune` | `manage_options` — Drop old audit counters (destructive)
- `signal-noise/run-insights-scan` | `manage_options` — Trigger cross-system synthesis scan (AI call, cached 7d)
- `signal-noise/run-narration` | `manage_options` — Generate weekly analytics digest (AI call, cached)

#### Health + Provenance Actions (2 abilities, v9.78.0 — not yet doored)
- `signal-noise/run-health-scan` | `manage_options` — Run the full site-health check suite now (bypasses the 24h cache; stores the scan for the Health tab, widget, and attention badge; returns `{ok,total,flagged}`)
- `signal-noise/anchor-sweep` | `manage_options` — Ask the provenance Worker to upgrade pending OpenTimestamps proofs now instead of waiting for the hourly cron (idempotent: only genuinely Bitcoin-confirmed proofs flip)

#### Post Metadata Maintenance (1 ability)
- `signal-noise/dismiss-candidate` | `edit_post` — Dismiss a scan candidate (idempotent postmeta write)
- `signal-noise/prepop-dismiss` | `edit_post` — Clear AI-prepopulation sentinels (idempotent)

#### Social Share (1 ability)
- `signal-noise/regenerate-og-card` | `edit_post` — Rebuild social-share PNG (idempotent file write)

#### Release Automation
- `signal-noise/draft-release-notes` | `manage_options` — Draft release notes from changelog (AI call)

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

**READ door** (`/wp-json/signal-noise/v1/mcp`) — 23 tools: all currently allowlisted abilities + 8 new read-only candidates. Same `manage_options` permission floor. All tools advertise `readOnlyHint: true`.

**READ-WRITE door** (`/wp-json/signal-noise/v1/mcp-rw`) — 34 tools: all 23 from read door EXCLUDED; only the RW-approved subset. Same `manage_options` permission floor; edit_post abilities require scoped post edit capability. No annotations in v1.

Both doors share the same JSON-RPC plumbing, wrap rule (handles array-rooted output), and envelope contract. The door context is resolved per-request and flows through dispatch — never global state.

---

## Cross-references

- **Live registry source:** [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise-tools/blob/main/inc/abilities-registration.php) (plugin) + [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise/blob/main/inc/abilities-registration.php) (theme)
- **MCP resource:** `sn://abilities-catalog` (read and read-write doors; live registry snapshot)
- **Future AI harvester:** [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) (Agents framework, step 3 = Abilities-as-tools bridge)
- **Upstream issue:** [WordPress/desktop-mode#271](https://github.com/WordPress/desktop-mode/issues/271)
