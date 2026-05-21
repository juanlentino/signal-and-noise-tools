# v3.6.0 Spec — Insights Tab + Content Opportunity Advisor

**Status:** Approved 2026-05-20
**Target release:** Plugin v3.6.0 (minor; new user-visible capability)
**Designed via:** `superpowers:brainstorming` (this session)

## 1. Goal

Combine SN-owned data sources (Plausible analytics + WP publish history + webhook delivery patterns + cron firings + site identity) into a single AI-synthesized advisor that surfaces concrete "what to write next" and "what to update" recommendations on a new wp-admin **Insights** tab.

This is the first cross-system AI synthesis feature in the plugin and the only AI-related work in v3.6.0. It establishes the synthesis pattern that future net-new AI features (e.g., semantic content drift in v3.7.0) can extend.

## 2. Non-goals

- **Not a generic content-generation tool.** `ai/ai` v1.0.0 already ships Title, Excerpt, Meta Description, Editorial Notes, Content Resizing, etc. We don't duplicate.
- **Not an operational anomaly detector.** That overlaps with the existing Health tab (v3.5.0) + Cron Dashboard (v3.2.0). Could be added later as a separate axis.
- **Not LLM-bot discoverability (llms.txt).** Multiple dedicated plugins already handle this.
- **Not an SEO scorer.** Yoast/RankMath/AIOSEO already do that.
- **Not voice-trained editorial review.** `ai/ai`'s Guidelines feature is the canonical path; we configure it, we don't rebuild it.

## 3. Why this is genuinely net-new

After auditing the `ai/ai` plugin v1.0.0 feature set + roadmap + the wider WordPress AI plugin ecosystem (verified 2026-05-20 via the wordpress.org plugin directory + make.wordpress.org/ai/ + the WordPress AI GitHub project board), nothing combines:

- Plausible analytics (traffic per page, top sources, 7-day window)
- WP publish history (which posts, when, last-modified)
- SN's own webhook delivery patterns (which posts triggered downstream automation, which webhooks succeeded vs failed)
- SN's own cron firing history (data freshness, scheduled job health)
- Site identity context (what kind of site this is)

This combination is only possible because Signal & Noise owns all five data sources in one plugin. That's the defensible "net-new" angle.

## 4. Architecture

### 4.1 Modules

- `inc/insights.php` (~350 LOC) — impl module: signal aggregation, AI prompt construction, JSON response parsing, recommendation state management, scan caching, weekly-cron handler
- `inc/insights-admin.php` (~200 LOC) — admin tab renderer using the `.sn-fieldset` / `.sn-field` design system (per the v3.5.1 lesson)
- `inc/admin-page.php` — Insights entry in `sn_admin_pages()` (slug `sn-insights`, tab `insights`) + dispatch case + 4 new `sn_action` branches in `sn_handle_admin_post`: `insights_run`, `insights_dismiss`, `insights_snooze`, `insights_mark_done`
- `inc/rest-api.php` — 2 new endpoints: `POST /signal-noise/v1/insights/run`, `GET /signal-noise/v1/insights/last`
- `inc/abilities-registration.php` — 2 new abilities: `signal-noise/run-insights-scan` (idempotent within cache window; `destructive: false`; `open_world_hint: false`) + `signal-noise/get-insights` (readonly, idempotent, `open_world_hint: false`)
- `inc/desktop-mode-integration.php` — 1 new ⌘K command `sn-cmd-insights` (read-only nav + summary toast, `aiCallable: true`)
- `tests/insights.php` — ~40 assertions

### 4.2 4-surface dispatch (matches Cron, Webhooks, Health pattern)

All four surfaces converge on the impl module functions:

```
                  ┌───────────────────────────────┐
                  │  snt_insights_run_scan()      │
                  │  snt_insights_last_scan()     │  ← pure impl
                  │  snt_insights_dismiss( id )   │     functions
                  │  snt_insights_snooze( id )    │     in
                  │  snt_insights_mark_done( id ) │     inc/insights.php
                  └────▲────────▲──────────▲──────┘
                       │        │          │
              ┌────────┘        │          └────────┐
              │                 │                   │
          [admin form]    [REST]    [Abilities API] [⌘K]
```

Single shared nonce action `sn_theme_options_nonce` (v3.5.1 lesson encoded).

### 4.3 Tab placement

Between Webhooks (9th) and Health (10th). Order in `sn_admin_pages()`:
1. Dashboard
2. Identity
3. Login
4. Cloudflare
5. Plausible
6. RSS
7. Reading Time
8. Cron
9. Webhooks
10. **Insights** ← new
11. Health
12. Links

Final count: 12 admin tabs. The SSOT from v3.0.2 makes adding this a one-line change in `sn_admin_pages()`.

## 5. Data flow

### 5.1 Signal aggregation (per scan)

`snt_insights_collect_signals()` returns a structured dict:

```php
[
  'site' => [
    'name'        => sn_setting('identity.site_name'),
    'description' => sn_setting('identity.site_description'),
    'person'      => sn_setting('identity.person_name'),
    'job_title'   => sn_setting('identity.job_title'),
    'home_url'    => home_url('/'),
  ],
  'plausible' => sn_plausible_dashboard_data(),  // aggregate + top_pages + top_sources (7-day window)
  'posts'     => [  // capped at 100 most-trafficked posts under 2yo
    [
      'id'            => 123,
      'title'         => '...',
      'slug'          => '...',
      'url'           => get_permalink(123),
      'published'     => '2025-08-14',
      'modified'      => '2025-08-14',
      'days_since_publish'  => 280,
      'days_since_modified' => 280,
      'type'          => 'post',
      'tags'          => ['modular synths', 'tutorial'],
      'views_7d'      => 142,  // from Plausible top_pages match
    ],
    ...
  ],
  'webhooks' => [
    'total_active' => 3,
    'recent_deliveries_summary' => [
      'wh_abc' => ['name' => 'n8n', 'success_rate' => 0.95, 'last_attempt_ago_days' => 1],
      ...
    ],
  ],
  'cron_freshness' => [
    'sn_plausible_refresh_dashboard' => [
      'last_fired_ago_minutes' => 4,
      'last_24h_count'         => 288,
    ],
    'sn_rss_tracker_daily_prune'     => [
      'last_fired_ago_minutes' => 720,
      'last_24h_count'         => 1,
    ],
  ],
  'collected_at' => time(),
]
```

Posts capped at top 100 by views_7d to bound the prompt. Posts older than 2 years excluded (rarely actionable). Token budget for this dict: ~1500 tokens of compact JSON.

### 5.2 AI prompt design

System instruction:

```
You are a content strategist analyzing a personal site's data. You will receive a
JSON blob with: site identity, 7-day Plausible analytics, post publish history with
traffic per post, webhook delivery patterns, and cron freshness signals.

Return ONLY a JSON array of exactly 5 recommendations. Each must be an object:

{
  "id": "rec_<short-stable-slug>",  // deterministic from type+title
  "type": "write_about" | "update_post" | "cadence_change" | "topic_double_down" | "topic_pivot",
  "title": "<concise headline; max 80 chars>",
  "rationale": "<2-3 sentence explanation citing specific numbers from the data>",
  "evidence_pills": ["<short fact 1>", "<short fact 2>", ...],
  "target": null OR {"post_id": int, "url": string}  // when applicable
}

Rules:
- Cite specific numbers (view counts, days, percentages). No vague claims.
- Prioritize recommendations that the site owner can act on this week.
- Mix recommendation types — don't return 5 of the same type.
- No marketing fluff, no exclamation marks.
- Output JSON only, no preamble, no markdown.
```

Generated via `snt_ai_generate_with_constraints( $signals_json, $system_instruction, max_tokens: 1500 )`.

### 5.3 Response parsing + validation

`snt_insights_parse_response( $raw_text )`:
1. Strip any markdown code fences (defensive — model sometimes wraps in `\`\`\`json`)
2. `json_decode` → array of 5 recommendation objects
3. Validate each: required keys present, `type` in allowed enum, `title` length ≤ 80, `evidence_pills` is array, `target.post_id` (if present) resolves to a real published post
4. Drop invalid entries; if fewer than 3 valid remain, mark the scan as failed (don't cache empty results)
5. Returns `array<recommendation>` or `WP_Error`

### 5.4 Cache + state

| Storage | Key | TTL | Purpose |
|---|---|---|---|
| Transient | `sn_insights_last_scan` | 7 days | Latest scan result (full payload + parsed recs) |
| Option | `sn_insights_state` | autoload=false | Per-recommendation user state: `dismissed_ids[]`, `snoozed_until[id => ts]`, `done_ids[]` |
| Option | `sn_settings.insights.weekly_cron_enabled` | autoload=true | Opt-in toggle |

State persists across scans so dismissed/done recommendations don't reappear if regenerated.

### 5.5 Trigger pattern

- **Default: manual.** "Run Analysis" button in the tab → fires `snt_insights_run_scan()` → AI call → cache result → redirect with success flash.
- **Opt-in weekly cron** (`sn_insights_weekly_scan` hook): toggle in tab Settings section, default OFF. When enabled: `wp_schedule_event( first-firing-next-sunday-3am, 'weekly', 'sn_insights_weekly_scan' )`. Hook handler is `snt_insights_run_scan()` (same impl as manual).
- Cache (`sn_insights_last_scan` transient) prevents duplicate AI calls within 7 days — even manual re-clicks within the window return cached unless `?force=1` query param is passed.

## 6. UI

Built with `.sn-fieldset` design system (v3.5.1 lesson encoded):

### 6.1 Status box (top)

```
┌────────────────────────────────────────────────────────────┐
│  Last scan 4 hours ago  ·  5 active / 2 dismissed / 1 done │  [Active] pill
└────────────────────────────────────────────────────────────┘
```

If no scan yet: warn-style box with "No analysis run yet" + Run button promoted.

### 6.2 Run section

`.sn-fieldset` with H2 "Run Analysis", helper text explaining cost (~$0.01 per scan, 7-day cache), and a primary button. If a cached scan exists, button text is "Re-run analysis" + a checkbox "Force fresh scan (ignore cache)".

### 6.3 Recommendation cards

Each recommendation rendered as a `.sn-card` (or `.sn-fieldset` block):

```
┌─ Title from the recommendation ────────── [type pill] ──┐
│                                                         │
│  Rationale paragraph citing specific numbers.           │
│                                                         │
│  [evidence pill 1] [evidence pill 2] [evidence pill 3]  │
│                                                         │
│  [Open target post →]   [Dismiss]  [Snooze 30d]  [Done]│
└─────────────────────────────────────────────────────────┘
```

- **Open target post**: only shown when `target.post_id` is present; deep-links to the editor
- **Dismiss**: hides forever (id added to `dismissed_ids`)
- **Snooze 30d**: hides until ts + 30 days (id added to `snoozed_until` map)
- **Done**: marks as completed (id added to `done_ids`; still visible but greyed out)

Each button is a form post with `sn_action` matching the operation; same shared nonce pattern.

### 6.4 Settings section (bottom)

`.sn-fieldset` with H2 "Settings":
- Checkbox: "Run a weekly scan automatically" (toggles `sn_settings.insights.weekly_cron_enabled`)
- Helper: "Defaults off. When enabled, fires every Sunday at 3am site time. You can still click Run Analysis any time."

## 7. Cost model

| Component | Tokens | Per-scan cost (Sonnet) |
|---|---|---|
| Input — signal aggregation JSON | ~1500 | ~$0.005 |
| Input — system instruction | ~400 | ~$0.001 |
| Output — 5 recommendations | ~1000 | ~$0.015 |
| **Per scan total** | ~2900 | **~$0.02** |

Annual ceiling at weekly cron + occasional manual: **~$1–$2/year**. Well under the "don't make my Anthropic account go wild" bar.

Defensive guard: if `snt_ai_is_available()` returns false (no provider configured), the Run button is disabled with a tooltip pointing to Settings → Connectors. No silent API failures.

## 8. Test plan

`tests/insights.php` (standalone, no PHPUnit — matches existing test pattern):

1. `snt_insights_collect_signals()` — given seeded options + posts, returns the expected dict shape
2. Post cap — given 200 posts, returns top 100 by views_7d
3. Post age cap — given a 3yo post, it's excluded
4. Webhook summary — success_rate computed correctly from log entries
5. Cron freshness — `last_fired_ago_minutes` computed correctly
6. `snt_insights_parse_response()` — happy path with valid JSON returns 5 recs
7. Parse — markdown code fence stripping works
8. Parse — drops invalid entries (missing keys, bad type enum)
9. Parse — returns WP_Error when fewer than 3 valid recs
10. Parse — validates `target.post_id` references real posts
11. State — dismiss adds to `dismissed_ids`
12. State — snooze adds to `snoozed_until` with correct timestamp
13. State — dismissed recommendations are filtered from active list
14. State — snoozed recommendations rejoin active list after expiry
15. State — done recommendations stay visible but flagged
16. Cache — `force=true` bypasses transient
17. Weekly cron — schedule only fires when setting is true

Target: ~40 assertions across these tests. Mock `snt_ai_generate_with_constraints` with deterministic fixture responses.

## 9. Risk register

| Risk | Mitigation |
|---|---|
| AI returns malformed JSON | `snt_insights_parse_response` defensive parse + validation; mark scan failed if < 3 valid recs |
| AI returns generic/useless recommendations | System instruction explicitly forbids vague claims; we ship with one tuning iteration after first prod scan |
| Plausible API down at scan time | `sn_plausible_dashboard_data()` returns cached/empty; we still call AI with what we have + flag in the prompt |
| User has no posts yet | Skip scan + show empty-state card "Publish a few posts first; come back when you have ~10 published posts and some Plausible data" |
| Weekly cron drift / missed firings | The Cron Dashboard from v3.2.0 makes this observable; user can see if it fired |
| AI provider not configured | Run button disabled with tooltip; `snt_ai_is_available()` gate |
| Per-recommendation state grows unbounded | Cap each state array at 200 entries (FIFO) |

## 10. Open questions

None blocking implementation. Possible future refinements (NOT v1):
- Allow user to "regenerate this one card" (per-rec re-roll) — needs a second smaller AI call path
- A/B variant pickers per card (3 variants, user picks) — adds 3x token cost
- Email summary on weekly cron firing — nice but adds SMTP dep
- Cross-link Insights findings into the Health tab as a special check type

## 11. Versioning

- Patch cap is 7 per minor (per project override, [feedback_versioning_patch_cap](../../../../../.claude/projects/-Users-juanlentino-Projects-signal-and-noise/memory/feedback_versioning_patch_cap.md))
- Current: plugin v3.5.1
- This release: **v3.6.0** (new user-visible capability → MINOR)

## 12. Implementation notes for the planner

- `inc/insights.php` is the impl boundary. Admin tab + REST + ability + ⌘K are all thin wrappers.
- Use `snt_ai_generate_with_constraints` from `inc/ai-bootstrap.php` — don't roll a new AI call path.
- Match the `.sn-fieldset` / `.sn-field` design system exactly (cloudflare-purge.php is the reference renderer).
- Form actions use `sn_theme_options_nonce` and route through `sn_handle_admin_post` (the v3.5.1 dispatcher pattern, NOT standalone admin_init handlers).
- Tests must run via `php tests/insights.php` standalone (matches the existing pattern).
- Add `Insights` entry to `sn_admin_pages()` at position 10 (between Webhooks and Health).
- Add a dispatch elseif case in `sn_theme_options_page()` for `'insights' === $active_tab`.
- All UI strings via `__()` / `esc_html__()` with the `signal-noise-tools` text-domain (v3.0.2 lesson).
