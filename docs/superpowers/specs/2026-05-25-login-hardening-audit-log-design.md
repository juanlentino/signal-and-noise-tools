# v3.8.3 design — Login hardening audit log

**Date:** 2026-05-25
**Target version:** plugin v3.8.3 (patch 3/7 in v3.8.x)
**Status:** Approved via brainstorming session; ready for writing-plans
**Author intent:** ship a focused audit log under Security → Audit log that captures the events `limit-login-attempts-reloaded` doesn't (successful logins, our login-hide.php reconnaissance 404s), with strict counter-only + hashed-IP + 90-day retention discipline.

---

## TL;DR

A new "Audit log" sub-tab under Security shows a focused timeline of login-related events on the site. We capture 6 events:

- **`login_success`** as per-event rows (timestamp + username; no IP).
- **`login_failed`**, **`wp_login_404`** (direct visits to /wp-login.php caught by login-hide.php), **`wp_admin_unauth_404`** (unauth /wp-admin visits caught by login-hide.php), **`lockout_triggered`** (from LLA), **`password_reset`** as per-day counters.

Plus a per-day **`unique_ips_count`** computed via an ephemeral transient set of hashed-IP fragments — gives the "one attacker or many" signal without ever storing raw or hashed IPs long-term.

Storage: one autoloaded `wp_options` blob (`sn_audit_log_v1`). Retention: 90 days, enforced by a daily WP-Cron prune. UI: stat-cards hero + counter timeline table + recent-logins table + small read-only LLA lockout summary. Full 4-surface dispatch (admin form, REST, Abilities, desktop-mode ⌘K) on read paths; the prune is `aiCallable: false`.

---

## Problem statement

`limit-login-attempts-reloaded` (LLA) is active on the site and already captures failed login attempts + locks out IPs. However:

1. **LLA's data model is private API.** Its `wp_options` shape (`limit_login_logged`, `limit_login_lockouts`, etc.) isn't documented as a stable surface. Building a viewer over those blobs would couple us to undocumented internals that could change in an LLA update.

2. **LLA only sees auth-stack events.** It doesn't capture the 404 reconnaissance happening through our own `login-hide.php` (v1.5.0). When an attacker hits `/wp-login.php` directly (line 144 of login-hide.php) or `/wp-admin/*` unauthenticated (line 152), the request is silently 404'd. No audit trail. That's reconnaissance signal going to `/dev/null`.

3. **LLA doesn't track successful logins.** For a single-author personal site, the most important compromise-detection signal is "did someone successfully log in as me at a time I wouldn't have?" There's no native WP audit for this.

4. **The "Audit log" sub-tab planned in the v3.8.x admin IA reorg has never been built.** Security tab currently has only "Login URL" as a single sub-tab (the sub-tab nav row is hidden when count=1, per v3.8.1 design rule). Shipping this fills the planned slot.

We want a focused, low-overhead, privacy-respecting audit log that:
- Captures the events LLA doesn't.
- Uses counter-only storage for high-volume events (failed attempts, recon 404s) to bound size.
- Uses per-event rows only for the security-critical `login_success` event (the one we genuinely want timestamped detail on).
- Never stores raw IPs long-term. Uses ephemeral transient-set hashing to get the "unique attackers per day" signal without persisting any IP data.
- Surfaces LLA's current lockout state via a stable read (just count + most-recent-timestamp from `limit_login_lockouts`) — gives one-glance visibility without coupling to LLA's internals.

---

## Approach chosen

Per brainstorming, the 6 locked decisions:

| Q | Decision |
|---|---|
| Scope | **A — Supplement + LLA summary**: own our schema for the events LLA doesn't capture; read LLA's lockout count as a stable summary. |
| Granularity | **B — Hybrid**: per-event for `login_success` (timestamp + username), per-day counters for the other 5 events. |
| Events | **The recommended 6**: login_success + login_failed + wp_login_404 + wp_admin_unauth_404 + lockout_triggered + password_reset. (Skip logout, skip user/role admin actions.) |
| Storage | **A — Single autoloaded `wp_options` blob** (`sn_audit_log_v1`), JSON-encoded, schema-versioned. |
| IP dimension | **B — Daily unique-IP count via ephemeral transient**: hashed-fragment set with 25h TTL, count rolls forward at day-flip into the long-term blob, no IPs persist. |
| UI | **A — Stat-cards hero + counter table + logins table** matching the Dashboard tab pattern at `inc/admin-tab-dashboard.php:6-25`. No chart library. |

---

## Section 1 — Architecture + file layout

Two new files matching the established split convention (impl module + admin UI module):

```
signal-and-noise-tools/
  inc/
    audit-log.php          # pure-function impls, event capture hooks, retention cron
    audit-log-admin.php    # Security → Audit log sub-tab renderer
  assets/
    audit-log.css          # additions for stat cards + counter timeline table
```

**Bootstrap:** both files loaded unconditionally from `signal-and-noise-tools.php`, gated only by `is_admin()` for the admin file. Event capture (`audit-log.php`) must run on every front-end request because `wp_login_failed` and login-hide.php's 404 paths fire on non-admin requests.

**Cross-file integration points:**
- `signal-and-noise-tools.php` — add 2 `require_once` lines for the new files
- `inc/login-hide.php` lines 144 + 152 — add 1 line each to call `snt_audit_increment_counter_impl( 'wp_login_404' )` / `'wp_admin_unauth_404'` before the existing `exit`
- `inc/admin-page.php` line 119-127 area — register `'audit-log' => array( 'label' => 'Audit log' )` as second sub-tab under 'security'
- `inc/abilities-registration.php` — register 4 new abilities (3 read + 1 write)
- `inc/desktop-mode-integration.php` — register 2 new ⌘K commands
- `inc/rest-api.php` — register 4 REST routes under `signal-noise/v1/audit/*` (or co-locate in `audit-log.php`; decision deferred to writing-plans)

---

## Section 2 — Data model

### Long-term storage (one autoloaded option)

Option key: `sn_audit_log_v1`. JSON-encoded value:

```json
{
  "schema_version": 1,
  "created_at": 1747800000,
  "counters": {
    "2026-05-25": {
      "login_failed": 7,
      "wp_login_404": 23,
      "wp_admin_unauth_404": 11,
      "lockout_triggered": 1,
      "password_reset": 0,
      "unique_ips_count": 4
    },
    "2026-05-24": {}
  },
  "login_success": [
    { "ts": 1747800000, "user": "admin" },
    { "ts": 1747756000, "user": "admin" }
  ]
}
```

Worst-case envelope: 90 day-buckets × 6 counter cells + 500 login_success rows × ~80 bytes ≈ 100 KB. Comfortably within `wp_options` performance budget.

### Ephemeral transient (NOT in the option blob)

Key: `sn_audit_today_ips`
TTL: 25 hours (overlap day boundary safely)
Value: `array<string,int>` where keys are hashed-IP fragments and values are always `1`.

Hash: `substr( hash( 'sha256', $ip . wp_salt( 'auth' ) ), 0, 16 )`. `wp_salt('auth')` is the canonical WP auth-rotation key; using it means hash-collision search is bounded to a 16-char namespace per salt epoch. We don't need cross-epoch retention since the transient lives only 25h.

On each counter-event capture path that has an IP context (all except `password_reset` which is post-auth):
1. Compute the hash fragment.
2. Read the transient set.
3. If the hash isn't in the set: increment `counters[today][unique_ips_count]` AND add the hash to the set.

### Schema versioning

`schema_version` field future-proofs the blob for v2 migrations (e.g., adding a new counter type). v1 → v2 migrator would live in `inc/content-migrations.php` (existing migration site).

---

## Section 3 — Event capture hooks + pure-function impls

### Hooks

```php
add_action( 'wp_login',             'snt_audit_capture_login_success_cb', 10, 2 );  // user_login, user
add_action( 'wp_login_failed',      'snt_audit_capture_login_failed_cb',  10, 1 );  // username
add_action( 'after_password_reset', 'snt_audit_capture_password_reset_cb', 10, 1 ); // user
// NOTE: lockout_triggered uses a polling fallback (per edge case E2) because LLA
// fires no action hook on lockout. Verified 2026-05-25 — only LLA do_action calls
// are `llar_plugin_version_updated` + `llar_mfa_generate_codes`. The daily prune
// tick reads `limit_login_lockouts` array size delta vs. last-tick value.
```

### Capture from login-hide.php

Add 1 line each to the two existing 404 paths:
- `login-hide.php:144` area (direct `/wp-login.php` 404): call `snt_audit_increment_counter_impl( 'wp_login_404' )` before `exit`.
- `login-hide.php:152` area (unauth `/wp-admin` 404): call `snt_audit_increment_counter_impl( 'wp_admin_unauth_404' )` before `exit`.

These calls also pass `$_SERVER['REMOTE_ADDR']` to the impl so the unique-IP transient set can be updated.

### Pure-function impls

```php
snt_audit_increment_counter_impl( string $event_type, ?string $ip = null ): void
snt_audit_record_login_success_impl( int $user_id, string $username ): void
snt_audit_get_counters_impl( int $days = 30 ): array
snt_audit_get_login_successes_impl( int $days = 30 ): array
snt_audit_get_summary_impl(): array
snt_audit_prune_impl(): array
snt_audit_read_lla_summary_impl(): array
```

The `summary` impl returns the 4-card hero data:
- `last_24h`: total failed + recon counters in the last 24 hours
- `last_7d_vs_prior`: trend string ("+34%" or "−12%")
- `unique_attackers_24h`: today's `unique_ips_count` (clamped to 24h via transient)
- `lla`: `{ active_lockouts: int, most_recent_lockout_ts: int|null }` (from `snt_audit_read_lla_summary_impl()`)

The `read_lla_summary` impl is a defensive read: `get_option( 'limit_login_lockouts', array() )` → return `count()` + max of timestamp values if they're numeric. Returns safe defaults if the option is missing or shaped unexpectedly. Schema drift in LLA can't crash us.

### Hashing helper

```php
snt_audit_hash_ip( string $ip ): string
```

Returns 16-char hex hash. Centralizes the salt + truncation choice.

---

## Section 4 — 4-surface dispatch + UI

### Surface 1 — wp-admin form (`audit-log-admin.php`)

Registered as the second sub-tab under `security`. Renders:

1. **Hero row** — 4 stat cards (`.sn-state-grid` pattern from `admin-tab-dashboard.php`):
   - "Last 24h" — total of failed + recon counters
   - "Last 7d trend" — vs. prior 7d, signed percent
   - "Unique attackers (24h)" — today's `unique_ips_count`
   - "LLA status" — `N IPs locked` (from `read_lla_summary_impl`)
2. **Counter timeline table** — `.widefat` table, rows = last 30 days, columns = the 5 counter event types + unique_ips_count. "Show 90 days" toggle.
3. **Successful logins table** — `.widefat` table, rows = timestamp + username, last 30 days. "Show 90 days" toggle.
4. **LLA summary card** — full read of `read_lla_summary_impl` output, with a small "Manage in LLA →" deep-link.
5. **Maintenance** — one button "Prune now" (POST → `snt_audit_prune_impl()`). Useful for testing retention.

### Surface 2 — REST routes

All require `manage_options` capability.

```
GET  /signal-noise/v1/audit/summary          → snt_audit_get_summary_impl()
GET  /signal-noise/v1/audit/counters?days=30 → snt_audit_get_counters_impl( $days )
GET  /signal-noise/v1/audit/login-successes?days=30 → snt_audit_get_login_successes_impl( $days )
POST /signal-noise/v1/audit/prune            → snt_audit_prune_impl()
```

### Surface 3 — Abilities API

Register in `inc/abilities-registration.php`:

| Ability | Category | aiCallable | Wraps |
|---|---|---|---|
| `signal-noise/get-audit-summary` | diagnostics | ✅ true | `get_summary_impl` |
| `signal-noise/get-audit-counters` | diagnostics | ✅ true | `get_counters_impl` |
| `signal-noise/get-audit-login-successes` | diagnostics | ✅ true | `get_login_successes_impl` |
| `signal-noise/run-audit-prune` | maintenance | ❌ false | `prune_impl` |

Read abilities are AI-callable (low risk; user can ask "what's my audit summary today?"). Prune is manual only — it's destructive of historical data.

### Surface 4 — desktop-mode ⌘K commands

Register in `inc/desktop-mode-integration.php`:

| Command | aiCallable | Description |
|---|---|---|
| `sn-cmd-audit-summary` | ✅ true | Returns hero-card summary as natural-language string |
| `sn-cmd-audit-recent-logins` | ✅ true | Returns last 10 login_success entries |

---

## Section 5 — Retention + edge cases

### Retention cron

Hook: `sn_audit_log_prune`. Scheduled at plugin activation (idempotent — check `wp_next_scheduled` before scheduling). Daily.

Tick body (`snt_audit_prune_impl`):
1. Read the blob.
2. Drop counter keys where date < `today - 90 days`.
3. Drop login_success rows where `ts < now - 90 days * DAY_IN_SECONDS`.
4. `update_option` the compacted blob.
5. Return prune stats (`{ counter_buckets_dropped: int, login_rows_dropped: int }`) for logging / UI.

The prune is also exposed via the "Prune now" admin button and the `run-audit-prune` ability (both call `prune_impl`).

### Edge cases

**E1. First-event before activation hook fires.** `snt_audit_increment_counter_impl` lazy-initializes the option blob: if `get_option('sn_audit_log_v1')` returns false/empty, create the default `{schema_version:1, created_at:time(), counters:{}, login_success:[]}` shape and proceed. Same for `record_login_success_impl`.

**E2. LLA has no lockout action hook — polling fallback is the canonical path (not a fallback).** Verified 2026-05-25 against the live LLA source on production: only `do_action` calls in LLA's core are `llar_plugin_version_updated` (self-upgrade) and `llar_mfa_generate_codes` (MFA codes). Zero hooks on the security path. So `lockout_triggered` counter capture works via polling:
- Store the last seen `count( get_option( 'limit_login_lockouts', array() ) )` in a separate option `sn_audit_lla_last_lockout_count`.
- On each daily prune tick, read current count. If current > last, add the delta to *today's* `lockout_triggered` counter. Store new count.
- Imprecise (only captures net-positive changes between tick boundaries; misses a "lockout + expire" round-trip within a day) but the only available approximation. Acceptable: the counter is for trend detection, not precision counting.
- If a future LLA version adds a proper action hook, swap the polling for a direct `add_action` — single-file change in `inc/audit-log.php`. Schema unaffected.

**E3. Object cache eviction of `sn_audit_today_ips` transient.** Worst case: today's `unique_ips_count` is undercounted. Counter totals (independent of the transient) are unaffected. Acceptable degradation — we run object-cache-pro and eviction under load is rare.

**E4. Brute force flooding the option blob.** Counters are O(1) increments. The real risk is `wp_options` write contention under sustained ~100/sec failed attempts. LLA will throttle the attacking IP after N attempts (default 4), naturally bounding the write rate to a few writes per IP per minute. With LLA active, this is a non-issue. Without LLA, we'd want rate limiting; out of scope for this version.

**E5. Schema migration to v2.** `schema_version` field in the blob lets a future v2 migration upgrade the shape. v1 → v2 migrator would live in `inc/content-migrations.php` (existing site). v1 design covers the seed; no v2 is anticipated for the initial ship.

**E6. Multi-byte usernames.** `login_success` rows store `$user_login` (the WP-canonical username). WP supports UTF-8 usernames; we don't transform. UI renders via `esc_html()`.

**E7. Deleted users in login_success history.** A login_success row may reference a username that no longer exists. UI renders the username as-stored; doesn't attempt to look up the current user record. Documenting this so future readers don't add an "enrich on render" pass that would crash on missing users.

---

## Section 6 — Ship sequencing

**Version target: plugin v3.8.3** (patch 3/7 in v3.8.x). Pure feature addition, no breaking changes.

**Wave plan** (refined in writing-plans):

| Wave | Scope | Files touched |
|---|---|---|
| 1 | Data model + capture impls + hooks + retention cron | new `inc/audit-log.php`; edit `signal-and-noise-tools.php` (require_once); edit `inc/login-hide.php` (2 lines) |
| 2 | Admin sub-tab UI + CSS | new `inc/audit-log-admin.php`; edit `inc/admin-page.php` (register sub-tab); new `assets/audit-log.css` |
| 3 | REST routes + Abilities + ⌘K commands | edit `inc/rest-api.php` OR co-locate routes in audit-log.php; edit `inc/abilities-registration.php`; edit `inc/desktop-mode-integration.php` |
| 4 | Verification — 4-surface smoke + production check | none (manual verification) |
| 5 | CHANGELOG + version bump (docblock) + commit + tag v3.8.3 + push | `CHANGELOG.md`; `signal-and-noise-tools.php` (docblock Version: 3.8.3) |

Estimated LOC: ~250-300 (modestly above the original ~150-200 estimate because of LLA summary integration and the 4-surface dispatch).

**Does NOT trigger v4.0.0 minor-cap rollover** — patch within v3.8.x.

---

## Verification gates (for writing-plans to expand into a checklist)

- **G1.** `sn_audit_log_v1` option exists after plugin activation + first event (lazy-init works).
- **G2.** `wp_login_failed` action fires the counter (test by submitting bad creds; option blob's today bucket should reflect +1 `login_failed`).
- **G3.** `wp_login` action records a per-event row (test by logging in; `login_success` array gets a new entry).
- **G4.** Direct `/wp-login.php` visit increments `wp_login_404` (test by curl-ing /wp-login.php; today bucket reflects +1).
- **G5.** Unauth `/wp-admin/index.php` visit increments `wp_admin_unauth_404` (test by curl-ing /wp-admin/index.php while logged out).
- **G6.** `unique_ips_count` increments on first event from a new IP, doesn't double-count on subsequent events from same IP within the day.
- **G7.** REST `GET /audit/summary` returns the hero-card structure with the expected keys.
- **G8.** Each of the 3 read abilities returns valid JSON via `/wp-abilities/v1/abilities/<name>/run`.
- **G9.** `aiCallable: true` correctly opts the 3 read abilities into desktop-mode AI Copilot (manual test in portal).
- **G10.** `aiCallable: false` correctly excludes `run-audit-prune` from AI (verify in portal).
- **G11.** ⌘K commands return summary strings in the desktop-mode palette.
- **G12.** Admin sub-tab "Audit log" renders the 4 hero cards + 2 tables + LLA summary without PHP notices.
- **G13.** Sub-tab nav row in Security tab is now visible (Security now has 2 sub-tabs — "Login URL" and "Audit log").
- **G14.** "Prune now" button purges counter buckets older than 90d (test by injecting a fake-old bucket).
- **G15.** Daily cron `sn_audit_log_prune` is scheduled after activation; visible in cron-dashboard.
- **G16.** `read_lla_summary_impl` handles a missing/empty `limit_login_lockouts` option gracefully (returns safe defaults).

---

## Out of scope (explicitly)

- **No IP allowlist / blocklist UI.** LLA handles blocking.
- **No alerting / notifications.** A future v3.9.x could add "email when failed-attempts > N/day"; not in this version.
- **No per-event row storage for failed attempts** (per Q2 decision — counter-only).
- **No reverse-lookup of hashed IPs.** Hashed-IP fragments live in transient only and are not persisted long-term.
- **No LLA write integration.** Only the safe read of `limit_login_lockouts` count + most-recent-ts. We never modify LLA's data.
- **No chart library / SVG visualization.** Tables only (per Q6).
- **No deprecation of LLA.** This audit log supplements LLA; the user can keep LLA as the blocking/throttling layer.
- **No new automated test framework.** Verification remains manual per the 16 gates above (matches project convention).

---

## References

**Code reference:**
- `inc/login-hide.php` — the existing custom login URL module (v1.5.0) we hook into for 404 captures
- `inc/admin-tab-dashboard.php` lines 6-25 — the Dashboard tab pattern we mirror for stat-cards hero
- `inc/cron-dashboard.php` — the canonical 4-surface dispatch pattern with pure-function impls
- `inc/abilities-registration.php` — existing 17 plugin abilities; we add 4 more
- `inc/desktop-mode-integration.php` — existing 13 commands; we add 2 more
- `inc/admin-page.php` lines 119-127 — Security tab registration (where we add the audit-log sub-tab)
- `inc/admin-page.php` lines 213, 226 — single-sub-tab nav-hidden rule (auto-flips when we add the 2nd sub-tab)

**Project conventions (CLAUDE.md):**
- Plugin patch cap: 7 per minor. v3.8.x currently at 2/7; v3.8.3 makes it 3/7.
- Versioning: v3.8.3 is a PATCH (additive feature within an existing capability surface — Security tab — not a new top-level surface).
- 4-surface dispatch pattern is the project's established way to expose features (per cron-dashboard.php § module docblock).

**Memory:**
- `feedback_skills_plugins_docs_always.md` — hard rule that drove this brainstorm (skill + read source + read docs)
- `feedback_read_framework_source.md` — verify LLA's hook surface (`llar_lockout` exists?) at implementation time
- `reference_ai_plugin_v1_features.md` — the `ai/ai` plugin doesn't ship audit-log functionality; this isn't duplicate work
- `feedback_internal_toc_vs_sub_tabs_decision.md` — the audit-log section is its own form/save context → sub-tab is correct (not TOC)

---

## What writing-plans should produce

When this spec is approved, the next step is `superpowers:writing-plans` to produce an implementation plan. That plan should:

1. **5-wave task breakdown** matching the table in Section 6.
2. **Verification gates** — the 16 gates from above (G1-G16) wired into the wave completion checks.
3. **Atomic commit boundaries** — one commit per wave (5 commits), with the v3.8.3 tag landing only after Wave 5.
4. **CHANGELOG entry** drafted for v3.8.3, calling out the 6 captured events + 90-day retention + 4-surface dispatch + Security sub-tab visibility change.
5. **LLA hook verification** — already done. Verified 2026-05-25: no lockout hook exists in LLA core. Wave 1 implements the polling fallback per E2 as the canonical path. No additional pre-wave research needed.
6. **Rollback plan** — clear path back to v3.8.2 if needed (single git revert; option blob persists harmlessly but unused).
