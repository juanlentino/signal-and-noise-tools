# Signal & Noise AI Abilities Catalog

The reference for the 144 Signal & Noise WordPress Abilities: 113 plugin abilities, 15 remote twins of them, and 16 theme abilities. They are consumed by `wp ability run`, the REST endpoint `/wp-json/wp-abilities/v1/abilities/<slug>/run`, the plugin's two MCP doors, and (for the twins) the remote MCP Worker.

**Machine-readable source:** the live registry is an MCP resource, `sn://abilities-catalog`, on both doors. Query it for schemas; this document is the human map.

**Regenerated from source 2026-09-24** against plugin 18.4.0 and theme 14.3.0: every `wp_register_ability()` call in both repos, and the door allowlists as `sn_mcp_allowlist()` and `sn_mcp_rw_allowlist()` return them. The door sizes are pinned by `tests/mcp-capabilities.php`; the twins' contract by `tests/remote-contract-shapes.php`. Where this file and those disagree, the tests are right.

## Quick reference

**Door:** READ is `signal-noise/v1/mcp`, RW is `signal-noise/v1/mcp-rw`, REMOTE is the sn-remote-mcp Worker's bridge, and — means no MCP door (still reachable by `wp ability run` and the Abilities REST route under its own permission callback).

| Slug | Label | Category | Door |
|---|---|---|---|
| **PLUGIN** | | | |
| `signal-noise/ai-alt-apply` | Apply alt text to an attachment | ai-generation | — |
| `signal-noise/ai-alt-inline-suggest` | Suggest alt text for an inline &lt;img&gt; in a post body | ai-generation | — |
| `signal-noise/ai-alt-suggest` | Suggest alt text for an attachment | ai-generation | — |
| `signal-noise/ai-cache-probe-status` | AI Prompt-Cache Probe Status | diagnostics | READ |
| `signal-noise/ai-drift-apply` | Apply replacement for a drifted time-phrase | ai-generation | — |
| `signal-noise/ai-drift-suggest` | Suggest replacement for a drifted time-phrase | ai-generation | — |
| `signal-noise/ai-generate-excerpt` | Generate post excerpt with AI | ai-generation | — |
| `signal-noise/ai-generate-meta-description` | Generate SEO meta description with AI | ai-generation | — |
| `signal-noise/ai-generate-og-card-title` | Generate OG card title with AI | ai-generation | — |
| `signal-noise/ai-link-apply` | Wrap an unlinked mention in an internal link | ai-generation | RW |
| `signal-noise/ai-link-suggest` | Suggest whether an unlinked mention should become a link | ai-generation | — |
| `signal-noise/ai-orphan-apply` | Delete an orphan attachment | ai-generation | — |
| `signal-noise/ai-orphan-suggest` | Suggest orphan-media verdict for an attachment | ai-generation | — |
| `signal-noise/ai-pair-suggest` | Suggest whether two related notes should link | ai-generation | RW |
| `signal-noise/anchor-status` | Provenance anchor overview | diagnostics | READ |
| `signal-noise/anchor-sweep` | Run the anchor upgrade sweep | maintenance | — |
| `signal-noise/apply-tag-description` | Write one tag description | content | RW |
| `signal-noise/bing-search-performance` | Bing Webmaster: the stored window | diagnostics | READ |
| `signal-noise/block-migrations-apply` | Apply a block migration to a post | tools | — |
| `signal-noise/block-migrations-scan` | Scan all posts for block-migration candidates | tools | — |
| `signal-noise/block-migrations-suggest` | Generate a block-migration suggestion preview | tools | — |
| `signal-noise/cache-freshness` | Edge Cache Freshness | diagnostics | READ |
| `signal-noise/cadence-flags` | Scan operational rhythms for cadence deviations | tools | READ |
| `signal-noise/clear-template-overrides` | Clear database template overrides | maintenance | — |
| `signal-noise/cloudflare-status` | Cloudflare Status | diagnostics | READ |
| `signal-noise/content-queue` | Content queue | content | — |
| `signal-noise/corpus-integrity-scan` | Scan the corpus for content-integrity defects | tools | — |
| `signal-noise/cron-health-summary` | Cron health, summarized | diagnostics | — |
| `signal-noise/describe-tags` | Draft tag descriptions (AI) | content | RW |
| `signal-noise/dismiss-candidate` | Dismiss a scan candidate | tools | — |
| `signal-noise/draft-echoes` | Find the existing notes a draft echoes | tools | READ |
| `signal-noise/duplicate-body-scan` | Scan the corpus for posts with identical bodies | tools | — |
| `signal-noise/edge-errors-summary` | Edge 5xx summary | diagnostics | READ |
| `signal-noise/edge-sampling-probe` | Edge Sampling Probe | diagnostics | READ |
| `signal-noise/export-audit-log` | Export login-audit log | diagnostics | — |
| `signal-noise/family-drift` | Crawler-family enum drift (stored report) | diagnostics | READ |
| `signal-noise/get-404-log` | Recent front-end 404 log | diagnostics | — |
| `signal-noise/get-analytics-events` | Get custom events | analytics | READ |
| `signal-noise/get-analytics-summary` | Get analytics summary | analytics | READ |
| `signal-noise/get-analytics-top-content` | Get top content | analytics | — |
| `signal-noise/get-audit-log` | Get login-audit log (summary, counters, or logins) | diagnostics | — |
| `signal-noise/get-collector-status` | Analytics collector health | diagnostics | — |
| `signal-noise/get-cron-history` | Get Cron Firing History | diagnostics | READ |
| `signal-noise/get-deploy-status` | Get theme + plugin deploy status | diagnostics | READ |
| `signal-noise/get-health-scan` | Get Content-Health Scan Summary | diagnostics | READ |
| `signal-noise/get-insights` | Get Last Insights Scan | diagnostics | — |
| `signal-noise/get-machine-readers-crosstab` | Get Machine Readers Crosstab | analytics | READ |
| `signal-noise/get-machine-readers-summary` | Get Machine Readers Summary | analytics | — |
| `signal-noise/get-narration` | Get Weekly Analytics Digest | diagnostics | — |
| `signal-noise/get-post-content` | Fetch full bodies for a bounded set of posts | tools | — |
| `signal-noise/get-rights-reads` | Get Rights Reads | analytics | READ |
| `signal-noise/get-rss-stats` | Get RSS feed activity statistics | diagnostics | READ |
| `signal-noise/inbound-pass` | Inbound-link pass for new notes (stored report) | diagnostics | READ |
| `signal-noise/jev-collision-check` | Jev: does this draft re-argue a published note? | maintenance | RW |
| `signal-noise/jev-fit-now` | Jev: judge the queries Google sends to each note, now | maintenance | RW |
| `signal-noise/jev-lane-map` | Jev: map the lanes the published notes share | maintenance | RW |
| `signal-noise/jev-lanes` | Jev: the stored lane map | diagnostics | READ |
| `signal-noise/jev-meter` | Jev: this cycle's spend, by feature | diagnostics | READ |
| `signal-noise/jev-notes` | Jev over the notes: the stored pass | diagnostics | READ |
| `signal-noise/jev-pass-now` | Run the Jev pass now | maintenance | RW |
| `signal-noise/jev-query-fit` | Jev: the queries each note is seen for and does not answer | diagnostics | READ |
| `signal-noise/jev-tags` | Jev: the stored tag-fit pass | diagnostics | READ |
| `signal-noise/jev-tags-now` | Jev: read every note against its tags, now | maintenance | RW |
| `signal-noise/jev-tells` | Jev: the stored anti-tell pass | diagnostics | READ |
| `signal-noise/jev-tells-check` | Jev: the anti-tell pass on one note, now | maintenance | RW |
| `signal-noise/jev-tells-pass` | Jev: the anti-tell pass over every published note | maintenance | RW |
| `signal-noise/keyring-status` | Keyring Status | diagnostics | READ |
| `signal-noise/keyword-candidates` | Rank a post's own terms as keyword candidates (TF-IDF) | tools | READ |
| `signal-noise/link-candidates` | Suggest related notes the post does not link to yet | tools | — |
| `signal-noise/list-cron-events` | List Cron Events | diagnostics | READ |
| `signal-noise/list-posts` | List corpus metadata for every post | tools | — |
| `signal-noise/list-template-overrides` | List database template overrides | diagnostics | — |
| `signal-noise/login-defense-ipv6-criterion` | Login defense: IPv6 criterion | analytics | READ |
| `signal-noise/merge-tags` | Merge duplicate post tags | content | — |
| `signal-noise/near-duplicate-scan` | Scan the corpus for near-duplicate (cousin) post pairs | tools | — |
| `signal-noise/note-dossier` | Note dossier | content | — |
| `signal-noise/pattern-adoption-apply` | Apply a v9.2.0 pattern upgrade to a post | ai-generation | — |
| `signal-noise/pattern-adoption-scan` | Scan posts for v9.2.0 pattern-adoption opportunities | tools | — |
| `signal-noise/pattern-adoption-suggest` | Suggest a v9.2.0 pattern upgrade for a structural block | ai-generation | — |
| `signal-noise/posts-signals` | Per-note signals (the Posts tab as data) | diagnostics | READ |
| `signal-noise/prepop-dismiss` | Dismiss the AI-prepopulation notice for a post | tools | — |
| `signal-noise/provenance-integrity-status` | Provenance integrity sweep status | diagnostics | READ |
| `signal-noise/prune-unused-tags` | Delete unused (zero-post) tags | content | RW |
| `signal-noise/purge-all-caches` | Purge all caches | maintenance | RW |
| `signal-noise/purge-verification-log` | Purge Verification Log | diagnostics | READ |
| `signal-noise/reader-anomalies` | Machine-reader volume and shape deviations | diagnostics | READ |
| `signal-noise/regenerate-og-card` | Regenerate Open Graph card image | content | — |
| `signal-noise/rights-evidence` | Rights evidence: the monthly records | diagnostics | READ |
| `signal-noise/rights-evidence-now` | Rights evidence: compose and post the last month now | maintenance | RW |
| `signal-noise/run-audit-prune` | Run audit log prune now | maintenance | — |
| `signal-noise/run-cron-event` | Run a scheduled cron event now | maintenance | — |
| `signal-noise/run-health-scan` | Run a health scan now | maintenance | — |
| `signal-noise/run-insights-scan` | Run Insights Synthesis Scan | diagnostics | — |
| `signal-noise/run-narration` | Generate Weekly Analytics Digest | diagnostics | — |
| `signal-noise/schedule-cron-event` | Schedule a cron event to run soon | maintenance | — |
| `signal-noise/search-coverage` | Search Console: index coverage per post (stored) | diagnostics | READ |
| `signal-noise/search-crossexam` | Search Console x crawler ledger: do the instruments agree? | diagnostics | READ |
| `signal-noise/search-drift` | Search Console: position drift | diagnostics | READ |
| `signal-noise/search-performance` | Search Console: the stored window | diagnostics | READ |
| `signal-noise/shape-stability` | Payload Shape Stability | diagnostics | READ |
| `signal-noise/sn-apply` | Apply a change to a post (consolidated write tool) | tools | RW |
| `signal-noise/sn-metrics` | Batch-read readership metrics (consolidated) | analytics | READ |
| `signal-noise/sn-posts` | List or fetch corpus posts (consolidated) | tools | READ |
| `signal-noise/sn-scan` | Scan the corpus for actionable candidates (consolidated) | tools | READ |
| `signal-noise/sn-site-facts` | Batch-read site facts (consolidated) | diagnostics | READ |
| `signal-noise/sn-status` | Batch-read operational status (consolidated) | diagnostics | READ |
| `signal-noise/sn-validate` | Validate proposed content before writing (consolidated, deterministic) | tools | READ |
| `signal-noise/topic-clusters` | Read the corpus topic partition | tools | READ |
| `signal-noise/unschedule-cron-event` | Unschedule cron event | maintenance | RW |
| `signal-noise/update-post-surfaces` | Write reviewed excerpt / meta description / OG card title to a post | tools | — |
| `signal-noise/uptime-status` | Get Better Stack uptime status | diagnostics | READ |
| `signal-noise/watches` | Watches Due | diagnostics | READ |
| `signal-noise/zenodo-status` | Zenodo DOI status | diagnostics | READ |
| **REMOTE TWINS** (plugin, reached only through the sn-remote-mcp Worker) | | | |
| `signal-noise/remote-cron-health-summary` | Cron health, summarized (remote) | diagnostics | REMOTE |
| `signal-noise/remote-edge-errors-summary` | Edge 5xx summary (remote) | diagnostics | REMOTE |
| `signal-noise/remote-get-analytics-events` | Get custom events (remote) | analytics | REMOTE |
| `signal-noise/remote-get-analytics-summary` | Get analytics summary (remote) | analytics | REMOTE |
| `signal-noise/remote-get-deploy-status` | Get theme + plugin deploy status (remote) | diagnostics | REMOTE |
| `signal-noise/remote-get-health-scan` | Get content-health scan summary (remote) | diagnostics | REMOTE |
| `signal-noise/remote-get-insights` | Get content insights (remote) | diagnostics | REMOTE |
| `signal-noise/remote-get-narration` | Get analytics narration (remote) | diagnostics | REMOTE |
| `signal-noise/remote-get-rss-stats` | Get RSS feed activity statistics (remote) | diagnostics | REMOTE |
| `signal-noise/remote-machine-readers-summary` | Get machine readers summary (remote) | analytics | REMOTE |
| `signal-noise/remote-provenance-integrity-status` | Get provenance integrity status (remote) | diagnostics | REMOTE |
| `signal-noise/remote-search-crossexam` | Search Console x crawler ledger: do the instruments agree? (remote) | diagnostics | REMOTE |
| `signal-noise/remote-search-drift` | Search Console: position drift (remote) | diagnostics | REMOTE |
| `signal-noise/remote-search-performance` | Search Console: the stored window (remote) | diagnostics | REMOTE |
| `signal-noise/remote-uptime-status` | Get Better Stack uptime status (remote) | diagnostics | REMOTE |
| **THEME** (read by agents through `sn-site-facts`, never doored directly) | | | |
| `signal-and-noise/ai-generate-page-note-summary` | Generate /notes-voice summary | ai-generation | — |
| `signal-and-noise/ai-generate-pattern-content` | Generate pattern content | ai-generation | — |
| `signal-and-noise/ai-rewrite-in-brand-voice` | Rewrite in brand voice | ai-generation | — |
| `signal-and-noise/ai-suggest-block-pattern` | Suggest block pattern for draft | ai-generation | — |
| `signal-and-noise/ai-validate-brand-alignment` | Validate brand alignment | ai-generation | — |
| `signal-and-noise/get-active-template-structure` | Inspect active template structure | diagnostics | — |
| `signal-and-noise/get-design-system-summary` | Get design-system summary (AI-prompt formatted) | diagnostics | — |
| `signal-and-noise/get-design-tokens` | Get design tokens | diagnostics | — |
| `signal-and-noise/get-editorial-conventions` | Editorial conventions (the house forms, as data) | content | — |
| `signal-and-noise/get-latest-theme-tag` | Get latest Signal & Noise theme release tag from GitHub | diagnostics | — |
| `signal-and-noise/get-llms-txt` | Get the llms.txt AI-crawler manifest | diagnostics | — |
| `signal-and-noise/get-page-notes-pillars` | List /notes pillar essays | content | — |
| `signal-and-noise/get-reading-time-for-slug` | Get reading time for slug | content | — |
| `signal-and-noise/get-seo-route-meta` | Get SEO meta for template-driven routes | diagnostics | — |
| `signal-and-noise/get-theme-version` | Get theme + WP version | diagnostics | — |
| `signal-and-noise/list-block-patterns` | List block patterns | content | — |

**Totals:** 113 plugin abilities + 15 remote twins + 16 theme = 144. **49** on the read door, **16** on the write door, 0 on both, **48** plugin abilities on neither. Theme abilities are on no door by design: `sn-site-facts` dispatches to them, so the read door carries zero theme slugs. Each remote twin shares its admin ability's execute callback and output schema byte for byte; remote contract version 9.

## How to use this catalog

**WP-CLI** — pass JSON input via `--input`:
```bash
wp ability run <slug>
wp ability run <slug> --input='{"post_id": 42}'
```

**REST API** — POST to `/wp-json/wp-abilities/v1/abilities/<slug>/run` with `wordpress_logged_in_*` session cookie and `X-WP-Nonce` header for write operations. The MCP doors expose subsets of these abilities via their respective allowlists.

**MCP client** — Query the `sn://abilities-catalog` resource on either door for the live registry snapshot. The read door offers 49 tools (read-only); the write door offers 16 (state-modifying, behind a kill switch, a bound application password, a rate limit and its own audit log).

## Detailed reference (selected abilities)

> Written across v9–v13 and kept for its use-case notes. Door statuses and counts below are historical; the Quick reference above is current.

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

#### `signal-noise/cache-freshness`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Is the edge serving the current render? Reads the same derive layer the Classic Admin cell and the OpenStation tile render, so all three surfaces cannot disagree — it also carries the `headline` and `phrase` those widgets show, from the shared producers.

`last` is the most recent purge verdict: `fresh`, `stale`, `pending`, or `unknown`. **Read `pending` as a known state, not a failure** — an auto purge (what a plugin or theme update fires) writes no verdict until its deferred cron verify lands about 75 seconds later, and `last_time` says when the purge happened. `unknown` means no usable report at all.

`post_save` counts **post-save probes only**. Manual purges do not write there, so those figures cannot be moved by pressing Purge, and a rising `stale` means a per-post purge genuinely failed to clear the edge.

`state: never_probed` is an absence of evidence and never a clean edge — the 2026-08-15 failure was a green readout over a 27-hour-old render.

Read-only, and triggers no probe: a reader that measured would change what it reports by being asked. Added in v13.92.0 — the summary had two renderers and no machine reader since v11.29.0, so six releases correcting it were each verified by asking a human to read a widget. Also available as the `cache` section of `sn-status`.

#### `signal-noise/watches`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

The registered watches — decisions deferred to a later date or a later **state** — and which have come due. Read this when asked what is outstanding, before planning work, or when a session resumes and needs to know what the site has been waiting on.

`ripe` lists only what has come due; **empty is the normal state, not an error.** `pending` is reported rather than left to inference, because an empty `ripe` list alone cannot be told apart from an empty registry.

`date_only` distinguishes the two kinds and the distinction carries weight: a state-tested watch ripened because something **measurable changed**, a date-only one because a **clock passed and nothing was measured**. A watch that cannot be tested — its module absent, its reader unavailable — is never reported ripe, on the standing rule that absence of evidence is not a finding.

Read-only; evaluates live state and stores nothing, so there is no cached verdict to go stale. The same registry is mailed to the owner each morning by the operations brief (`inc/morning-brief.php`), which stays silent when nothing is due. Added in v13.90.0.

#### `signal-noise/shape-stability`
**Capability:** `manage_options` | **Category:** diagnostics | **Output root:** object

Has a payload's **structure** held still long enough to be frozen — types and keys, never values. Read this before shipping a remote MCP twin, or before any change that copies a payload shape somewhere it becomes expensive to alter: a twin copies its origin `output_schema` byte-identically, so shipping one freezes that shape, and changing it later costs a contract bump plus a worker release.

**Read `ever_changed` before interpreting `since`.** `false` means `since` is when recording *began* for that subject; `true` means it is when the shape last *moved*. A recent `since` with `ever_changed: true` says the payload is still changing and waiting is not the answer. `changes[]` carries the history as `{at, at_iso, from, to}` — the fingerprints are what turn "it moved" into "this key changed type" — capped by the ledger at `SN_SHAPE_LEDGER_MAX_CHANGES`, so an unstable subject keeps its most recent changes rather than all of them.

Per subject, `state` is one of `settled` (unchanged across at least `SN_SHAPE_STABLE_READINGS` readings spanning at least `SN_SHAPE_STABLE_DAYS` days — safe to freeze), `settling` (`reason` names which threshold is short), or `unknown` (never recorded — an **absence of evidence**, never a pass). `thresholds` reports the gate so a caller can see *why* something is still settling without knowing the constants.

**Read-only, and it records nothing.** A reader that fingerprinted the payload would add a reading, so polling would drive a subject toward `settled` on its own — a diagnostic reacting to the operator, which is the defect removed from the cache readout in v13.87.2/v13.87.3. Pinned by mutation.

Subjects are recorded by their producers; `reader-anomalies` records one on the hourly machine-reader snapshot cron (v13.85.0). Added in v13.88.0 — the ledger shipped in v13.84.0 with a writer and no reader at all, `sn_shape_stability()` being called only from tests. Also available as the `shape_stability` section of `sn-status`. `changes[]` and `ever_changed` added in v13.88.1: without them `since` was ambiguous between the clock starting and the countdown restarting, which are opposite answers to "can I freeze this".

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

#### Tag Pruning (1 ability; suggest-tags retired 16.9.2)
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

## Per-door visibility

**READ door** (`/wp-json/signal-noise/v1/mcp`) — 49 tools, all advertising `readOnlyHint: true`, `manage_options` floor.

**WRITE door** (`/wp-json/signal-noise/v1/mcp-rw`) — 16 tools: `ai-link-apply`, `ai-pair-suggest`, `prune-unused-tags`, `describe-tags`, `apply-tag-description`, `unschedule-cron-event`, `purge-all-caches`, `sn-apply`, `jev-pass-now`, `jev-collision-check`, `jev-lane-map`, `jev-fit-now`, `jev-tells-check`, `jev-tells-pass`, `jev-tags-now`, `rights-evidence-now`. `sn-apply` is the one tool for every content mutation, behind fingerprint, validation, capability and idempotency gates, with `dry_run` defaulting to true.

**REMOTE** — 15 twins through the sn-remote-mcp Worker's bearer-checked bridge, off until the wp-admin toggle is on.

Both plugin doors share the same JSON-RPC plumbing, wrap rule and envelope contract. The door context is resolved per request and flows through dispatch, never global state.

## Cross-references

- **Live registry source:** [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise-tools/blob/main/inc/abilities-registration.php) (plugin) + [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise/blob/main/inc/abilities-registration.php) (theme)
- **MCP resource:** `sn://abilities-catalog` (read and read-write doors; live registry snapshot)
- **Future AI harvester:** [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) (Agents framework, step 3 = Abilities-as-tools bridge)
- **Upstream issue:** [WordPress/desktop-mode#271](https://github.com/WordPress/desktop-mode/issues/271)
