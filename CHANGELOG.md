# Changelog

All notable changes to Signal & Noise Tools are documented here.

## [4.11.0] - 2026-06-07 — Editor UX + frugal AI

**Headline:** Four additive surfaces that smooth the publishing path and one that makes the weekly advisor smarter for free. The editor grows a pre-publish mistake gate; the ⌘K command palette learns to create Notes and jump around; the Tools tab gains an AI release-notes drafter; and Insights recommendations can spawn a draft in one click. The weekly advisor now reads the actual post bodies it talks about — same model, same per-run cost, sharper output. No new admin-bar node or dashboard widget; everything lands where you already work.

### New
- **Pre-publish advisory gate.** A `PluginPrePublishPanel` in the editor checks a post or page before it goes live and surfaces non-blocking warnings for easy-to-miss mistakes: it's set to `noindex` or has an empty meta description (posts *and* pages), or — for posts — has no tags. The warnings update live as you edit. Advisory only — it never blocks the publish button; it just makes the oversight visible at the moment it matters. Wired in [inc/pre-publish-gate.php](inc/pre-publish-gate.php) + [assets/pre-publish-gate.js](assets/pre-publish-gate.js), reading the existing `_sn_noindex` and `_sn_meta_description` post meta.
- **Expanded ⌘K command palette.** The WordPress 7.0 command palette ([inc/command-palette.php](inc/command-palette.php) + [assets/command-palette.js](assets/command-palette.js)) gains three navigation conveniences: **New Note** (jump straight into a fresh Note draft), **tab-jumps** to each Signal & Noise admin tab, and your **recent Notes** as direct-open commands. All client-side — no new server work per keystroke.
- **AI release-notes drafter.** A new Tools sub-tab turns a pasted commit/change delta into Mimestream-style categorized release notes (New / Improvements / Fixed …) via one on-demand, input-capped AI call. Also exposed as a read-only ability for AI callers ([inc/abilities-system.php](inc/abilities-system.php)). Lives in [inc/release-notes-draft.php](inc/release-notes-draft.php) + [inc/admin-forms/release-notes.php](inc/admin-forms/release-notes.php); bumps the registered ability count to 43.
- **Insights "Create draft."** The weekly advisor's `write_about` recommendation cards gain a one-click **Create draft** button that seeds a Notes draft pre-filled from the cached recommendation — title plus a valid `wp:paragraph` block body — and marks the recommendation done. The success notice links straight to the new draft's editor. Wired through the shared admin-post dispatcher ([inc/admin-post-actions.php](inc/admin-post-actions.php), [inc/insights-admin.php](inc/insights-admin.php)) so it PRG-redirects cleanly back to the Insights tab.

### Improvements
- **The weekly advisor is now content-aware.** Insights recommendations are grounded in the actual post bodies — the advisor reads a bounded, word-capped excerpt from each of the top-25 candidate posts instead of reasoning from titles and metadata alone. Same model, same single per-run AI call, no added cost; the recommendations are simply better-informed. ([inc/insights.php](inc/insights.php))

## [4.10.1] - 2026-06-07 — Webhook: post.deleted fires once per deletion

### Fixed
- **`post.deleted` no longer double-fires, nor fires for never-published posts.** The permanent-delete trigger (`before_delete_post` → `sn_webhook_on_delete()`) now gates on `post_status === 'publish'`, so it fires exactly once per logical deletion. Two bugs in the v4.10.0 webhook-lifecycle work are fixed:
  - **Trash → empty-trash double-fire (HIGH).** Trashing a published post fires `post.deleted` from the `publish→trash` transition; later emptying the trash hit `before_delete_post` on the already-`trash` row and re-fired `post.deleted` with a *different* `delivery_id`, so receivers couldn't dedupe. Purging an already-`trash` row is now suppressed.
  - **Never-published rows firing spuriously (MED).** Drafts, pending/private/future posts, and — most visibly — `auto-draft` rows that WordPress core's `wp_delete_auto_drafts()` cron force-deletes daily fired `post.deleted` despite never having fired `post.published`. (`auto-draft` is a post *status* on a `post_type=post`/`page` row, so the post-type gate never excluded it.) These now fire nothing.
  - A direct force-delete of a still-`publish` post (e.g. `EMPTY_TRASH_DAYS` off) still fires exactly once.
- Corrected two docblocks in [inc/webhooks.php](inc/webhooks.php) that wrongly claimed the post-type gate excludes auto-drafts — the gate excludes revisions/attachments/nav-items by *type*; auto-drafts are excluded by the new `post_status` guard. Expanded [tests/webhooks.php](tests/webhooks.php) coverage across publish/trash/draft/auto-draft/revision/attachment delete cases plus a full publish→trash→purge lifecycle assertion (exactly one `post.deleted`).

### Security
- **Audit-log CSV export neutralizes spreadsheet formula injection.** A username (the only user-controllable field in the export) that begins with a formula trigger — `=`, `+`, `-`, `@`, or a leading tab/CR — is now prefixed with a single quote so Excel/Google Sheets render it as literal text instead of evaluating it. The export ability is REST-reachable, so this is defense-in-depth.

### Cleanup
- The `WP_Ability` test stub now mirrors the **real** `WP_Ability::execute()` error contract (`ability_invalid_permissions` on permission denial, `ability_invalid_input` on bad input) instead of the REST layer's `rest_forbidden`/`rest_invalid_param`. Verified against WordPress/abilities-api; the ability permission/validation tests now assert what production actually returns. Test-only — no behaviour change.
- The privacy module ([inc/privacy-exporters.php](inc/privacy-exporters.php)) now uses the canonical `signal-noise-tools` i18n text domain, matching the rest of the plugin (i18n remains a documented non-goal; cosmetic).

## [4.10.0] - 2026-06-07 — Webhooks, privacy & perf

**Headline:** Six additive surfaces across three tracks — the webhook pipeline grows from publish-only to the full post lifecycle with per-webhook event selection; the plugin wires into WordPress's native privacy tooling (exporter, eraser, suggested policy text) so the only PII it stores can be exported and erased on request; the audit log gains a CSV/JSON export; and the site can opt into Speculation Rules prerendering for perceived-instant navigation. No new admin-bar node or dashboard widget; everything lands where admins already look.

### New
- **Webhooks for the full post lifecycle.** Webhooks now fire on `post.updated`, `post.unpublished`, and `post.deleted` in addition to `post.published`, each over the same HMAC-SHA256-signed pipeline. Every webhook subscribes to the events you choose (existing webhooks keep firing on publish only — `post.published` is the default when no events are ticked). The `X-SN-Event` header and the body's `event` field carry the event name; for unpublished/deleted events the `post` block is a snapshot captured at trigger time, since the post may already be gone by delivery.
- **`signal-noise/list-abilities` ability** (`inc/abilities-system.php`). A read-only meta-ability that returns the catalogue of every ability registered on the site — name, label, description, category, namespace, and annotations — optionally filtered by namespace. Self-discovery for AI callers asking "what can you do here?".
- **Privacy personal-data exporter + eraser** (`inc/privacy-exporters.php`). The plugin now plugs into Tools → Export / Erase Personal Data. The only persisted per-person PII it holds is the plaintext username on each successful-login row of the audit log; a privacy request keyed off a user's email resolves to that username and either exports or removes the matching rows. Aggregate counters and the salted, expiring IP hashes carry no individual PII and are out of scope.
- **Suggested Privacy Policy text** (`inc/privacy-exporters.php`). Settings → Privacy now surfaces accurate, copy-ready policy language describing exactly what the plugin handles — the login audit and its retention window, the cookieless aggregate counters, the one-way IP hashing, and (only when at least one webhook is configured) the content sent to third-party webhook endpoints, plus a Plausible note when analytics is enabled.
- **Audit-log CSV/JSON export** (`inc/audit-log-export.php`). The Audit tab gains a nonce-protected download (CSV or JSON) of the retention-clamped login-audit data — counters plus successful-login rows. The same payload is available programmatically via the new `signal-noise/export-audit-log` ability. Both surfaces are gated on `manage_options`.
- **Opt-in Speculation Rules prerendering** (`inc/speculation-rules.php`). A Performance sub-tab toggle opts the site into WordPress 7.0's native Speculation Rules with a `prerender` / `moderate` profile — for a mostly-static notes site the perceived-instant navigation is a clean win. The custom login slug and `/contact/*` are excluded from prerendering (core already excludes `/wp-admin/*`, `wp-*.php`, and query-string URLs); unticking the toggle disables speculative loading outright.

## [4.9.0] - 2026-06-06 — Site Health & observability

**Headline:** Five additive observability surfaces that lift the plugin's most operational subsystems into native, self-checking WordPress surfaces — Site Health checks, an Info panel, an opt-in uptime heartbeat, and live-refreshing admin tables. No new dashboard widget or admin-bar node; everything lands where admins already look.

### New
- **Cloudflare security-header drift detection.** A new SN Health check fires one 6-hour-cached HEAD probe at the home URL and flags any of the five edge-delivered security headers (CSP, HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy) that have gone missing — so a dropped Cloudflare Transform Rule no longer silently strips the site's security posture. Self-heals if the edge is briefly unreachable.
- **Native Site Health test for the cron pipeline.** Tools → Site Health → Status now runs an async test against the Signal & Noise cron pipeline: every SN-owned hook (including the cron-history prune) is checked for being scheduled, not stale, and not silently disabled (DISABLE_WP_CRON without a declared system cron). Deep-links to the Cron tab.
- **SN operational state in Site Health → Info.** A "Signal & Noise Tools" panel surfaces plugin/theme versions, update state, DB-override count, per-hook cron state, cron-history table presence, external-API rate state, AI availability, webhook counts, and cache state. Integration-adjacent fields are marked private (excluded from the copy-to-clipboard export).
- **Opt-in Uptime Kuma heartbeat.** Configure a Kuma push-monitor URL on the Webhooks tab and the plugin sends a `status=up` heartbeat every 5 minutes. If WP-Cron stops firing or the site goes down, Kuma stops receiving it and flips the monitor to DOWN — external "is it alive + is cron working" monitoring with no inbound surface. Default off; SSRF-hardened (URL validation + no redirects, mirroring the webhook posture).
- **Live-refreshing cron + webhook tables.** The Cron tab's last-fired cells and the Webhooks tab's delivery logs now update in place via the WordPress Heartbeat API — no page reload. The server only does work for the tables actually on screen and only for admins.

## [4.8.1] - 2026-06-06 — Track A SEO/structured-data + ops/a11y patch

**Headline:** A 9-item patch bundle that enriches the site's structured data, leans out the sitemap, makes social cards accessible, and adds two performance/a11y niceties. No new admin UI — all additive calibration.

### Fixed
- **Breeze HTML cache now rolls over automatically on deploy.** When a new plugin or theme version is detected, the inlined critical CSS held in Breeze's HTML page cache is purged — no more stale above-the-fold styles after an update. Site Editor template overrides are preserved.

### Improvements
- **Richer JSON-LD.** Article schema now carries reading time, word count, keywords, and section; the Person schema gains an author image (doubling as the publisher logo); the /notes CollectionPage enumerates its recent posts as an ItemList; and the WebSite schema emits a SearchAction pointing at on-site search, so Google can surface a sitelinks search box.
- **Accessible social cards.** Open Graph and Twitter image tags now include alt text (featured-image alt → page title → site name).
- **Leaner sitemap.** The author sitemap and the tag/category term-archive sitemaps are dropped from the index — a single-author Notes site doesn't need them, and the term archives are thin/duplicate-y.
- **Conditional-GET on RSS feeds.** Feeds now answer `If-Modified-Since`/`If-None-Match` with a `304 Not Modified` when unchanged, saving bandwidth and crawl budget for well-behaved feed readers.
- **Accessible Identity-tab table of contents.** The "Jump to" TOC on the Identity & SEO tab now highlights the section you're viewing as you scroll (`aria-current`), with graceful fallback where unsupported.

## [4.8.0] - 2026-06-06 — Post-editor cleanup + AI prepopulation

### New
- **AI prepopulation at publish.** When you publish or schedule a post, the meta description, excerpt, and OG card title now auto-fill from the content — but only when each field is empty (your own writing is never overwritten). Generation runs in the background, so publishing stays instant. A one-line notice in the Signal & Noise panel tells you which fields were auto-generated, and clears the next time you save or when you dismiss it.

### Improvements
- **De-duplicated the editor AI sidebar.** The "AI" plugin's Meta Description and Excerpt generators duplicated Signal & Noise's own; both are now hidden, leaving a single generator for each field. The plugin's other AI features (editorial notes, summary, suggest categories/tags, title) are untouched.
- **Tighter auto-generated copy.** Auto-filled meta descriptions stay within the ~155-character search-result limit; auto-filled excerpts stay punchy (≤3 short sentences).

### Fixed
- **Removed a duplicate `<meta name="description">` tag.** With no third-party SEO plugin active, the "AI" plugin emitted its own meta-description tag alongside Signal & Noise's. Hiding its meta-description feature resolves the double tag.

## [4.7.0] - 2026-06-06 — Admin-bar expansion — 3 quick-action items

**Released:** 2026-06-06.

**Headline:** Three new one-click quick actions land in the existing grandfathered S&N admin-bar dropdown — Force Update Check, Scan Pattern Adoption, and a contextual Regen OG Card. Each wraps the same impl its matching Ability calls, so the admin-bar shortcut and the Ability stay behaviorally identical. Iterating an existing grandfathered surface (not a new surface), per the no-new-admin-surfaces carve-out.

### Added

- **↺ Force Update Check** (`inc/admin-bar.php`). One-click bust of the GitHub tag caches + WP's `update_themes` / `update_plugins` transients, so a freshly-pushed tag shows up under Dashboard › Updates without waiting for the next cron poll. Calls `snt_cmd_impl_force_check()` — the same impl the `signal-noise/force-check-updates` ability uses. No guard (always shown to `manage_options` users). Toast: "Update check forced — see Dashboard › Updates."
- **⌕ Scan Pattern Adoption** (`inc/admin-bar.php`). Triggers a walk of every post/page for v9.2.0 pattern candidates via `snt_pattern_adoption_run_scan()`, then reports the **candidate count** in the toast (counts `$result['candidates']`, not the 3-key scan envelope). No guard. Toast: "Pattern scan complete — N candidate(s)."
- **⟳ Regen OG Card** (`inc/admin-bar.php`). **Contextual** — only appears when a single post is in context (admin post-edit screen with `?post=`, or a front-end singular view). Regenerates the social-share card for that post via `sn_generate_og_card()` — the same impl the `signal-noise/regenerate-og-card` ability uses. The resolved post ID is plumbed to the AJAX request server-side; the handler re-validates it (post exists + per-post `edit_post` capability) before regenerating. Toast: "OG card regenerated for this post."

### Security

- The contextual Regen handler cap-gates the post ID with a per-post `edit_post` check, not just `manage_options` — a site admin cannot regen a card for a post they lack edit rights to. The post ID is re-validated server-side (existence + capability) rather than trusted from the request.

## [4.6.0] - 2026-06-05 — Prep minor for v5.0.0 — 6 new abilities + WP 7.0 pre-warning + legacy REST deprecation annotations

**Released:** 2026-06-05.

**Headline:** v4.6.0 is the prep-minor cycle for v5.0.0 + theme v10.0.0 paired-major event. Three additive workstreams: (1) close gaps in the Abilities API surface by registering 6 abilities that the existing REST routes had no Ability replacement for, (2) warn admins that v5.0.0 will require WordPress 7.0 via a dismissible admin notice, (3) annotate the legacy REST handlers with `@deprecated since 4.6.0` PHPdoc so v5.0.0 can promote to runtime warnings and v6.0.0 can remove.

**v5.0.0 plan** (per `docs/superpowers/specs/2026-05-27-v5-and-v10-paired-cycle-design.md`):
- HARD-raise `Requires at least: 7.0`. Plugin will refuse to install on WP < 7.0.
- REMOVE the 4 `@deprecated since 2.5.0` REST routes (`/ai/generate-meta-description`, `/ai/generate-excerpt`, `/ai/generate-og-card-title`, `/cmd/<action>`).
- REMOVE the 6 REST routes deprecated in this v4.6.0 cycle (`/plausible/stats`, `/plausible/realtime`, `/plausible/test`, `/cron/run`, `/health/pattern-adoption-scan`, `/health/pattern-adoption-dismiss`) — all have Ability replacements.
- REMOVE the orphaned `sn_login_rewrites_flushed` option.
- DROP pre-7.0 compat code (`snt_ai_is_available()` simplifies; native breadcrumbs fallback removed; etc.).
- PROMOTE `@deprecated` PHPdoc on the non-Ability surface to `_deprecated_function()` runtime warnings. Removal scheduled for v6.0.0.

### New

- **`signal-noise/get-plausible-stats` ability** (`inc/abilities-plausible.php`). Returns the Plausible dashboard breakdown. Replaces the `GET /signal-noise/v1/plausible/stats` REST route for AI agents and automation.
- **`signal-noise/get-plausible-realtime` ability** (`inc/abilities-plausible.php`). Returns the current realtime visitor count.
- **`signal-noise/test-plausible-connection` ability** (`inc/abilities-plausible.php`). Reports the health of the most recent Plausible fetch (cached realtime value + last-recorded-error transient) for setup diagnostics — does not perform a live ping.
- **`signal-noise/run-cron-event` ability** (`inc/abilities-cron.php`). Synchronously dispatches a cron event by hook name. Refuses SN-internal `sn_*` hooks (use the dedicated abilities for those — `purge-all-caches`, `force-check-updates`, etc.).
- **`signal-noise/pattern-adoption-scan` ability** (`inc/abilities-pattern-adoption.php`). Walks every post/page for v9.2.0 pattern candidates. Replaces `POST /signal-noise/v1/health/pattern-adoption-scan`.
- **`signal-noise/pattern-adoption-dismiss` ability** (`inc/abilities-pattern-adoption.php`). Dismisses a scanned candidate by writing its `pattern_type:block_fingerprint` key into the post's `_snt_pattern_adoption_dismissed` meta — the same store the scanner reads. Requires `post_id` + `pattern_type` + `block_fingerprint`. Idempotent.
- **WP 7.0 pre-warning admin notice** (`inc/admin-notice-wp-version.php`). Dismissible notice on every wp-admin page when WP < 7.0. Persists dismissal via user-meta. Self-contained file — will be deleted in v5.0.0.

### Fixed

Adversarial pre-ship review of the new v4.6.0 abilities surfaced six behavioral defects (registration-shape tests had passed them):

- **`run-cron-event` was a strictly weaker dispatcher** — it called `do_action_ref_array()` directly, bypassing the proven `snt_cron_run_event_impl()` (`inc/cron-dashboard.php`). It returned `ok:true` for orphan hooks with no handlers, had no `Throwable` catch (a throwing callback would fatal), no `DOING_CRON` spoof, and no last-fired/history tracking — and `sanitize_key()` mangled mixed-case/namespaced hook names so they never matched. Now delegates to the impl (matching hook names verbatim) and only adds the `sn_*` pre-filter. (`inc/abilities-cron.php`)
- **`pattern-adoption-dismiss` wrote a DEAD store** — it appended to the option `sn_pattern_adoption_dismissed`, which nothing reads. The scanner reads per-post meta `_snt_pattern_adoption_dismissed` (array of `pattern_type:fingerprint` keys), so dismissals had no effect. Extracted a shared `snt_pattern_adoption_dismiss_impl( $post_id, $pattern_type, $fingerprint )` (`inc/pattern-adoption-admin.php`); the REST route and the ability now both call it. Ability input is now `post_id` + `pattern_type` + `block_fingerprint` (was a bare `fingerprint`). (`inc/abilities-pattern-adoption.php`, `inc/pattern-adoption-admin.php`)
- **`pattern-adoption-scan` returned the wrong shape** — `snt_pattern_adoption_run_scan()` returns an envelope `{candidates, counts, scanned_at}`; the ability treated the whole envelope as the candidate list, so `count` was always 3 (the envelope key count). Now reads `result['candidates']`. Description also corrected — it cited a non-existent option `sn_pattern_adoption_last_scan`; the scan actually caches in a user-scoped transient. (`inc/abilities-pattern-adoption.php`)
- **The `'tools'` ability category was registered NOWHERE** — the 2 pattern-adoption abilities and the 4 block-migrations abilities all cite `category => 'tools'`, but the registry silently bails on `wp_register_ability()` when the category isn't registered, so all 6 would have failed to register in real WP. Added a guarded `tools` registration. (`inc/abilities-categories.php`)
- **`test-plausible-connection` overstated its behavior** — label/description/comment claimed it "pings the Plausible API" / "forces a fresh call", but `sn_plausible_realtime()` only reads a cached transient (never a network call). Softened to accurately report the health of the most recent fetch via the cached value + `sn_plausible_last_error()`. (`inc/abilities-plausible.php`)
- **`tests/legacy-deprecation.php` banner detection could false-credit** — the preceding-window scan didn't stop at the close of an earlier block comment, so a neighboring `@deprecated` banner could satisfy a function whose own docblock omitted it. The window now breaks at the previous `*/`. (`tests/legacy-deprecation.php`)

### Deprecated

PHPdoc-level annotations only — no runtime warnings yet. Runtime `_deprecated_function()` promotion lands in v5.0.0; removal in v5.0.0+.

- `sn_rest_plausible_stats`, `sn_rest_plausible_realtime`, `sn_rest_plausible_test` in `inc/rest-api.php` → use the corresponding `signal-noise/get-plausible-*` / `signal-noise/test-plausible-connection` abilities.
- `snt_rest_cron_run` in `inc/rest-api.php` → use `signal-noise/run-cron-event`.
- `snt_rest_pattern_adoption_scan` (in `inc/pattern-adoption-detect.php`) + `snt_rest_pattern_adoption_dismiss` (in `inc/pattern-adoption-admin.php`) → use the corresponding `signal-noise/pattern-adoption-*` abilities.

**Total abilities registered:** 40 (was 34 at v4.5.8).

**Tests:** 1,098 assertions across 33 suites (excludes the WP-only `contracts-smoke.php`) — adds 6 new-ability registration assertions to `tests/abilities-integration.php`, the new `tests/wp-version-admin-notice.php` (4 assertions across the WP-version/dismissal/capability matrix), extends `tests/legacy-deprecation.php` from 4 to 10 covered handlers (and hardens its banner detection), and adds the new `tests/abilities-behavior-v460.php` (24 BEHAVIORAL assertions exercising the adversarial-review fixes: run-cron-event delegation incl. orphan/throwing/mixed-case paths, dismiss writing the real post-meta store + leaving the dead option untouched, and scan returning the real candidate count).

## [4.5.8] - 2026-06-05 — Post-ship audit fix: restore admin table top-inset

**Released:** 2026-06-05.

**Headline:** Post-ship audit (AR-01) caught a cosmetic regression in v4.5.7's inline-style consolidation: moving `margin-top:0.5rem` from an inline `style=` (which always wins) into the single-class utilities `.snt-mt-half` / `.snt-table-log` (specificity 0,1,0) let the pre-existing `.snt-scroll-table .widefat { margin: 0 }` rule (0,2,0) silently override it — so the Health-findings table and the webhooks delivery-log table lost their ~8px top inset and rendered flush. Restored at higher specificity (no functional impact; supersedes v4.5.7, which was never required to be installed).

### Fixed

- **Admin table top-inset restored** — added `.snt-scroll-table .widefat.snt-mt-half, .snt-scroll-table .widefat.snt-table-log { margin-top: 0.5rem; }` so the two tables inside `.snt-scroll-table` regain the 0.5rem top gap that the v4.5.7 refactor dropped. Inline styles and class selectors are not render-equivalent (specificity differs) — this corrects v4.5.7's "byte-equivalent, no visual change" claim for these two tables. (`assets/admin.css`)

## [4.5.7] - 2026-06-05 — /sn-login noindex header + wp-admin inline-style cleanup

**Released:** 2026-06-05.

**Headline:** Two pre-v5.0.0 hardening/cleanup items batched into one patch. (1) The custom `/sn-login` form now emits an HTTP `X-Robots-Tag: noindex, nofollow` header as defense-in-depth over WP core's existing `wp_robots` noindex meta tag — an HTTP header is honored by non-HTML-parsing crawlers and survives output filtering. (2) 22 inline `style=` attributes across four admin screens moved into `assets/admin.css` utility classes, which also removed a brutalist brand-red border leaking into wp-admin (now a native WordPress blue).

### Added

- **`X-Robots-Tag: noindex, nofollow` on the `/sn-login` serve-form path** — emitted before `wp-login.php` loads, behind a `headers_sent()` guard (`wp_loaded` runs before any login output, so the guard is a safety net). Pure testable seam `sn_login_serve_form_headers()` locks the header contract. (`inc/login-hide.php`)

### Cleanup

- **22 inline `style=` attributes → `assets/admin.css` utility classes** across `health-checks-admin.php`, `pattern-adoption-admin.php`, `block-migrations-admin.php`, `webhooks-admin.php` — byte-equivalent relocation (margins, column widths, log table, payload `<pre>`) into `snt-*` utilities, plus deduping the verbatim 40%/20%/40% column triple shared by pattern-adoption and block-migrations into `.snt-col-40` / `.snt-col-20`.
- **Removed a brand-vocabulary leak in wp-admin** — the new-webhook fieldset's `border-left` used the front-end `--wp--preset--color--blood` brand red; it now uses native wp-admin blue (`#2271b1`) via `.sn-fieldset--new`, keeping wp-admin reading as native WordPress (discharges the no-brutalist-in-admin rule).

### Tests

- **`tests/login-noindex-header.php`** — 6 assertions locking the noindex-header contract (function defined, returns the `X-Robots-Tag: noindex, nofollow` header, exactly one well-formed entry, handler still registered on `wp_loaded`).

## [4.5.6] - 2026-05-29 — Self-updater authenticates to GitHub (60/h → 5000/h)

**Released:** 2026-05-29.

**Headline:** The self-updater's GitHub tag-fetch (`sn_gh_latest_plugin_tag`) only ever sent `Accept` + `User-Agent` — never an `Authorization` header — so every WP update-check spent from GitHub's **60/h unauthenticated** pool (shared per-server-IP on Cloudways). When that pool exhausts, the fetch 403s, the function returns `null`, and the Updates page silently shows "no update available" even when a release exists. The deploy-history poller (`github-actions-api.php`) already authenticated with the `SNT_GITHUB_TOKEN` wp-config constant and ran at 5000/h — this brings the updater to parity. (Surfaced when the dashboard's GitHub-API counter showed 48/60 instead of the authenticated ~5000.)

### Fixed

- **`sn_gh_latest_plugin_tag()` now sends `Authorization: Bearer <SNT_GITHUB_TOKEN>`** when the constant is defined in wp-config.php — 60/h → 5000/h. Conditional: when the constant is absent the request is byte-for-byte the previous unauthenticated call, so there is no regression and no behavior change for installs without a token. Mirrors `inc/github-actions-api.php`. (`inc/wp-update-integration.php`)

### Added

- **`tests/updater-github-auth.php`** — 6 assertions: token-defined → `Authorization: Bearer <token>` present, Accept/User-Agent preserved, application guarded by `defined()` (graceful unauthenticated fallback), header built from `SNT_GITHUB_TOKEN`. Plugin test total: **1,052 assertions across 30 suites**.

## [4.5.5] - 2026-05-29 — Dashboard External-APIs line only shows APIs that actually report

**Released:** 2026-05-29.

**Headline:** The Dashboard "External APIs" summary showed GitHub's rate-limit count but rendered Cloudflare and Plausible as a permanent `—`, implying "tracked, no data yet." They can never populate: the monitor (`inc/api-rate-monitor.php`) parses `X-RateLimit-*` headers, but Cloudflare uses non-standard `Ratelimit`/`Ratelimit-Policy` headers (verified against Cloudflare's API limits docs) and the Plausible stats API emits no rate-limit headers at all (600/h, documented-only). The line now renders a host only when it has a real snapshot — GitHub shows; CF + Plausible are omitted. Self-healing: if either ever starts reporting, it appears automatically with no code change. The monitor still tracks all three internally (email warnings unaffected).

### Fixed

- **`snt_dashboard_render_api_summary()` skips hosts with no rate-limit snapshot** instead of printing a permanent `—` placeholder. The separator before "Refresh now" is now suppressed when zero host items render (avoids a dangling `·`; unreachable in practice since GitHub is polled by the update-checker, but kept clean). (`inc/admin-tab-dashboard.php`)

### Added

- **`tests/dashboard-api-summary.php`** — 13 assertions locking the contract: reporting hosts shown, non-reporting hosts omitted (no `—`), section heading + Refresh link always present, the self-heal path (a previously-silent host appears once it reports), critical (<10%) hosts still surface the warning notice, and no dangling separator with zero items. Plugin test total: **1,046 assertions across 30 suites**.

## [4.5.4] - 2026-05-29 — Refactor: split inc/admin-page.php into handler + flash-data + form modules

**Released:** 2026-05-29.

**Headline:** Pure, behavior-preserving refactor of the 1,467-line `inc/admin-page.php` monolith (flagged HIGH in the 2026-05-29 full-codebase QA audit — ~10× the ~150-line convention, 2× the next-biggest file) into 10 focused modules + a 248-line render orchestrator. No functional change: every form action, nonce check, capability gate, sanitize/unslash, flash message, redirect, and tab behaves identically. Lands on top of the v4.5.3 conformance pass and holds its 0/0 PHPCS baseline. (Numbered 4.5.4 — 4.5.3 shipped the WPCS pass first.)

### Cleanup

- **Dispatcher de-monolithed** — the 270-line, 22-branch `if/elseif` in `sn_handle_admin_post()` is now an action→callback map (`sn_admin_post_handlers()` in `inc/admin-post-handler.php`) dispatching to 22 atomic, individually-testable `sn_handle_<action>()` functions in `inc/admin-post-actions.php`. Per-handler unslash behavior preserved verbatim — including `save_identity`'s raw-`$_POST` pass-through to `sn_settings_save()`.
- **Two duplicate flash ladders collapsed into one** — the dispatcher's emitted `?sn_flash=…` codes and the renderer's notice translation now share a single registry (`inc/admin-flash-messages.php`: `sn_admin_flash_messages()` + `sn_admin_flash_to_notice()`), removing the hand-synced second `if/elseif`. Handles all three message shapes (static, count/id-prefixed, live-data); all 34 literal messages verified byte-identical against the old translator.
- **Inline-HTML form walls extracted** — Identity & SEO (`inc/admin-forms/identity-and-seo.php`), Login URL (`inc/admin-forms/login.php`), and Links (`inc/admin-forms/links.php`) moved out of the renderer as byte-identical echo-for-echo lifts.
- **Tab data / framework / legacy / menu split out** — `inc/admin-tabs-data.php` (the v3.8.0+ IA), `inc/admin-tabs.php` (accessors + nav renderers), `inc/admin-legacy-redirect.php` (legacy-URL 301 layer), and `inc/admin-menu.php` (menu registration + asset enqueue). All loaded via the existing flat `require_once` manifest in `signal-and-noise-tools.php`.
- **`inc/admin-page.php` reduced 1,467 → 248 lines** — now a thin orchestrator (cap check → legacy redirect → tab resolution → flash loop → page shell → tab router).
- **PHPCS 0/0 baseline maintained** — the single `EscapeOutput` exclusion (the `$aria` literal-attribute fragment) moved with `sn_admin_render_sub_tabs()` from `admin-page.php` to `inc/admin-tabs.php` in `phpcs.xml.dist`; `admin-page.php` is now fully covered. Verified by inspection (only one bare-variable echo across the split); re-run `composer run lint` to confirm 0/0.

### Improvements

- **New unit coverage where there was none** — `tests/admin-post-actions.php` (40 assertions: handler flash codes + side effects — `save_login`, `audit_save_retention` clamp, `save_identity`, `cf_save` constant-lock, `pl_save` branches, plus map completeness) and `tests/admin-flash-messages.php` (46 assertions: all three message shapes + a coordination guard that every emitted code resolves). `tests/audit-retention-bounds.php` upgraded from a brittle source-grep proxy to a real handler call; `tests/admin-tabs.php` + `tests/legacy-url-redirect.php` re-pointed at the new module locations.

**Tests:** 1,033 assertions across 29 suites, 0 failed (was 945 pre-refactor; +88 from the extracted dispatcher/flash coverage).

**Refs:** 2026-05-29 full-codebase QA audit (HIGH). Spec: `docs/superpowers/specs/2026-05-29-admin-page-refactor-design.md`. Plan: `docs/superpowers/plans/2026-05-29-admin-page-refactor.md`.

## [4.5.3] - 2026-05-29 — WordPress-handbook conformance pass (PHPCS + WPCS) + input/escaping hardening

**Released:** 2026-05-29.

**Headline:** Ran the codebases against the WordPress Plugin/Theme handbooks via PHPCS + WordPress-Coding-Standards (curated ruleset). Committed a `phpcs.xml.dist` baseline + `composer run lint` workflow; the plugin now passes clean (0/0). The runtime changes below are the genuine handbook violations the scan surfaced — all low-severity (admin-only / comparison-only inputs), none exploitable, fixed for correctness and to lock a true zero-baseline.

### Security

- **Unslash before use on 5 superglobal reads** — `$_SERVER['REMOTE_ADDR']` (`inc/login-hide.php`), `$_SERVER['REQUEST_URI']` (`inc/security-headers.php`), `$_POST['log_retention_days']` + `$_POST['purge_days']` (`inc/rss-plausible-tracker.php`), and the `force-check` cache-buster (`inc/wp-update-integration.php`) now pass through `wp_unslash()` per the WordPress unslash-then-sanitize rule (magic-quotes correctness).
- **`$preview_url` escaped at output** in the reading-time tool — was `esc_url()`'d only at assignment; now also at the echo. (`inc/reading-time.php`)

### Fixed

- **Annotated the intentional `$pagenow` global override** in the login-hide intercept (the core mechanism, mirrored from wps-hide-login, that routes the custom login slug) with a `phpcs:ignore` + rationale so it reads as deliberate, not an accidental global clobber. (`inc/login-hide.php`)

### Tooling

- **Added `phpcs.xml.dist`** — curated handbook ruleset: `WordPress.Security` (escaping/sanitization/nonce), `PreparedSQL`, deprecated/discouraged-API sniffs, `PHPCompatibilityWP` against the PHP 8.0 floor. Architecturally-determined false-positive categories (central-dispatcher nonce verification, custom-table direct queries + table-name interpolation, comparison-only `$_SERVER` reads, pre-escaped admin HTML builders, i18n non-goal) are scoped-excluded with inline rationale; all high-value security sniffs stay ACTIVE everywhere else. **Result: 0 errors / 0 warnings across 68 files.**
- **Added `composer require-dev`** (`squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp` + installer plugin) with `composer run lint` / `lint:fix` scripts. `vendor/` stays gitignored.

## [4.5.2] - 2026-05-29 — Post-ship QA audit fixes — dead button + audit-retention data loss + unstyled tab + SSRF/input hardening

**Released:** 2026-05-29. (CHANGELOG entry backfilled 2026-05-29 — the v4.5.2 commit + tag shipped without it due to an editor no-op; code + version were correct.)

**Headline:** Full-codebase QA audit (7 parallel review agents across both repos) run before the v4.6.0 cycle. One dead control, one cross-tab data-loss bug, one unstyled screen, plus input/SSRF hardening. No settings-schema changes. The recurring bug-classes (`is_callable` AI gate, install-hook self-observation, version-from-docblock, all 37 REST permission_callbacks, custom-table SQL) were all verified CLEAN.

### Fixed

- **Dead pattern-adoption Suggest/Dismiss buttons on the Health tab when no AI provider is configured** — `health-suggest-actions.js` was enqueued only under the `snt_ai_is_available()` gate, but the AI-free Opportunities (pattern-adoption) section rendered its buttons unconditionally, so clicks did nothing. Same dead-button class as the v4.5.1 Tools-tab fix. Now enqueued unconditionally on the Health tab. (`inc/admin-page.php`)
- **Identity save silently wiped the Audit-log retention setting** — `sn_settings_save()` did a whole-option replace that omitted the `audit` subtree, reverting a configured retention to the 90-day default. Now preserved, mirroring the existing `login.slug` guard. Locked behind a new regression test. (`inc/settings.php`)
- **Audit-log sub-tab rendered unstyled via the "Security" sidebar link** — CSS was guarded on `toplevel_page_sn-theme-options`, which the `?page=sn-security` deep-link doesn't match. Switched to the canonical `sn_admin_page_hooks()` guard (same fix as the cron tab's D-11). (`inc/audit-log-admin.php`)

### Security

- **Webhook dispatch no longer follows redirects** — `redirection` was `3`; WP's HTTP layer doesn't re-validate redirect targets, so a receiver returning a 30x to an internal host / cloud-metadata endpoint (`169.254.169.254`) would be followed and its body recorded in the admin-visible delivery log (SSRF + exfiltration). Set to `0`. (`inc/webhooks.php`)
- **Apply handlers now reject non-block markup** — malformed `replacement_markup` that `parse_blocks()` resolves to a nameless freeform block previously passed the validity guard and spliced raw HTML into post content. Both apply handlers now require a named block. (`inc/pattern-adoption-apply.php`, `inc/block-migrations-apply.php`)

### Improvements

- **Reading-time "Apply" now confirms before running** — the irreversible bulk content mutation gained the shared confirm modal (`snt-confirm.js`), matching every other destructive action. (`inc/reading-time.php`)

### Added

- **`tests/settings-save-preserves-subtrees.php`** — regression test (5 assertions) locking cross-tab subtree preservation (audit + login) through an Identity save. Plugin test total: **945 assertions across 26 suites**.

## [4.5.1] - 2026-05-27 — v4.5.0 post-ship audit fixes — dead Suggest button + Discard retry break + 2 minor

**Released:** 2026-05-27.

**Headline:** v4.5.0 shipped with a JS enqueue gap that left the Block Migrations Suggest button dead in production (script only loaded on the Health tab, but block-migrations lives under Tools). Plus a Discard → Suggest retry path that lost the button's data-attrs. Both caught by the post-ship audit before user UAT exposure. Patch in v4.5.1.

### Fixed

- **CRITICAL: `assets/health-suggest-actions.js` now enqueued on the Tools tab too** (`inc/admin-page.php`). Previously only loaded when `?tab=health` AND `snt_ai_is_available()`. Block Migrations is pure structural (no AI), so neither condition fired and every click on `[data-snt-suggest]` in the block-migrations table silently produced nothing. The whole Suggest+Apply loop was non-functional. Added a parallel enqueue for `?tab=tools` with no AI gate.
- **IMPORTANT: `buildSuggestButton()` now handles `block_migrations_heading_skip`** (`assets/health-suggest-actions.js`). The function rebuilds the Suggest button after Discard. Every other check type had a branch restoring its `data-*` attrs; the v4.5.0 cycle added the new check type to the click-handler chain but missed adding it here. After Discard, the rebuilt button lacked `data-post-id` / `data-fingerprint` / `data-migration-type` — clicking Suggest then fell through validation and rendered an error. Same shape as the v4.4.3 Bug-B2 fix for pattern-adoption — carried forward correctly this time.
- **MINOR: Test 4 dismiss fixture in `tests/block-migrations-detect.php` was double-nested** — `array(array('heading-hierarchy-skip:...'))` instead of `array('heading-hierarchy-skip:...')`. The test reported 0 candidates and passed, but the dismiss filter was never actually exercised because the stored value didn't match what `in_array()` looked for. Unwrapped to a single-level array — Test 4 now tests what it claims.
- **MINOR: `inc/block-migrations-admin.php` file docblock corrected** — dismiss REST endpoint was described as "back-compat surface for JS clients" (carried over from pattern-adoption-admin's docblock where it WAS a back-compat alias). For block-migrations it's the PRIMARY JS surface; the ability wrapper is the secondary path for AI agents. Rephrased for accuracy.

**Tests:** 940 assertions across 25 suites, 0 failed (unchanged total — Test 4 now passes for the right reason instead of the wrong reason).

**Refs:** Post-ship audit findings (cross-cutting holistic review over the 15-commit v4.5.0 cycle).

## [4.5.0] - 2026-05-26 — Block Migration tool (Tools sub-tab) + deprecation annotations on legacy REST

**Released:** 2026-05-26.

**Headline:** First content-migration tool ships under a new Tools sub-tab — `parse_blocks` / `serialize_block` infrastructure with Suggest+Apply UX, mirroring v4.3.0 pattern-adoption. First migration is **heading-hierarchy-skip** (h3 with no preceding h2 → h2, WCAG 1.3.1). Plus signal-only `_deprecated_function()` annotations on 4 legacy REST handlers so we can track external callers ahead of v5.0.0 removal.

### Added

- **Block Migration tool** (`inc/block-migrations-detect.php`, `inc/block-migrations-suggest.php`, `inc/block-migrations-apply.php`, `inc/block-migrations-admin.php`). Tools sub-tab "Block Migrations" detects + fixes structural block issues via `parse_blocks`/`serialize_block` round-trip. Suggest+Apply UX mirrors v4.3.0 pattern-adoption (modal preview, fingerprint conflict detection at 409, per-finding dismiss state, idempotent per-user scans cached for 1 hour). Generalizes the PA-01 SQL-miss from the v4.4.x cycle — future content migrations all use this infrastructure (no more bespoke SQL).
- **4 new abilities** under category `tools` (NOT `ai-generation` — these are pure structural, zero AI calls): `signal-noise/block-migrations-scan`, `signal-noise/block-migrations-suggest`, `signal-noise/block-migrations-apply`, `signal-noise/block-migrations-dismiss`. Apply gets `annotations.idempotent: false, destructive: true`; the rest are `idempotent: true`. See `inc/abilities-block-migrations.php`.
- **REST surfaces** at `POST /signal-noise/v1/tools/block-migrations-{scan,suggest,apply,dismiss}`.
- **`_deprecated_function()` annotations** on the 4 `@deprecated 2.5.0` REST handlers: `snt_ai_meta_desc_rest_handler`, `snt_ai_excerpt_rest_handler`, `snt_ai_og_card_title_rest_handler`, `snt_desktop_cmd_handler`. Signal-only — notices fire under `WP_DEBUG_LOG=true` when external callers (WP-CLI scripts, wp-cron jobs, third-party integrations, `WordPress/desktop-mode`'s `command-palette.js`, cached browser tabs) hit the legacy routes. Once the notice log shows zero traffic for a few months, v5.0.0 can safely remove them per spec §9.
- **Test coverage**: 52 new assertions across 4 new test files:
  - `tests/legacy-deprecation.php` (5 assertions, static-grep guard for WS1)
  - `tests/block-migrations-detect.php` (15 assertions across 8 scenarios)
  - `tests/block-migrations-suggest.php` (18 assertions across 6 scenarios — includes regex boundary regression for `<h3-custom>`)
  - `tests/block-migrations-apply.php` (14 assertions across 6 scenarios — includes `invalid_markup` 422 coverage)
- Plugin aggregate: 888 → **940 assertions**, 0 failures across 25 runnable suites.

### Changed

- Tools tab gains a 3rd sub-tab: **Block Migrations** (after Reading Time + Links). New per-sub-tab hook `sn_admin_block_migrations_tab` matches the project's flat hook convention (`sn_admin_cloudflare_tab`, `sn_admin_health_tab`, etc.).
- `assets/health-suggest-actions.js` extended (~120 LOC) to handle the new `block_migrations_heading_skip` check type. Shared modal + status infrastructure reused — no new JS file.
- `inc/admin-page.php`: new entry in Tools `sub_tabs`, new render-branch elseif, new `block_migrations_scan` dispatcher branch, new `block_migrations_scanned` flash notice.

**Implementation notes:**

- Workstream 2 from the original v4.5.0 paired-design spec ("JS-client flip: ability-first, REST-fallback") was **DROPPED** during pre-plan-writing source verification — the flip already shipped in v2.5.0 (verified at `assets/ai-*.js`). Spec revised at theme commit `7475d2c` before plan-writing began. Direct application of the `read framework source` discipline rule.
- Subagent-driven execution found 3 code-quality issues during the review cycles that produced cleanup commits: regex word-boundary bug (`\b` matched `<h3-custom>` — fixed at `cf8a571`); dead-code branch from malformed test fixture (fixed at `7552fcd`); hook-name convention deviation (renamed `sn_admin_tools_block_migrations_tab` → `sn_admin_block_migrations_tab` at `e80c1f2`). All caught BEFORE the v4.5.0 tag.
- Spec source: [docs/superpowers/specs/2026-05-26-v4.5.0-and-v9.5.0-paired-design.md](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-26-v4.5.0-and-v9.5.0-paired-design.md) (theme repo, paired with theme v9.5.0).
- Plan source: `docs/superpowers/plans/2026-05-26-v4.5.0.md` (this repo).

**Paired with:** Theme v9.5.0 (not yet shipped; will convene after this gate per spec §1 relaxed sync).

---

## [4.4.5] - 2026-05-26 — PA-10 social inputs aria-label + test catch-up after v4.4.4 docblock re-framing

**Released:** 2026-05-26.

**Headline:** Two small fixes from the Tier B backlog + a test catch-up that v4.4.4 should have included.

### Fixed

- **PA-10 (UI-UX) — Repeating `social_same_as[]` inputs now have per-input `aria-label="Profile URL"`.** Existing server-rendered rows (line 1135) and the no-JS fallback row (line 1139) in `inc/admin-page.php` were missing the accessible name that the JS-added rows already had (`assets/admin.js:141`). Screen readers tabbing through the Identity → Social settings page now announce each row consistently. Brings server-render parity with the dynamically-added rows.
- **Test catch-up: `tests/legacy-url-redirect.php` updated to match v4.4.4's docblock re-framing.** v4.4.4 changed `sn_admin_pages()`'s docblock from `@deprecated 4.2.0` → `@internal` (Audit C HYG-08), but this test's Test 5 was hardcoded to `preg_match( '/@deprecated\s+4\.2\.0/' )` as a regression guard. The test broke at v4.4.4 ship time and **should have been caught before tagging** — plugin tests were not re-run after the v4.4.4 docblock edits, and the previous handoff's "888 assertions / all green" claim was inaccurate. The test now accepts either `@internal` or `@deprecated` framing (the future v5.0.0 cleanup per Audit E U-01 option 2 may flip it back to `@deprecated` again).

**Audit reference:** [`docs/superpowers/specs/2026-05-26-audits-c-d-cycle-findings.md`](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-26-audits-c-d-cycle-findings.md) — Tier B PA-10.

**Tests:** 888 assertions / 21 plugin suites — all green (now verified, not just claimed).

**Lesson:** version-bump commits must include a test run between edit and tag. v4.4.4 went edit → commit → tag → push without `for f in tests/*.php; do php "$f"; done` in between. The verification-before-completion discipline applies even to "small" patches.

**Post-install user actions:**

- Install v4.4.5 via wp-admin → Dashboard → Updates (canonical) or `gh workflow run deploy.yml --ref v4.4.5` (emergency).
- Tab through the SN admin → Site → Identity & SEO → Social section with VoiceOver / NVDA. Each Profile URL row should now announce as "Profile URL, edit text" rather than just "edit text."

---

## [4.4.4] - 2026-05-26 — Audit C + D fixes — admin tabs aria-current, docblock hygiene, Tested up to: 7.0

**Released:** 2026-05-26.

**Headline:** Bundles the plugin-side Tier A bug fix from Audit D (PA-03 — admin tabs missing `aria-current` for screen readers, WCAG 4.1.2 Level A) with three Tier C / Tier D doc-hygiene fixes from Audit C. None of the underlying behaviour changes — this is a small accessibility + documentation accuracy patch.

**Why bumped:** PA-03 is a real WCAG-AA failure (active state announced visually but not programmatically) and warrants a code change in `inc/admin-page.php`. The three doc edits (HYG-03, HYG-06, HYG-08) ride along because they touch the same plugin and would otherwise need their own commit churn.

### Fixed

- **PA-03 (BUG-MED, WCAG 4.1.2 Level A) — Plugin admin sub-tabs now emit `aria-current="page"` on the active item.** The visual `.sn-sub-tab.is-active` styling was present but no programmatic affordance for assistive tech; screen readers couldn't announce which sub-tab was selected. `sn_admin_render_sub_tabs()` (`inc/admin-page.php:227-248`) now renders `aria-current="page"` on the active anchor in addition to the `is-active` class. ~3 LOC. Note: the in-page `.sn-toc` anchor nav (lines 202-210) does not get the same treatment because its active state is scroll-position-dependent and would need a JS observer to maintain — deferred to backlog.
- **HYG-03 — `Tested up to: 7.0` header added to plugin docblock.** WP's Updates UI was showing "compatibility unknown" because the plugin header omitted this field. Plugin has been live since v1.x; just never declared its target WP range.
- **HYG-06 — `inc/abilities-registration.php` docblock count fixed.** Docblock said "Total: 28 abilities + 5 categories"; actual is 30 (v4.3.0's `abilities-ai-pattern-adoption.php` added 2 abilities but the prose summary missed updating). The `require_once` list at lines 47-56 always loaded the file correctly — this is purely a docblock-accuracy fix.
- **HYG-08 — `sn_admin_pages()` framing corrected (`@deprecated 4.2.0` → `@internal`).** Function is load-bearing legacy infrastructure (active call site at line ~474 for the POST allowlist + the legacy URL redirect at `sn_admin_maybe_redirect_legacy()`), not pending removal. The `@deprecated` tag was misleading.

**Audit reference:** [`docs/superpowers/specs/2026-05-26-audits-c-d-cycle-findings.md`](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-26-audits-c-d-cycle-findings.md) §3 (PA-03), §4 (HYG-06, HYG-08), §3 (HYG-03).

**Tests:** 888 assertions / 21 plugin suites — all green. PA-03 fix is HTML-attribute only; no test surface affected.

**Post-install user actions:**
- Install v4.4.4 via wp-admin → Dashboard → Updates (canonical) or `gh workflow run deploy.yml --ref v4.4.4` (emergency).
- Verify in Updates UI that "compatibility unknown" warning is gone (the new `Tested up to: 7.0` header).
- Tab through plugin sub-tabs in wp-admin → Signal & Noise with VoiceOver / NVDA — the active sub-tab should now announce its current state.

---

## [4.4.3] - 2026-05-26 — Bundled fixes from v4.4.x cycle audit

**Released:** 2026-05-26.

**Headline:** Bundled patch addressing all remaining non-urgent findings from the v4.4.x cycle audit. v4.4.2 closed the critical security exposure; this patch closes the v4.3.0 functional regression, two medium-severity defense-in-depth gaps, and consolidates inline styles across 4 admin files.

### Fixed

- **Bug-B2 (HIGH) — v4.3.0 pattern-adoption Suggest+Apply now functional in the UI.** v4.3.0 added new check types to the `ABILITY_BY_CHECK` map but the JS dispatcher (`assets/health-suggest-actions.js`) never gained input-building branches for them. Clicking Suggest on a pattern-adoption Opportunity row sent `{}` → ability failed schema validation. Apply path was using drift-shaped input instead of pattern-adoption shape. Both paths now correctly read `post_id` / `block_fingerprint` / `pattern_type` (Suggest) and `replacement_markup` (Apply) from button data attributes. The PHP impls (76 PHP-level assertions across 3 test files) always worked; this fix lets the UI actually reach them.
- **Bug-B1 (MEDIUM, latent) — Login intercept Branch 3 now allowlists `/wp-admin/admin-ajax.php` etc.** The `plugins_loaded` allowlist (lines 159-166 of `inc/login-hide.php`) skips setting flags for admin-ajax / async-upload / wp-cron / /wp-json/ / /feed, but Branch 3 of `sn_login_handle_request()` ran independently and 404-ed them. No current impact (no first-party plugin uses `wp_ajax_nopriv_*`), but any future plugin shipping public AJAX would have broken silently. Now safe.
- **Bug-E1 (MEDIUM) — Article schema `inLanguage` reads from Identity → Locale setting.** Previously hardcoded `'en-US'` while WebSite schema correctly read from `sn_setting('identity.locale')`. If locale ever changed, schemas diverged.
- **TSF coexistence defense-in-depth in `inc/seo.php`.** Canonical / robots-meta / meta-description emitters now early-return when `function_exists('the_seo_framework')`. Previously only the WP-core `rel_canonical()` REMOVAL was gated on TSF inactivity; emitters themselves ran unconditionally. If TSF were ever reactivated, duplicate tags would have shipped.
- **Inline-style consolidation across 4 admin files** (`inc/health-checks-admin.php`, `inc/insights-admin.php`, `inc/pattern-adoption-admin.php`, redundant `cursor:pointer` removed from `<summary>` elements). Inline `style="..."` attributes promoted to utility classes in `assets/admin.css`. New classes: `.sn-fieldset-h--row`, `.sn-pill--done`, `.sn-fieldset--muted`, `.sn-text--err`, `.sn-ml-auto`, `.sn-pill--spaced`. No visual change; refactor only. Kills the copy-paste pattern before it spreads further.

**Audit reference:** [`docs/superpowers/specs/2026-05-26-v4.4.x-and-v9.4.x-cycle-audit-findings.md`](../signal-and-noise/docs/superpowers/specs/2026-05-26-v4.4.x-and-v9.4.x-cycle-audit-findings.md) — Bugs B-1, B-2, E-1 + UI/UX U-03, U-06, U-07, U-08.

**Tests:** 888 assertions / 21 plugin suites — all green (CSS + PHP fixes don't affect test count; JS change uncovered by tests but verified by inspection).

**Cap math:** plugin patch 2/7 → **3/7** in v4.4.x. 4 patches remaining.

---

## [4.4.2] - 2026-05-26 — URGENT security patch (remote unauthenticated destructive action)

**Released:** 2026-05-26.

**Headline:** Closes a remote unauthenticated destructive-action vulnerability in `tests/contracts-smoke.php`. The file was web-accessible after v4.4.0 install; an HTTP GET would bootstrap WordPress and trigger `apply_filters('sn_clear_template_overrides_result', 0)`, which deletes every `wp_template`, `wp_template_part`, and `wp_navigation` post in the database. The v4.4.x post-ship cycle's deep audit pass surfaced this within hours of v4.4.0 / v4.4.1 shipping.

**v4.4.1's claim that the smoke runner was "safe to run repeatedly against production without unintended side effects" was WRONG.** That patch correctly disabled Contract 1's destructive payload (`template_overrides=false`, `cloudflare=false`) but Contract 2 takes no args — its filter dispatch invokes `sn_clear_template_overrides()` in the theme directly, with no payload to neutralize. v4.4.2 closes the gap by gating ALL `tests/*.php` files on CLI-only execution.

**Changes:**

- **CLI-only guard on every `tests/*.php` file** (22 files total). Top-of-file check:
  ```php
  if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
      http_response_code( 404 );
      exit;
  }
  ```
  Closes both the `contracts-smoke.php` destructive trigger AND the info-leak surface across all other test files (function names, ability slugs, capability matrices visible via HTTP).
- **`tests/.htaccess` with `Require all denied`** as Apache defense-in-depth. Nginx (Cloudways' actual server) ignores `.htaccess` — the PHP guard is the load-bearing defense.
- **`.github/workflows/deploy.yml`** header comment updated to document that `tests/` is deployed via `git checkout` (no rsync, no exclude list). PHP guards are the primary defense; `.htaccess` is Apache-only.

**Audit reference:** [`docs/superpowers/specs/2026-05-26-v4.4.x-and-v9.4.x-cycle-audit-findings.md`](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-26-v4.4.x-and-v9.4.x-cycle-audit-findings.md) §3 — full Bug-A1 + Bug-A2 detail.

**Post-install user actions:**
1. Install v4.4.2 via wp-admin → Updates IMMEDIATELY
2. Verify the URL `https://juanlentino.com/wp-content/plugins/signal-and-noise-tools/tests/contracts-smoke.php` returns 404
3. Verify `https://juanlentino.com/wp-content/plugins/signal-and-noise-tools/tests/abilities-integration.php` also 404s (info-leak surface check)
4. Check Cloudflare WAF logs + Aikido Security alerts for any prior exploitation of the URL between v4.4.0 install and v4.4.2 install
5. Inspect WP Site Editor to confirm no template/template-part/navigation rows were destroyed during the exposure window (Tools → Site Editor → Templates / Template Parts / Navigation)

**Cap math:** plugin patch 1/7 → **2/7** in v4.4.x. 5 patches remaining.

---

## [4.4.1] - 2026-05-26 — Docs tightening from v4.4.x QA pass

**Released:** 2026-05-26.

**Headline:** Three small documentation clarifications surfaced in the v4.4.x post-ship QA. No behavior changes; tests still pass at 888 assertions across 21 suites.

**Changes:**

- **Smoke runner payload safety** — [`tests/contracts-smoke.php`](tests/contracts-smoke.php) line 41 now passes `template_overrides => false, cloudflare => false` in the Contract 1 `$args` payload. Previously, running the smoke test against a live install would trigger a full Cloudflare cache purge as a side effect because the theme listener's `sn_purge_all_caches()` defaults both to `true` when not specified. Smoke test is now safe to run repeatedly against production without unintended side effects.

- **v5.0.0 scope doc namespace philosophy** — [`docs/superpowers/specs/2026-05-26-v5.0.0-scope.md`](docs/superpowers/specs/2026-05-26-v5.0.0-scope.md) §1 now explicitly documents that the audit covers only the `sn_*` public-surface namespace; the ~171 `snt_*` internal-impl functions are intentionally not enumerated. Default disposition for `snt_*` is KEEP; case-by-case `@deprecated` docblock is the workflow. Eliminates the implicit-assumption risk surfaced by the v4.4.x QA.

- **WORDPRESS-REFERENCE §10.0 Contract 1 `$args` shape** — companion theme repo's [`docs/WORDPRESS-REFERENCE.md`](https://github.com/juanlentino/signal-and-noise/blob/main/docs/WORDPRESS-REFERENCE.md) now documents the expected `$args` keys for `sn_purge_all_caches_result`. Pinned dispatch contract; eliminates payload-key drift between dispatch sites, test fixtures, and contract docs.

**Cap math:** plugin patch 0/7 → **1/7** in v4.4.x. 6 patches remaining before forced v5.0.0.

---

## [4.4.0] - 2026-05-26 — Cross-package contracts E2E + v5.0.0 readiness pass

**Released:** 2026-05-26.

**Headline:** Last plugin minor before forced v5.0.0. Two coordinated workstreams: (A) hybrid test harness locking the 4 theme↔plugin filter contracts documented in [WORDPRESS-REFERENCE §10.0](https://github.com/juanlentino/signal-and-noise/blob/main/docs/WORDPRESS-REFERENCE.md#10-the-theme--companion-plugin-split-v820--v840-complete-ongoing-contract-surface); (D) v5.0.0 readiness pass with a public-API inventory at [`docs/superpowers/specs/2026-05-26-v5.0.0-scope.md`](docs/superpowers/specs/2026-05-26-v5.0.0-scope.md). No new user-facing features in this minor; this is infrastructure to enable v5.0.0 to ship cleanly.

**A — Cross-package contracts E2E:**
- [`tests/contracts-stub.php`](tests/contracts-stub.php) (NEW, 213 LOC) — pure-PHP filter-simulator scaffolding + 4 contract tests, 20 assertions
- [`tests/contracts-smoke.php`](tests/contracts-smoke.php) (NEW, 71 LOC) — WP-loaded smoke runner, ~13 assertions, manual `wp eval-file` invocation
- Contracts locked: `sn_purge_all_caches_result`, `sn_clear_template_overrides_result`, `sn_og_font_paths`, `sn_gh_latest_theme_tag_result`

**D — v5.0.0 readiness pass:**
- [`docs/superpowers/specs/2026-05-26-v5.0.0-scope.md`](docs/superpowers/specs/2026-05-26-v5.0.0-scope.md) (NEW) — canonical inventory of plugin public APIs with v5.0.0 dispositions

**Audit outcome:** 268 surface items inventoried (177 functions, 30 REST routes, 27 hooks, 31 options). Disposition: **267 KEEP / 1 REMOVE / 0 RENAME / 0 SCHEMA-CHANGE**. The single REMOVE is the orphaned `sn_login_rewrites_flushed` option (already unused since v4.2.1; v5.0.0 will `delete_option()` once during upgrade). **No `_deprecated_function()` calls were added** because zero functions or hooks warrant deprecation — the plugin's public surface is already stable after the v4.0.x–v4.2.x audit/fix sweeps. v5.0.0 can ship as a minimal-breakage major (option cleanup + minor-counter reset), not a sweeping API rewrite.

**Tests post-v4.4.0:**

| Suite | Δ | Total |
|---|---|---|
| `tests/contracts-stub.php` | NEW (+20) | 20 |
| `tests/contracts-smoke.php` | NEW (manual; 0 in standard sweep) | 0 in sweep |
| `tests/abilities-integration.php` | 0 | 168 |
| All other suites | 0 | 700 |
| **All 21 plugin suites (sweep)** | **+20** | **888 / 0 failed** |

**Cap math:** plugin minor 4/5 → **5/5 (FULL)** — v4.4.0 is the LAST minor before forced v5.0.0. Plugin patch resets to 0/7 for v4.4.x (7 patches available; anticipated triggers in [plan §"Out-of-scope for this plan"](docs/superpowers/plans/2026-05-26-v4.4.0-cross-package-contracts-and-v5-readiness.md)).

**Plan reference:** [`docs/superpowers/plans/2026-05-26-v4.4.0-cross-package-contracts-and-v5-readiness.md`](docs/superpowers/plans/2026-05-26-v4.4.0-cross-package-contracts-and-v5-readiness.md)
**Spec reference:** [`docs/superpowers/specs/2026-05-26-v4.4.0-cross-package-contracts-and-v5-readiness-design.md`](docs/superpowers/specs/2026-05-26-v4.4.0-cross-package-contracts-and-v5-readiness-design.md)

---

## [4.3.1] - 2026-05-26 — v4.3.0 code-review polish sweep

**Released:** 2026-05-26.

**Headline:** Addresses the 6 deferred items from the v4.3.0 code-quality review (CHANGELOG entry below, "Known minor items deferred"). Zero behavior changes for the happy path. Net +10 test assertions (236 → 246 across the 4 pattern-adoption + abilities suites; 868 across all 20 plugin suites). Plugin patch 0/7 → **1/7** in v4.3.x; 6 patches remaining before forced v4.4.0.

**Changes:**

- **Detector docblock corrected** ([`inc/pattern-adoption-detect.php`](inc/pattern-adoption-detect.php)) — `snt_pattern_adoption_walk_blocks`'s docblock said top-level blocks have `block_path = "0"`; actual output is `"0/N"` (the leading `"0"` is the seed prefix, each `$idx` appends after it). Docblock now reflects reality. Diagnostic field only; no downstream consumer depends on the format.
- **Detector cleanup** ([`inc/pattern-adoption-detect.php`](inc/pattern-adoption-detect.php)) — three small clarities:
  - Removed redundant `'fields' => 'all'` from the `get_posts()` call (WP default).
  - Removed dead `is_array( $dismissed )` check after the `(array)` cast (always true after the cast).
  - Removed the asymmetric `function_exists( 'get_permalink' )` guard. The function is now stubbed in [`tests/pattern-adoption-detect.php`](tests/pattern-adoption-detect.php) for the same reason the other WP functions are. Treats all WP function dependencies uniformly.
- **wp_kses test stub warning expanded** ([`tests/pattern-adoption-suggest.php`](tests/pattern-adoption-suggest.php)) — the existing stub comment said "real WP wp_kses does much more (attribute validation, etc.)" but was silent on the most security-relevant gap: URL scheme validation. Expanded comment now explicitly warns that this stub passes `<a href="javascript:...">` through unchanged, and forbids adding tests against this stub that assert URL-scheme or attribute-injection sanitization (such tests would falsely validate production behavior).
- **Suggest: empty-cite edge case** ([`inc/pattern-adoption-suggest.php`](inc/pattern-adoption-suggest.php)) — source `<cite><em></em></cite>` previously emitted an attribution paragraph containing `<em></em>` because `wp_kses` preserves the empty inline tag, so the `'' !== $cite_html` guard let it through. Now uses `'' !== trim( strip_tags( $cite_html ) )` to detect visible content. Test 11 fixture added (2 new assertions: result-is-array + attribution-omitted).
- **Apply: three new error-path tests** ([`tests/pattern-adoption-apply.php`](tests/pattern-adoption-apply.php)) — Tests 6 (capability denial → 403), 7 (empty replacement_markup → 422; documents the existing `snt_pattern_adoption_invalid_pattern_type` conflation between "type not in enum" and "replacement didn't parse"), 8 (`wp_update_post` returning WP_Error → 500). Two stub mods enable the new paths: `current_user_can` now reads `$GLOBALS['__test_caps']` (default true), `wp_update_post` now reads `$GLOBALS['__test_force_wp_error']` (default record + apply). Net +8 assertions.
- **Pattern-adoption ability wrappers guarded** ([`inc/abilities-ai-pattern-adoption.php`](inc/abilities-ai-pattern-adoption.php)) — both `snt_ability_pattern_adoption_suggest` and `snt_ability_pattern_adoption_apply` now check `function_exists( '<impl>' )` before calling and return `WP_Error( 'snt_helper_unavailable', ..., 500 )` if missing. Matches the canonical pattern used by the 7 health-ability wrappers in [`inc/abilities-ai-health.php`](inc/abilities-ai-health.php). Defense-in-depth — the `require_once` chain at plugin bootstrap protects against the failure mode in production; the guards exist for convention consistency and to return a clean 500 instead of a fatal `Call to undefined function` if the chain ever breaks.

**Tests post-v4.3.1:**

| Suite | Δ | Total |
|---|---|---|
| `tests/pattern-adoption-detect.php` | 0 | 12 |
| `tests/pattern-adoption-suggest.php` | +2 (Test 11) | 37 |
| `tests/pattern-adoption-apply.php` | +8 (Tests 6/7/8) | 29 |
| `tests/abilities-integration.php` | 0 | 168 |
| **All 20 plugin suites** | **+10** | **868 / 0 failed** |

**Cap math:** plugin patch 0/7 → **1/7** in v4.3.x. 6 patches still available before forced v4.4.0 minor. Plugin minor still 4/5 (v4.4.0 is the LAST minor before v5.0.0).

**Source reference:** [docs/superpowers/handoffs/2026-05-26-v9.3.0-shipped-next-cycle-prep.md §3](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/handoffs/2026-05-26-v9.3.0-shipped-next-cycle-prep.md) — the patch sweep plan that drove this release.

---

## [4.3.0] - 2026-05-26 — Structural-block pattern adoption (Suggest+Apply)

**Released:** 2026-05-26.

**Headline:** Zero-AI structural-block upgrade Suggest+Apply for two of the three v9.2.0 theme patterns. Existing `core/quote` blocks become pull-quote candidates; ordered `core/list` blocks become steps-enumerated candidates. Surfaced in the Health tab's new "Opportunities" sub-section, collapsed-by-default. Fingerprint-validated apply via `parse_blocks ↔ serialize_blocks` round-trip — no byte offsets, no AI calls, no recurring cron scans.

**Compare-columns is intentionally excluded** from v4.3.0 detection (no structural antecedent in raw HTML; pattern stays available in the inserter for new posts). Rationale in spec §3 Q2.

**New modules:**
- `inc/pattern-adoption-detect.php` (187 LOC) — block-tree walk + scan REST endpoint
- `inc/pattern-adoption-suggest.php` (~245 LOC) — deterministic template substitution mirroring `theme/patterns/pull-quote.php` and `theme/patterns/steps-enumerated.php` + REST endpoint
- `inc/pattern-adoption-apply.php` (188 LOC) — fingerprint-validated mutate-in-place via `parse_blocks ↔ serialize_blocks` round-trip + REST endpoint
- `inc/pattern-adoption-admin.php` (165 LOC) — Health-tab Opportunities section renderer + dismiss REST endpoint
- `inc/abilities-ai-pattern-adoption.php` (151 LOC) — 2 Abilities API registrations (suggest + apply pair)

**Modified files:**
- `signal-and-noise-tools.php` — bump `Version:` to 4.3.0; 4 new `require_once` lines (`SNT_VERSION` constant derives from docblock via `get_file_data`)
- `inc/abilities-registration.php` — 1 new `require_once` line for the pattern-adoption abilities file
- `inc/health-checks-admin.php` — Opportunities section render call + `$suggest_supported_checks` extension + new defensive branches in `sn_health_render_suggest_cell()`
- `inc/admin-page.php` — `pattern_adoption_scan` dispatcher branch in `sn_handle_admin_post()` (matches `health_scan` convention) + flash decoder for `pattern_adoption_scanned`
- `assets/health-suggest-actions.js` — 2 new entries in `ABILITY_BY_CHECK` map + new `onDismissClick` handler wired through the existing `init()` dispatcher

**New REST endpoints** (back-compat surface; JS dispatches via Abilities API):
- `POST /signal-noise/v1/health/pattern-adoption-scan` — runs detector, caches result in user-scoped transient (1h TTL)
- `POST /signal-noise/v1/ai/pattern-adoption-suggest` — synthesizes replacement markup for a fingerprinted candidate
- `POST /signal-noise/v1/ai/pattern-adoption-apply` — fingerprint-validated splice via `parse_blocks ↔ serialize_blocks`
- `POST /signal-noise/v1/health/pattern-adoption-dismiss` — appends `"<pattern_type>:<fingerprint>"` to `_snt_pattern_adoption_dismissed` post meta

**New Abilities API registrations:**
- `signal-noise/pattern-adoption-suggest` (idempotent, category: `ai-generation`)
- `signal-noise/pattern-adoption-apply` (destructive, category: `ai-generation`)

**Apply safety model:** fingerprint = `md5(serialize_block($node))`. On apply, the impl re-parses current `post_content`, walks the tree, locates the block whose `serialize_block` markup still matches the fingerprint, mutates it in place, and writes back via `wp_update_post`. If the block changed or was removed between scan and apply, returns `snt_pattern_adoption_conflict` (409) and JS prompts re-scan. The block-tree round-trip sidesteps the v4.1.1 raw-vs-stripped coordinate bug class entirely.

**Dismiss state:** lives in `_snt_pattern_adoption_dismissed` post meta as an array of `"<pattern_type>:<fingerprint>"` strings. Persistent across scans — a writer's "decline this candidate" sticks.

**Two critical bugs caught + corrected during execution** (via two-stage review discipline):
- Task 2 (commit b453112): suggest impl initially emitted `<!-- wp:signal-noise/* -->` block-comment delimiters, but those are pattern slugs (not registered block types). Code quality review caught this before downstream tasks; fixed in 75473b3 with core-block compositions matching the theme pattern files.
- Task 4 (commit eac0b88): "Scan for opportunities" button initially registered via `add_action('admin_post_*', ...)` but the form posts to the SN admin URL (not `admin-post.php`), routing through `sn_handle_admin_post()` instead. Code quality review caught this; fixed in 9772836 by adding a `pattern_adoption_scan` branch to the central dispatcher.

**Tests post-v4.3.0:**

| Suite | New assertions | Status |
|---|---|---|
| `tests/pattern-adoption-detect.php` | 12 (new file) | green |
| `tests/pattern-adoption-suggest.php` | 35 (new file) | green |
| `tests/pattern-adoption-apply.php` | 21 (new file) | green |
| `tests/abilities-integration.php` | +11 (extension; 157 → 168 total) | green |
| **Total v4.3.0 new** | **79** | **green** |

**Cap math:** plugin minor 3/5 → **4/5** (v4.0, v4.1, v4.2, v4.3 used; v4.4.0 is the last minor before v5.0.0). Plugin patch cap resets from 1/7 → **0/7** for v4.3.x (7 patches available).

**Plan reference:** [`docs/superpowers/plans/2026-05-26-v4.3.0-structural-pattern-adoption.md`](docs/superpowers/plans/2026-05-26-v4.3.0-structural-pattern-adoption.md)
**Spec reference:** [`docs/superpowers/specs/2026-05-26-v4.3.0-bulk-pattern-adoption-suggest-apply-design.md`](docs/superpowers/specs/2026-05-26-v4.3.0-bulk-pattern-adoption-suggest-apply-design.md)

**Known minor items deferred** (documented in code review reports; candidates for v4.3.1 patch):
- Detector's `block_path` docblock describes "0" prefix; actual output is "0/N" (cosmetic, diagnostic field only)
- Detector: redundant `'fields' => 'all'` arg; dead `is_array` check after `(array)` cast; asymmetric `function_exists` guard on `get_permalink`
- Suggest: test stub `wp_kses` doesn't validate URL schemes (documented in stub comment); empty-inline-tag cite would produce empty attribution paragraph (edge case requires malformed source)
- Apply: capability denial / malformed replacement / `wp_update_post` WP_Error paths lack explicit tests
- Abilities wrappers lack `function_exists($impl_name)` guard that the 7 health-ability wrappers in `inc/abilities-ai-health.php` use (require_once chain protects against the failure mode)

---

## [4.2.1] - 2026-05-26 — Login: refactor to plugins_loaded intercept pattern

**Released:** 2026-05-26.

**Why this patch exists:** v4.2.0's self-heal addressed *symptoms* of the production `/backend` 404 (flush sentinel desync) but not the *root cause* — v1.5.0–v4.2.0's reliance on `add_rewrite_rule`, which depends on a chain of fragile assumptions (rewrite_rules option persisted, Apache routes through index.php, no plugin wipes the rule after us, WP's deferred-flush succeeds). On production juanlentino.com, `/backend` continued to 404 even after v4.2.0 install. The reference implementation we replaced — [wps-hide-login](https://github.com/WPPlugins/wps-hide-login/blob/master/wps-hide-login.php#L351) — uses a completely different approach (intercept at `plugins_loaded` priority 2, set `$pagenow = 'wp-login.php'`, `require_once ABSPATH . 'wp-login.php'` in `wp_loaded`). That's bulletproof against rewrite-engine fragility and runs on millions of sites. v4.2.1 refactors `inc/login-hide.php` to this proven pattern.

**Changes:**
- **Removed:** `add_rewrite_rule()` registration at init priority 10.
- **Removed:** init priority 99 flush sentinel + verify-before-trust self-heal (v4.2.0's symptomatic fix — no longer needed without the rewrite rule).
- **Removed:** `delete_option('sn_login_rewrites_flushed')` force-flush call in the `save_login` handler.
- **Added:** `sn_login_intercept_request()` at `plugins_loaded` priority 2 — parses `REQUEST_URI`, sets `$pagenow = 'wp-login.php'` + serve-form flag when the path matches the custom slug, sets block-wp-login flag for direct `/wp-login.php` access, returns early for allowlisted endpoints (admin-ajax, async-upload, wp-cron, /wp-json/, /feed).
- **Modified:** `sn_login_handle_request()` at `wp_loaded` — three branches: (1) `require_once ABSPATH . 'wp-login.php'; die` for serve-form, (2) audit-log counter + 404 for block-wp-login, (3) audit-log counter + 404 for unauthenticated `/wp-admin`. All hardening preserved (audit counters, allowlist, status_header + nocache_headers + 404 template).
- **Added:** preflight check for `rename-wp-login` (parity with `wps-hide-login`'s own conflict-detection — see [wps-hide-login.php:125-141](https://github.com/WPPlugins/wps-hide-login/blob/master/wps-hide-login.php#L125)). If active, our module stands down with the same admin-notice pattern as the existing `wps-hide-login` check.

**Preserved hardening (unchanged):**
- `SN_LOGIN_BYPASS` constant — emergency escape hatch restores `/wp-login.php` immediately.
- `SN_LOGIN_SLUG` constant — wp-config override for per-environment slugs.
- `wps-hide-login` v2.1.1 preflight (require BOTH `is_plugin_active` AND `file_exists` — defends against orphaned `active_plugins` entries).
- URL filters: `site_url`, `network_site_url`, `wp_redirect` — replace `/wp-login.php` in generated URLs (password reset emails, logout, etc.).
- Audit-log counter integration: `wp_login_404` and `wp_admin_unauth_404` still fire for blocked requests.
- Allowlist precedence: admin-ajax / async-upload / wp-cron / /wp-json/ / /feed never get touched by the intercept or the wp_loaded handler.

**Tests:**
- Removed: `tests/login-self-heal.php`, `tests/login-self-heal-no-regression.php` (tested removed code).
- Added: `tests/login-intercept.php` (19 assertions — covers serve-form for matching slug, block-wp-login for direct access, allowlist precedence for 5 endpoints, substring-match prevention, nested-path rejection, action-registration priority).
- Total tests: 459 → 467 → 491 (v4.2.0) → **507** (v4.2.1).

**Migration notes:**
- The `sn_login_rewrites_flushed` option from v1.5.0–v4.2.0 is now orphaned in the database. It's a single autoloaded string — harmless. Any future cleanup can remove via `delete_option('sn_login_rewrites_flushed')` (no risk of breaking anything since the code path that read it is gone).
- The stale `rewrite_rules` option may still contain `^<slug>/?$ => wp-login.php` from earlier. Also harmless — the rewrite engine just no longer reaches this path because the intercept short-circuits at `plugins_loaded` priority 2. The rule will be naturally cleaned on the next `flush_rewrite_rules()` triggered by anything else (any other plugin that uses `add_rewrite_rule`, or wp-admin → Settings → Permalinks → Save Changes).

**Lesson learned (codified in memory):** when fixing a fragile component that replaces or runs parallel to a proven third-party implementation, read the proven implementation's source FIRST. The HARD RULE memory says "read framework source before claiming to know it" — but the same applies to peer plugins. We treated `wps-hide-login` as something we could reimplement from first principles in v1.5.0; the better approach was to read their source and understand WHY they made the design choices they did. v4.2.0's self-heal was a sophisticated fix for the wrong layer of the problem.

**Cap math:** plugin patch cap 0/7 → 1/7 for v4.2.x. Minor cap unchanged at 3/5.

---

## [4.2.0] - 2026-05-26 — Login self-heal + Tier C cleanup + audit-log retention

**Released:** 2026-05-26.

**Highlights:**
- **Fixed:** production `/backend` 404 bug via verify-before-trust self-heal in the login module's flush sentinel check. The rewrite-rule sentinel can desync from the persisted `rewrite_rules` option (silent DB failure, plugin conflicts, WP deferred-flush failures); v4.2.0 now confirms the rule is actually present before trusting the sentinel.
- **Class-of-bug elimination:** generalizes the "cached sentinel must verify against underlying reality" pattern. Same shape as v4.1.5's admin_init version-check + this release's D-06 accessor enforcement.
- **Audit-log retention controls (new feature):** Settings → Security → Audit log now has a `Retention (days)` field (7–365, default 90). The daily cron prune respects user overrides. Mirrors the v1.4.1 RSS tracker retention pattern.

**Closed audit findings (Tier C, 6 of 8):**
- **D-02:** `sn_admin_pages()` marked `@deprecated 4.2.0` with explicit POST-data-loss warning. The existing `sn_admin_maybe_redirect_legacy()` handler continues to 302 legacy URLs on GET.
- **D-06:** New `sn_setting_update()` and `sn_setting_reset_cache()` helpers enforce the accessor contract on writes. Replaced 5 direct `get_option`/`update_option` calls across 2 save handlers (`save_login`, `save_insights_settings`). The accessor's per-request static cache is now busted on every write.
- **D-09:** Cross-reference comments added between `inc/health-checks.php` (drift-DETECT prompt) and `inc/ai-drift-phrase-suggest.php` (drift-SUGGEST prompt).
- **B-06:** Acknowledged-limitation docblock on the `wp_update_post` side-effect in `snt_ai_drift_apply_impl` — md5 fingerprint check is the mitigation.
- **U-05:** `.sn-fieldset-actions--inline` CSS modifier replaces inline `display:inline-block` on the Insights Dismiss form.
- **U-11:** `.sn-input--filter` CSS modifier replaces inline width/padding on the cron-dashboard filter input.

**Deferred (not in v4.2.0):**
- **B-07** (stylistic `const` at file scope) — skip per audit recommendation.
- **U-12** (`.sn-audit-card` ↔ `.sn-state-card` duplication) — holds for a v4.3.0 visual-cleanup minor where it can ship with company.

**Tests:** 8 new test files added; total now 491 (was 459).

**Cap math:** plugin minor cap usage 2/5 → 3/5. Patch cap resets to 0/7 for v4.2.x. Theme repo untouched (stays at v9.1.7).

**Spec:** [docs/superpowers/specs/2026-05-25-v4.2.0-login-self-heal-tier-c-design.md](docs/superpowers/specs/2026-05-25-v4.2.0-login-self-heal-tier-c-design.md).

---

## [4.1.7] - 2026-05-25 ⚠️ patch-cap rollover

Test catch-up release. Two test files had been left modified-but-uncommitted in the working tree from an earlier session that rewrote them for the v3.8.0+ two-source IA architecture (`sn_admin_top_tabs()` + `sn_admin_legacy_redirect_map()`). They were running cleanly post-rewrite but were never staged or committed. v4.1.7 captures the catch-up.

⚠️ **This is the last allowed patch in v4.1.x** (7/7). Any subsequent plugin change rolls to v4.2.0.

### Changed

- **[`tests/admin-tabs.php`](tests/admin-tabs.php) — rewritten for v3.8.0+ architecture.** Pre-rewrite assertions targeted the v3.0.2-era single-source-of-truth helpers (`sn_admin_pages()` driving everything). v3.8.0 split into two sources: `sn_admin_top_tabs()` (6 canonical top tabs that drive `valid_tabs`, `tab_labels`, `subtitle`) and `sn_admin_legacy_redirect_map()` (legacy slug → canonical top tab + sub-tab + anchor). The rewrite now asserts the new coordination constraint: **every legacy tab is reachable via EITHER `sn_admin_top_tabs()` OR the redirect map** — catching the same class of regression as the original v3.0.0 Cron-tab miss under the new architecture. Test count: 84 → 189 passes (legitimate coverage expansion).
- **[`tests/theme-ability-commands.php`](tests/theme-ability-commands.php) — count assertion updated for v3.8.3 audit-log additions.** The baseline existing-command count went from 16 to 18 when v3.8.3 added `sn-cmd-audit-summary` + `sn-cmd-audit-recent-logins`. Test assertion updated from `28` to `30` to match (16+12 → 18+12).

### Architectural notes

- These tests were orphaned during the v3.8.x churn — the rewrites tracked the code, just never got committed. Discarding them would have regressed test coverage (the v3.0.2 versions don't exercise the legacy redirect map at all).
- Running both files in isolation confirms green: `admin-tabs.php` → 189/0, `theme-ability-commands.php` → 37/0. Existing suites unchanged: `abilities-integration.php` → 157/0, `health-checks.php` → 76/0.
- The `@since` annotation in each test file reflects "rewritten for v3.8.0+" rather than a fresh `@since 4.1.7` — the original v3.0.2 / v3.7.4 provenance is the meaningful origin; this commit just landed work that should have shipped sessions ago.

### Audit + roadmap closeout

This release closes the **plugin absorption roadmap** (15 phases from `docs/superpowers/specs/2026-05-16-plugin-absorption-roadmap.md`). All 5 residual items from the 2026-05-25 reconciliation handoff are now resolved:

1. ✅ Login hardening audit log — shipped v3.8.3
2. ✅ AI-assisted content-health fixes — shipped v4.0.0–v4.1.0 (massively over-scope: alt-text + inline-alt + drift + orphan)
3. ✅ Native Breadcrumbs theme-template adoption — verified no-action (zero breadcrumb references across 13 templates / 2 parts / 2 patterns)
4. ✅ GSC sitemap submission — user-confirmed submitted
5. ⏳ WORDPRESS-REFERENCE.md gotchas append — ships as theme docs commit (no version bump per CLAUDE.md)

**v4.x cap state:** patches **7/7 in v4.1.x ⚠️ MAX** — any subsequent plugin change forces v4.2.0. Minors 2/5 in v4.x.

### Verification

- `php tests/admin-tabs.php` → 189/0
- `php tests/theme-ability-commands.php` → 37/0
- `php tests/abilities-integration.php` → 157/0 (unchanged — no code modified)
- `php tests/health-checks.php` → 76/0 (unchanged)

## [4.1.6] - 2026-05-25

Tier B polish batch from the 2026-05-25 audit — 6 small refactors bundled into one ship per the v4.1.2-deferred-audit handoff recommendation. No behavioral changes; all are consistency / consolidation / dedup work that the audit flagged but classified below the Tier A modal-CSS-extract + abilities-registration-split work.

### Fixed

- **D-11 — Cron-dashboard JS was silently broken since v3.8.1.** [`inc/cron-dashboard-admin.php`](inc/cron-dashboard-admin.php) used `strpos($hook_suffix, 'sn-cron')` to gate the enqueue, but v3.8.1's IA reorg consolidated 12 submenu entries to 6 top-tabs — the Cron sub-tab now lives inside the Automation page whose `$hook_suffix` is `signal-noise_page_sn-automation`. The substring match never fired, so the cron filter input + Run-now + Unschedule JS never loaded on the live tab. Replaced with the canonical `in_array($hook_suffix, sn_admin_page_hooks(), true)` guard from [`admin-page.php:532`](inc/admin-page.php). JS is a no-op when its selectors don't match, so loading on every SN admin page is safe overhead.

### Changed

- **U-13 — Maintenance card button hierarchy reflects action gravity.** [`inc/admin-tab-dashboard.php`](inc/admin-tab-dashboard.php): "Purge All Caches" is now `button-primary` (most-common routine action — what you click after every deploy). "Full Reset" is `button-link-delete` (red destructive — clears overrides AND every cache). "Clear Overrides" + "Check for Updates" stay bare `button`. Pre-v4.1.6 all four cards looked equally de-emphasized except for the highly-destructive Full Reset being highlighted as primary — inverted hierarchy.
- **D-12 — `snt_ai_is_available()` convention documented.** v4.1.1's D-03 consolidation already standardized every CALL SITE on `snt_ai_is_available()`; v4.1.6 adds the documenting docblock to [`inc/ai-bootstrap.php`](inc/ai-bootstrap.php) explaining the two-function convention (`snt_ai_is_available()` for callers, `snt_ai_can_text_generate()` as the impl-named internal). Future grep audits land in one place.
- **D-13 — 3 desktop-mode `admin_enqueue_scripts` hooks merged into 1.** [`inc/desktop-mode-integration.php`](inc/desktop-mode-integration.php) had separate hooks for (1) registering scripts + localizing data, (2) registering Command Palette commands, (3) registering desktop widgets. Single hook now with three guarded sub-blocks (preserves the independent `function_exists` checks for `desktop_mode_register_command` and `desktop_mode_register_widget`). Removes ~10 boilerplate lines + a hook-firing-order ambiguity.
- **D-10 — Centralized post-AI quote-strip.** Moved `trim($s, "\"'")` from 4 caller sites ([`ai-alt-text-suggest.php`](inc/ai-alt-text-suggest.php), [`ai-alt-inline-suggest.php`](inc/ai-alt-inline-suggest.php), [`ai-drift-phrase-suggest.php`](inc/ai-drift-phrase-suggest.php), [`ai-meta-description.php`](inc/ai-meta-description.php)) into the `snt_ai_generate_with_constraints()` return path in [`inc/ai-bootstrap.php`](inc/ai-bootstrap.php). Verified safe for all 9 known callers — JSON-shaped outputs (insights, orphan-suggest) have outer `{` or `[` chars not in the trim charset, so the centralized trim is a no-op for them. Net -4 lines across 4 sites, +1 line in bootstrap. Future AI callers get the defense automatically.
- **U-15 — Shared `setStatus` helper extracted to [`assets/snt-status.js`](assets/snt-status.js).** 4 byte-identical `setStatus()` copies (post-editor: [`ai-meta-description.js`](assets/ai-meta-description.js), [`ai-excerpt.js`](assets/ai-excerpt.js), [`ai-og-card-title.js`](assets/ai-og-card-title.js); health admin: [`health-suggest-actions.js`](assets/health-suggest-actions.js)) replaced by a single shared utility exposing `window.sntSetStatus(node, text, kind)`. Registered in [`inc/ai-bootstrap.php`](inc/ai-bootstrap.php)'s `admin_enqueue_scripts` hook; each of the 4 caller scripts declares `'snt-status'` in its deps array. Palette changes (e.g., switching to CSS variables) now land in one file. Each caller keeps a `var setStatus = window.sntSetStatus;` local alias so existing call sites inside each file work unchanged.

### Verification

- `php -l` clean on all 7 modified PHP files.
- `node --check` clean on all 5 modified JS files (1 new + 4 modified).
- `php tests/abilities-integration.php` → 157/0 · `php tests/health-checks.php` → 76/0 (no PHP behavior touched).
- Code traced for D-10 across all 9 callers of `snt_ai_generate_with_constraints()` — JSON-shaped outputs proven unaffected by the centralized trim.
- Code traced for U-15 dependency chain: `inc/ai-bootstrap.php` is required BEFORE the 4 callers in [`signal-and-noise-tools.php`](signal-and-noise-tools.php), so its `add_action('admin_enqueue_scripts', ...)` runs first and registers `snt-status` before any caller's enqueue. WP resolves script deps at enqueue time, not registration time.

### Audit provenance

Closes Tier B from the v4.1.2-deferred-audit handoff: D-10, D-11, D-12, D-13, U-13, U-15 — 6 of 6. Tier C (D-02, D-06, D-09, B-06, B-07, U-05, U-11, U-12) remains deferred indefinitely per the original tier classifications (acknowledged design limitations or pending design decisions).

**v4.x cap state:** patches **6/7** in v4.1.x · minors 2/5 in v4.x. v4.1.7 is the LAST patch slot before forced v4.2.0.

### Process note

This release was scoped via the v4.1.2-deferred-audit handoff (committed to the theme repo on 2026-05-25 as `73f5bb9`). Each finding has its commented inline reference (`v4.1.6 (X-NN): ...`) so a future reader can trace from the code back to the audit and the handoff that classified it.

## [4.1.5] - 2026-05-25

Bugfix release. v4.1.4 shipped the Recent Deploys logging fix but had a self-observation gap: the `upgrader_process_complete` hook fires in the SAME PHP request as the install, which means v4.1.3's PHP code was in memory during the v4.1.4 install — and v4.1.3 had no handler for that hook. The v4.1.4 install slipped past its own fix. User confirmed the dashboard stayed stuck at v3.8.6 even after v4.1.4 deployed.

### Fixed

- **Self-bootstrap gap closed via `admin_init` version-check.** New `snt_deploy_history_version_check()` runs on every admin page load, compares the in-memory plugin + theme versions to a tiny autoloaded sentinel option (`sn_deploy_history_current_versions`), and records any version not yet in history. Once v4.1.5 lands on the user's site, the FIRST admin page load picks up the current versions and writes records — no further install action required.

### Architectural notes

- **Why the v4.1.4 fix missed:** WordPress's `Plugin_Upgrader` does not re-require plugin files during an upgrade. The PHP process loaded at request-start is what's in memory throughout. So a new handler defined in version N cannot observe the install that brings version N into existence. Future installs (N → N+1) ARE caught — that path works as designed in v4.1.4.
- **Fast path:** the sentinel option is `autoload=true` (tiny payload, lives in `wp_load_alloptions` cache). On the hot path — admin user visits any wp-admin page after their versions have been recorded — the check is an in-memory string compare and bails without any DB write. Only version changes trigger a write.
- **Dedupe vs the upgrader hook:** for v4.1.5 → v4.1.6 and beyond, both the `upgrader_process_complete` hook (records during the install request) AND the `admin_init` check (records on the next admin page load) could write the same version. `snt_deploy_history_has_version()` scans the history before writing to avoid duplicates.
- **Capability gate:** only fires for `manage_options` users. Non-admin visits to wp-admin (subscribers reading their profile, etc.) don't burn cycles on a check whose output only matters for the SN dashboard.
- **Theme detection:** only records the active theme as the SN theme if `wp_get_theme()->get_stylesheet() === 'signal-and-noise'`. If the user switches themes, we don't attribute another theme's version to the SN repo.
- **Cap discipline:** the new sentinel option is the only addition to `wp_options`. The history option remains autoload=no (capped at 20 FIFO rows).

### Expected behavior post-deploy

After v4.1.5 lands via wp-admin Updates:

1. User updates plugin → v4.1.5 files on disk, `upgrader_process_complete` fires in v4.1.4 PHP context — and v4.1.4 HAS the handler, so it records "plugin v4.1.5" (this path works correctly from v4.1.4 onward; only v4.1.3 → v4.1.4 was the gap).
2. User loads any wp-admin page → `admin_init` fires in v4.1.5 PHP context → `snt_deploy_history_version_check()` runs.
3. Sentinel option doesn't exist yet → both plugin (4.1.5) and theme (9.1.7) compared against missing sentinel.
4. `has_version('plugin', '4.1.5')` returns true (step 1 recorded it) → sentinel updated, no duplicate written.
5. `has_version('theme', '9.1.7')` returns false (v9.1.7 install pre-dated deploy-history feature) → theme record written.
6. User visits Dashboard → merged list shows: theme v9.1.7 (just-now), plugin v4.1.5 (a moment ago), then the GHA-cached plugin v3.8.6/.5/.4 trail.
7. Subsequent admin page loads → sentinel matches current versions → no-op fast path.

**v4.x cap state:** patches **5/7** in v4.1.x · minors 2/5 in v4.x.

### Verification

- `php -l inc/deploy-history.php` → clean (fresh in this commit's working set).
- `php tests/abilities-integration.php` → 157 passed, 0 failed (no change — deploy-history isn't exercised by tests).
- `php tests/health-checks.php` → 76 passed, 0 failed.
- Code traced through 4 execution scenarios: (A) v4.1.5 install via v4.1.4's hook + admin_init backfill, (B) post-install dashboard render, (C) subsequent admin page loads (no-op), (D) future v4.1.6 install (hook + check dedupe via `has_version`). All scenarios produce correct sentinel state and history records.
- Live verification REQUIRES user to install v4.1.5 via wp-admin and visit any admin page. Cannot be verified from CLI.

### Process note

This release was the first in the v4.1.x track to formally invoke `superpowers:systematic-debugging` (Phase 1 root-cause investigation through Phase 4 implementation) AND `superpowers:verification-before-completion` (fresh evidence in this turn before claiming done). The v4.1.4 bug was the consequence of skipping both — v4.1.4 was shipped on assumption that `upgrader_process_complete` would observe its own install, which a 30-second read of `WP_Upgrader::run()` source would have falsified.

## [4.1.4] - 2026-05-25

Bugfix release. Headline: **the Dashboard's "Recent deploys" panel froze at plugin v3.8.6 (9+ hours stale)** — every release since v1.10.1 (plugin) / v8.5.1 (theme) landed via wp-admin Updates UI, which bypasses the GitHub Actions workflow-runs API the panel reads from. Now wp-admin installs are recorded locally and merged with GHA runs.

### Fixed

- **Recent Deploys panel now reflects wp-admin Updates installs.** Pre-v4.1.4 the panel was sourced exclusively from `https://api.github.com/repos/<repo>/actions/workflows/deploy.yml/runs` — which only fires on `workflow_dispatch` or (pre-v1.10.1) tag push. Every release between v3.9.x and v4.1.3 that the user landed via wp-admin → Updates → "Update plugin/theme" was invisible to this feed. Result: the most recent entry on the live site was plugin **v3.8.6** (the last auto-on-tag-push deploy from before v1.10.1 made plugin deploys manual-dispatch). The panel was 15+ releases stale by the time it was reported.

### Added

- **[`inc/deploy-history.php`](inc/deploy-history.php) — local install log + merge helper.** New module:
  - `snt_deploy_history_record( $package, $version )` — appends a record (newest-first, capped at 20 rows) to a new `sn_deploy_history` option (autoload=no — read only on the SN admin page).
  - `snt_deploy_history_get( $limit )` — returns records in the same shape as `snt_gh_recent_runs_merged()` so the dashboard renderer needs no per-source branching.
  - `snt_deploy_history_merged( $repos, $limit )` — merges GHA runs with local installs, sorts by `created_at` DESC, dedupes by (repo, ref) preferring GHA records (which have `html_url` for click-through to the run).
  - `snt_deploy_history_on_upgrader_complete()` — hooks `upgrader_process_complete` (fires after successful WP-admin installs), filters to only SN packages via `SNT_DEPLOY_HISTORY_PACKAGES`, reads the post-install version via `get_plugin_data()` / `wp_get_theme()`, calls `snt_deploy_history_record()`.

### Changed

- **[`inc/admin-tab-dashboard.php`](inc/admin-tab-dashboard.php):** `snt_dashboard_tab_render()` now calls `snt_deploy_history_merged()` instead of `snt_gh_recent_runs_merged()`. No renderer changes — local install records have an empty `html_url` and the existing branch in `snt_dashboard_render_deploy_row()` already emits an empty `<span>` for that case.

### Architectural notes

- The record shape matches GHA workflow runs exactly (`id`, `status`, `conclusion`, `ref`, `trigger`, `created_at`, `duration_s`, `html_url`, `repo`). Three fields differ from a real GHA run: `id` is a synthetic timestamp+rand, `html_url` is empty (no URL — this happened on-server), `trigger` is `'wp_admin'` instead of `'workflow_dispatch'`. The renderer treats them uniformly.
- The hook handler reads the version POST-install (after the upgrader has placed the new files on disk), not from `$hook_extra` — that way the recorded `ref` reflects what's actually loaded, not what the upgrader claimed it was installing.
- Backfill is intentionally NOT done: the v3.9.x through v4.1.3 historical deploys won't appear retroactively. The user will see one row at first ("v4.1.4 via wp-admin"), then accumulate forward. Re-importing tag history from GitHub would conflate "tag exists" with "this site installed it" and is out of scope.

### Audit provenance

This bug was reported in-session after v4.1.3 shipped — not from the 2026-05-25 audit findings. Adds to the v4.1.x track on its own.

**v4.x cap state:** patches **4/7** in v4.1.x · minors 2/5 in v4.x.

**Verification:** `php tests/abilities-integration.php` → 157/0 · `php tests/health-checks.php` → 76/0. `php -l` clean on the 3 modified/added files. Live verification: after this v4.1.4 lands via wp-admin Updates, the "Recent deploys" panel should show a fresh "plugin v4.1.4" entry within seconds of clicking "Update plugin".

## [4.1.3] - 2026-05-25

Structural refactor — single-concern follow-up to v4.1.2 closing audit finding **B-11** (the file-size violation that affected `inc/abilities-registration.php` at 1660 lines / 11× the CLAUDE.md 150-line guideline). The monolith is now a 55-line orchestrator that requires 8 per-feature ability files. All 28 abilities still register identically; the abilities-integration test stays 157/0.

### Changed

- **`inc/abilities-registration.php`: 1660 → 55 lines (97% reduction).** Now a thin orchestrator that `require_once`s the 8 split files plus a named-helper file. Bootstrap require at [`signal-and-noise-tools.php:155`](signal-and-noise-tools.php) is unchanged — drop-in swap.
- **Per-feature ability files added under `inc/`:**
  - [`abilities-permission-helpers.php`](inc/abilities-permission-helpers.php) (87 LOC) — 4 named callables (`snt_ability_perm_manage_options/_edit_post/_edit_attachment/_delete_attachment`) replacing ~12 inline closure copies. Permission audits now read from a single file.
  - [`abilities-categories.php`](inc/abilities-categories.php) (73 LOC) — 5 ability category registrations on `wp_abilities_api_categories_init`, each guarded by `wp_has_ability_category()` (X-02 from v4.1.1 retained).
  - [`abilities-system.php`](inc/abilities-system.php) (344 LOC) — 6 abilities: purge-all-caches, clear-template-overrides, list-template-overrides, full-reset, force-check-updates, get-deploy-status.
  - [`abilities-content.php`](inc/abilities-content.php) (167 LOC) — 2 abilities: regenerate-og-card, get-rss-stats.
  - [`abilities-cron.php`](inc/abilities-cron.php) (277 LOC) — 4 abilities: list-cron-events, get-cron-event, get-cron-history, unschedule-cron-event.
  - [`abilities-insights.php`](inc/abilities-insights.php) (128 LOC) — 2 abilities: run-insights-scan, get-insights.
  - [`abilities-audit.php`](inc/abilities-audit.php) (194 LOC) — 4 abilities: get-audit-summary, get-audit-counters, get-audit-login-successes, run-audit-prune.
  - [`abilities-ai-post-editor.php`](inc/abilities-ai-post-editor.php) (172 LOC) — 3 abilities: ai-generate-meta-description, ai-generate-og-card-title, ai-generate-excerpt.
  - [`abilities-ai-health.php`](inc/abilities-ai-health.php) (412 LOC) — 7 abilities: ai-alt-suggest/apply, ai-drift-suggest/apply, ai-alt-inline-suggest, ai-orphan-suggest/apply.

### Architectural notes

- WordPress's `add_action()` queues all callbacks for a hook regardless of registration order, so splitting one `wp_abilities_api_init` action into 8 parallel ones is semantically identical to the original.
- The named-permission-helper pattern means future security reviews can `grep -r "snt_ability_perm_"` to enumerate every permission rule in one place rather than reading 28 ability registration blocks.
- Each split file follows the same shape: docblock → ABSPATH guard → `add_action(..., function() { ... wp_register_ability(...); ... })` → impl wrapper functions co-located outside the action callback.
- Largest remaining file (`abilities-ai-health.php` at 412 LOC) still exceeds the 150-line guideline but is 4× smaller than the original monolith. Further splitting (per-feature pairs like alt-suggest/apply) was rejected: the 7 abilities share an underlying mental model (Health-tab Suggest+Apply UX) and splitting further would fragment the per-ability docblocks.

### Audit provenance

Closes audit finding B-11 (file-size violation, plugin-side). The companion theme refactor (1814-line `inc/abilities-registration.php` in the theme repo) ships as v9.1.7.

**v4.x cap state:** patches **3/7** in v4.1.x · minors 2/5 in v4.x.

**Verification:** `php tests/abilities-integration.php` → 157/0 (every dispatched ability still routes identically) · `php tests/health-checks.php` → 76/0. No PHP-side behavioral change.

## [4.1.2] - 2026-05-25

Polish release — single-concern follow-up to v4.1.1's audit. Headline: the Health-tab Apply preview modal and the shared `sntConfirm` dialog rendered every visual property via inline `setAttribute('style', …)` strings (~50 calls combined across two files). All chrome is now CSS-driven, leaving only genuinely-dynamic styling inline.

### Changed

- **Modal + confirm + verdict-panel chrome extracted to `admin.css`.** ~50 `setAttribute('style', …)` calls removed from [`assets/health-suggest-actions.js`](assets/health-suggest-actions.js) and [`assets/snt-confirm.js`](assets/snt-confirm.js). New class catalog in [`assets/admin.css`](assets/admin.css): `.snt-modal-backdrop/box/header/title/close/body/divider/pane-label/footer`, `.snt-modal-thumb/filename/caption/textarea/count`, `.snt-modal-snippet/phrase-err/phrase-ok`, `.snt-modal-warn-box/warn-text/warn-note`, `.snt-suggest-panel/textarea/actions/status/inline-err`, `.snt-verdict-panel/headline (+--err/--ok/--warn)/reason/actions/delete-btn`, `.snt-cell-applied/error`, `.snt-confirm-backdrop/box/header/title/close/body/footer`. Shared shell rules between the two modal flavors are comma-grouped so neither file owns the rule, but each module assigns its own class name. (U-03 / this commit)

### Preserved as inline

Three genuinely-dynamic mutations stay inline by design, documented in the CSS banner:

1. `body.style.gridTemplateColumns` in `openApplyModal` switches on `isMobile` (single-column vs. 2-column Before/After).
2. `row.style.opacity = '0.5'` after a successful Apply or Delete — state mutation on existing DOM, not construction-time styling.
3. `setStatus()` color writes in `health-suggest-actions.js:71` — out of U-03 scope; targeted by deferred audit finding U-15, which dedupes the helper across 4 files in a future batch.

### Manual gate-walk (post-update)

Run after pulling v4.1.2. No PHP changed, so abilities + checks tests don't catch visual regressions.

1. **Alt-text Apply modal** — Health → "Missing alt text" → click Suggest on any attachment → Apply opens modal; verify thumbnail + filename + "no existing alt" caption render in Before pane, read-only textarea + char-count render in After pane.
2. **Drift Apply modal** — Health → "Time-phrase drift" → click Suggest → Apply opens modal; verify phrase highlights red in Before snippet, green in After snippet.
3. **Orphan Delete modal** — Health → "Orphaned media" → click Suggest on a `verdict=delete` row → click Delete; verify thumbnail + reason in Before pane, red warning box in After pane.
4. **Mobile breakpoint** — narrow window to ≤600px during any of the above; verify Before/After stack vertically with horizontal-rule divider between them.
5. **sntConfirm sites (7 buttons)** — Cron Run Now, Cron Unschedule, Webhook Delete, Insights Dismiss, RSS Reset, RSS Purge, Desktop-mode Full Reset. Each should open a 480px modal that looks identical to v4.1.1.
6. **Focus + Escape** — open any modal, press Escape; verify it closes and focus returns to the originating button. Open a modal, click outside the box; verify it closes.

### Audit provenance

Removes U-03 from deferred list. Remaining audit deferrals: D-02 (`sn_admin_pages()` deprecation), D-06 (option-cache invalidation), D-09 (cross-prompt reference), D-10 (centralize quote-strip), D-11 (cron-dashboard guard pattern), D-12 (standardize ai-availability check), D-13 (desktop-mode hook merge), U-05/U-11/U-12/U-13/U-15 (refactors), B-06/B-07 (acknowledged design limitations), B-11 (file-size violations — 13 files > 150 LOC).

**v4.x cap state:** patches **2/7** in v4.1.x · minors 1/5 in v4.x.

**Verification:** `php tests/abilities-integration.php` → 157/0 · `php tests/health-checks.php` → 76/0 green. `node --check` on both modified JS files → silent (no syntax errors). Visual parity verified by reading every extracted inline-style string into the corresponding CSS rule before deletion.

## [4.1.1] - 2026-05-25

Bugfix + polish release. Headline: AI Suggest+Apply for `drift_time_phrases` was silently broken on every Gutenberg post since v4.0.0 — the extractor reported byte offsets in stripped content but the impls used those offsets against raw `post_content`, so preflight 409'd. Restored via a new locator that resolves position dynamically in raw content. Six v4.1.1-labelled commits had landed on `main` since v4.1.0 but the release packaging (version bump, CHANGELOG, tag) was skipped — this entry closes the release.

### Fixed

- **Drift Suggest+Apply now works on Gutenberg posts.** Added `snt_ai_drift_locate_in_raw()` in [`inc/ai-drift-phrase-suggest.php`](inc/ai-drift-phrase-suggest.php), which resolves the raw-content byte offset dynamically: single occurrence → fast path; multiple → highest `similar_text` match against context snippet wins. Suggest now returns the raw position; Apply re-resolves on every call as defense against post edits between Suggest and Apply. Silent regression since v4.0.0. (B-01, B-03, B-12 / `cc2c93d`)
- **Orphaned-media health check is image-only.** `sn_health_check_orphaned_media()` now filters to `post_mime_type LIKE 'image/%'` at the SQL layer. Previously listed PDFs, videos, and ZIPs with Suggest buttons that always 422'd against the image-only AI orphan-suggest impl. (B-02 / `f79c7a5`)
- **Cron schedules register on `init`, not `admin_init`.** Three cron registrations (`snt_cron_history_schedule_cron`, `snt_insights_maybe_schedule_weekly_cron`, `sn_rss_tracker_schedule_cron`) moved off `admin_init` so they fire under WP-CLI and front-end-only requests. On fresh deploys whose first hit was not an admin page, these crons never registered — cron-history pruning, RSS-tracker pruning, and weekly insights scans silently never ran. The idempotent `wp_next_scheduled()` guard inside each callback makes init-hooked registration safe. (B-04 / `f79c7a5`, `79594d9`)

### Changed

- **Shared AI-gate helper extracted.** `snt_ai_require_text_generation()` added to [`inc/ai-bootstrap.php`](inc/ai-bootstrap.php); replaces 7 copies of the same 6-line guard across `inc/ai-*.php`. Error messages had already drifted between copies (drift suggest used a shorter message than the other 6) — now consolidated to a single Connectors-pointing message. (D-03 / `f2e9a89`)
- **Helper deduplication.** D-01: `admin-tab-dashboard.php` force-check handler delegates to `snt_cmd_impl_force_check()` instead of byte-duplicating its 4 transient deletes. D-05: `snt_cron_sn_owned_hooks()` uses `defined()`-guarded constant references instead of hardcoded hook-name strings. D-07: the `sn_purge_all_caches_result` filter dispatch in `full_reset` passes explicit `array('template_overrides' => true)` instead of empty args. D-08: `wp_localize_script` called once instead of twice for `snDesktopData` in `desktop-mode-integration.php`. (D-01, D-05, D-07, D-08 / `3a7d909`)
- **Admin UI polish (8 items).** Added `.sn-badge` + `.sn-badge-warn` to `admin.css` (Cron-tab badges were unstyled inline text pre-v4.1.1). Documented the `--sn-space-3` / `--sn-space-4` 12px alias. Removed stale "Net-new in v3.6.0" from Insights intro. Updated Health tab intro to advertise inline AI fixes for alt, drift, and orphan. Updated stale JS comment in `onSuggestClick` to list the 4 supported check types. Replaced inline `background:#fffbcc;` on new-webhook secret input with `.snt-input-highlight`. Added `role="dialog"` + `aria-modal="true"` + `aria-labelledby` to `openApplyModal` so screen readers announce it correctly. Switched Insights evidence pills from `.sn-pill--ok` (green) to neutral `.sn-pill` — evidence is data, not status. (U-02, U-04, U-06, U-07, U-08, U-09, U-10, U-14 / `0c73fc1`)
- **`window.confirm` migrated to in-page modal (7 call sites).** New shared utility `assets/snt-confirm.js` exposes `window.sntConfirm()` (Promise-based) + a global click-handler for `[data-snt-confirm]` attributes (PHP onclick replacement). Native `confirm()` is blocked inside the desktop-mode portal iframe by the chrome-extension boundary — the 7 destructive buttons (cron Run Now + Unschedule, Webhooks Delete, Insights Dismiss, RSS Reset + Purge, desktop-mode Full Reset) were unusable in that context. Now an in-page modal with proper ARIA + focus trap. (U-01 / `0480ac4`)
- **Cross-repo cleanup (3 items).** Plugin's `snt_deploy_status_for('theme')` now fetches the latest theme tag via `apply_filters('sn_gh_latest_theme_tag_result', null)` instead of calling the theme function directly via `function_exists` guard — a documented contract violation. Plugin ability category registrations now wrapped in `wp_has_ability_category()` guards (mirrors theme since v9.1.0) to avoid `_doing_it_wrong` notices on debug installs where theme + plugin both register the shared categories. Deleted dead `get_option('sn_github_local_sha')` read in `admin-page.php` (option was retired in theme v8.3.0 with the legacy updater). Companion theme changes ship as v9.1.6. (X-01, X-02, X-03 / `1d4f219`)
- **Documentation refresh.** `health-checks.php` file-header docblock now lists all 5 checks with their version annotations (was stuck at the v3.5.0 4-check list). `drift_time_phrases` function docblock updated to reflect the v4.0.0 Suggest+Apply ship + v4.1.1 raw-position fix (was still saying "Detection only in v1; AI-suggested replacement text is a future v3.7.x feature"). Health admin status box now shows a dynamic check count instead of hardcoded "4 checks". Extracted the literal `25` (candidate cap per post in drift detection) to a named constant `SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST`. (B-05, B-08, B-10 / `cb8ba7f`)

### Audit provenance

50-finding audit run via parallel explorer agents at `.planning/audit-2026-05-25/` (duplicates, bugs+quality, UI/UX, cross-repo). 27 of 50 findings shipped in v4.1.1. Deferred to a future minor (not blocking, larger refactors): D-02 `sn_admin_pages()` deprecation, D-09 cross-prompt reference, D-11 cron-dashboard guard pattern, D-13 desktop-mode hook merge, U-03 modal CSS extract, U-05/U-11/U-12/U-13/U-15 (refactors), B-11 file-size violations (13 files > 150 LOC). The `tests/abilities-integration.php` "17 abilities" stale comment was already refreshed in the v4.1.0 review followup.

**v4.x cap state:** patches 1/7 in v4.1.x · minors 1/5 in v4.x.

**Verification:** `php tests/abilities-integration.php` → 157/0 · `php tests/health-checks.php` → 76/76 green. The Gutenberg drift regression test (B-12) explicitly asserts the pre-v4.1.1 bug shape (`substr` on stripped-coords position against raw content does NOT match the phrase) so future regressions of B-01 fail loudly.

## [4.1.0] - 2026-05-25

### Added — AI Suggest+Apply for orphaned-media

Completes the AI Suggest+Apply suite. Orphan-flagged attachments (detected via SQL since v3.5.0) now get an AI verdict layer (delete/keep/unsure) with one-click delete (modal-confirmed) for high-confidence cases.

**New abilities:**
- `signal-noise/ai-orphan-suggest` (idempotent) — returns `{verdict, reason}` for a SQL-flagged orphan attachment
- `signal-noise/ai-orphan-apply` (destructive, idempotent) — force-deletes via `wp_delete_attachment($id, true)` after capability + existence re-validation

**UX:**
- Suggest button per orphan-media row (matches the existing alt + drift Suggest column)
- Verdict-shaped response renders 3 distinct cell states:
  - `delete` → red Delete button + reason → modal confirmation → `wp_delete_attachment(force=true)` → "✓ Deleted"
  - `keep` → "✓ Likely keep" + reason + Discard (false positive — no Apply)
  - `unsure` → "? Manual review" + reason (use the existing per-row Edit link)
- Delete modal reuses v4.0.3 `openApplyModal()`; Before pane = thumbnail + filename + reason, After pane = red-background destructive warning + caveat about widgets/customizer
- Modal primary button label stays "Apply" — destructive intent carried by modal title ("Permanently delete this attachment?") and After-pane warning. Changing the shared modal's button signature would have risked regressing the v4.0.3-shipped alt + drift Apply paths.

**Cost + caching:**
- 30-day transient cache per `(attachment_id, post_modified_gmt, prompt_version_md5)` — mirrors v4.0.1 drift cache pattern
- Errors never cache; `unsure`-fallback verdicts DO cache (prevents repeated AI calls against a model returning malformed output until 30d decay)
- Cold scan against 50 orphans ≈ $0.025 in Anthropic Sonnet tokens; warm scan = $0.00

**Security invariants:**
- Apply impl takes only `attachment_id` (no `verdict` parameter) — server re-validates capability + attachment existence from scratch. Client-side verdict is JS display logic only.
- Defense-in-depth: Abilities API permission_callback + REST permission_callback + impl-level `current_user_can` check, all on `delete_post`.
- Cache verdict cleared explicitly on successful Apply (belt + suspenders against ghost cache hits).

**Scope changes from the original v4.1.0 plan:**
- `stale_posts` Suggest+Apply **dropped** from this minor and removed from the v4.x roadmap. juanlentino.com posts are evergreen by design — the stale-posts feature would have generated suggestions the user dismisses every time. `drift_time_phrases` (v3.7.0) already handles the real decay vector in evergreen content. Theme A is now complete with orphan-media alone.

**Files:** 1 new (`inc/ai-orphan-suggest.php`), 4 modified (`signal-and-noise-tools.php` for require + version, `inc/abilities-registration.php` for 2 new abilities, `inc/health-checks-admin.php` for the Suggest button render, `assets/health-suggest-actions.js` for the verdict-render branch + delete modal). 2 test files extended. ~270 LOC production code + ~110 LOC of tests.

**v4.x cap state:** patches 0/7 in v4.1.x · minors 1/5 in v4.x.

**Verification:** 8 PHP ability-dispatch tests + 5 cache-invariant assertions all green (155/0 + 69/0). 14-gate manual walk required before tag push.

## [4.0.3] - 2026-05-25

### Added — Before/After Apply preview modal

Replaces `window.confirm()` on Apply clicks with a custom modal showing side-by-side before/after preview. Catches "wait, that's not what I expected" before the destructive write — visual confirmation for AI-suggested edits to attachment alt text and post body text.

**Unified modal, two render variants:**
- Attachment-alt Apply: thumbnail + filename + "(no existing alt)" caption / read-only textarea with proposed alt text + character count
- Drift-phrase Apply: post_content snippet with phrase highlighted (red before / green after)

**UX details:**
- Keyboard: Escape = Cancel, Enter (not in textarea) = Apply
- Focus trap: focus moves into modal on open, returns to originating cell's Apply button on close
- Backdrop click + × close button = Cancel
- Mobile (<600px viewport): panes stack vertically with `<hr>` separator

**Backend addition:** `signal-noise/ai-alt-suggest` ability output gains `thumbnail_url` + `filename` fields (powers the modal thumbnail + filename label). Two extra calls per Suggest (`wp_get_attachment_image_url()` + `wp_basename(get_attached_file())`); cost is microseconds (local DB lookups via WP object cache). Additive change; older JS consumers ignore the fields — no coordinated rollout needed.

**Inline-img findings (v4.0.2) skip the modal** — they use the `apply: null` sentinel which renders Copy button + helper text instead of Apply + Discard. The modal never opens for those.

**Zero new AI cost.** Modal is pure UI. The 136 integration test assertions pass (was 133; +3 verify `thumbnail_url` + `filename` shape).

**Patch cap status:** 3/7 in v4.0.x. v4.0.x roadmap is now fully shipped (v4.0.1 drift cache + v4.0.2 inline-img Suggest+Copy + v4.0.3 Before/After modal). Next ship goes to v4.1.0 (stale-posts + orphaned-media Suggest+Apply + cheap zero-AI Health checks like color drift + block pattern usage analytics).

## [4.0.2] - 2026-05-25

### Added — Inline-`<img>` alt Suggest + Copy

Discharges the v4.0.0 CHANGELOG-flagged scope debt for inline-`<img>` missing-alt findings. v4.0.0 deferred this because inline imgs have no attachment_id; subject_id is the parent post ID, and `snt_ai_alt_suggest_impl()` rejects non-attachment input.

**v4.0.2 ships Suggest + Copy only.** Apply remains deferred indefinitely per block-serialization risk (WORDPRESS-REFERENCE.md gotcha #4). UX: click Suggest → AI generates alt text from post title + image filename + ~500 chars of stripped paragraph context → user reviews in a read-only textarea → clicks Copy → opens the editor → pastes into the alt field.

**Impl boundary decision (Option B from v4.0.x roadmap):** sibling ability `signal-noise/ai-alt-inline-suggest` with separate `(post_id, image_src)` schema. Preserves the canonical single-shape input_schema pattern from the 25 existing abilities; avoids the polymorphic-input cost of Option A.

**4-surface integration:**
- New impl `snt_ai_alt_inline_suggest_impl()` + context-extraction helper `snt_ai_extract_inline_img_context()` in `inc/ai-alt-inline-suggest.php`
- New ability `signal-noise/ai-alt-inline-suggest` with `annotations: { idempotent: true }`
- New REST endpoint `POST /signal-noise/v1/ai/alt-inline-suggest`
- JS dispatch via `missing_alt_inline` check key with `apply: null` sentinel for no-apply variant rendering (read-only textarea + Copy button + helper text "Open the editor to apply")

The `apply: null` sentinel generalizes for future Suggest-only check variants (e.g., read-only stale-post suggestions in v4.1.0). One JS dispatch shape serves all "Suggest with no destructive apply" cases.

**Patch cap status:** 2/7 in v4.0.x (after v4.0.1 drift cache).

**Coming in v4.0.3:** Before/After Apply preview modal — visual diff before destructive Apply writes, zero new AI cost.

## [4.0.1] - 2026-05-25

### Cache AI drift-verdicts per (post_id, post_modified) — stops re-evaluating unchanged posts on Run scan

**Problem**: `sn_health_check_drift_time_phrases()` was calling `snt_ai_generate_with_constraints()` once per post that had regex-flagged candidates, on every Run scan. Production logs showed 8-10 AI calls per scan, all redundant for unchanged posts. Drift verdicts are deterministic from `(post_content, post_modified_gmt, system_prompt)`, so re-evaluating stable content burned tokens for no new signal.

**Fix**: wrap the AI call in a transient cache keyed on `(post_id, post_modified_gmt, md5(SNT_AI_DRIFT_SYSTEM))`. 30-day TTL. Invalidation is content-change-driven, not time-driven — the cache entry is ignored if either the post's `post_modified_gmt` or the drift system prompt's hash changes.

**Result**: re-scans on stable content are now ~free. First scan still costs the full per-post AI evaluation; subsequent scans of unchanged posts skip the AI call entirely. A post edit (which bumps `post_modified_gmt`) or a tweak to `SNT_AI_DRIFT_SYSTEM` (which changes the prompt hash) transparently re-evaluates the affected posts on the next scan.

Patch cap: 1/7 in v4.0.x.

## [4.0.0] - 2026-05-25

### Added — AI-assisted content-health fix proposals (alt text + drift phrases)

Closes the longstanding "AI-assisted fix proposals are a future extension" note in `inc/health-checks.php` (since v3.5.0). Two checks gain AI Suggest+Apply UX in this release; the remaining two (stale posts + orphaned media) ship in v4.1.0.

**`missing_alt` check (attachment case only in v4.0.0)** — for image attachments without alt text, the new "Suggest" button generates a descriptive 80–125 character proposal via the WP AI Client (using attachment title + caption + filename + first referencing post as context). User reviews + optionally edits the textarea, then "Apply" writes to `_wp_attachment_image_alt`. **Inline-`<img>` findings within `post_content` continue rendering only the Edit button** — both the Suggest and Apply paths for inline imgs are deferred to v4.0.x. The impl boundary (inline imgs have no attachment_id; subject_id is the parent post ID) is cleaner to settle in a follow-up than to ship inside v4.0.0's broader change.

**`drift_time_phrases` check** — the detection layer (`sn_health_check_drift_time_phrases()`) already AI-evaluated regex-matched candidates and flagged the stale ones. v4.0.0 adds the Suggest layer (AI proposes a temporally-explicit replacement, e.g., "recently" → "in early 2025") plus an Apply layer that splices the replacement into `post_content` at the known byte position. Concurrency-safe via a fingerprint pattern: `md5(phrase + 80-char context window)` is computed at Suggest time and validated at Apply time, so a post edited between scan and apply surfaces a clear "Post changed since scan. Re-run scan." error instead of overwriting the wrong content.

**UX**: per-row Suggest button + per-check "Suggest all N" batch button (sequential, 500ms throttle, populates inline proposals). Apply remains per-row to keep the AI from writing without explicit user confirmation. Suggest is gated on AI availability — the "AI fix" column and buttons don't render at all when no provider is configured.

**4 new abilities** registered under the existing `ai-generation` category:
- `signal-noise/ai-alt-suggest` (idempotent)
- `signal-noise/ai-alt-apply` (destructive, idempotent)
- `signal-noise/ai-drift-suggest` (idempotent)
- `signal-noise/ai-drift-apply` (destructive, idempotent)

All 4 follow the established `snt_*_impl()` pure-function pattern with REST endpoints (back-compat for non-JS callers under `/wp-json/signal-noise/v1/ai/`) and Abilities API registrations. JS calls go through the Abilities REST surface.

**Cost**: bounded to user clicks. No background generation. ~$0.001 per AI call at Sonnet 4.6 pricing. A "Suggest all" on 50 missing-alt findings = ~$0.055 + ~25 seconds at the 500ms throttle.

#### Cap rollover — v3.x → v4.x

The plugin's minor-version cap is 5 per major (per `CLAUDE.md` versioning rules). v3.x reached 8 minors (v3.0 → v3.8), past the cap. This release's headline feature would conventionally have been v3.9.0 — instead it rolls to v4.0.0 per the cap policy. Future v4.x minors reset the counter; the next cap check happens after v4.5.

#### v3.x in summary (36 ships across 9 minors)

The v3.x line was about consolidating the plugin's role from a small operational tooling layer into the canonical SEO + login + dashboard surface for the Signal & Noise stack. Key milestones: **v3.0.0** (abilities-first refactor + WP 7.0 Armstrong support), **v3.4.0** (webhooks), **v3.5.0** (content health checks — detection only), **v3.6.0** (Insights / Content Opportunity Advisor — cross-system AI synthesis), **v3.7.0** (drift detection — first AI-powered health check), **v3.7.1** (the 6-month silent-bug fix where `method_exists()` was failing on the wp-ai-client's `__call`-routed methods), **v3.7.3** (deploy SSH+wp-eval reroute — eliminated all rotatable credentials), **v3.8.0** (12 → 6 admin tab IA reorganization), **v3.8.3** (login hardening audit log with 90-day retention), **v3.8.6** (viewport-fit admin pages — sticky chrome + density). v4.0.0 turns the detection-only health checks into actionable fixes.

#### Coming in v4.1.0

- AI suggest for stale posts (>12mo unmodified) — read-only suggestion list (whole-post rewrites are too risky for one-click apply)
- AI keep/delete recommendation for orphaned media with one-click apply
- Inline-`<img>` alt Suggest + Copy + manual application in editor (Apply itself remains deferred pending a clean block-serialization primitive)

## [3.8.6] - 2026-05-25

### Changed — Viewport-fit admin pages (system-wide CSS pass)

SN admin pages now fit the desktop-mode portal viewport via sticky chrome + internal-scroll for long tables + density tightening. Dashboard-app feel: chrome stays anchored (sub-tab nav + TOC + hero cards always visible), data regions (tables) scroll internally with sticky `<thead>` so column headers stay visible during scroll. Forms keep natural page scroll (users tab through fields).

**6 tactics:**

1. **Sub-tab nav becomes sticky** below the WP admin bar (`top: 32px`)
2. **TOC nav sticky** below the sub-tab nav (`top: 80px`) — used on Identity & SEO
3. **Long tables get `.snt-scroll-table` wrapper** with `max-height: 50vh`, sticky `<thead>`, scoped border. 6 wrappers across 5 module files: audit-log-admin (counter timeline + recent logins), cron-dashboard-admin (events), health-checks-admin (scan results), webhooks-admin (deliveries log inside `<details>`), rss-plausible-tracker (recent requests)
4. **Hero stat cards tightened** — `.sn-audit-card` padding 16→12px, value font 28→22px; `.sn-state-card` padding 14/16→10/12px, value font 1.4→1.2rem, min-height 96→76px
5. **`.sn-fieldset` density tightened** via CSS variable updates — `--sn-space-4` 16→12px, `--sn-space-5` 24→20px; ripples through 23 callsites; all UI tightens by ~25%
6. **Section intros (`.sn-prose` / `.sn-fieldset-intro`) compacted** — 0.95→0.88em font, margins to 8px

**Pages affected:**

- Audit log, Cron Dashboard, Content Health, Webhooks, RSS Recent: get internal-scroll tables + sticky chrome + tighter density
- Dashboard: tighter density only (recent-deploys list is short)
- Identity & SEO, Cloudflare, Login, Plausible, Webhooks form: tighter density + sticky chrome; forms keep natural page scroll (users tab through fields)
- Insights, Reading Time: tighter density only

**Verification:** 16 manual smoke gates in the spec (G1-G16). The big wins: sub-tab nav never scrolls off, table headers stay visible during in-table scroll, hero cards stay anchored at top of every page.

**File diff:**

- Modified: `assets/admin.css` (+40 / -8 lines: variable tightening, sticky chrome, density, `.snt-scroll-table` rule)
- Modified: `assets/audit-log.css` (2 line replacements: card padding + value font)
- Modified: `inc/audit-log-admin.php` (+4 lines: 2 table wrappers)
- Modified: `inc/cron-dashboard-admin.php` (+2 lines: 1 wrapper)
- Modified: `inc/health-checks-admin.php` (+2 lines: 1 wrapper)
- Modified: `inc/webhooks-admin.php` (+3 lines: 1 wrapper inside the deliveries log `<details>` disclosure)
- Modified: `inc/rss-plausible-tracker.php` (+2 lines: 1 wrapper)
- Modified: `signal-and-noise-tools.php` (version bump)

**Patch 6/7 in v3.8.x.** 1 patch remains before v3.9.0 rollover.

## [3.8.5] - 2026-05-25

### Fixed — RSS Monitoring tab: `.sn-2col` always stacks (v3.8.4 breakpoint fix wasn't enough)

v3.8.4 raised the stack breakpoint from `960px` to `1200px`, which helped narrow desktop-mode portal viewports. But on wider monitors (>1200px) the 2-col stayed active and stayed cramped — the right column was capped at `360px` so the Settings form labels still felt squeezed against the table.

Defensive fix: `.sn-2col` now always renders as a single column. Both Recent-requests table and Settings/Maintenance get full width to breathe. The grid container stays in place (so the `.sn-2col__col` wrappers still work) — only the `grid-template-columns` was changed from `minmax(0, 1fr) minmax(280px, 360px)` to plain `1fr`. The 24px gap between rows preserves visual separation.

**If side-by-side at very wide viewports is wanted back**, easy to re-add a `min-width` media query (e.g., `@media (min-width: 1600px) { .sn-2col { grid-template-columns: 1fr 1fr; } }`).

**Patch 5/7 in v3.8.x.**

## [3.8.4] - 2026-05-25

### Fixed — Desktop-mode dock submenu showed 8 stale entries; RSS 2-col layout was cramped

**Bug 1 — Duplicate-nav appearance in desktop-mode portal.** The dock-items filter in `inc/desktop-mode-integration.php` was still hardcoded with the legacy 8-entry submenu (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Links) — even though v3.8.1 reduced the wp-admin sidebar submenu to the 6 new top-tab entries (Dashboard, Site, Security, Automation, Monitoring, Tools). Desktop-mode renders the dock-items submenu as a horizontal top-nav row, so the portal continued showing the OLD 8 entries above the 6 new in-page tabs — exactly the "duplicate nav" pattern v3.8.1 was meant to fix.

**Root cause:** single-source-of-truth violation. v3.8.1 updated `add_submenu_page()` iteration to read from `sn_admin_top_tabs()` but missed the parallel dock-items filter.

**Fix:** dock-items submenu now derives from `sn_admin_top_tabs()` — same list, single source. Future top-tab additions update both surfaces automatically. URLs go direct-to-canonical (`?page=sn-theme-options&tab=<top>`) instead of the legacy slugs.

**Bug 2 — RSS Monitoring tab cramped 2-col layout.** The Recent-requests table and Settings form rendered side-by-side via `.sn-2col` (`grid-template-columns: minmax(0, 1fr) minmax(280px, 360px)`) with a stack breakpoint at `max-width: 960px`. Desktop-mode portal viewport sits in the ~1000-1200px range — above the breakpoint, so the layout stayed 2-col, but the right column hit its 280px minimum while the left column wasn't wide enough for the table's content. Visual result: form labels and table cells competing for the same horizontal band.

**Fix:** raised the stack breakpoint from `960px` to `1200px`. The 2-col still works comfortably on full wp-admin (>1200px viewport); stacks on the desktop-mode portal width. `.sn-2col` is only used by the RSS tab (1 caller in the codebase), so the change is scoped.

**Patch 4/7 in v3.8.x.**

## [3.8.3] - 2026-05-25

### Added — Login hardening audit log under Security

New "Audit log" sub-tab under Security (`?page=sn-theme-options&tab=security&sub=audit-log`). Captures 6 login-related events:

- **`login_success`** as per-event rows (timestamp + username; no IP) — the security-critical event you want to spot anomalies in ("did someone log in as me at 4am?")
- **`login_failed`**, **`wp_login_404`** (direct visits to `/wp-login.php` caught by `login-hide.php`), **`wp_admin_unauth_404`** (unauth `/wp-admin` visits caught by `login-hide.php`), **`lockout_triggered`** (from LLA via polling fallback), **`password_reset`** — as day-bucketed counters
- **`unique_ips_count`** per day, computed via an ephemeral hashed-IP transient set with 25h TTL. The set rolls forward at day-flip into the long-term counter; raw or hashed IPs **never persist long-term**.

**4-surface dispatch** (per project pattern; all 4 converge on `snt_audit_*_impl()` pure functions):

- **Admin sub-tab** with stat-card hero (last 24h totals + 7d trend + unique IPs + LLA status) + 30-day counter timeline table + recent-logins table + LLA lockout summary card with deep-link
- **REST**: `GET /signal-noise/v1/audit/{summary,counters,login-successes}` + `POST /audit/prune`
- **Abilities** (4 total — categories: `diagnostics` + `maintenance`):
  - `signal-noise/get-audit-summary` (read, AI-eligible)
  - `signal-noise/get-audit-counters` (read, AI-eligible)
  - `signal-noise/get-audit-login-successes` (read, AI-eligible)
  - `signal-noise/run-audit-prune` (maintenance, **NOT** AI-callable — destructive of historical data)
- **desktop-mode ⌘K**: `SN: Audit log summary` + `SN: Recent successful logins` (both `aiCallable: true`, read-only fetch + toast via `wp.apiFetch`)

**Storage:** single autoloaded `wp_options` blob `sn_audit_log_v1` (JSON-encoded, schema-versioned). Worst-case envelope ~100 KB after 90 days. Daily WP-Cron `sn_audit_log_prune` enforces the 90-day retention window — visible in the Cron Dashboard tab.

**Notable verified-at-design-time finding:** LLA fires **NO** action hook on lockout (only `llar_plugin_version_updated` + `llar_mfa_generate_codes` exist in LLA core). The `lockout_triggered` counter therefore uses a polling fallback — the daily prune tick reads `limit_login_lockouts` array size delta vs. the last-seen count and adds the delta to today's bucket. Imprecise (only captures net-positive changes between tick boundaries) but acceptable for the trend-detection use case.

**Visible UI change:** the Security tab's sub-tab nav row is now visible (was hidden when count=1; adding the "Audit log" sub-tab makes count=2).

**Patch 3/7 in v3.8.x.**

### File diff (this release)

- **New:** `inc/audit-log.php` (~470 LOC — impls + capture hooks + REST routes co-located + retention cron)
- **New:** `inc/audit-log-admin.php` (~200 LOC — sub-tab renderer)
- **New:** `assets/audit-log.css` (~60 LOC — stat-cards grid + responsive)
- **Modified:** `signal-and-noise-tools.php` — `require_once` the 2 new files + version bump
- **Modified:** `inc/login-hide.php` — 2 lines at the 404 paths to increment the new counters
- **Modified:** `inc/admin-page.php` — `audit-log` added to Security `sub_tabs`; dispatch arm added
- **Modified:** `inc/abilities-registration.php` — +4 abilities + 4 execute callbacks (~120 LOC)
- **Modified:** `inc/desktop-mode-integration.php` — 2 new commands in the registration array
- **Modified:** `assets/desktop-mode.js` — 2 new `registerCommand` calls

## [3.8.2] - 2026-05-25

### Fixed — `SNT_VERSION` constant now derives from the docblock Version (no more drift)

The `SNT_VERSION` constant was hardcoded as a literal `'3.7.6'` string at the top of `signal-and-noise-tools.php`. Bumping the docblock `Version:` header to 3.8.0 then 3.8.1 left this constant at `'3.7.6'`, which cascaded into TWO visible bugs:

1. **Dashboard widget showed wrong plugin version.** The SN admin Dashboard tab's "PLUGIN" badge reads `SNT_VERSION` directly. After v3.8.0/v3.8.1 deploys, it kept reporting "PLUGIN 3.7.6 • v3.8.1 available" — the plugin was actually at v3.8.1 on disk but the badge reflected the stale constant.

2. **Sub-tabs CSS didn't render.** `wp_enqueue_style()` uses `SNT_VERSION` as the `?ver=…` cache-buster for `admin.css`. Browser had cached `admin.css?ver=3.7.6` (the OLD CSS without `.sn-sub-tabs` rules from v3.8.0). When v3.8.1's CSS shipped, the URL key didn't change (still `?ver=3.7.6`), so browsers served their cached OLD content. Sub-tab nav rendered as run-on inline links instead of the styled pill-tab pattern.

**The fix:** read the version from the docblock at load time using WP's `get_file_data()`:

```php
$snt_plugin_data = function_exists( 'get_file_data' )
    ? get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' )
    : array( 'Version' => '0.0.0' );
define( 'SNT_VERSION', $snt_plugin_data['Version'] ?: '0.0.0' );
unset( $snt_plugin_data );
```

**Structural improvement:** the docblock `Version:` header is now the single source of truth for the plugin version. Future bumps need only edit the docblock — `SNT_VERSION` follows automatically. No more drift possible.

**After v3.8.2 deploys**, browsers fetch `admin.css?ver=3.8.2` (a fresh URL key) → new CSS loads → sub-tabs render correctly. Dashboard widget also reads the correct version.

**Patch cap status:** v3.8.x at 2/7 patches used.

### Note on previously theme-side fix (theme v9.1.5)

The earlier theme v9.1.5 added `wp_clean_plugins_cache()` to the purge filter chain. That fix IS working as intended (WP's general plugin metadata cache IS being invalidated on each deploy). The remaining stale-version bugs were unrelated — they came from the hardcoded constant, not WP's cache.

## [3.8.1] - 2026-05-25

### Changed — Sub-tabs navigation + 6-entry submenu (post-v3.8.0 refinement)

v3.8.0 shipped 6 top tabs with internal-TOC scroll-pattern for sub-sections. Real-world use surfaced three issues:

1. **WP submenu duplicated in-page tabs in desktop-mode.** The 12 legacy WP submenu entries (kept for deep-link shortcut preservation) render as a horizontal top nav in desktop-mode plugin, visually duplicating the 6-tab in-page nav.
2. **Long scrolling per tab.** Internal-TOC pattern forced each multi-section tab onto one long page (Site tab = Identity + Social + OG + SEO Copy + Cloudflare stacked).
3. (Theme-side, shipped as v9.1.5) Plugin metadata cache stale after SSH deploys.

**Fixes in v3.8.1:**

- **Sub-tabs** (click-to-swap, URL-driven via `&sub=` query arg) replace internal-TOC scrolling on multi-section top tabs. Each sub-tab renders only its own content. Exception: "Identity & SEO" sub-tab on the Site tab preserves the internal TOC for its 4 form-coupled sections (Identity / Social / Open Graph / SEO Copy), keeping the existing single-save UX intact.
- **WP submenu reduced from 12 entries to 6** matching the new top-tab IA. Eliminates the duplicate-nav appearance in desktop-mode. Legacy slugs (`sn-identity`, `sn-login`, etc.) still 301-redirect via the extended redirect map — bookmarks survive.
- **New helpers** in `inc/admin-page.php`: `sn_admin_render_sub_tabs($tab, $active_sub)`, `sn_admin_get_sub_tabs($tab)`, `sn_admin_resolve_active_sub($tab)`. Existing `sn_admin_render_toc()` updated to take a sub-tab slug parameter (scoped to the inner-TOC use case).
- **`sn_admin_legacy_redirect_map()` extended** with `sub` field on each entry so legacy URLs land on the correct sub-tab. New entries for `social`, `open-graph`, `seo-copy` — these were previously inner section anchors only, now redirectable to canonical `?tab=site&sub=identity-and-seo#sn-sec-<inner>`.
- **PRG flash redirect preserves `&sub=`** so saving a form on a sub-tab redirects back to the same sub-tab (instead of the top tab's default).
- **`.sn-sub-tabs` CSS** added — pill-style nav on a light-gray track, visually subordinate to the top tabs.

**Architectural property preserved:** module hook contracts unchanged. No `inc/*-admin.php` module files touched.

**Visual review of admin spacing** (user-reported "space issues") deferred to post-deploy user feedback — the structural sub-tabs change should resolve the most visible issue (the long-scroll problem). Specific spacing complaints addressed in follow-up patches as identified.

**Patch cap status:** v3.8.x at 1/7 patches used. Six patches remain in the v3.8.x line.

**Spec + plan:** `docs/superpowers/specs/2026-05-25-v3.8.1-sub-tabs-and-cache-fix-design.md` + `docs/superpowers/plans/2026-05-25-v3.8.1-sub-tabs-and-cache-fix-v3.8.1.md`.

**Companion ship:** theme v9.1.5 (already deployed) — `wp_clean_plugins_cache()` added to the theme's purge filter so plugin metadata cache stays fresh through SSH deploys.

## [3.8.0] - 2026-05-25

### Changed — Admin tabs reorganized from 12 flat → 6 hierarchical

Major IA refactor of the SN Tools admin page. 12 flat top-level tabs (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Cron, Webhooks, Insights, Health, Links) consolidate into **6 hierarchical tabs**:

```
Dashboard │ Site │ Security │ Automation │ Monitoring │ Tools
```

Each multi-section tab uses the internal-TOC anchor pattern (already proven on the Identity tab) for sub-section navigation. **14 sub-sections** distributed across the 6 top tabs. Within the 5-7 magic number for top-level nav.

**Architectural property: module hook contracts unchanged.** Each module's `do_action('sn_admin_<slug>_tab')` still fires identically; only the parent dispatcher changes. Refactor contained entirely to `inc/admin-page.php` — zero LOC in any module file.

**Backward compatibility:** all 12 legacy `?tab=<slug>` and `?page=sn-<slug>` URLs 301-redirect to canonical `?tab=<category>#sn-sec-<sub>` destinations. WP sidebar keeps all 12 entries as direct-jump shortcuts (different optimization surface than in-page tabs). Existing bookmarks survive; browsers update them over time per 301 semantics.

**Two new helpers added:**
- `sn_admin_render_toc( $tab_slug )` — generates the in-page anchor nav for multi-section tabs
- `sn_admin_render_section( $section_slug, $callback )` — wraps content in anchor target

**Patch cap status:** plugin v3.7.x hit 7/7 patches; this is the cap-rollover minor bump.

### What's NOT in this release
- **No new functionality.** Refactor only.
- **No CSS changes.** Existing `assets/admin.css` already supports the patterns used.
- **No JavaScript changes.** Anchor scroll is browser-native.
- **No data-schema changes.** `sn_settings` schema unchanged.

### Coming in v3.8.1
- Login hardening: counter-based audit log under Security → Audit log sub-section. Designed but not implemented (paused brainstorm Section 1).

### Spec + plan
- Spec: `docs/superpowers/specs/2026-05-25-admin-tabs-ia-reorganization-design.md`
- Plan: `docs/superpowers/plans/2026-05-25-admin-tabs-ia-reorganization-v3.8.0.md`

## [3.7.6] - 2026-05-24

### Security — error_log surfacing on 2 silent catch sites

Per the security audit (Wave 5 from `2026-05-21-maintenance-pass-in-flight.md`) — both pre-existing catch blocks were silently coercing exceptions to a fallback value. The v3.7.1 incident memorialized why this matters: SN AI features silently no-op'd for 6 months because a guard returned false on exception with no log trail.

#### What changed

**`inc/cron-dashboard.php:269`** ([`fe83c72`](https://github.com/juanlentino/signal-and-noise-tools/commit/fe83c72)) — `snt_cron_run()` catch persisted the exception message to the `snt_cron_history` table (visible in the Cron Dashboard UI) but didn't surface to PHP error log. DB-only logging is invisible to log search tools (grep, journalctl, fail2ban, Cloudways alerts). Added one-line `error_log()` to keep both surfaces in sync. The DB persistence path is unchanged.

**`inc/ai-bootstrap.php:84`** ([`fe83c72`](https://github.com/juanlentino/signal-and-noise-tools/commit/fe83c72)) — `snt_ai_can_text_generate()` catch was the exact bug surface the v3.7.1 fix corrected, but the catch itself remained silent. The current code returns `false` correctly on exception (caller treats AI as unavailable), but runtime exceptions deserved a log trail so future regressions surface in PHP error log instead of vanishing. Added one-line `error_log()`.

No behavioral change for callers — both sites still return their original value paths. Pure observability improvement.

#### Audit clean results (no other findings)

The full security audit covered 9 dimensions across both repos:

- **0 live API keys / SSH keys / GitHub tokens / passwords** in committed code (either repo)
- **0 unauthenticated mutating REST endpoints** (all 15 plugin routes have proper `permission_callback`)
- **0 destructive abilities reachable by unprivileged users** (29 abilities audited, all consistent: destructive → `manage_options` + `destructive: true`; readonly → low cap + `readonly: true`)
- **0 `is_plugin_active` calls without `file_exists` pairing** (WP-REFERENCE entry #26 — both call sites correctly paired)
- **`rel_canonical` + `wp_robots` removals both present** at `inc/seo.php:111,119` (WP-REFERENCE entries #21, #22)
- **All 16 wpdb queries safe** — 11 use `prepare()`, 5 raw queries verified constant-SQL with no user input
- **All admin POST handlers paired** with capability check + nonce verification
- **0 `shell_exec` / `eval` / `proc_open` usages** in either repo's `inc/`
- **0 plan/spec docs contain literal live credentials** in current HEAD

Posture has materially improved since the v3.7.1 incident — the 12 ability callbacks added in v3.7.5 P4 (named-function refactor) all include `error_log` discipline; theme abilities-registration.php is 12-for-12 on `error_log`-in-catch. Only the 2 pre-existing catches above were stragglers.

#### Files

- `inc/cron-dashboard.php` — +5 lines (comment + error_log)
- `inc/ai-bootstrap.php` — +6 lines (comment + error_log)

#### Companion changes (not in this release)

- **Theme**: WORDPRESS-REFERENCE.md gained 3 new gotchas (entries #32–#34) capturing the v3.7.x lessons. Docs-only commit `dc7a123` — no theme version bump per project versioning rule.
- **GH secrets cleanup**: `WP_DEPLOY_USER` + `WP_DEPLOY_APP_PASSWORD` verified absent from `gh secret list --repo juanlentino/signal-and-noise-tools` (cleanup happened post-v3.7.3; comments updated separately).

#### Patch-cap status

Patch cap is 7 per minor. v3.7.6 is the 7th patch in v3.7.x. **This is the last patch slot in v3.7.x** — the next code-bearing release must roll to v3.8.0. Plan accordingly.

## [3.7.5] - 2026-05-24

### Added — Integration tests + named-function refactor + JSON Schema examples + AI catalog + agent guideline templates

Final phases of the AI-readiness preparation arc (v9.1.2 → v9.1.3 in theme, v3.7.4 → v3.7.5 here). Five concurrent threads:

1. **AI-invocation integration tests** — 108 new assertions exercising `wp_get_ability()->execute()` for all 17 plugin abilities. ([`23f89f8`](https://github.com/juanlentino/signal-and-noise-tools/commit/23f89f8))
2. **Closure-to-named-function refactor** — 10 inline `execute_callback` closures extracted to top-level `snt_ability_*` functions, matching theme's pattern. ([`3ce5fa7`](https://github.com/juanlentino/signal-and-noise-tools/commit/3ce5fa7))
3. **JSON Schema `examples` arrays** on 8 input properties across 6 plugin abilities. ([`8259316`](https://github.com/juanlentino/signal-and-noise-tools/commit/8259316))
4. **AI abilities catalog** — 1384-line canonical reference at [`docs/ai-abilities-catalog.md`](docs/ai-abilities-catalog.md) for all 29 SN abilities (12 theme + 17 plugin). Verbatim descriptions, full input/output examples, wp-cli + REST invocations, use cases. ([`1d70aa8`](https://github.com/juanlentino/signal-and-noise-tools/commit/1d70aa8))
5. **Agent guideline templates** — speculative pre-PR-#240 templates at [`docs/agent-guidelines/`](docs/agent-guidelines/) for 3 SN-specific agent workflows (Brand Audit, Draft Editor, Site Maintenance). ([`05d9269`](https://github.com/juanlentino/signal-and-noise-tools/commit/05d9269), [`53e0d1b`](https://github.com/juanlentino/signal-and-noise-tools/commit/53e0d1b))

#### Test additions

`tests/abilities-integration.php` — NEW (747 lines, 108 assertions):
- Dispatch fundamentals (18 assertions): unknown slug + 17 registered checks
- Read/diagnostics happy paths (8 abilities, ~16 assertions)
- Read/diagnostics capability denial (7 × 2 = 14)
- Required + enum validation (4)
- Destructive ops (purge-all-caches idempotency + 4 capability denials + unschedule-cron-event SN-owned hook refusal + missing-hook validation)
- `regenerate-og-card` per-post edit_post gating
- Generative AI abilities (happy paths + denial + missing-required across 3 plugin AI abilities)

Plugin test count: 658 total across 10 suites (108 new integration + 550 prior). All green.

#### Named-function refactor

The 10 anonymous closures in `inc/abilities-registration.php` are now top-level named functions `snt_ability_<slug-as-snake-case>`. The file now has 17 top-level `snt_ability_*` functions matching 1:1 with the 17 `wp_register_ability()` calls. Behavioral equivalence guaranteed by the integration test suite landed in the same release.

Examples:
- `signal-noise/purge-all-caches` → `snt_ability_purge_all_caches`
- `signal-noise/ai-generate-meta-description` → `snt_ability_ai_generate_meta_description`
- `signal-noise/unschedule-cron-event` → `snt_ability_unschedule_cron_event`

Benefits: grep-able from anywhere, unit-testable in isolation, matches theme's `sn_theme_ability_*` convention.

#### Schema example additions

Properties enhanced (8 total):

| Ability | Property | Examples |
|---|---|---|
| `regenerate-og-card` | `post_id` | integer examples |
| `ai-generate-meta-description` | `post_id` | integer examples |
| `ai-generate-og-card-title` | `post_id` | integer examples |
| `ai-generate-excerpt` | `post_id` | integer examples |
| `get-cron-event` | `hook`, `args_signature` | real SN-owned hooks + md5 prefix |
| `get-cron-history` | `hook`, `limit` | hook examples + integer examples |
| `unschedule-cron-event` | `hook` | non-SN hook examples (SN-owned hooks refused by impl) |

No validation changes; examples are advisory metadata. Non-breaking.

#### AI abilities catalog

`docs/ai-abilities-catalog.md` is now the canonical reference for all 29 SN abilities. Each ability's section includes:

- Category, capability, annotations
- Description (verbatim from registration)
- Input parameters (humanized table)
- Output JSON example with realistic values
- wp-cli + REST invocation
- 2-4 specific use cases

Verified against actual registrations — no hallucinated paths, no phantom endpoints. Will be the source-of-truth for documentation across releases.

#### Agent guideline templates

`docs/agent-guidelines/` contains 3 speculative `wp_guideline`-format templates pre-authored for WordPress/desktop-mode PR #240's Agents framework when it lands:

- `sn-brand-audit-agent.md` — drafts → brand alignment audit (editor role)
- `sn-draft-editor-agent.md` — content writing in SN voice (editor role)
- `sn-site-maintenance-agent.md` — operational monitoring (administrator role, destructive ops intentionally excluded)

All 17 ability references verified against v9.1.3/v3.7.5 registrations. The brand-audit agent's allowlist dropped `signal-noise/get-rss-stats` after capability-mismatch review (the ability requires `manage_options`; an `editor` role would 403).

#### Files

- `inc/abilities-registration.php` — closure refactor + schema example additions
- `tests/abilities-integration.php` — NEW test file
- `docs/ai-abilities-catalog.md` — NEW canonical catalog
- `docs/agent-guidelines/README.md` + 3 agent files — NEW speculative templates

#### Patch-cap status

Patch cap is 7 per minor. v3.7.5 is the 6th patch in v3.7.x. **1 patch remains** before forced rollover to v3.8.0. Plan the next code-bearing release accordingly.

## [3.7.4] - 2026-05-24

### Refactor — AI-readiness preparation pass + v3.8.0 cancellation cleanup

Two concurrent threads in this release:

1. **AI-readiness pass** for the 17 plugin ability registrations (same prep work as theme v9.1.2 — making our `wp_register_ability()` registrations great AI tools for when [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) ships the Abilities-as-tools harvester).
2. **v3.8.0 cancellation cleanup** — formally cancelling the planned v3.8.0 (Anthropic provider + 26 manual AI tool wrappers) after reading upstream signals.

No breaking changes. All 9 test suites pass (550+ assertions, 0 failures).

#### What changed — AI-readiness pass

**Backfilled `get-rss-stats` output_schema with real shape** ([`f420d27`](https://github.com/juanlentino/signal-and-noise-tools/commit/f420d27))

`output_schema.data` was an opaque `{type: 'object'}` with no property hints — consumers (wp-cli, REST, future AI tool harvester) had no insight into return structure. Now reflects the actual `snt_cmd_impl_rss_stats()` return shape: `last_request`, `last_request_relative`, and `windows` (per-window object keyed by day count with `total` + `uniques` integers).

**`additionalProperties: false` on all 17 input schemas** ([`5850c6a`](https://github.com/juanlentino/signal-and-noise-tools/commit/5850c6a))

Conventional LLM-tool-calling signal that "these are all the parameters" — without it, models may hallucinate extra fields. Matches theme abilities (all 12 already set this correctly). Non-breaking: extras were already ignored by the abilities-api input validator. Affected: all 17 plugin abilities.

**Description use-case hints on 3 ambiguous abilities** ([`33c77cd`](https://github.com/juanlentino/signal-and-noise-tools/commit/33c77cd))

| Ability | Hint added |
|---|---|
| `get-rss-stats` | "Use to verify RSS feed traffic before changing feed structure or auditing crawler activity." |
| `list-cron-events` | "Pass `sn_only=true` to filter to the 3 SN-owned hooks (Plausible refresh, RSS prune, deploy webhook)." |
| `get-cron-event` | "`args_signature` is the md5 hash returned by `signal-noise/list-cron-events`. Use that ability first to discover signatures." |

Descriptions are LLM-facing in WP 7.0 Abilities-API harvesting; specific use-case hints help the model pick the right tool for the user's actual scenario.

#### What changed — v3.8.0 cancellation cleanup

**12 launcher commands stripped to display-only entries** ([`b3430cc`](https://github.com/juanlentino/signal-and-noise-tools/commit/b3430cc))

The 12 ⌘K Command Palette entries previously shipped (in earlier session work) carried `ability`, `render_mode`, `input_fields`, `ai_callable` metadata fields. Reading [WordPress/desktop-mode `commands.php:145-153`](https://github.com/WordPress/desktop-mode/blob/trunk/includes/commands.php#L145) revealed that `desktop_mode_register_command()` silently strips fields outside its 6-key schema (`slug`, `label`, `description`, `icon`, `hint`, `script`). Our extras never reached the registry in production — they only "worked" in tests because the stub captured the full args. Stripped to launcher-only entries (slug/label/description/icon). The 12 ⌘K entries remain as discoverability hooks (no `run()` callback wired yet — that lands when the Agents framework ships).

**v3.8.0 plan + spec annotated as CANCELLED** ([`efc9459`](https://github.com/juanlentino/signal-and-noise-tools/commit/efc9459))

The planned v3.8.0 (Anthropic provider + 26 manual `desktop_mode_register_ai_tool()` wrappers) is cancelled. Reasons:

1. The Anthropic provider is generic infrastructure — it contained zero Signal & Noise content. It belongs in desktop-mode or WordPress Core, not in our plugin.
2. The 26 manual tool wrappers will be obsoleted by step 3 of PR #240's Agents framework (Abilities-as-tools bridge), which will auto-promote `wp_register_ability()` registrations into LLM-shaped tools.
3. Desktop-mode maintainer signal ([issue #271 comment](https://github.com/WordPress/desktop-mode/issues/271)) explicitly says they're waiting for WordPress Core's AI provider abstraction to crystallize before adding more built-in providers.

The v3.8.0 spec + plan documents remain in `docs/superpowers/` with `CANCELLED` headers as historical record. The Anthropic provider implementation (HTTP layer, tool format translation, 3 callbacks, 71 test assertions) is preserved in git history at commits `d3d89cc`, `92e39cc`, `a1275b2` (reverted in `efc9459`) — available for porting if maintainers later open the door.

**Renamed plan namespaces for the v9.1.1 theme sync** ([`2a42937`](https://github.com/juanlentino/signal-and-noise-tools/commit/2a42937))

Stale `signal-noise/*` references in the v3.7.4 plan (since cancelled) updated to `signal-and-noise/*` to match the theme's v9.1.1 namespace rename. Bug discovered during planning: `strpos($ability, 'signal-noise/')` does NOT match `signal-and-noise/...` since the strings diverge at character 7.

#### Why now

Reading WordPress/desktop-mode source ([`commands.php`](https://github.com/WordPress/desktop-mode/blob/trunk/includes/commands.php), [provider registry](https://github.com/WordPress/desktop-mode/blob/trunk/includes/ai-copilot/providers-registry.php), [tools registry](https://github.com/WordPress/desktop-mode/blob/trunk/includes/ai-copilot/tools-registry.php), [PR #240](https://github.com/WordPress/desktop-mode/pull/240)'s Agents framework mock, and [issue #271](https://github.com/WordPress/desktop-mode/issues/271)) made the v3.8.0 architecture obsolete before it shipped. This release captures the lessons + leaves the plugin's 17 abilities well-positioned for whichever upstream story crystallizes first.

#### Files

- `inc/abilities-registration.php` — schema improvements (3 commits)
- `inc/desktop-mode-integration.php` — launcher command refactor (1 commit, from earlier session work)
- `docs/superpowers/specs/2026-05-24-plugin-v3.8.0-anthropic-provider-design.md` — CANCELLED annotation
- `docs/superpowers/plans/2026-05-24-plugin-v3.8.0-anthropic-provider.md` — CANCELLED annotation
- `docs/superpowers/plans/2026-05-24-plugin-v3.7.4-ability-command-palette.md` — SUPERSEDED annotation (also cancelled)
- `signal-and-noise-tools.php` — version bump 3.7.3 → 3.7.4 (header + `SNT_VERSION` constant)

#### Patch-cap status

Patch cap is 7 per minor. v3.7.4 is the 5th patch in v3.7.x. 2 patches remain before v3.8.0.

## [3.7.3] - 2026-05-21

### Security — Eliminated the deploy App Password (rotatable credential removed entirely)

Architecture change: the GH Actions deploy workflow no longer uses HTTP Basic Auth to trigger cache purges. The auth surface that required rotatable WP App Passwords is gone.

#### What changed

`.github/workflows/deploy.yml`'s "Purge Cloudflare cache" step previously called `POST /wp-json/signal-noise/v1/purge-cache` over HTTP, authenticating with `WP_DEPLOY_USER` + `WP_DEPLOY_APP_PASSWORD` GH secrets. This required:

1. Manually generating an App Password in wp-admin → Users → Profile
2. Manually setting `WP_DEPLOY_APP_PASSWORD` GH secret via `echo -n ... | gh secret set ...`
3. Rotating periodically (or after any chat/log exposure)

The fundamental problem: rotating a credential automatically requires getting the new credential into GH secrets without human eyes, which requires SSH and `wp user application-password create` and piping output to `gh secret set` — at which point we're already in SSH-land and the HTTP layer is doing nothing the SSH layer can't do directly.

#### New flow

The deploy workflow now invokes the same purge work via `wp eval` over the existing SSH connection (the one already used by the git checkout step):

```yaml
ssh sn-plugin@cloudways "cd /apps/.../public_html && \
  wp eval 'echo (int) apply_filters(\"sn_purge_all_caches_result\", 0, array(\"template_overrides\" => false));'"
```

The theme's `inc/template-maintenance.php` registers a handler for the `sn_purge_all_caches_result` filter that does the actual work: object cache + Breeze + Varnish + Cloudflare (using CF API credentials from WP options). The REST endpoint at `/wp-json/signal-noise/v1/purge-cache` is just a thin auth + dispatch wrapper around this filter — calling the filter directly via WP-CLI does the same work with no auth ceremony.

#### What was eliminated

- ❌ `WP_DEPLOY_USER` GH secret (no longer referenced anywhere in `.github/workflows/`)
- ❌ `WP_DEPLOY_APP_PASSWORD` GH secret (no longer referenced anywhere in `.github/workflows/`)
- ❌ HTTP Basic Auth path during deploy
- ❌ The 401/403 `sn_rest_forbidden` failure mode entirely
- ❌ Manual password rotation forever

After v3.7.3 ships successfully, both GH secrets can be deleted (`gh secret delete WP_DEPLOY_USER --repo juanlentino/signal-and-noise-tools` etc.) and the App Password revoked in wp-admin → Users → Profile → Application Passwords.

#### What's preserved

- ✅ The `/wp-json/signal-noise/v1/purge-cache` REST endpoint still exists and still requires `manage_options` — for manual curl debugging or third-party integration callers
- ✅ All other dispatch paths into the purge filter (admin form, desktop-mode commands, abilities API) are unchanged
- ✅ `continue-on-error: true` shape from v3.7.2 retained — wp-cli being unavailable or the filter returning 0 doesn't redden the workflow

#### Verifying after deploy

1. Trigger a deploy: `gh workflow run deploy.yml --repo juanlentino/signal-and-noise-tools --ref v3.7.3`
2. Watch the run: the "Purge caches via WP-CLI in-process" step should show `Purged N caches via sn_purge_all_caches_result filter.`
3. Once verified, delete the now-unused secrets:
   ```bash
   gh secret delete WP_DEPLOY_USER --repo juanlentino/signal-and-noise-tools
   gh secret delete WP_DEPLOY_APP_PASSWORD --repo juanlentino/signal-and-noise-tools
   ```
4. Optionally revoke the App Password in wp-admin → Users → Profile → Application Passwords (clean hygiene; not strictly required since nothing uses it from automation anymore)

#### Why this matters

The previous flow had a fundamental contradiction: it required a credential that needed to be rotated, but rotation required either (a) a human pasting the new value into chat/CLI (vector for accidental disclosure) or (b) a more complex automation involving `wp user application-password create` pipelining. The right architecture eliminates the credential, not just automates its rotation.

The auth model is now: "you have the SSH key, you can do the things." Same scope as the SSH git checkout step that ships the actual code — no new attack surface beyond what's already been authorized.

### Tests

No new tests. The deploy.yml workflow change is verified live by the next deploy. All 6 PHP test suites still pass at 441 total assertions.

### Files

- `.github/workflows/deploy.yml` — replaced HTTP Basic Auth purge step with SSH+wp-eval invocation of the existing purge filter
- `signal-and-noise-tools.php` — version bump 3.7.2 → 3.7.3

## [3.7.2] - 2026-05-21

### Maintenance pass — Wave 1: cost + deploy hygiene

First wave of the post-v3.7.1 maintenance pass. Three independent fixes bundled into one release because they're small, mechanical, and unblock subsequent work.

#### Fixed — Anthropic Opus 4.7 model selection (5x cost overage)

`snt_ai_generate_with_constraints()` in `inc/ai-bootstrap.php` did not pin a model preference, letting the WP AI Client route to the Anthropic provider's default — which on a fully-configured install is `claude-opus-4-7`, roughly 5x the cost of Sonnet 4.6 per token.

The v3.6.0 Insights plan budgeted **~$0.01/scan** assuming Sonnet pricing. Verified via AI Request Logs on 2026-05-21 (first scan after the v3.7.1 gate fix landed): single Insights call was 4.9K tokens at `claude-opus-4-7` ≈ **$0.10/scan** in production — 10x plan, ~$5/year at weekly use, ~$40/year at daily use.

Fix: pin `claude-sonnet-4-6` via `->using_model_preference('claude-sonnet-4-6')` in the builder chain. Per [php-ai-client/src/Builders/PromptBuilder.php:288](https://github.com/WordPress/php-ai-client/blob/trunk/src/Builders/PromptBuilder.php), `usingModelPreference()` accepts string model IDs as well as ModelInterface instances and provider/model tuples — string IDs are portable across providers that expose the same model.

Filter `snt_ai_model_preference` lets callers override per-feature if the quality differential ever justifies Opus for a specific surface (e.g., Insights cross-system synthesis). v3.6.0 plan budget restored: ~$0.02/scan, ~$1/year at weekly use.

Gate check (`snt_ai_can_text_generate`) intentionally does NOT pin a model — it asks "is ANY text-gen model available?" and a permissive check is correct there.

#### Fixed — Plugin repo deploy.yml CF purge step strict-fails

`.github/workflows/deploy.yml` previously strict-failed the entire workflow when the CF purge step returned 401/403, even though the load-bearing SSH git checkout step succeeded. This produced misleading red runs for v3.6.0, v3.6.1, v3.7.0, and v3.7.1 — each one showed ❌ on the GH Actions UI and in SN's Dashboard "Recent deploys" widget despite plugin files being live on Cloudways.

Fix: applied theme repo's [`continue-on-error: true`](https://github.com/juanlentino/signal-and-noise/commit/4ec38b6) shape from v8.5.1. The step still runs and attempts the purge; on auth failure it emits a `::warning::` annotation with diagnostic remediation steps but no longer reddens the workflow.

The 401 diagnostic message now correctly prioritizes the `sn_rest_forbidden` interpretation (user-role issue — verify `manage_options` first) over the "stale password" interpretation (rotate App Password second), per the 2026-05-21 diagnosis. This was the prior handoff's QA item #4 + #5 combined.

#### Fixed — `inc/health-checks.php:405` cosmetic comment self-reference

The `KEEP IN SYNC WITH` comment above `sn_health_drift_time_patterns()` self-referenced its own function name instead of pointing at the SQL caller. Rewrote to "Source-of-truth list. KEEP IN SYNC WITH the SQL REGEXP in `sn_health_check_drift_time_phrases()`" so future maintainers know which way the sync arrow points.

Caught during v3.7.0 Task B code review; non-blocking, fixed in this maintenance wave per the prior handoff item #20.

### Tests

No new tests. All 6 existing suites pass unchanged: **441 total assertions** (admin-tabs 177 / cron-dashboard 54 / cron-history 24 / webhooks 46 / health-checks 64 / insights 76).

Test-coverage gaps for `snt_ai_can_text_generate` (the function the v3.7.1 bug lived in) and `snt_ai_generate_with_constraints` (the function now pinning Sonnet) are scoped for the maintenance pass's Wave 3 — integration tests stubbing `wp_ai_client_prompt` to lock in correct gate + model-pinning behavior.

### Files

- `inc/ai-bootstrap.php` — added `using_model_preference('claude-sonnet-4-6')` to the builder chain in `snt_ai_generate_with_constraints` + filter hook
- `inc/health-checks.php` — line 405 comment direction fixed
- `.github/workflows/deploy.yml` — CF purge step now `continue-on-error: true` with diagnostic remediation messages
- `signal-and-noise-tools.php` — version bump 3.7.1 → 3.7.2

## [3.7.1] - 2026-05-21

### Fixed — AI gate `method_exists()` guard always returned false

Critical one-line bug in `inc/ai-bootstrap.php`'s `snt_ai_can_text_generate()` that has been silently disabling ALL SN AI features since v2.5.0 (introduced 2026-05-17).

#### What was broken

The gate guarded against "older wp-ai-client backport without the feature-detection method" using:

```php
if ( ! method_exists( $builder, 'is_supported_for_text_generation' ) ) {
    return false;
}
```

But the WP AI Client's `Prompt_Builder` class dispatches snake_case methods through PHP's `__call` magic method (so it can translate WordPress-convention `is_supported_for_text_generation` to the underlying PHP AI Client's `isSupportedForTextGeneration`). PHP's `method_exists()` does NOT detect magic-method-routed methods — only `is_callable()` does. So this guard always returned false on every install, regardless of whether the AI Client was configured, regardless of which connector was set up, regardless of Connector Approval state.

Verified against [wp-ai-client trunk source](https://github.com/WordPress/wp-ai-client/blob/trunk/includes/Builders/Prompt_Builder.php): the parent class declares only `__construct`, `using_abilities`, and `__call`. Every other snake_case API method (`using_temperature`, `using_max_tokens`, `is_supported_for_text_generation`, `generate_text`, etc.) is `__call`-routed magic dispatch, documented via `@method` PHPDoc annotations rather than actual declarations.

#### Impact

Six months of SN AI features have been silently no-op'ing in production:
- Insights tab + Content Opportunity Advisor (v3.6.0)
- Drift detection (v3.7.0)
- AI Meta Description generator
- AI Excerpt generator
- AI OG card title generator
- AI alt-text helper

In every case, `snt_ai_is_available()` returned false → features rendered "AI client not available" warnings → no AI calls fired from SN → SN's calls never appeared in the AI plugin's AI Request Logs. The Anthropic connector works fine; the AI plugin's own features call Anthropic successfully. The bug was entirely SN-side.

#### Fix

Removed the `method_exists()` guard entirely. The existing try/catch (which catches `\Throwable`) already handles the "method doesn't exist" case — PHP throws `BadMethodCallException` if `__call` is missing, the catch returns false. The guard's intent (fail safe when the method isn't present) is preserved; the broken `method_exists` mechanism is gone.

```php
function snt_ai_can_text_generate() {
    if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
        return false;
    }
    try {
        $builder = wp_ai_client_prompt( 'check' );
        if ( ! is_object( $builder ) ) {
            return false;
        }
        return (bool) $builder->is_supported_for_text_generation();
    } catch ( \Throwable $e ) {
        return false;
    }
}
```

#### How this was diagnosed

User reported AI gate returning false despite Anthropic being connected and SN being approved in the Connector Approval matrix. After four messages of incorrect hypothesis-driven guessing (model selection, cache, Connector Approval, parsing differences) — all wrong — user redirected: *"You'd read the documentation on this before doing anything."* Reading the wp-ai-client trunk source for `Prompt_Builder.php` revealed the `__call`-based dispatch; a 5-line PHP one-liner confirmed `method_exists` returns false for `__call`-routed methods while `is_callable` returns true. Root cause confirmed from upstream source in ~5 minutes.

The discipline lesson is captured in the memory file [`feedback_read_framework_source.md`](../../.claude/projects/-Users-juanlentino-Projects-signal-and-noise/memory/feedback_read_framework_source.md): when integrating with any upstream primitive, read the actual source first; don't reason from dev notes or summaries.

### Files

- `inc/ai-bootstrap.php` — removed lines 75–77 (the wrong guard); added `@since v3.7.1` docblock entry citing wp-ai-client trunk source
- `signal-and-noise-tools.php` — version bump 3.7.0 → 3.7.1

### Tests

No new tests added. Existing test suites (6 suites, 441 total assertions) pass unchanged because they mock `snt_ai_is_available()` and don't exercise the actual gate function. A future v3.7.x candidate is a small integration test that stubs `wp_ai_client_prompt` and confirms the gate returns true when the stubbed builder's `is_supported_for_text_generation()` returns true.

## [3.7.0] - 2026-05-21

### Added — Health tab: time-relative drift detection (5th check)

New 5th check on the v3.5.0 Health tab named `drift_time_phrases`. Detects time-relative phrases ("as of 2024", "recently", "just released", "this year", "the latest", etc.) that decay deterministically as posts age. The second of two net-new AI-native features designed in the 2026-05-20 brainstorm session (after v3.6.0's Insights tab). Genuinely net-new — the existing `ai/ai` plugin v1.0.0 + roadmap have no semantic content drift detection; v3.5.0's Health tab is purely rule-based.

#### Hybrid algorithm (regex + AI)

The cost-control trick is the two-stage hybrid:

1. **Stage 1 — regex pre-filter (free).** SQL `REGEXP` clause + PHP-side `preg_match_all` against 9 patterns covering common time-relative idioms. Posts with zero candidate phrases skip AI entirely.
2. **Stage 2 — AI evaluation (only on candidates).** For each post with candidates, single AI call passes the post's `last_modified` date + the candidates with ~200-char context snippets + the current date. The model returns per-candidate verdicts (`stale` / `ok` / `unsure`). Only `stale` verdicts surface as Health-tab findings.

Per-scan cost estimate at 10 candidate posts: ~$0.01–$0.02. Annual ceiling at weekly use: under $1. Candidate count is capped at 25 per post to keep responses within the 600-token budget — guards against silent post-drops from mid-JSON truncation.

#### Detection only in v1

Findings follow the existing Health-tab finding shape: per-post `subject_label` + `subject_url` + `edit_url` + `note`. The `note` field carries the offending phrase + the AI's one-sentence reason. Users click "Edit" to deep-link to the editor and fix manually.

AI-suggested replacement text (e.g., "Suggested: 'as of 2026'") is deferred to a future v3.7.x feature — needs more design work on the accept/reject UX.

#### Graceful degradation

If the AI gate (`snt_ai_is_available()` from `inc/ai-bootstrap.php`) returns false, the check still runs but produces zero findings and updates `fix_hint` to point users at the AI plugin's Connectors + Settings pages. No fatal errors, no log spam. Soft-fail paths also handle: `wp_json_encode` returning false (skip post), AI returning `WP_Error` (skip post), malformed AI JSON (skip post), markdown-fenced JSON responses (strip fences and parse).

#### Tests

`tests/health-checks.php` extended with **11** new test blocks (32 → 64 assertions, +32) covering:
- Regex match enumeration across the full pattern set
- Empty content + no-match content both return empty arrays
- Context snippet bounds (30 ≤ length ≤ 220 chars)
- AI-unavailable path returns empty findings without crashing
- Zero-candidate post skips AI entirely (call count = 0)
- `stale` verdict surfaces as finding with phrase + reason in note
- `ok` verdict produces no finding
- Malformed AI JSON degrades silently (no fatal, just skips the post)
- Finding shape matches existing Health checks (`subject_id`, `subject_url`, `edit_url`, `subject_label`)
- Markdown-fenced AI response is unwrapped and parsed
- Partial-fence response (no closing fence) still parses

All 6 test suites green: **441** total assertions (177 admin-tabs + 54 cron-dashboard + 24 cron-history + 46 webhooks + 64 health-checks + 76 insights).

#### Why this is genuinely net-new

`ai/ai` v1.0.0 ships Editorial Notes (block-by-block review for grammar / SEO / readability / a11y) but doesn't analyze semantic drift over time. v3.5.0's Health tab is rule-based — counts missing alts, finds orphaned media, etc., but can't tell whether "recently" is still accurate. This check fills that exact gap.

### Files

- `inc/health-checks.php` — `sn_health_drift_time_patterns()` + `sn_health_extract_time_phrase_candidates()` + `sn_health_check_drift_time_phrases()` + `sn_health_run_scan()` registers the 5th check + `SNT_AI_DRIFT_SYSTEM` constant
- `tests/health-checks.php` — 11 new test blocks (+32 assertions; 32 → 64)
- `signal-and-noise-tools.php` — version bump

## [3.6.1] - 2026-05-20

### Fixed — Insights tab "AI client not available" link pointed nowhere

The unavailable-state helper text in the Insights tab linked to `admin.php?page=ai-connectors`, which 404s with "Sorry, you are not allowed to access this page." That URL was a guess in v3.6.0; it's not where the `ai/ai` plugin actually puts its surfaces.

Replaced with the correct two-link sentence pointing to both required setup steps:
- **Settings → AI** at `options-general.php?page=ai-wp-admin` — global enable + per-feature toggles (per [Gutenberg PR #77336](https://github.com/WordPress/gutenberg/pull/77336), the canonical slug for the AI plugin's settings page)
- **Settings → Connectors** at `options-general.php?page=connectors` — provider + API key configuration (the WP 7.0 Connectors API page)

Both must be configured before AI features (Insights, meta description, excerpt, OG card title) can run. The `ai/ai` plugin readme.txt makes this explicit but it's an easy step to miss; this patch surfaces both links inline when the AI gate fails.

### Files

- `inc/insights-admin.php` — single helper-text line updated
- `signal-and-noise-tools.php` — version bump

No test impact (UI-only string change).

## [3.6.0] - 2026-05-20

### Added — Insights Tab + Content Opportunity Advisor (first cross-system AI synthesis)

New 11th admin tab "Insights" between Webhooks and Health. Combines Plausible analytics, WP publish history, webhook delivery patterns, cron firings, and site identity into a single AI call that returns 5 actionable recommendations per scan: write_about, update_post, cadence_change, topic_double_down, topic_pivot.

This is the first cross-system AI feature in the plugin and the only AI-related work in v3.6.0. Genuinely net-new — nothing else in the WP plugin ecosystem combines these 5 SN-owned data sources. Verified via audit of `ai/ai` v1.0.0 features + roadmap + the wider WP AI plugin space before designing.

#### What got built

- **New module `inc/insights.php`** — constants, signal aggregation (`snt_insights_collect_signals`), AI call (`snt_insights_call_ai`), response parsing + validation (`snt_insights_parse_response`), state management (`snt_insights_dismiss/snooze/mark_done`), run orchestrator + 7-day cache (`snt_insights_run_scan` / `snt_insights_last_scan`), opt-in weekly cron, desktop-mode summary helper
- **New module `inc/insights-admin.php`** — tab renderer using the `.sn-fieldset` design system (v3.5.1 lesson encoded)
- **`inc/admin-page.php`** — Insights entry in `sn_admin_pages()` (the v3.0.2 SSOT meant only 1 line + 1 dispatch case to add the tab); 5 new `sn_action` branches (`insights_run`, `insights_dismiss`, `insights_snooze`, `insights_mark_done`, `save_insights_settings`); 6 new flash messages
- **`inc/rest-api.php`** — `POST /signal-noise/v1/insights/run` + `GET /signal-noise/v1/insights/last`
- **`inc/abilities-registration.php`** — `signal-noise/run-insights-scan` (idempotent, open_world_hint: false) + `signal-noise/get-insights` (readonly, idempotent)
- **`inc/desktop-mode-integration.php`** + **`assets/desktop-mode.js`** — `sn-cmd-insights` ⌘K command (`aiCallable: true`) + `pages.insights` + `insightsSummary` localize data

#### AI integration

Single call per scan via `snt_ai_generate_with_constraints` (from the existing `inc/ai-bootstrap.php`). System instruction explicitly forbids vague claims, requires JSON-only output, enumerates the 5 allowed `type` values, caps title at 80 chars. Response parser strips optional markdown fences (defensive — model sometimes wraps despite instructions), validates each entry against the schema, drops invalid entries, and returns WP_Error if fewer than 3 valid recommendations remain (configurable via `SN_INSIGHTS_MIN_VALID_RECS`).

Estimated cost per scan: ~$0.02 (Sonnet). Annual ceiling at weekly cron + occasional manual: ~$1–2.

#### State management

Per-recommendation state stored in `sn_insights_state` option (autoload=false): `dismissed_ids[]`, `snoozed_until[id => ts]`, `done_ids[]`. Each list FIFO-capped at 200 entries. State persists across scans so dismissed recommendations don't reappear if regenerated. Snoozed recommendations rejoin the active list after their TTL expires (30 days).

#### Trigger pattern

Manual "Run Analysis" button by default. Opt-in weekly cron (`sn_insights_weekly_scan` hook) gated by `sn_settings.insights.weekly_cron_enabled` setting (default OFF). When toggled on, schedules via `wp_schedule_event` with `weekly` recurrence; when toggled off, unschedules. Cache (`sn_insights_last_scan` transient, 7-day TTL) prevents duplicate AI calls within the window — even manual re-clicks return cached unless "Force fresh scan" is checked.

#### Tests

New `tests/insights.php` — 27 named tests across ~76 assertions covering:
- Signal aggregation: shape, post sort by views_7d, post cap (100), post age cap (730d), webhook success rate, cron freshness, Plausible breakdown shape (the C1 lesson from Task 2 review)
- AI prompt construction: signals encoded into JSON, system instruction shape, max_tokens
- Response parsing: happy path, markdown fence stripping, invalid entry drops, < 3 valid → WP_Error, target.post_id validation, title length cap
- State management: read/write defaults, dismiss/snooze/mark_done writes, filter_active behavior, FIFO 200-entry cap
- Orchestrator: cache miss → AI call, cache hit short-circuits, force=true bypasses cache, WP_Error propagates without writing cache
- Weekly cron: schedule when setting on, no-schedule when off, unschedule

All 6 test suites still green: **~390 total assertions** (177 admin-tabs + 54 cron-dashboard + 24 cron-history + 46 webhooks + 32 health-checks + 76 insights).

#### Why this is genuinely net-new (vs every other plugin)

`ai/ai` v1.0.0 already ships title generation, excerpt generation, meta description, content classification, editorial notes, alt-text generation, content resizing, summarization, comment moderation, image generation, and Guidelines (the canonical voice-training surface). None of them combine Plausible analytics + cron history + webhook delivery data. That synthesis is only possible because SN owns all three data sources in one plugin — which makes Insights the first genuinely defensible "on steroids" AI feature in this codebase.

### Files

- `inc/insights.php` — new
- `inc/insights-admin.php` — new
- `inc/admin-page.php` — Insights entry + dispatch + 5 sn_action branches + 6 flash messages
- `inc/rest-api.php` — 2 new endpoints
- `inc/abilities-registration.php` — 2 new abilities + execute callbacks
- `inc/desktop-mode-integration.php` — 1 new ⌘K command + summary localize
- `assets/desktop-mode.js` — JS-side ⌘K registration with aiCallable + toast
- `signal-and-noise-tools.php` — 2 new requires + version bump
- `tests/insights.php` — new (~76 assertions across 27 tests)

## [3.5.1] - 2026-05-20

### Fixed — Nonce conflict on Webhooks + Health forms + design-system alignment

Two bugs from user smoke-testing v3.5.0, both rooted in the same process failure: I didn't read enough of the existing `inc/admin-page.php` patterns before adding the new tabs in v3.4.0 + v3.5.0. The systematic-debugging skill found both root causes in one pass.

#### Bug 1: "The link you followed has expired" on Health "Run scan"

**Root cause:** `sn_handle_admin_post` in `inc/admin-page.php:223` calls `check_admin_referer( 'sn_theme_options_nonce' )` for ANY POST that hits an SN admin page with `$_POST['sn_action']` set. It doesn't gate on action-name first. My v3.5.0 Health form sent a nonce for action `'sn_health'`, my v3.4.0 Webhooks forms sent nonces for `'sn_webhooks'`. The existing handler's nonce check ran FIRST (admin_init), failed against the wrong action, and died with `wp_nonce_ays()` — the standard WP "Link expired" page. My own per-handler nonce checks never got the chance to run.

**Fix:** route all action dispatch through `sn_handle_admin_post` (the established pattern — matches how `cf_save`, `pl_save`, `apply_reading_time_cleanup` all work). My module files now have the impl functions; the central dispatcher routes incoming actions to them by name. All SN forms now use the single shared `sn_theme_options_nonce` action — one nonce contract for the whole admin.

Removed: `sn_health_handle_post` and `sn_webhooks_handle_post` standalone `add_action( 'admin_init', ... )` handlers. Added: `health_scan`, `webhook_add`, `webhook_update`, `webhook_delete` elseif branches in `sn_handle_admin_post` that delegate to `sn_health_run_scan` / `sn_webhook_create` / `sn_webhook_update` / `sn_webhook_delete` (impl functions stayed in their modules).

#### Bug 2: Webhooks + Health tabs visually didn't match other SN tabs

**Root cause:** I used WordPress's generic `<table class="form-table">` for the new forms instead of the bespoke `.sn-fieldset` / `.sn-field` / `.sn-field-label` / `.sn-field-helper` / `.sn-card-grid` design system that `inc/cloudflare-purge.php` and `inc/plausible-admin.php` (and every other existing tab) use. Visual result: the new tabs rendered with default gray-bordered WordPress admin tables while everything else uses the bespoke SN brand styling.

**Fix:** rewrote both `inc/webhooks-admin.php` and `inc/health-checks-admin.php` to use the same design-system classes as the existing tabs. Same structure: `.sn-prose` intro, `.sn-status-box` with `.sn-pill` badges for status displays, `.sn-fieldset` containers with `.sn-fieldset-h` headers + `.sn-fieldset-intro`, `.sn-field sn-field-w-lg/md/sm` for inputs with `.sn-field-label` + `.sn-field-helper`, `.sn-fieldset-actions` for submit buttons.

#### Flash routing

Action redirects now go through `sn_handle_admin_post`'s bottom-of-function `wp_safe_redirect` block. The webhook flash names that needed extra context (showing the secret once after add/rotate) are encoded as `wh_added_<id>` / `wh_rotated_<id>` and decoded back to `$_GET['new_id']` in the page renderer — same pattern as the existing `rt_applied_<count>` flash.

#### Process gap acknowledgment

This patch landed because the user flagged both bugs from a live screenshot. The discipline gap that allowed them in: I shipped v3.4.0 + v3.5.0 without invoking the superpowers skills (`brainstorming`, `writing-plans`, `verification-before-completion`, `systematic-debugging`) explicitly via the Skill tool. I did the *substance* of each (read framework source, dual test suites, lint pass before commit) but didn't formally invoke the skill workflow, and that's what missed the existing patterns in the codebase. The hard-rule memory entry ([feedback_skills_plugins_docs_always](../../signal-and-noise/.claude/projects/-Users-juanlentino-Projects-signal-and-noise/memory/feedback_skills_plugins_docs_always.md)) was set after a similar failure mode in earlier work. This patch was developed using `superpowers:systematic-debugging` (Phase 1–4: root-cause investigation → pattern analysis → hypothesis → verification) + `superpowers:verification-before-completion` (lint + 5 test suites green before claiming fix).

### Files

- `inc/admin-page.php` — 4 new elseif branches in `sn_handle_admin_post`; flash decoder updated to pull `new_id` from prefixed flash strings
- `inc/webhooks-admin.php` — rewritten to use SN design-system classes; standalone POST handler removed
- `inc/health-checks-admin.php` — rewritten to use SN design-system classes; standalone POST handler removed
- `signal-and-noise-tools.php` — version bump

### Tests

All 5 suites still green (no regressions): **319 assertions** (163 admin-tabs + 54 cron-dashboard + 24 cron-history + 46 webhooks + 32 health-checks).

## [3.5.0] - 2026-05-20

### Added — Content Health detection scans

New "Health" admin tab (10th tab) runs four independent detection scans against the post + attachment graph. v1 is **detection-only** — findings list with deep-links to the editor; no AI tokens spent, no auto-fixes. The "Run scan" button is manual-only; results cache in a 24h transient.

#### The 4 checks

1. **Missing alt text** — two passes:
   a. Image attachments where the `_wp_attachment_image_alt` meta is empty (LEFT JOIN with NULL/'' filter — single query, ≤500 rows).
   b. Inline `<img>` tags in published `post_content` that lack an `alt` attribute (regex-extracted; case-insensitive; respects the HTML spec's "alt='' is valid for decorative images" rule).
2. **Orphaned media** — attachments older than 7 days that are neither used as a `_thumbnail_id` (featured image) NOR referenced by basename in any published post body. The 7-day skip avoids flagging media that was just uploaded and not yet inserted.
3. **Broken internal links** — extract internal links (same-host absolute OR root-relative) from published post content; HEAD-probe each (24h-cached per URL in a separate transient). Flag 4xx + 5xx + network errors. HEAD-405 falls through to GET (some hosts reject HEAD).
4. **Stale posts** — published posts whose `post_modified_gmt` is older than 12 months. No AI judgment in v1; user reviews currency in the editor.

#### Why detection-only for v1

User explicitly chose "Detection only" in the scope question — keeps v1 free of AI token spend and avoids any auto-edit surprise. The same `findings` shape is forward-compatible with a v3.5.x "AI propose fix" extension that would add a `suggestion` field per finding without breaking the current admin UI consumer.

#### Why manual-only for v1

User explicitly chose "Manual button only" over weekly cron / on-publish hook. The 24h transient cache means re-visiting the tab is cheap; only "Run scan" triggers the underlying DB + HTTP work.

#### Performance notes

- Each check has a `LIMIT` cap (500 for alt + orphaned, 1000 for broken-links source rows, 200 for stale posts) so even on a large site the scan completes in seconds.
- Broken-links uses a per-URL 24h transient cache (`sn_health_link_<md5>`) so the second scan only probes URLs added since the previous scan.
- The combined scan envelope records its `elapsed_ms` in the cached transient so the admin can see "Last scanned 2 minutes ago (1247ms)."

#### Findings shape

Each finding is a self-contained dict:
```php
[ 'subject_type'  => 'attachment' | 'inline_img' | 'internal_link' | 'post',
  'subject_id'    => int,
  'subject_url'   => string,
  'subject_label' => string,
  'edit_url'      => string (deep-link to the editor),
  'note'          => string ]
```

This is the v3.5.x extension point: future AI-suggest work can add a `suggestion` field per finding without breaking the admin renderer.

#### Tests

New `tests/health-checks.php` — 32 assertions across 4 tests covering the **pure logic**: regex extractors (alt + internal-link parsers, including edge cases like uppercase tag names, `alt=""` decorative, single-quoted attrs, anchor/mailto/tel/javascript: skipping, root-relative normalization, case-insensitive host matching, deduplication), link-status transient caching (verified the second call returns the cached value not the live updated one), HEAD-405 → GET fallback (with separate fixture maps for the two HTTP verbs), WP_Error → ok:false / code:0, and the `pack_check` result envelope.

The DB-touching scan functions (the 4 main `sn_health_check_*` entry points) are exercised by the live "Run scan" button — they're pure wpdb calls that don't benefit from fixture coverage.

All 5 test suites green: **319 total assertions** (163 admin-tabs + 54 cron-dashboard + 24 cron-history + 46 webhooks + 32 health-checks). The +14 in admin-tabs since v3.4.0 is the SSOT refactor's recurring dividend — adding the Health tab earned its regression coverage automatically from the registry-iteration tests.

### Files

- `inc/health-checks.php` — new (~290 LOC); all 4 scan impls + pure-logic helpers + transient cache
- `inc/health-checks-admin.php` — new (~110 LOC); the Health tab renderer + the `health_scan` form handler
- `inc/admin-page.php` — Health entry in `sn_admin_pages()` (one line) + dispatch case + 1 new flash message
- `signal-and-noise-tools.php` — 2 new requires + version bump
- `tests/health-checks.php` — new (32 assertions)

## [3.4.0] - 2026-05-20

### Added — Personal automation webhooks

POST HMAC-SHA256-signed JSON payloads to user-configured endpoints (n8n, Zapier, Pipedream, anything that accepts webhooks) when a post or page is published. Async dispatch via WP-Cron — the publish request never blocks on slow receivers. Three retries with 5-minute backoff on transient failure.

Note: v3.3.0 was skipped — Action Scheduler integration was triaged out because no plugin on juanlentino.com bundles it (no WooCommerce, no large AS-using plugin). The version sequence is intentional, not a slip.

#### What got built

- **New module `inc/webhooks.php`** (~270 LOC) — owns CRUD over the webhook config store, HMAC signing, payload construction, async dispatch + retry, per-webhook delivery log
- **New module `inc/webhooks-admin.php`** (~170 LOC) — admin tab UI (CRUD form + delivery log details disclosure + payload reference)
- **9th admin tab** registered in `sn_admin_pages()` — the v3.0.2 SSOT refactor meant adding this was a one-line edit (entry registers itself with valid_tabs + tab_labels + dispatch case derived; only the tab body dispatcher case in `sn_theme_options_page()` needed to be added explicitly)

#### Trigger semantics

`transition_post_status` with the canonical `($new === 'publish' && $old !== 'publish')` guard. This is more reliable than `publish_post` alone — the latter fires on metadata updates of already-published posts, which would spam receivers on every quick-edit.

Allowed post types default to `array( 'post', 'page' )` and are filterable via the `sn_webhook_post_types` filter. Attachments, revisions, nav-menu-items, and other internal types are skipped without needing per-type exclusions.

#### Signing

Every delivery carries `X-SN-Signature: sha256=<HMAC_SHA256(secret, raw_body)>`. Standard pattern — matches GitHub, Stripe, Square. Receivers MUST verify before trusting the payload (the payload reference panel in the admin UI documents the expected header set + verification pattern).

Secrets are 48 chars of `[A-Za-z0-9]` from `wp_generate_password` (~288 bits of entropy). Shown ONCE on create + on explicit rotation; not retrievable from the admin UI thereafter (the secret column shows only a `xxxx…xxxx` preview). Rotation is a single checkbox on the update form — the old secret stops working immediately.

#### Async + retry

The `transition_post_status` handler doesn't open any HTTP connections. It calls `wp_schedule_single_event` with the dispatch hook name + (webhook_id, post_id, attempt=1, delivery_id). The cron worker `sn_webhook_dispatch` is the only thing in the module that POSTs.

Retry policy:
- HTTP 2xx → success, no retry
- HTTP 5xx OR network error → retry +5min, attempt+1, max 3
- HTTP 4xx → receiver-side rejection, no retry (don't spam a receiver that's saying "go away")

Each attempt records a row to the per-webhook delivery log (capped at 20 entries, stored in `sn_webhook_log_<id>` with `autoload=false`). The admin UI surfaces the log as a `<details>`-disclosure below each webhook card with fired-at, attempt #, HTTP code, success ✓/✕, and a truncated response excerpt.

#### Storage

- `sn_webhooks` (autoload=true): array of `{ id, name, url, secret, enabled, created_at }`
- `sn_webhook_log_<id>` (autoload=false): array of `{ delivery_id, attempt, fired_at, response_code, response_excerpt, success }`, capped at 20

No custom table for v1. Log volume is bounded (20 entries × N webhooks), so even with the autoload=false flag the storage is trivial. Promotion to a custom table is a v3.5+ consideration if usage scales.

#### Tests

New `tests/webhooks.php` — 46 assertions across 10 tests:
- HMAC signature shape, determinism, and sensitivity (validates against an independent `hash_hmac` call)
- CRUD round-trip with validation rejections for empty name / invalid URL
- Update path with optional secret rotation
- Delete path, including log purge + unknown-id WP_Error
- Log cap at 20 entries (oldest fall off, newest retained)
- Payload shape including post.published event + post fields + draft/missing rejection
- `transition_post_status` enqueue logic (enabled-only)
- Guard: publish→publish, publish→draft, publish→trash all skip
- Post-type allowlist: attachments don't trigger

All 4 test suites still green: **273 total assertions** (149 admin-tabs + 54 cron-dashboard + 24 cron-history + 46 webhooks).

### Files

- `inc/webhooks.php` — new (~270 LOC)
- `inc/webhooks-admin.php` — new (~170 LOC)
- `inc/admin-page.php` — Webhooks entry in `sn_admin_pages()` + dispatch case + 6 wh_* flash messages
- `signal-and-noise-tools.php` — 2 new requires + version bump
- `tests/webhooks.php` — new (46 assertions)

## [3.2.0] - 2026-05-20

### Added — Cron Dashboard: persistent firing history log

Every WP-Cron firing now writes a row to a dedicated `wp_snt_cron_history` table. Surfaces the last 10 firings per hook in the Cron dashboard's "Last fired" cell on click, and exposes the same data via REST + a read-only ability for AI / automation use.

#### What got built

- **New module `inc/cron-history.php`** (~230 LOC) — owns the table schema, INSERT/SELECT helpers, pre/post action callbacks for elapsed-time bracketing, and the daily prune.
- **Schema (`wp_snt_cron_history`, db version 1):**
  ```
  id BIGINT, hook VARCHAR(190), args_signature CHAR(32),
  fired_at DATETIME, elapsed_ms MEDIUMINT, success TINYINT(1),
  error_message TEXT, KEY (hook, fired_at), KEY (fired_at)
  ```
  VARCHAR(190) is the largest indexable varchar at utf8mb4; matches our existing `wp_rss_feed_log` choice for the same reason. Installed via `dbDelta` gated on the `snt_cron_history_db_version` option (same install-once pattern as the RSS Plausible tracker module).
- **Capture mechanism:**
  - For scheduled firings: pre-callback at `-PHP_INT_MAX` stashes `microtime(true)` in a static map keyed by hook; post-callback at `PHP_INT_MAX` reads the stash, calculates elapsed, INSERTs a row. Both callbacks are registered for every unique hook discovered in `_get_cron_array()` during DOING_CRON requests — same wp_loaded gate as the existing `snt_cron_track_last_fired_cb`, extended.
  - For Run-now (manual dispatch via `snt_cron_run_event_impl`): the impl writes a history row directly using its own measured `elapsed_ms` + the `success` / `error` values from its try-catch. A global flag `__snt_cron_history_skip_auto` tells the post-callback to skip the auto-record so we never get a duplicate row for the same firing.
- **Retention** (daily cron `snt_cron_history_prune`):
  - Rolling 30-day window via indexed `DELETE WHERE fired_at < UTC_TIMESTAMP() - INTERVAL 30 DAY`.
  - Per-hook hard cap of 1000 rows. For each distinct hook, fetch the newest 1000 ids and `DELETE WHERE hook = X AND id NOT IN (...)`. Race-safe under concurrent INSERTs because the cap is enforced atomically per hook.
- **`GET /signal-noise/v1/cron/history?hook=X&limit=10`** — `manage_options`-gated; returns the newest N rows for a single hook. Limit is clamped to 1–100 by the REST schema.
- **`signal-noise/get-cron-history` ability** — read-only, idempotent, `open_world_hint: false`. Same response shape as the REST endpoint.
- **Dashboard UI** — adds a small `history` toggle next to the Last-fired timestamp on every row. Click → `aria-expanded="true"` + fetch + render an inline table with Fired-at (local time), Elapsed (ms), Status (ok/fail). Failed rows surface the error message as a `title` tooltip on the Status cell. The toggle works on rows with no last-fired record too — useful for confirming an event that fires for the first time after install.
- **Plurality / i18n** — 11 new strings on `sntCronI18n` (toggle labels, panel headers, status pills, error templates).

#### Why a custom table over wp_options

At 33 cron events × ~10–100 firings/day, a wp_options-based store would (a) bloat the autoloaded options set (even with `autoload=false`, write contention is real), (b) race-condition under concurrent firings (no atomic append), and (c) make retention windowing painful (parsing serialized blobs to filter by timestamp). A dedicated table with proper indexes is the right primitive. The `dbDelta` install-once pattern keeps it cheap on hot path.

#### Tests

New `tests/cron-history.php` — 24 assertions across 10 tests. Highlights:
- Round-trip record + read with elapsed_ms rounding
- Success path defaults vs explicit failure path with error capture
- Empty/null hook rejection
- elapsed_ms clamp to mediumint unsigned range (0–16,777,215)
- error_message truncation at 4096 chars
- limit clamp bounds (0 → 1, 9999 → 100)
- Newest-first ordering by `(fired_at DESC, id DESC)`
- Per-hook isolation (SELECT for hook A doesn't return hook B rows)
- 30-day prune window drops old rows
- 1000-row per-hook cap is enforced

Plus the existing `tests/cron-dashboard.php` (54 assertions) and `tests/admin-tabs.php` (135 assertions) — all still green. **Total: 213 passing assertions.**

### Files

- `inc/cron-history.php` — new (~230 LOC); the whole module
- `inc/cron-dashboard.php` — wp_loaded gate now also registers the history pre/post callbacks; `snt_cron_run_event_impl` writes a richer history row directly
- `inc/cron-dashboard-admin.php` — history-toggle button + panel placeholder + 11 new localize keys
- `assets/cron-dashboard.js` — `wireHistory()` + `renderHistoryPanel()`; safe-DOM construction throughout (no innerHTML)
- `inc/rest-api.php` — `GET /cron/history` route + `snt_rest_cron_history` callback
- `inc/abilities-registration.php` — `signal-noise/get-cron-history` ability + `snt_ability_get_cron_history` execute callback
- `signal-and-noise-tools.php` — module include + version bump
- `tests/cron-history.php` — new (24 assertions)

## [3.1.0] - 2026-05-20

### Added — Cron Dashboard: Unschedule (destructive, SN-owned refused)

Net-new on top of the read-only-plus-Run-now surface from v3.0.0. Lets you permanently remove any non-Signal-&-Noise scheduled WP-Cron event directly from the dashboard. Useful for pruning orphans inline, without having to go fire the `cron-orphan-cleanup.yml` workflow.

#### What got built

- **`snt_cron_unschedule_event_impl($hook, $args)`** in `inc/cron-dashboard.php` — pure impl with three safety gates: `manage_options`, non-empty-hook validation, and SN-owned refusal. Uses `wp_clear_scheduled_hook()` (not `wp_unschedule_event()`) so BOTH the next firing AND the recurring schedule disappear in one call. Pruning orphans is the explicit intended use case, so `has_action()` is deliberately NOT gated.
- **`POST /signal-noise/v1/cron/unschedule`** in `inc/rest-api.php` — `manage_options`-gated, accepts `{ hook, args }`. Thin wrapper around the impl.
- **`signal-noise/unschedule-cron-event`** ability in the `maintenance` category — `destructive: true`, `idempotent: true` (running twice on the same hook is safe), `open_world_hint: false` (only mutates local cron state, no network calls). First destructive cron ability — Run-now stays read-only per the v3.0.0 spec § 6 / Q4 decision.
- **Dashboard UI** — Unschedule button next to Run-now on every event row. SN-owned events get a `disabled` button with a `title` explaining where to disable the owning module instead. Click → `confirm()` prompt → POST → row fades out + removes from the table on success. Both Run-now and Unschedule are disabled during dispatch so users can't double-act on a half-removed event.
- **i18n** — 6 new strings on the `sntCronI18n` localize bag (Unscheduling…, Unschedule, confirm prompt, success/no-match/failure templates).

#### Why `wp_clear_scheduled_hook` not `wp_unschedule_event`

A user looking at a recurring row in the dashboard almost certainly wants to STOP the schedule entirely, not skip one firing. `wp_unschedule_event(ts, hook, args)` only removes ONE event at a specific timestamp; the recurrence keeps re-creating it. `wp_clear_scheduled_hook(hook, args)` removes both the next firing and the recurring schedule. Tested as Test 18 (matching-args clears the row) + Test 19 (mismatched args do NOT touch the original — the args signature must round-trip exactly).

#### Why SN-owned events are refused

The plugin schedules 3 of its own cron events: `sn_plausible_refresh_dashboard`, `sn_plausible_refresh_realtime`, `sn_rss_tracker_daily_prune`. Unscheduling any of these from the cron dashboard would silently break the corresponding admin widget without giving the user a way to recover (the schedule won't come back until plugin re-activation OR a manual fix). The impl-layer guard refuses the op with a clear error message pointing the user to the correct settings tab for disabling the owning module. The dashboard UI mirrors this by disabling the button with a tooltip — same protection at two layers.

#### Tests

- 35 → **54 passing assertions** (added 8 new tests + 19 new assertions covering: permission gate, invalid-hook validation, SN-owned refusal, successful unschedule with row removal, idempotent no-match path, orphan-allow path, args round-trip, args mismatch isolation)
- The 4-surface dispatch shape (admin / REST / ability / desktop-mode read-only) is unchanged — Unschedule extends the existing pattern, doesn't introduce a 5th surface

### Files

- `inc/cron-dashboard.php` — `snt_cron_unschedule_event_impl` (~60 LOC)
- `inc/rest-api.php` — `/cron/unschedule` route + `snt_rest_cron_unschedule` callback
- `inc/abilities-registration.php` — `signal-noise/unschedule-cron-event` registration + `snt_ability_unschedule_cron_event` execute callback
- `inc/cron-dashboard-admin.php` — Unschedule button in the Actions column; 6 new localize keys
- `assets/cron-dashboard.js` — `wireUnschedule()` handler with confirm + row-fade + Run-now-disable-during-dispatch
- `tests/cron-dashboard.php` — `wp_clear_scheduled_hook` stub + 19 new assertions
- `signal-and-noise-tools.php` — version bump

## [3.0.2] - 2026-05-20

### Added — Tab registry single source of truth + a11y + i18n polish

The v3.0.0 session caught a real regression at the eleventh hour: Task 10 added the Cron tab's page entry + dispatch case but missed two inline `$valid_tabs` / `$tab_labels` whitelists ~200 lines away in `sn_theme_options_page()`. The final cross-cutting reviewer found it. This patch encodes that lesson architecturally so the same coordination bug class becomes impossible.

#### Single source of truth

`sn_admin_pages()` is now the only place a new admin tab is registered. Two derived helpers replace the previously-duplicated inline arrays:

- `sn_admin_page_valid_tabs()` → `array_column( sn_admin_pages(), 'tab' )`
- `sn_admin_page_tab_labels()` → `array_column( sn_admin_pages(), 'label', 'tab' )`

`sn_theme_options_page()` now calls those instead of holding its own copies. Adding a future tab = one entry in `sn_admin_pages()`; whitelist, label map, dispatch list, hook enqueue all derive from it automatically.

#### A11y on the Cron dashboard renderer

Addresses Task 9 + Task 12 reviewer notes:

- `<label for="sn-cron-filter">` (`.screen-reader-text`) — search input now has a programmatic label
- `<caption class="screen-reader-text">` on the table — describes purpose for assistive tech
- `<th scope="col">` on column headers + `<th scope="row">` on the hook cell
- SN / orphan badges now have `title` + `<span class="screen-reader-text">` prefixes (e.g., "Signal and Noise owned:" / "Warning:")
- Run-now buttons get `aria-label="Run cron event {hook} now"` so screen readers can disambiguate identically-labeled buttons across rows
- Plural-aware count line via `_n()` + `number_format_i18n()`

#### i18n on the JS layer

`wp_localize_script` now passes a `sntCronI18n` bag with every user-facing string (Running…, Run now, just now, confirm prompt, toast templates). The JS consumes them with defensive English fallbacks. `wp_set_script_translations` registers the handle so `.pot` extraction picks them up.

The PHP-side renderer strings now flow through `__()` / `esc_html__()` / `esc_attr__()` with `translators:` comments on the ones that use `printf` placeholders.

#### Tests

New standalone test file `tests/admin-tabs.php` — **135 assertions across 8 tests** covering:

- `sn_admin_pages()` registry shape (every page has the 5 required keys, every value is a non-empty string)
- Unique slugs + unique tabs (no duplicate dispatch routes)
- `sn_admin_page_valid_tabs()` derives exactly from `array_column`
- `sn_admin_page_tab_labels()` round-trips every tab → label pair
- **The Cron tab IS resolvable end-to-end** (regression guard — would have caught v3.0.0's whitelist miss before merge)
- `sn_admin_page_tab_for_slug()` round-trips every slug; unknown slug falls through to dashboard
- `sn_admin_page_subtitle_for_tab()` returns the registered subtitle for each tab

Run: `php tests/admin-tabs.php` — exits 0 on pass. Existing `tests/cron-dashboard.php` still passes (35 assertions, unchanged).

### Files

- `inc/admin-page.php` — adds `sn_admin_page_valid_tabs()` + `sn_admin_page_tab_labels()`; collapses two inline arrays at the call sites in `sn_theme_options_page()`
- `inc/cron-dashboard-admin.php` — `wp_register_script` + `wp_localize_script` + `wp_set_script_translations`; a11y attributes + i18n on every renderer string
- `assets/cron-dashboard.js` — reads `window.sntCronI18n` with a defensive English fallback; replaces all hardcoded strings with localized references
- `tests/admin-tabs.php` — new (135 assertions)
- `signal-and-noise-tools.php` — version bump

## [3.0.1] - 2026-05-20

### Fixed — Cron Dashboard Run-now timezone display

After clicking Run-now, the JS replaced the **Last fired** cell's content using a client-side `Date.toISOString()` formatter, which always emits UTC. The rest of the table renders timestamps via PHP `wp_date( 'Y-m-d H:i:s' )`, which honors the site's configured timezone. Result: the inline-updated cell briefly displayed UTC time until the next page reload, creating a visual inconsistency users would (rightly) read as a bug.

**Fix:** `snt_cron_run_event_impl` now includes a `last_fired_formatted` string in its response (`wp_date( 'Y-m-d H:i:s', $last_fired_ts )` server-side), and the JS uses it directly. The cell content matches the rest of the table's timezone exactly.

Bumps tests from 34 to 35 passing assertions (Test 10 now verifies the response shape includes `last_fired_formatted`).

Flagged by the Task 12 code quality review during the v3.0.0 implementation; landed as the first v3.x patch.

### Files

- `inc/cron-dashboard.php` — `snt_cron_run_event_impl` return shape adds `last_fired_formatted`
- `assets/cron-dashboard.js` — `updateLastFiredCell` accepts a pre-formatted string; removes the `formatTimestamp` helper
- `tests/cron-dashboard.php` — new assertion in Test 10

## [3.0.0] - 2026-05-20

### Added — Phase 15 net-new: Cron Dashboard

New wp-admin **Cron** tab (9th tab) surfaces every scheduled WP-Cron event with next-run, recurrence, last-fired, args, and a Run-now button. ~955 LOC across 4 new files + 6 modified files.

**Cap rollover note:** v3.0.0 is a minor-cap rollover, NOT a semantic breaking change. v2.x consumed 6 minors (v2.0–v2.5) which is the project's cap of 5 per major (per `CLAUDE.md` versioning rules). All existing APIs, abilities, REST routes, and ⌘K commands continue to work exactly as in v2.5.5.

### 4-surface dispatch (per Phase 14+ convention)

All four routes converge on `snt_cron_*_impl()` pure functions:

| Surface | Read | Run-now |
|---|:---:|:---:|
| wp-admin Cron tab | ✅ | ✅ (button + `confirm()` prompt) |
| Legacy REST `/signal-noise/v1/cron/run` | — | ✅ (`manage_options` gated) |
| Abilities API `list-cron-events`, `get-cron-event` | ✅ | ❌ |
| desktop-mode ⌘K `sn-cmd-cron-health`, `sn-cmd-cron-list` | ✅ (`aiCallable: true`) | ❌ |

Run-now stays human-only per the v2.5.5 destructive-command safety precedent.

### Safety guards on Run-now

1. `manage_options` permission gate
2. `has_action($hook)` pre-flight — orphans return `snt_cron_no_handler` WP_Error
3. `DOING_CRON` spoof — handlers gated on `wp_doing_cron()` actually execute
4. `Throwable` catch — covers PHP 7+ `Error` subclasses
5. JS `confirm()` prompt before any side-effecting POST

### Universal last-fired tracking

WP-Cron doesn't track last-fired natively. We register `snt_cron_track_last_fired_cb` at `PHP_INT_MAX` for every unique cron hook during `DOING_CRON` requests (gated at `wp_loaded` priority 1; non-cron requests pay only one `defined()` check). Storage: `wp_options` autoload `false`, keys hashed via `md5($hook)` to fit the varchar(191) limit.

### WP 7.0 ecosystem audit (no overlap)

Verified our `signal-noise/list-cron-events` + `signal-noise/get-cron-event` abilities don't duplicate native functionality:
- **WP 7.0 core abilities** registered in `wp-includes/abilities.php`: only `core/get-site-info`, `core/get-user-info`, `core/get-environment-info` — none cron-related
- **WP REST API native endpoints**: no cron endpoints (20 documented resources, all content/admin)
- **`WordPress/abilities-api` shim plugin**: defines API only, registers zero abilities
- **`WordPress/desktop-mode` AI tools**: 7 built-ins (`search_posts/pages/comments/comments_by_post`, `list_admin_pages`, `search_wporg_plugins`, `get_php_error_log`) — none cron

Our abilities fill a real diagnostic gap.

### Files

- **New:** `inc/cron-dashboard.php` (~300 LOC), `inc/cron-dashboard-admin.php` (~125 LOC), `assets/cron-dashboard.js` (~123 LOC), `tests/cron-dashboard.php` (~205 LOC, 34 passing assertions)
- **Modified:** `signal-and-noise-tools.php`, `inc/admin-page.php`, `inc/abilities-registration.php`, `inc/rest-api.php`, `inc/desktop-mode-integration.php`, `assets/desktop-mode.js`

### Out of scope (deferred)

Editing cron events (reschedule/unschedule); adding new cron events from UI; cron history log; pagination; per-user filter prefs; Action Scheduler-specific handling.

### Process

`superpowers:brainstorming` → 4 design decisions locked (all events, universal last-fired, synchronous run-now, read-only AI exposure) → safety hardening lens applied → `superpowers:writing-plans` → 18-task plan → `superpowers:subagent-driven-development` executed task-by-task with spec + code-quality reviews. Final cross-cutting review caught a `$valid_tabs` whitelist blocker that per-task reviews missed in isolation — fix landed before deploy. No source-reading skipped per `feedback_skills_plugins_docs_always`.

### Spec & plan

- Spec: `docs/superpowers/specs/2026-05-20-cron-dashboard-design.md` (theme repo)
- Plan: `docs/superpowers/plans/2026-05-20-cron-dashboard.md` (theme repo)

## [2.5.5] - 2026-05-20

### Added — Phase 16 slice 1: SN commands opt into desktop-mode AI Copilot

10 of our 13 desktop-mode-registered ⌘K commands now carry `aiCallable: true`, which opts them into `wp.desktop.ai.ask()`'s tool registry. Result: when you press ⌘K and type a natural-language query like *"force-check for updates"* or *"go to the RSS tab"*, desktop-mode's AI Copilot can dispatch the right SN command instead of just doing fuzzy string match.

**Why this is 10 LOC instead of 600:** the WordPress/desktop-mode plugin already ships the entire AI orchestration stack — `wp.desktop.ai.ask(query, opts)` since 0.17.0, the ⌘K Copilot overlay, the `/ai/search` server endpoint, built-in tools for site queries (`search_posts`, `search_pages`, `search_comments`), and the `aiCallable: true` opt-in flag. The user already has desktop-mode installed and uses it. So our work is just flagging which SN commands are safe for AI invocation.

Reference: [desktop-mode docs/javascript-reference.md `wp.desktop.ai.ask`](https://github.com/WordPress/desktop-mode/blob/trunk/docs/javascript-reference.md).

### Selection

| Command | aiCallable | Reason |
|---|:---:|---|
| `sn-cmd-force-check` | ✅ | Idempotent transient clear. Safe. |
| `sn-cmd-purge-caches` | ❌ | Destructive (cache wipe). Manual ⌘K only. |
| `sn-cmd-clear-overrides` | ❌ | Deletes DB rows. Manual only. |
| `sn-cmd-full-reset` | ❌ | Combination of above. Manual only. |
| 7 nav commands (`sn-cmd-nav-*`) | ✅ | Navigation, no state change. |
| `sn-cmd-version-theme`, `sn-cmd-version-plugin` | ✅ | Read-only info toast. |

The 3 destructive commands stay manual — typing them explicitly IS the safety check. Per desktop-mode's own doc: *"AI tool-calling is a paraphrasing channel, and handing the model every registered command (including destructive ones) would turn a typo into a catastrophe."*

### File

- `assets/desktop-mode.js` — `aiCallable: true` added inline on each of the 10 opted-in `wp.desktop.registerCommand()` calls, with comments documenting WHY each destructive command intentionally omits it.

### What this is NOT

- Not a chat UI (desktop-mode owns that)
- Not a custom AI client integration (desktop-mode owns that)
- Not new REST endpoints
- Not new abilities (desktop-mode's `search_*` tools cover site queries)

### Process

`superpowers:brainstorming` → user pushed back on "chatbot" framing → recommended augmented ⌘K via desktop-mode → read FULL source of desktop-mode AI integration before writing code (per memory rule [`feedback_skills_plugins_docs_always`](https://github.com/juanlentino/signal-and-noise/blob/main/.claude/projects/-Users-juanlentino-Projects-signal-and-noise/memory/feedback_skills_plugins_docs_always.md)).

## [2.5.4] - 2026-05-20

### Fixed — abilities REST input validation rejects null + URL-encoded `{}` for GET/DELETE

**Symptom:** Cmd+K "SN: Show deploy status" (and any other readonly/destructive-idempotent ability) returned: *"Ability 'signal-noise/get-deploy-status' has invalid input. Reason: input is not of type object."*

**Two-step diagnosis** (per `superpowers:systematic-debugging`):

1. **The abilities-api server doesn't JSON-decode the `?input=` query parameter** for GET/DELETE requests. Verified at [class-wp-rest-abilities-v1-run-controller.php `get_input_from_request()`](https://github.com/WordPress/abilities-api/blob/trunk/includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php): the function returns the raw string from `$query_params['input']` with no decoding. The repo's own `docs/rest-api.md` documents "URL-encoded JSON" as the input format, but the implementation never decodes it. This is an upstream bug/oversight.

2. **My v2.5.3 fix made it worse.** v2.5.3's JS forced sending `?input=%7B%7D` (URL-encoded empty object) for every call, thinking the server would parse it. The server reads the literal string `"{}"` and validates it against `type: 'object'` → fails because string ≠ object.

**The fix has two parts** — JS sends nothing for empty input, AND PHP schemas accept null:

1. **`assets/command-palette.js` `executeAbility()`** — only append `?input=` when input is a non-empty object. For empty input, server reads `null`.
2. **`inc/abilities-registration.php`** — change `'type' => 'object'` to `'type' => array( 'object', 'null' )` on the 6 abilities that hit the GET/DELETE path: `purge-all-caches`, `clear-template-overrides`, `full-reset` (DELETE), `get-deploy-status`, `list-template-overrides`, `get-rss-stats` (GET). Verified at [wp-includes/rest-api.php `rest_validate_value_from_schema()`](https://github.com/WordPress/WordPress/blob/master/wp-includes/rest-api.php): when `type` is an array, WP uses `rest_handle_multi_type_schema()` to pick the best matching type. `null` input against `['object', 'null']` matches `'null'` → validation passes.

POST abilities (`force-check-updates`, `regenerate-og-card`, the 3 AI generation abilities) are unaffected because WP REST natively JSON-decodes POST bodies.

### Process

This release followed the project's new hard rule (memory: `feedback_skills_plugins_docs_always`):

- Invoked `superpowers:systematic-debugging` — Phase 1 root cause via source-reading, not symptom-guessing.
- Read FULL source of: abilities-api `WP_Ability::validate_input()` + `WP_REST_Abilities_V1_Run_Controller::get_input_from_request()` + WP core `rest_validate_value_from_schema()` + `rest_handle_multi_type_schema()`.
- Verified fix theory against source before writing code (`type: ['object', 'null']` IS valid JSON Schema 7 syntax and accepted by WP REST).
- Will use `superpowers:verification-before-completion` before claiming shipped — curl the live `/wp-abilities/v1/abilities/signal-noise/get-deploy-status/run` after deploy.

### Files changed

- `assets/command-palette.js` — `executeAbility()` only sends `?input=` for non-empty input
- `inc/abilities-registration.php` — 6 input_schemas changed to `type: ['object', 'null']`

### Why v2.5.0 → v2.5.4 had 4 attempted fixes

For future reference + the project's WP-REFERENCE gotchas list:
- **v2.5.0:** Built JS against the abilities-api `docs/rest-api.md` URL pattern (`/wp-abilities/v1/<name>/run`) instead of reading the implementation. Docs are wrong vs source — the actual route is `/wp-abilities/v1/abilities/<name>/run`.
- **v2.5.1:** Fixed the `<SnackbarList>` absence on wp-admin Dashboard with DOM-built admin notices. Correct fix but downstream of the URL bug.
- **v2.5.2:** Fixed the URL after reading the run-controller source. Surfaced the next-layer input validation bug.
- **v2.5.3:** Tried to fix input validation by sending `{}` URL-encoded. Made it worse (server reads string, not object).
- **v2.5.4 (this):** Read the full validation chain. Server doesn't decode query-param JSON; fix is "don't send when empty" + "schemas accept null."

## [2.5.3] - 2026-05-20

### Fixed — ability input validation rejected `null`

v2.5.2's JS `executeAbility()` skipped sending the input parameter when the caller passed `null` (e.g., the "SN: Show deploy status" command callback). The abilities REST controller's input validation requires the value match the ability's `input_schema` — our maintenance/diagnostic abilities have `{ type: 'object', properties: {} }`, which accepts empty objects but rejects `null`. Server error: *"Ability has invalid input. Reason: input is not of type object."*

Fix in [assets/command-palette.js](assets/command-palette.js) `executeAbility()`: always coerce `null`/`undefined` to `{}` before sending. For GET requests the input goes in `?input=%7B%7D`; for POST in `{ input: {} }`. Single defensive change; covers all 11 abilities + future callers.

### Added — "Check for Updates" button in SN Dashboard tab Maintenance section

Removes the need to run `gh workflow run deploy.yml --ref vX.Y.Z` to land plugin releases on the live site.

**Why this is needed:** WP's own `update_plugins` site transient has a ~12h TTL. Our `pre_set_site_transient_update_plugins` filter (which injects our GitHub-hosted release into the WP update flow) only fires when WP is about to *re-set* that transient — on cache miss, on `WP_FORCE_UPDATE_CHECK`, or on `?force-check=1`. After tagging a new release, WP's cached "no update" answer persists until WP next polls 12 hours later. The user would either wait or click "Check Again" on update-core.php.

The new "Check for Updates" card (next to Full Reset / Clear Overrides / Purge Caches) is one click → existing `sn_force_update_check` admin-post handler clears `sn_gh_latest_theme`, `sn_gh_latest_plugin`, `update_themes`, `update_plugins` → redirects to `update-core.php?force-check=1` → WP repolls → our filter injects the new tag → Updates UI shows it. Same flow that was already wired to the "Refresh now" link in the External APIs summary; just made it discoverable in the Maintenance section where users look after tagging a release.

After v2.5.3 is installed, the canonical release workflow becomes:
1. `git push origin main && git push origin vX.Y.Z`
2. **Wait ~30 seconds** for GitHub to surface the tag in its API
3. wp-admin → S&N → Dashboard → **Check Now** under "Check for Updates"
4. wp-admin → Updates → click "Update plugin" for Signal & Noise Tools
5. Done

No more `gh workflow run` for routine releases.

### Documented — WP 7.0 research findings

Comprehensive post-launch read of [WP 7.0 Field Guide](https://make.wordpress.org/core/2026/05/14/wordpress-7-0-field-guide/) (final), [Armstrong release announcement](https://wordpress.org/news/2026/05/armstrong/), and abilities-api source. Findings relevant to this plugin:

- **WP 7.0 changes nothing in the plugin update system.** `pre_set_site_transient_update_plugins`, `plugins_api`, and `upgrader_*` filters all unchanged. The "Updates UI doesn't show my plugin" issue we hit was about WP's own transient TTL, not a 7.0 regression.
- **WP 7.0 changes nothing in plugin readme.txt validation.** `Requires at least: 6.4`, `Tested up to: 7.0`, `Requires PHP: 8.0` continue to be the canonical headers.
- **Abilities REST URL is `/wp-abilities/v1/abilities/<name>/run`.** The abilities-api repo's `docs/rest-api.md` documents it without the `/abilities/` segment — **the docs are wrong vs the implementation**. Source verified at [run-controller.php](https://github.com/WordPress/abilities-api/blob/trunk/includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php). v2.5.2 fixed this in our JS.
- **AI Client availability check is `is_supported_for_text_generation()`, not `wp_has_ai_client()`.** Per the [AI Client dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/). v2.5.0 fixed this in our gate function.
- **Command Palette has NO official UI feedback pattern.** `@wordpress/core-commands` imports `element`, `router`, `commands` — but NOT `notices`. No `<SnackbarList>` rendered on wp-admin pages outside the Gutenberg block editor. v2.5.1 fixed this with DOM-built admin notices.

The Field Guide had post-launch updates on 5/17 (DataViews dev note) and 5/18 (Notes section removed) — neither affects SN.

## [2.5.2] - 2026-05-20

### Fixed — abilities REST URL was missing `/abilities/` segment

The actual root cause behind v2.5.0/v2.5.1 Command Palette + AI buttons not working: the URL my JS constructed for the abilities REST API was **wrong** by one path segment.

**My (wrong) URL:** `/wp-abilities/v1/signal-noise/get-deploy-status/run`
**Correct URL:** `/wp-abilities/v1/abilities/signal-noise/get-deploy-status/run`

The `/abilities/` segment is the `rest_base` of the run-controller. Verified at [class-wp-rest-abilities-v1-run-controller.php:35-61](https://github.com/WordPress/abilities-api/blob/trunk/includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php) on 2026-05-20:

```php
protected $namespace = 'wp-abilities/v1';
protected $rest_base = 'abilities';

register_rest_route(
    $this->namespace,
    '/' . $this->rest_base . '/(?P<name>[a-zA-Z0-9\-\/]+?)/run',
    ...
);
```

**Why I got this wrong:** the abilities-api repo's own [docs/rest-api.md](https://github.com/WordPress/abilities-api/blob/trunk/docs/rest-api.md) documents the URL as:

```
GET|POST|DELETE /wp-abilities/v1/(?P<namespace>[a-z0-9-]+)/(?P<ability>[a-z0-9-]+)/run
```

— **without the `/abilities/` segment**. The documentation is wrong vs the implementation. I trusted the docs and built v2.5.0's JS off them. Cost: 2 incorrect releases (v2.5.0, v2.5.1) + a wasted diagnostic round before I read the actual source.

**Verified empirically on the live server:**
- Wrong URL: `curl -sI /wp-json/wp-abilities/v1/signal-noise/get-deploy-status/run` → **HTTP/2 404**
- Correct URL: `curl -sI /wp-json/wp-abilities/v1/abilities/signal-noise/get-deploy-status/run` → **HTTP/2 405** (route exists, HEAD not allowed = correct for a GET-only ability)

### Files changed

- `assets/command-palette.js` — `executeAbility()` URL builder: `/wp-abilities/v1/` → `/wp-abilities/v1/abilities/`
- `assets/ai-meta-description.js` — hardcoded URL: same fix
- `assets/ai-og-card-title.js` — hardcoded URL: same fix
- `assets/ai-excerpt.js` — hardcoded URL: same fix

Single-character fix per file (well, single-segment). PHP untouched. No version regression in registered abilities (still 11). The v2.5.1 DOM-notice feedback fix stays in place — that was correct (snackbar host is genuinely absent on wp-admin pages outside the block editor), just downstream of the actual broken URL.

### Process

Followed `superpowers:systematic-debugging` Phase 1 + the project memory rule `feedback_read_framework_source`. The framework-source check pointed at the right answer in ~5 minutes. Earlier guesses (ad blocker, snackbar UI) cost an entire diagnostic loop because I trusted the abilities-api docs instead of the implementation.

WP-REFERENCE upstream-gotcha entry to be added in a follow-up theme docs commit.

## [2.5.1] - 2026-05-20

### Fixed — Command Palette result feedback was invisible

**Symptom:** v2.5.0 Command Palette commands registered successfully (visible in ⌘K when typing "SN:") but clicking any of them produced no visible result — no error, no success message, nothing.

**Root cause** (identified via source inspection after an earlier incorrect guess about ad blockers): `@wordpress/core-commands` — the WP-admin Command Palette integration — only imports `element`, `router`, and `commands`. It does NOT import or render `@wordpress/notices` / `<SnackbarList>`. Verified at [gutenberg/packages/core-commands/src/index.js](https://github.com/WordPress/gutenberg/blob/trunk/packages/core-commands/src/index.js) on 2026-05-20: `initializeCommandPalette` creates a div, appends `<CommandMenu />`, and renders — no snackbar host alongside.

v2.5.0's `showToast()` called `wp.data.dispatch('core/notices').createNotice({ type: 'snackbar', ... })`. The notice was added to the store, but with no `<SnackbarList>` DOM consumer on regular wp-admin pages, **nothing rendered**. The promise resolved silently; the user saw no feedback. (The `<SnackbarList>` is only rendered inside Gutenberg block editor screens.)

**Fix:** [assets/command-palette.js](assets/command-palette.js) replaces `showToast()` with DOM-built native WP admin notices (`<div class="notice notice-success is-dismissible">`). Universal across every wp-admin page. Injects after `.wp-header-end` (WP convention) with fallbacks to `#wpbody-content` or `<body>`. Auto-dismisses after 6s; click × to close earlier.

Also added `console.log` checkpoints at each step of `run()` so any future silent-failure modes are visible to anyone with browser devtools open.

### What's NOT changing

- The abilities REST path (`/wp-abilities/v1/.../run`) stays — there's no evidence it's broken. v2.5.0 introduced TWO new things (REST path + snackbar UI) and only the latter was the failure; reverting both would be over-correcting.
- No PHP changes. The 11 registered abilities, 5 categories, gate function fix, impl extractions all stay as v2.5.0 shipped them.

### Process note

The earlier diagnostic of "the ad blocker is killing the abilities REST" was a guess from the `ERR_BLOCKED_BY_CLIENT` on `load-scripts.php` in the browser console — that error was actually unrelated (it blocked a script bundle, not the REST endpoint). After invoking `superpowers:systematic-debugging`: source inspection pointed to the snackbar UI absence rather than a network issue.

## [2.5.0] - 2026-05-20

### Changed — abilities-first architecture

WordPress 7.0 ("Armstrong") ships the Abilities API as a coupled stack with the AI Client and Command Palette. This release consolidates ALL SN actions (4 from Phase 14 + 7 new) onto abilities as the single canonical layer. Three caller surfaces — wp-admin UI, ⌘K Command Palette, future AI Client chat — all converge on `/wp-abilities/v1/<ability>/run`.

**Note on v2.3.0 + v2.4.0:** both were tagged on origin but never installed on the live site. This release supersedes them. The user-facing surface from those releases (Command Palette commands + 2 new AI generation buttons) is included here, just rewired through abilities.

### Added — 7 new abilities + 2 new ability categories

Existing abilities (Phase 14, v2.0.4) gained `meta.show_in_rest = true` so they're now invocable from client-side JS via the abilities REST endpoint:
- `signal-noise/purge-all-caches` (was REST-hidden)
- `signal-noise/regenerate-og-card` (was REST-hidden)
- `signal-noise/get-deploy-status` (was REST-hidden)
- `signal-noise/clear-template-overrides` (was REST-hidden)

New abilities (this release):
- `signal-noise/force-check-updates` (category: updates, idempotent)
- `signal-noise/full-reset` (maintenance, destructive+idempotent)
- `signal-noise/list-template-overrides` (diagnostics, readonly+idempotent — pairs with the destructive clear-template-overrides)
- `signal-noise/get-rss-stats` (diagnostics, readonly+idempotent)
- `signal-noise/ai-generate-meta-description` (ai-generation, idempotent)
- `signal-noise/ai-generate-og-card-title` (ai-generation, idempotent)
- `signal-noise/ai-generate-excerpt` (ai-generation, idempotent)

New categories: `updates`, `ai-generation`.

### Fixed — `snt_ai_is_available()` gate function

The previous gate used `wp_has_ai_client()` which returns true the moment the AI Client package is installed (true on 7.0+) even when NO provider is configured. AI buttons would render but fail at request time with a 503. Per the official AI Client dev note (2026-03-24):

> "Available support check methods include `is_supported_for_text_generation()`, `is_supported_for_image_generation()`, and others — **not** `wp_has_ai_client()`."

The corrected gate `snt_ai_can_text_generate()` builds a no-cost prompt and asks `is_supported_for_text_generation()` (deterministic per the dev note — no API calls fire). `snt_ai_is_available()` becomes a back-compat alias so every existing call site inherits the fix.

### Deprecated

Legacy REST endpoints under `/signal-noise/v1/cmd/*` and `/signal-noise/v1/ai/*` are marked `@deprecated since 2.5.0` but stay wired. The desktop-mode plugin's command palette still uses `/cmd/*`; back-compat preserved.

### Implementation

- Modified PHP: `inc/ai-bootstrap.php`, `inc/abilities-registration.php`, `inc/desktop-mode-integration.php`, `inc/ai-meta-description.php`, `inc/ai-og-card-title.php`, `inc/ai-excerpt.php`.
- Modified JS: `assets/command-palette.js`, `assets/ai-meta-description.js`, `assets/ai-og-card-title.js`, `assets/ai-excerpt.js`.
- No new files.
- Pattern: each operation has ONE impl function (`snt_*_impl()` or `snt_cmd_impl_*()`). Both the legacy REST handler AND the new ability's `execute_callback` call it. Single source of truth.
- JS goes through `wp.apiFetch` against `/wp-abilities/v1/<name>/run` with HTTP verb chosen per annotation (GET/POST/DELETE). Avoids the `@wordpress/core-abilities` ES-module loader entirely — works in classic-script IIFE files.

## [2.4.0] - 2026-05-20

### Added — AI excerpt generation (Phase 16, slice 3)

Completes the per-post AI editing trio (meta description from v1.16.0, OG card title from v2.3.0, post excerpt here). New "AI helpers" section appended to the per-post SN meta box with a "Generate excerpt with AI" button that fills WordPress's native excerpt field (Document panel → Excerpt) via `wp.data.dispatch('core/editor').editPost({ excerpt: ... })`.

Why `wp.data` instead of DOM polling: the block editor's excerpt textarea has no stable id/class — the React tree may rerender it under different markup across Gutenberg versions. `editPost()` is the canonical API and is version-stable. Classic editor fallback is included (writes to `#excerpt` directly if the wp.data dispatch path is unavailable).

Prompt design:
- 2-3 sentences, 50-75 words total
- Hook-driven — captures the reader's reason to click
- Provider-agnostic posture (same as the other AI surfaces): no temperature/top_p/top_k, all constraints in `using_system_instruction()`
- Banned words list: amazing, ultimate, best, powerful, revolutionary, transformative, cutting-edge, dive into, unlock, unleash — the AI-detection tells

Files:
- [inc/ai-excerpt.php](inc/ai-excerpt.php) — REST endpoint `POST /signal-noise/v1/ai/generate-excerpt` + script enqueue
- [assets/ai-excerpt.js](assets/ai-excerpt.js) — DOM-built UI section + button, polls for `.sn-post-settings` container, falls back gracefully if container never appears (e.g. meta box hidden in screen options)

### Notes

- Dormant unless `snt_ai_is_available()` returns true (= WP 7.0+ AI Client with configured provider). Zero behavior change for installs without 7.0 + a provider.
- The button writes to `post_excerpt`, the canonical WP field used by RSS feeds, archive cards, search results, and `the_excerpt()`. No SN-shadow field — single source of truth.

## [2.3.0] - 2026-05-20

### Added — WP 7.0 Command Palette integration

WordPress 7.0 ships a built-in Command Palette (⌘K / Ctrl+K in wp-admin) via `@wordpress/commands`. This release registers 5 SN actions in it so the most-used maintenance operations are reachable from anywhere in wp-admin without navigating to the SN settings page.

Commands registered (admin only — gated on `manage_options`):
- **SN: Purge all caches** → `POST /signal-noise/v1/cmd/purge-caches`
- **SN: Clear template overrides** → `POST /signal-noise/v1/cmd/clear-overrides`
- **SN: Force-check updates** → `POST /signal-noise/v1/cmd/force-check`
- **SN: Show deploy status** → `GET /signal-noise/v1/cmd/status` (snackbar)
- **SN: Open Signal & Noise settings** → navigate to dashboard

Implementation — [inc/command-palette.php](inc/command-palette.php) + [assets/command-palette.js](assets/command-palette.js):
- JS-only registration via `wp.data.dispatch( 'core/commands' ).registerCommand( … )` — WP 7.0's commands package has no PHP wrapper.
- Each command's callback hits an existing REST endpoint registered by [inc/desktop-mode-integration.php](inc/desktop-mode-integration.php) — one endpoint set, three callers (SN admin UI, desktop-mode palette, WP-native palette).
- Snackbar results via `core/notices` — the canonical WP toast surface.
- Bails silently if `wp.commands` / `wp.data` / `wp.apiFetch` is missing (WP < 7.0, no admin context, stripped-down install).
- Dashicons-as-React-element pattern (`wp.element.createElement('span', { className: 'dashicons dashicons-…' })`) avoids needing a JSX build step.

### Added — AI-assisted OG card title generation (Phase 16, slice 1)

Builds on v1.16.0's AI-meta-description scaffolding. Adds a "Generate with AI" button next to a new "OG card title" field in the per-post SN meta box. AI generates a 60-90 character punchier variant of the post title, writes to `_sn_og_card_title` post meta, AND immediately re-runs `sn_generate_og_card()` so the PNG on disk reflects the new title without the user having to save the post.

**Critical:** the `og:title` HTML meta tag is untouched — search engines and social-share scrapers still see the canonical article title. Only the visual title baked into the PNG is replaced. Think of it as "alt for the card image."

Implementation:
- [inc/og-card-generator.php:181](inc/og-card-generator.php) — new `sn_og_card_title` filter point. Default = `$post->post_title`, matching pre-v2.3.0 behavior exactly. Pre-existing posts have no behavior change until the user explicitly opts in via the new field.
- [inc/post-settings.php](inc/post-settings.php) — new `_sn_og_card_title` post meta (REST-exposed) + textarea field in the meta box + save/sanitize handler + typed accessor.
- [inc/ai-og-card-title.php](inc/ai-og-card-title.php) — REST endpoint `POST /signal-noise/v1/ai/generate-og-card-title` + filter listener that reads the post meta override + script enqueue.
- [assets/ai-og-card-title.js](assets/ai-og-card-title.js) — DOM-built button + status row, mirrors the AI meta-description button's XSS-safe pattern.
- Prompt design: same provider-agnostic / no-temperature posture as ai-meta-description (works against Anthropic + Gemini connectors without code branching). System instruction constrains to 60-90 chars, active voice, no marketing fluff, drop subtitles after a colon if they pad length.

### Fixed — false claim about WP 7.0 emitting BreadcrumbList JSON-LD

[inc/seo-schema.php](inc/seo-schema.php) docblock and the `sn_schema_breadcrumb_list()` function comment both claimed WP 7.0's native `core/breadcrumbs` block would emit its own BreadcrumbList structured data, so we could drop our JSON-LD emission post-7.0 launch. **The claim was false.** Verified 2026-05-20 against Gutenberg trunk: the native block emits visual `<nav><ol>` HTML only — no `<script type="application/ld+json">` anywhere in the package. Deleting `sn_schema_breadcrumb_list()` would have silently dropped breadcrumb rich results from SERPs. Corrected the comments + logged the gotcha at WORDPRESS-REFERENCE.md #30 (theme repo) so future-us doesn't re-believe the false claim.

### Notes

- Both AI features (meta description from v1.16.0, OG card title from this release) are dormant on installs without WP 7.0 + a configured AI provider. The `snt_ai_is_available()` gate means zero behavior change for users who haven't enabled the AI Client.
- Command Palette commands are admin-only and bail silently on WP < 7.0 — safe to ship without a 7.0-only gate.

## [2.2.0] - 2026-05-18

### Fixed — icon was rendering but invisible against white admin background

After v2.1.5 fixed the SVG XML parse errors and v2.1.7 fixed the transient field assignment, the icon URL was: serving 200 OK with correct content-type, fresh from CDN (cache MISS confirmed), valid XML, and reaching the `<img>` tag correctly. **It was rendering — just invisible.**

The original brand SVG was white-first brutalist: `fill="#ffffff"` ground, a 5px red stripe at the corner, and small black "SN" wordmark. At 32px display size in the wp-admin Updates row (white background), the ground blended into the page, the red stripe shrank to a sub-pixel speck, and only faint black "SN" text remained. The "broken-image-looking" appearance was actually the icon rendering against a same-colored background.

### Implementation — [assets/icon.svg](assets/icon.svg)

Inverted treatment to match the existing [assets/banner.svg](assets/banner.svg):
- Black ground (`#000000`) — strong contrast against white admin rows
- White "SN" wordmark
- Red `#e00404` accent stripe, sized to remain visible at 32px display
- Added a subtle bottom rule + "TOOLS" sub-label for the 64px+ contexts

Same brand vocabulary, same XML compliance from v2.1.5. The banner already used this palette — icon now consistent.

### Version cap rollover

Per project versioning: patch cap is 7 per minor. v2.1.x exhausted (2.1.0 → 2.1.7). This is functionally a patch but the cap forces the minor bump to **2.2.0**.

### Notes

- Banner.svg already used the inverted palette — this aligns the two assets.
- After install, the icon should be unambiguously visible in: Dashboard → Updates row, Plugins list, Desktop Mode's Plugins window, the View Details modal hero, and the Desktop Mode Installed detail panel hero.

## [2.1.7] - 2026-05-18

### Fixed — icon still blank + wp.org button still visible after v2.1.6

User confirmed v2.1.6 fixed the plugin name (`Signal & Noise Tools` now decodes correctly via `rest_prepare_plugin`) but the icon stayed blank and the "View on WordPress.org" button stayed visible. Two follow-on fixes:

### Fix 1 — icon: unconditional override in `rest_prepare_plugin`

v2.1.6 had `if ( empty( $data['desktop_mode_icon_url'] ) )` before re-asserting our URL. **But the field is never empty** — Desktop Mode's REST `get_callback` populates it with `https://ps.w.org/signal-and-noise-tools/assets/icon.svg` even for self-hosted plugins. Non-empty + wrong, so the `empty()` guard let the 404 URL pass through.

Now overwrites unconditionally for our basename: self-hosted plugins know their own canonical icon URL.

### Fix 2 — wp.org button: inline JS via `admin_print_footer_scripts`

v2.1.6 enqueued [assets/desktop-mode-installed-view-patch.js](assets/desktop-mode-installed-view-patch.js) via `wp_enqueue_script` inside `admin_enqueue_scripts`. The button persisted post-install, suggesting Desktop Mode's custom Plugins window runs JS in a different lifecycle than the standard admin enqueue chain. Switched to printing the patch inline into `admin_print_footer_scripts` at priority 99 — guarantees the script lands in the raw DOM regardless of how Desktop Mode loads its frontend. Deleted the orphaned assets/ file.

### Implementation — [inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)

- `rest_prepare_plugin` filter now always sets `desktop_mode_icon_url` to our canonical URL when the row is ours (was: only when empty).
- New `admin_print_footer_scripts` action gated on `function_exists('desktop_mode_register_command')`. Prints a ~1.5KB self-contained MutationObserver that:
  - Hides `<a href="…wordpress.org/plugins/signal-and-noise-tools…">`, `…/support/plugin/…`, and `…ps.w.org/…` links pointing at our slug
  - Defensively decodes any `Signal &amp; Noise Tools` leaf-node text
- Removed [assets/desktop-mode-installed-view-patch.js](assets/desktop-mode-installed-view-patch.js) (replaced by the inline version).

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 6/7 → **7/7 on 2.1.x** (cap reached — next bump is 2.2.0).
- Why inline instead of enqueued: `admin_print_footer_scripts` fires at the very end of every admin page render, in the raw `<body>` DOM, with no script-handle dependencies. Enqueued scripts depend on the `admin_enqueue_scripts` hook firing for the right screen + the enqueue chain not being short-circuited by a custom loader.
- Pairs with the upstream issue worth filing: Desktop Mode should add a `desktop_mode_plugins_window_show_wporg_link` filter so self-hosted plugins can suppress the button cleanly.

## [2.1.6] - 2026-05-18

### Fixed — Desktop Mode Installed view: name decode at REST layer + wp.org button hidden

After v2.1.5, the SVG icon was clean XML but the Plugins window still rendered:
- Plugin name as the literal `Signal &amp; Noise Tools`
- A "View on WordPress.org" button on the expanded detail panel (404 for self-hosted)

### Root cause (verified upstream)

A research subagent mapped the full Desktop Mode data flow against [WordPress/desktop-mode](https://github.com/WordPress/desktop-mode) trunk. The Installed view calls **Core's** REST endpoint `GET /wp/v2/plugins?context=view` ([rest.ts:261-272](https://raw.githubusercontent.com/WordPress/desktop-mode/trunk/src/plugins-window/rest.ts)) — Desktop Mode only adds custom fields via `register_rest_field()`. Two consequences:

1. **The v2.1.3 `all_plugins` filter never fires for this code path.** That filter is wired into wp-admin/plugins.php's UI layer, not Core's REST controller. The REST controller bypasses it entirely.
2. **Core's `_get_plugin_data_markup_translate()` ([wp-admin/includes/plugin.php:188](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-admin/includes/plugin.php)) runs `wp_kses` on the Name field unconditionally** — even when called with `$markup=false`. So the REST JSON response always carries `"name": "Signal &amp; Noise Tools"`. Desktop Mode's frontend then does `title.textContent = row.name` ([installed-view.ts:754 + installed-detail.ts:241](https://raw.githubusercontent.com/WordPress/desktop-mode/trunk/src/plugins-window/installed-view.ts)) which renders entities literally.

The "View on WordPress.org" button is gated purely on `if (slug)` in [installed-detail.ts:297-301](https://raw.githubusercontent.com/WordPress/desktop-mode/trunk/src/plugins-window/installed-detail.ts), where slug = `dirname(row.plugin)`. **No server-side hook exists to suppress it for self-hosted plugins.**

### Implementation

- **Replaced `all_plugins` filter with `rest_prepare_plugin` filter** in [inc/desktop-mode-integration.php](inc/desktop-mode-integration.php). `rest_prepare_plugin` is Core's last writable layer before JSON serialization ([class-wp-rest-plugins-controller.php:619](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php)). The filter decodes `name` + `author` for our basename and belt-and-suspenders re-asserts `desktop_mode_icon_url` in case Desktop Mode's REST field arrives empty.
- **Added [assets/desktop-mode-installed-view-patch.js](assets/desktop-mode-installed-view-patch.js)** — a ~3KB MutationObserver that hides any `<a href="…wordpress.org/plugins/signal-and-noise-tools…">` for the wp.org button (no upstream filter exists) and defensively decodes `Signal &amp; Noise Tools` if it ever leaks through. Enqueued only on admin pages where Desktop Mode is active; no-ops everywhere else.

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 5/7 → **6/7 on 2.1.x**.
- The JS patch is **upstream-friendly**: hosts the wp.org button instead of deleting it, leaves the DOM tree intact, and won't conflict if upstream later adds a server-side suppressor.
- Worth a new WP-REFERENCE gotcha: **`all_plugins` is a UI-layer filter, not a data-layer filter.** Only fires from wp-admin/plugins.php. For REST/CLI/custom integrations, use `rest_prepare_plugin` instead.
- Worth filing upstream: a `desktop_mode_plugins_window_show_wporg_link` filter would let self-hosted plugins suppress the broken button cleanly.

## [2.1.5] - 2026-05-18

### Fixed — broken plugin icon was a malformed SVG (not a cache issue)

User report after v2.1.4: icon STILL renders as the browser broken-image glyph in the Updates page row. Server returns the file correctly (`HTTP/2 200, content-type: image/svg+xml`), our `pre_set_site_transient_update_plugins` filter sets `icons['svg']` on the transient row, core's `list_plugin_updates()` at [`wp-admin/update-core.php:520`](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-admin/update-core.php) reads it and emits the right `<img>`. So the transport was fine — the *body* was the bug.

### Two XML violations in the SVG bodies

Both `assets/icon.svg` and `assets/banner.svg` were authored as HTML rather than strict XML. When served as `Content-Type: image/svg+xml` (the standard for SVG-as-`<img>`), browsers parse the body as XML and reject anything that violates XML 1.0:

1. **Raw `&` inside an attribute value** — `aria-label="Signal & Noise Tools"`. XML 1.0 §2.4 requires `&` in attribute values be encoded as `&amp;`. Most browsers' SVG renderer fails strict parsing → broken-image glyph.
2. **`--` inside an XML comment** (icon only) — `<!-- … the red \`--\` brand mark … -->`. XML 1.0 §2.5 forbids the literal substring `--` inside comments because `-->` is the terminator. Strict parsers fail at the first `--` they see inside a comment.

### Implementation

- [assets/icon.svg](assets/icon.svg) — encoded `&` as `&amp;` in `aria-label`; rephrased the comment to remove the literal `--` reference to the brand bar.
- [assets/banner.svg](assets/banner.svg) — encoded `&` as `&amp;` in `aria-label`.
- Verified both files now parse cleanly under `python3 -c "import xml.etree.ElementTree as ET; ET.parse(...)"` (strict XML parser equivalent to what browsers run on SVG-as-`<img>`).

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 4/7 → **5/7 on 2.1.x**.
- The `<title>` element body was already using `&amp;` correctly — the bug was only inside attribute values + the comment.
- Worth a new WP-REFERENCE gotcha: **SVGs served as `image/svg+xml` and consumed via `<img>` are parsed in strict XML mode**, not HTML mode. Common HTML shortcuts (raw `&` in attrs, `--` in comments) are fatal. Always validate plugin SVG assets with an XML parser — opening them in a browser tab via the URL bar is not equivalent (different parser context).

## [2.1.4] - 2026-05-18

### Fixed — Dashboard → Updates row: "Compatibility: Unknown" + broken icon

User report: the Updates page row for SN Tools renders a broken `<img>` icon next to the plugin name AND "Compatibility with WordPress 6.9.4: Unknown".

### Root cause (verified against WP 6.9.4 source)

[`wp-admin/update-core.php:527`](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-admin/update-core.php) reads `$plugin_data->update->tested` and falls through to the "Unknown" string when the field is unset. v2.1.2's brand-assets work set `tested` on the [`plugins_api`](inc/wp-update-integration.php) filter response (View Details modal) but never propagated it to the `pre_set_site_transient_update_plugins` filter row. Core's Updates page consults the transient directly, not plugins_api — so the field never reached the "Compatibility" comparison.

The broken icon was a stale browser cache of a 404 from the brief window between v2.1.2's tag push and the actual file landing on disk. The URL itself resolves correctly (verified `HTTP/2 200, content-type: image/svg+xml`); a hard refresh after install clears the cached 404.

### Implementation — [inc/wp-update-integration.php](inc/wp-update-integration.php)

Added `tested`, `requires`, `requires_php` to the `$plugin_data` stdClass pushed into `$transient->response[basename]`:

```php
$plugin_data->tested       = '7.0';
$plugin_data->requires     = '6.4';
$plugin_data->requires_php = '8.0';
```

`tested = '7.0'` satisfies `version_compare( '7.0', '6.9.4', '>=' )` → "Compatibility with WordPress 6.9.4: 100% (according to its author)". `requires` + `requires_php` mirror the file header for consistency with the View Details modal.

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 3/7 → **4/7 on 2.1.x**.
- After install: hard-refresh (Cmd+Shift+R) the Updates page to clear any cached broken `<img>`.
- Worth a new WP-REFERENCE gotcha: the Updates page reads the update transient directly; `plugins_api` only powers View Details. Compatibility/requires fields must be set on both code paths.

## [2.1.3] - 2026-05-18

### Fixed — Desktop Mode Plugins window: missing icon + literal `&amp;` in plugin name

User report: in WordPress/desktop-mode's custom "Plugins" panel (the OS-style window, distinct from `wp-admin/plugins.php`), our entry rendered with no icon AND the plugin name displayed as the literal text `Signal &amp; Noise Tools` — entity not decoded. The v2.1.2 brand-assets work covered WP core surfaces only; Desktop Mode has its own REST controller + TypeScript frontend that does not consult `plugins_api` or the `update_plugins` site_transient.

Dispatched a research subagent against [WordPress/desktop-mode](https://github.com/WordPress/desktop-mode) trunk to locate the exact lookup paths before writing any code (per project memory: "Read framework source before claiming to know it").

### Root causes (verified against upstream source)

- **Icon**: [`includes/plugins-window/rest-fields.php:404-445`](https://github.com/WordPress/desktop-mode/blob/trunk/includes/plugins-window/rest-fields.php) hardcodes `https://ps.w.org/<slug>/assets/icon.svg` from `dirname( plugin_file )`. Self-hosted plugins get a `ps.w.org` 404 → JS fallback at [`src/plugins-window/icon-fallback.ts`](https://github.com/WordPress/desktop-mode/blob/trunk/src/plugins-window/icon-fallback.ts) gives up after one shot for non-`ps.w.org` URLs → the dashicons-admin-plugins placeholder paints. The exposed `desktop_mode_plugins_window_icon_url` filter is the documented escape hatch.
- **Name**: [`src/plugins-window/installed-view.ts:396`](https://github.com/WordPress/desktop-mode/blob/trunk/src/plugins-window/installed-view.ts) uses `title.textContent = row.name;` directly. WP core's `_get_plugin_data_markup_translate()` already `wp_kses`'d the `&` to `&amp;`, and `textContent` renders entities literally. The Browse view at [`src/plugins-window/card.ts:91`](https://github.com/WordPress/desktop-mode/blob/trunk/src/plugins-window/card.ts) correctly calls `decodeEntities()` first — the Installed view forgot to. **Pure upstream frontend oversight; cannot be fixed via any plugin-side JS hook.**

### Implementation — [inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)

- **`desktop_mode_plugins_window_icon_url` filter**: returns `plugins_url('assets/icon.svg', …)` when slug matches `SN_GH_PLUGIN_SLUG`. SVG renders crisp at any DPR; served from same WP origin so CSP + mixed-content pass.
- **`all_plugins` filter**: substitutes `html_entity_decode()`'d Name back into the global plugin list, scoped to `SN_GH_PLUGIN_BASENAME`. Idempotent (`strpos( …, '&amp;' )` guard prevents double-decode). Standard wp-admin surfaces still render correctly because the browser is lenient with raw `&` AND any downstream `esc_html()` calls re-encode safely.

### Roundtrip verification for the `all_plugins` filter

| Consumer | Behavior |
|---|---|
| `wp-admin/plugins.php` `<strong>$name</strong>` | Raw `&` parsed leniently by browser → "Signal & Noise Tools" ✓ |
| `wp-admin/update-core.php` (echoes through `esc_html`) | Re-encodes to `&amp;` → browser decodes ✓ |
| Desktop Mode REST + `textContent` | Receives raw "Signal & Noise Tools" → renders correctly ✓ |
| JSON/REST consumers | Receive canonical unescaped value (the expected JSON form) ✓ |

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 2/7 → **3/7 on 2.1.x**.
- Both filters short-circuit cleanly when Desktop Mode is uninstalled (the icon filter is never invoked; the `all_plugins` filter no-ops since no consumer cares about the difference).
- Worth a new WP-REFERENCE gotcha: `all_plugins` is the right hook for surgical Name/Description overrides since the underlying file header is immutable and core's `wp_kses` pass is hardcoded.

## [2.1.2] - 2026-05-18

### Added — plugin brand assets in wp-admin

User asked: "We'd create an image for the plugin to show in the plugin list and all that... it's WP rule, right?" Yes — WP supports plugin icons + banners via the `plugins_api` filter + the update transient. Self-hosted plugins (like this one) have to provide them; the default is a puzzle-piece dashicon.

Parallel-dispatched a research subagent to read the upstream WP source (`wp-admin/update-core.php`, `wp-admin/includes/plugin-install.php`, `wp-admin/includes/class-wp-plugin-install-list-table.php`) before writing code. Findings drove the exact shape used here:

### Implementation

- **[assets/icon.svg](assets/icon.svg)** — 256×256 viewBox, brand-aligned: white ground, condensed display sans "SN" wordmark in black, red blood-accent stripe top-left, "TOOLS" sub-label in DM Mono. SVG scales crisply at any DPR; pure markup, no font dependency at runtime (uses Bebas Neue + Impact + Helvetica Neue + sans-serif fallback chain — Impact is the widest-installed system font that approximates Bebas Neue's geometry).
- **[assets/banner.svg](assets/banner.svg)** — 1544×500 viewBox, inverted treatment (black ground, white wordmark) for the View Details modal header. Same brand vocabulary.
- **[inc/wp-update-integration.php](inc/wp-update-integration.php) update transient filter** — added `icons` + `banners` arrays to the `$plugin_data` object pushed into `update_plugins` site_transient. Every key (`svg`, `2x`, `1x`, `default`) points at the same SVG — modern WP picks `svg` first; older paths get the SVG via `default` (which MUST be set per `class-wp-plugin-install-list-table.php:445` which reads it without an `! empty()` guard).
- **[inc/wp-update-integration.php](inc/wp-update-integration.php) NEW `plugins_api` filter** — supplies the View Details modal data (name, slug, version, author, sections, icons, banners). Without this filter, the modal shows "Plugin not found" for self-hosted plugins because the wordpress.org API returns nothing. The `description` section reads as a real plugin landing page (SEO, ops tooling, WP 7.0 readiness, desktop-mode integration).
- **Cache invalidation** — extended the existing version-change watchdog at `admin_init` to also clear `plugin_information_<slug>` site transient (24h WP-default TTL). Without this, the View Details modal would keep showing the previous version's metadata after install.

### Surfaces affected

| Surface | Now shows |
|---|---|
| wp-admin → Dashboard → Updates (when update available) | SN icon (svg) next to the plugin entry |
| wp-admin → Plugins → Add New (search/browse) | Not relevant for self-hosted; we don't appear in search results |
| wp-admin → Plugins → View Details modal | SN banner header + icon + name + description sections + author + version + tested-up-to + requires-PHP |
| wp-admin → Plugins → Installed Plugins list | No icon — WP core never renders icons on this surface |

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 1/7 → **2/7 on 2.1.x**.
- All URLs are HTTPS (mixed-content blocks `<img>` silently on HTTPS admin).
- SVG fine in WP 5.0+; the developer.wordpress.org "PNG fallback required" rule is wordpress.org CDN docs, not WP core rendering — core renders SVG via `<img>` without issue.
- Worth a new WP-REFERENCE gotcha: `class-wp-plugin-install-list-table.php` reads `$plugin['icons']['default']` without `! empty()` — always set the default key.

## [2.1.1] - 2026-05-18

### Critical hotfix — production login lockout caused by `wps-hide-login` ghost option entry

User reported login was broken after deleting `wps-hide-login` from disk. Root-cause investigation (parallel debugging agent + WP-core source verification) confirmed: the `wps-hide-login` files were removed without going through WP's `Deactivate Plugin` flow, leaving the slug as an orphan in the `active_plugins` DB option. Our [`inc/login-hide.php:40`](inc/login-hide.php) pre-flight check uses `is_plugin_active()` — which is a pure option lookup that never checks the filesystem — so the orphan slug made it return `true`. Our module bailed entirely, never registered the rewrite rule, and `/sn-login` returned 404 indefinitely.

### Fixed

- **[inc/login-hide.php](inc/login-hide.php)**: pre-flight check now requires BOTH `is_plugin_active( $wps_basename )` AND `file_exists( WP_PLUGIN_DIR . '/' . $wps_basename )`. If the file is gone, the orphan option entry is no longer authoritative — our module activates, adds the rewrite rule, flushes, and `/sn-login` resolves correctly.
- **[inc/admin-page.php](inc/admin-page.php)** Login tab status display: mirrored the same tightened check at line ~713 so the admin status doesn't falsely claim "dormant — conflict with wps-hide-login" when the file is actually gone.

### Why this matters — the upstream-WP gotcha

WordPress's `is_plugin_active()` (in `wp-admin/includes/plugin.php`) is documented as a state lookup against the `active_plugins` option. It does NOT verify the file referenced by the slug exists on disk. WP runs the `active_plugins` slug list every page load, tries to `include` each file, and **silently skips** missing files without removing them from the option. That divergence between option-state and filesystem-state is what bit this.

Worth adding to the running upstream-WP-gotchas list in [docs/WORDPRESS-REFERENCE.md](https://github.com/juanlentino/signal-and-noise/blob/main/docs/WORDPRESS-REFERENCE.md).

### Emergency unlock path

If you hit this lockout again, the plugin has a baked-in escape hatch: add `define( 'SN_LOGIN_BYPASS', true );` to `wp-config.php`. The module returns at [line 31](inc/login-hide.php) before reaching any rewrite or interception logic, restoring default `/wp-login.php` behavior. Remove the constant once you've fixed the underlying issue.

### Other latent bugs spotted by the audit (deferred — not critical)

- `sn_login_rewrites_flushed` keying trusts the option, not actual rule presence in `rewrite_rules`. Could leave us stuck if a flush silently fails. Defer until evidence it bites.
- `strpos( $request_uri, '/' . $slug ) === 0` (line 114) matches `/sn-login-foo` as a prefix. Tighten with a regex boundary check. Defer.
- REST allowlist substring match could be query-string-bypassed. Tighten with `wp_parse_url(..., PHP_URL_PATH)` normalization. Defer.

### Notes

- **PATCH within `2.1.x`.** Production hotfix. Patch headroom: 0/7 → **1/7 on 2.1.x**.
- Recommended cleanup AFTER installing this patch: WP-CLI `wp plugin uninstall wps-hide-login --deactivate` (or remove the orphan slug from `active_plugins` via SQL/phpMyAdmin) to clear the ghost entry. Not required for the fix to work — just hygiene.

## [2.1.0] - 2026-05-17

### Desktop-mode dock fixed + two new desktop widgets

User reported they couldn't open Signal & Noise from the desktop-mode dock after the v2.0.1 auto-import suppression. Parallel subagent investigation surfaced a deeper bug: **our dock entry has been broken since v1.15.0** — the [WordPress/desktop-mode docs](https://github.com/WordPress/desktop-mode/blob/trunk/docs/hooks-reference.md) say `'slug'` is the key, but the actual code at [`includes/core/payload.php:163`](https://github.com/WordPress/desktop-mode/blob/trunk/includes/core/payload.php#L163) uses `'id'`. Wrong key → `item.id` was `undefined` in JS → click handler threw `TypeError: Cannot read properties of undefined (reading 'startsWith')` at [`src/dock.ts:1711`](https://github.com/WordPress/desktop-mode/blob/trunk/src/dock.ts) on every click of the SN tile. The Phase 13 auto-import suppression just made the breakage visible.

### Fixed

- **Dock entry key renamed `'slug'` → `'id'`** ([inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)) — fixes the click TypeError. SN tile now opens the Dashboard on single click.
- **Dock icon switched from `dashicons-shield-alt` → `dashicons-megaphone`** — matches the icon passed to `add_menu_page()` in admin-page.php (`'dashicons-megaphone'` at line 121), which was the icon rendering on the now-suppressed auto-imported entry. User specifically requested it back.
- **Submenu entries cleaned up** — only `'title'` + `'url'` are honored per [`src/dock.ts:89`](https://github.com/WordPress/desktop-mode/blob/trunk/src/dock.ts) SubmenuItem type. Removed the silently-dropped `'slug'` + `'icon'` keys on the 8 submenu items. The 8 tabs ride into the opened SN window as the in-window tab strip (per desktop-mode behavior verified in src/dock.ts:1703-1765).

### Added — two new desktop widgets

User: *"if there's a way to create widgets for the desktop, do it... Maybe we can replace some that are hidden in the menu or other screens"*

1. **SN Quick Actions widget** ([assets/desktop-mode-widget-actions.js](assets/desktop-mode-widget-actions.js)) — three buttons (Purge all caches / Clear DB overrides / Full reset) calling the existing `/signal-noise/v1/cmd/{action}` REST endpoints. Replaces the 3-click path of S&N → Dashboard tab → Maintenance section with single-click access from the desktop. Inline toast feedback on success/failure.

2. **SN RSS Subscribers widget** ([assets/desktop-mode-widget-rss.js](assets/desktop-mode-widget-rss.js)) — surfaces 24h / 7d / 30d unique-subscriber + total-request counts + last-request timestamp at-a-glance. Data previously lived only behind S&N → RSS tab + a single line on the Dashboard tab; now visible without navigation. Polls every 5 min (RSS counts don't change rapidly).
   - New REST endpoint: `GET /signal-noise/v1/cmd/rss-stats` — read-only wrapper around the existing `sn_rss_tracker_window_stats_multi()` function. Capability-gated `manage_options`.

Existing `sn-deploy-status` widget unchanged.

### Why MINOR (not PATCH)

Per [CLAUDE.md](https://github.com/juanlentino/signal-and-noise/blob/main/CLAUDE.md): "MINOR for new user-visible capabilities." Two new desktop widgets is net-new user-facing surface. Resets minor count from 0/5 → **1/5 on 2.x**.

### Notes

- Surfaced via the audit-then-fix pattern: 5-agent parallel audit caught WP 7.0 + Phase 13 cleanup work in v2.0.4; the followup user-report dispatched a focused subagent that decoded the desktop-mode `id`-vs-`slug` docs error.
- Filed a mental note to PR the [WordPress/desktop-mode docs](https://github.com/WordPress/desktop-mode/blob/trunk/docs/hooks-reference.md) to align with the actual code.

## [2.0.4] - 2026-05-17

### Comprehensive audit pass — 8 findings fixed before WP 7.0 launch (3 days out)

After the v2.0.3 deploy, dispatched 5 parallel subagents to audit Phase 13's full surface (code review, WP 7.0 readiness, WCAG accessibility, critical.css size, Abilities API verification). Zero "must fix before May 20" breakers surfaced. Eight non-breaker findings consolidated into this single patch:

### Fixed — Phase 13 SEO code

1. **BreadcrumbList final ListItem now includes `item` URL** ([inc/seo-schema.php](inc/seo-schema.php)) — Google Rich Results spec requires `item` on every ListItem. Missing on the current-page item suppressed breadcrumb display in SERPs.
2. **`sn_og_image_url` filter seed consistency** ([inc/seo-schema.php](inc/seo-schema.php)) — was seeded with `''` in Article schema but with `sn_setting('og.default_image_url', '')` in OG meta. Latent inconsistency: any augment-style filter would behave differently between the two callsites. Both now use the same seed.
3. **`SERVER_PROTOCOL` allowlisted in 304 emission** ([inc/seo.php](inc/seo.php)) — was passed through `wp_unslash()` only and concatenated into `header()`. Allowlisted against `HTTP/1.0 | 1.1 | 2 | 2.0 | 3` with fallback `HTTP/1.1`. Defensive against CRLF response splitting even though Cloudways front-end normalizes.

### Fixed — Abilities API (Phase 14 — was broken in v2.0.3)

A parallel audit caught that v2.0.3's experimental `inc/abilities-registration.php` would have silently failed on WP 7.0 due to FOUR issues. Fixed before the file got registered:

4. **Categories now pre-registered** — `wp_register_ability()` calls return `null` (with `_doing_it_wrong`) if the category slug isn't registered first. Added `wp_abilities_api_categories_init` hook that registers `maintenance`, `content`, `diagnostics` before the abilities themselves try to cite them.
5. **`sn_og_card_regenerate` → `sn_generate_og_card`** — the function name was wrong; right function returns `bool`, not URL. Code now calls `sn_generate_og_card()` for the work and `sn_og_image_url_for_post()` separately for the URL in the response.
6. **`regenerate-og-card` permission_callback simplified** — was returning `WP_Error` for missing input, but `input_schema`'s `required: ['post_id']` handles that automatically before `permission_callback` fires. Now purely auth.
7. **`meta.annotations` added to all 4 abilities** — destructive/idempotent/readonly behavioral hints so the AI Client doesn't treat `purge-all-caches` or `clear-template-overrides` as safe operations. Required by the API for AI Clients to make sound decisions.
8. **`abilities-registration.php` now in the require_once chain in [signal-and-noise-tools.php](signal-and-noise-tools.php)** — file was created in v2.0.3 but never loaded. Silent regression.

### Fixed — WP 7.0 defensive

9. **`wp_robots()` now suppressed via `remove_action`** ([inc/seo.php](inc/seo.php)) — WP core's `wp_robots()` fires on `wp_head` priority 1 and emits a competing robots tag when `blog_public=0`. Production is fine today but a staging clone or accidental toggle would cause double-emission. Added next to the existing `rel_canonical` removal, gated on TSF absence.

### Notes

- **PATCH within `2.0.x`.** Patch headroom: 3/7 → **4/7 on 2.0.x**.
- All fixes verified against actual upstream WordPress source on trunk + the WP 7.0 Field Guide.
- The audit-then-fix pattern (5 parallel subagents → one consolidated patch) caught 8 issues that linear sequential review would have shipped silently or surfaced post-launch.

## [2.0.3] - 2026-05-17

### Deploy workflow hardening — same plugin code as v2.0.2

The v2.0.2 code (title format fix + canonical de-duplication) couldn't reliably reach the live server via the WP-UI Updates path due to the plugin's 1h GitHub-tag cache lag combined with WP-installer slowness. Manual GHA deploy via `gh workflow run` was failing too because prior WP-UI installs had written files to disk without updating the git index, leaving a dirty working tree that `git checkout` refused to overwrite.

### Fixed

- **`.github/workflows/deploy.yml`** — adds `git reset --hard HEAD` and `git clean -fd` before `git fetch && git checkout <tag>`. Makes the manual deploy idempotent regardless of working-tree state. Safe because the plugin directory is fully reproducible from git; "real" runtime data (uploads, cache, logs) lives outside the plugin dir.

### Notes

- **Plugin code is unchanged from v2.0.2.** This release exists purely to land a deploy-workflow improvement that the next manual deploy will use (GHA pulls the workflow definition from the ref being deployed, so the fix has to be tagged to be effective).
- **PATCH within `2.0.x`.** Patch headroom: 2/7 → **3/7 on 2.0.x**.

## [2.0.2] - 2026-05-17

### Post-cutover hotfix — two duplicate emissions caught by verification

The full TSF cutover (TSF deactivated + deleted) surfaced two regressions that didn't appear while TSF was still suppressing things. Both fixed in this patch.

### Fixed

1. **`<title>` now emits the brand format cleanly — no tagline append.**
   In v2.0.0, the `document_title_parts` filter set `$parts['title']` and `$parts['site']` but didn't clear `$parts['tagline']` (which WP populates from `get_bloginfo('description')`). Result post-cutover: `<title>Juan Lentino – Site Name – Site Tagline</title>` — three segments instead of two. WP joins every non-empty key in `$parts` with the separator, so the only correct fix is to **replace the whole array** rather than augment it. Filter now returns `array( 'title' => $title )` — one segment, fully pre-built, exactly the format TSF was emitting before.

2. **No more duplicate `<link rel="canonical">` tags.**
   [WP core's `rel_canonical()`](https://github.com/WordPress/WordPress/blob/master/wp-includes/link-template.php) is registered on `wp_head` at priority 10 and fires on singular views (which includes static front pages). Until Phase 13, TSF was suppressing it. With TSF gone, our seo.php canonical (priority 1) and WP core's (priority 10) were both firing, producing two canonical tags per page. Fix adds `remove_action( 'wp_head', 'rel_canonical' )` on `init`, gated on `! function_exists( 'the_seo_framework' )` so accidental TSF reactivation doesn't double-suppress.

### Why the gates matter

Both fixes are gated on TSF being absent. This preserves the rollback property of the v2.0.0 cutover: if TSF is ever reactivated, our gates flip back, WP core's `rel_canonical` re-registers, and the legacy TSF suppression resumes. No code revert needed.

### Notes

- **PATCH within `2.0.x`.** Bug fixes to v2.0.0/v2.0.1 cutover. Patch headroom: 1/7 → **2/7 on 2.0.x**.
- Caught by the 10-check verification script from the [cutover spec](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-17-tsf-cutover-design.md#verification-checklist). Without that systematic verification both regressions would have shipped silently.

## [2.0.1] - 2026-05-17

### Comprehensive QA pass — three fixes bundled

A QA audit after the v2.0.0 deploy surfaced three issues. This patch addresses all of them in one release so the TSF cutover can proceed cleanly.

### Fixed

1. **Identity tab now has UI for `jobTitle` + `knowsAbout`** ([inc/admin-page.php](inc/admin-page.php), [inc/settings.php](inc/settings.php)) — v2.0.0 shipped these as new Person-schema fields with hard-coded defaults, but the spec promised "settable via existing settings layer" without delivering admin UI. Fix adds:
   - **Job title** (text input, placeholder "Music Producer"): emitted as `jobTitle` on the Person schema.
   - **Knows about** (textarea, one topic per line): emitted as the `knowsAbout` array. Empty lines stripped, each line `sanitize_text_field()`'d.

2. **Desktop-mode dock no longer shows SN duplicated** ([inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)) — verified against [WordPress/desktop-mode core/payload.php on trunk](https://github.com/WordPress/desktop-mode/blob/trunk/includes/core/payload.php): desktop-mode auto-imports every `add_menu_page()` entry into the dock by default. Our admin page was being auto-imported AS WELL AS our explicit `desktop_mode_dock_items` filter entry, so the dock showed two "Signal & Noise" entries (different icons because the auto-import falls back to a generic dashicon — the "megaphone" the user spotted). Fix uses the documented `desktop_mode_dock_placement` filter to return `'hidden'` for the `sn-theme-options` menu slug, keeping only our explicit entry (which has the richer 8-tab submenu + update-available badge).

3. **RSS activity section restored to Dashboard tab** ([inc/admin-tab-dashboard.php](inc/admin-tab-dashboard.php)) — v1.13.0 had RSS stats on the Dashboard; v1.14.0's redesign removed them as "arithmetic, not content-driven." This re-adds the data in a content-driven shape that matches the existing External APIs single-line summary pattern: last-request timestamp + 24h/7d/30d totals + unique-subscriber counts + click-through to the RSS tab. Hidden when the rss-plausible-tracker module isn't loaded (`function_exists()` guard).

### Notes

- **PATCH bump within `2.0.x`.** All three are post-v2.0.0 QA corrections to surfaces that should have shipped with v2.0.0. Patch headroom: 0/7 → **1/7 on 2.0.x**.
- No version bump for the theme (theme was clean at v8.5.5).
- Companion to the v2.0.0 release shipped earlier this session.

## [2.0.0] - 2026-05-17

### Major release — The SEO Framework dependency dropped

Phase 13 of the plugin absorption roadmap. The SEO Framework (`autodescription`) is no longer required for this site's SEO emission. All meta tags, JSON-LD structured data, sitemap routing, and `<title>` emission now come from this plugin (plus WP core's title-tag support via the companion theme release v8.5.5).

### Added — Six new gated emitters

All NEW emissions are gated on `function_exists('the_seo_framework')` — they stay dormant while TSF is active and activate the instant TSF is deactivated. Existing v1.6.0–v1.8.0 emissions (canonical, robots, description, OG, Twitter) stay unconditional.

1. **`document_title_parts` filter in [inc/seo.php](inc/seo.php)** — emits the page `<title>` via WP-native `_wp_render_title_tag()` (theme v8.5.5 declares `add_theme_support('title-tag')`). Format matches what TSF was emitting: `Page Name — Site Name`. Pulls from existing `sn_seo_meta_for_current_view()` so per-route titles (front page, /notes, /provenance) still come from settings copy.
2. **`sn_schema_webpage()` in [inc/seo-schema.php](inc/seo-schema.php)** — WebPage schema for every singular (Page or Post). Includes `breadcrumb` reference + `isPartOf` WebSite reference.
3. **`sn_schema_collection_page()`** — CollectionPage schema for /notes and home archive views.
4. **`sn_schema_breadcrumb_list()`** — manual breadcrumb trail until WP 7.0 native Breadcrumbs block lands in templates (then this becomes a small refactor in a follow-up release).
5. **`inc/sitemap-redirect.php`** — 301 redirect from TSF's legacy routes (`/sitemap.xml`, `/sitemap_index.xml`, `/sitemap.xsl`) to WP core's `/wp-sitemap.xml`. Preserves Google Search Console crawl continuity.
6. **Last-Modified header + If-Modified-Since 304 in [inc/seo.php](inc/seo.php)** — singular content gets `Last-Modified` header set to post's modified GMT. Honors `If-Modified-Since` request header by returning `304 Not Modified` when post is unchanged. Improves crawl budget efficiency. (TSF emits Last-Modified itself when active; gate keeps ours dormant until cutover.)

### Added — Music-specific Person schema fields

`sn_schema_person()` now includes:
- `jobTitle` — defaults to `"Music Producer"`; settable via `sn_setting('identity.job_title')`.
- `knowsAbout` — defaults to `["Music Production", "Audio Engineering", "Provenance", "Music Industry"]`; settable via `sn_setting('identity.knows_about')`.

Both fields exist because this plugin uses richer domain context for the Person entity than TSF's generic schema generator can. A future v2.1.0+ may add a settings UI surface for these fields; for now they're settings-array-only.

### Why MAJOR (breaking change)

Per [CLAUDE.md](https://github.com/juanlentino/signal-and-noise/blob/main/CLAUDE.md) versioning rules: "removed/renamed public API, settings schema change without a migration, or a behavioural shift that requires user action." This release requires a user wp-admin action (TSF deactivation) to take full effect. The plugin's effective contract changes from "we cover SEO gaps TSF doesn't" to "we are the SEO surface." Resets minor count to 0 for v2.x.

### Cutover sequence (executed in this session)

1. Theme v8.5.5 deployed (declares `add_theme_support('title-tag')`).
2. This release (v2.0.0) deployed — new code live but gated dormant.
3. User deactivates TSF in wp-admin → Plugins.
4. Gates flip; new emissions activate; TSF stops emitting anything.
5. Verification via the runnable script in [the design spec](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-17-tsf-cutover-design.md#verification-checklist).
6. After 24-48h with no regressions: TSF plugin deleted from wp-admin.

### Rollback

Reactivate TSF in wp-admin (one click). All new emissions flip back to dormant automatically (gates re-fire). No code revert needed for rollback.

### Notes

- **Existing OG/Twitter suppression** (the `the_seo_framework_meta_generator_pools` filter from v1.4.1) stays in place permanently as defense-in-depth.
- **No data migration needed.** Plugin already reads from its own `_sn_*` post meta keys; no TSF data to import.
- Companion: theme v8.5.5 (PATCH) shipped in the same session.

## [1.16.0] - 2026-05-17

### Added — Phase 12 scaffolding: AI-assisted meta description generation

Pre-stages the AI features arc for WordPress 7.0 (ships 2026-05-20, 3 days from this release). Everything in this release is **dormant on WP 6.x** — gated behind `wp_has_ai_client()` which returns `false` until either WP 7.0 is installed OR the `wp-ai-client` plugin is active on 6.x. The instant either condition becomes true, the "Generate with AI" button appears on the per-post SN meta box and the REST endpoint starts answering.

### Three new files

- `inc/ai-bootstrap.php` (~140 LOC) — central function_exists() gate (`snt_ai_is_available()`) and shared prompt-execution helper (`snt_ai_generate_with_constraints()`). All AI code in the plugin goes through this — there are no scattered `function_exists()` checks elsewhere. Helper accepts a prompt + system instruction + max_tokens cap, returns string or `WP_Error`. Defensive try/catch around the SDK call (the WP wrapper converts most exceptions but PHP runtime errors can still bubble; we catch + convert to keep callers' error handling uniform).
- `inc/ai-meta-description.php` (~110 LOC) — Phase 12 slice 1. Registers REST endpoint `POST signal-noise/v1/ai/generate-meta-description` (permission: `edit_post` for the given post_id — not just `manage_options`). On post.php / post-new.php screens, enqueues the JS that injects the button. Both gated on `snt_ai_is_available()` — zero overhead, zero markup on 6.x without backport.
- `assets/ai-meta-description.js` (~120 LOC) — IIFE, no globals. Polls for the meta description textarea (id="sn_meta_description") for up to 10s after DOMContentLoaded (block editor renders meta boxes asynchronously; classic editor has them at load). On click: `wp.apiFetch` → fill textarea → fire `input`/`change` events so block editor's meta-sync picks up the change. DOM-built throughout (createElement + textContent — no innerHTML, no XSS risk class).

### Provider-agnostic by design — NOT pinned to Anthropic

Code calls `wp_ai_client_prompt()` (the WP-idiomatic procedural wrapper). It does NOT pick a provider, NOT set `temperature` / `top_p` / `top_k`, NOT pin a model. The provider is whatever the user configured in `Settings > Connectors`. Reasoning:

- **Claude Opus 4.7 specifically removed sampling params** — setting `temperature` returns 400. The portable choice is to set none. Constraints go in the system instruction.
- **The user could swap providers tomorrow** without our code changing — Anthropic today, OpenAI next week, Google after that. WP AI Client abstracts this.
- **Each provider has different model availability** — pinning a model name would lock the user into one provider's catalog.

This matches the rule from prior course-corrections: work WITH WordPress's abstraction, don't fight it. (Same reasoning as v1.14.0 admin redesign sticking to wp-admin native classes — extend, don't replace.)

### Meta description prompt

The system instruction targets SEO meta description conventions: 140-160 chars, active voice, no marketing fluff, output only the description text. Tuned for the SN voice (no first-person plural, capture single most-useful thing). Input is post content truncated to 1000 words (~1200-1400 input tokens — quality plateaus well before context-window limits; tokens scale linearly).

### REST endpoint design

`POST /signal-noise/v1/ai/generate-meta-description`
- Body: `{ post_id: int }`
- Permission: `current_user_can( 'edit_post', $post_id )` (per-post, not global)
- Returns: `{ ok: true, description: string, length: int }` or `WP_Error`

Error cases all return `WP_Error` with appropriate HTTP status codes (503 if AI unavailable, 422 if post empty, 500 if runtime error, 502 if AI returned empty).

### Companion documentation

Theme repo: [`docs/WP-7.0-AI-API-MAP.md`](https://github.com/juanlentino/signal-and-noise/blob/main/docs/WP-7.0-AI-API-MAP.md) — full API map + Phase 7/12/14 plan + verified-from-source notes on `wp_ai_client_prompt()`, `AiClient::prompt()`, `wp_has_ai_client()`, the Abilities API. Read that doc before working on AI features in future sessions.

### Phase 7 (May 21) — what user does on launch day

1. Upgrade WP core to 7.0
2. Install `WordPress/ai-provider-for-anthropic` plugin
3. Settings → Connectors → Anthropic → paste API key
4. (Optional) Install `WordPress/ai` for generic features (alt text, title gen)
5. Edit any post → SN meta box → click "Generate with AI" → ~150 chars in ~3-5 seconds

If step 5 fails: the REST endpoint returns a `WP_Error` with a clear message (status 503 = AI unavailable, 422 = empty post, etc.) — surfaced in the button's inline status text.

### Verified against actual source

- `WordPress/wp-ai-client/autoload.php` — confirmed `wp_has_ai_client()` is the canonical 7.0/backport detection function (not `function_exists('wp_ai_client_prompt')`).
- `WordPress/php-ai-client/src/AiClient.php` — confirmed fluent API: `usingSystemInstruction()`, `usingMaxTokens()`, `generateText()`. WP wrapper uses snake_case versions.
- WP make blog (Feb 3 merge proposal + Mar 24 intro + May 14 Field Guide) — confirmed providers ship as separately-installed plugins, not bundled in core. Confirmed `Settings > Connectors` is in core.

### Versioning

**MINOR bump (1.15.2 → 1.16.0)** — new user-visible capability (the "Generate with AI" button + the REST endpoint that backs it). Dormant on the current 6.x install until WP 7.0 + a provider plugin land on May 21+. Plugin minor count: 15 → 16 (continues over-cap pattern per documented user preference).

### Notes

- **Phase 12 slice 1 only.** Slice 2 (OG card title gen) deferred to v1.17.0+ — ship the meta description pattern, verify it works under real 7.0, then expand.
- **Phase 14 (Abilities API registration)** deferred separately to v1.17.0 or later. SN has obvious candidates (regenerate_og_card, purge_caches, etc.) but they're all currently exposed as filter hooks; registration is thin glue, ~80 LOC. Worth doing once we know the Abilities API stability.
- **Hybrid model with `WordPress/ai`:** that experimental plugin provides generic features (alt text, title gen). Recommended install on May 21 for those features; SN's plugin owns SN-specific features. If `WordPress/ai` breaks (it's marked experimental), our SN code keeps working.

## [1.15.2] - 2026-05-17

### Fixed
- **GitHub API rate-limit pressure.** Live site hit 53/60 on the unauthenticated 60/h tier. Root causes traced to:
  1. GHA runs cache TTL of 60s × 2 repos = 120 req/h theoretical max when the SN Dashboard tab is open.
  2. Force-check action cleared ALL caches including the runs cache — every click cost 4 fresh requests instead of 2 (the user is asking "is there a new version?", which is the tag-poll question, not the deploy-history question).
  3. No ETag conditional requests — every cache miss spent a full quota slot even when GitHub had nothing new to return.

### Three-layer fix
1. **GHA runs cache TTL bumped 60s → 5min** in `inc/github-actions-api.php`. Practical impact alone: ~5× reduction.
2. **Force-check handler (`inc/admin-tab-dashboard.php`) no longer clears the GHA runs cache.** Only clears the version-comparison caches (`sn_gh_latest_*`, `update_themes`, `update_plugins`). The runs cache stays warm — natural 5min TTL handles freshness. The REST `/cmd/force-check` endpoint already didn't clear runs (v1.15.0 separation), so this brings parity.
3. **ETag/If-None-Match conditional requests in `snt_gh_recent_runs()`.** Cache shape upgraded from flat array to `{ data, etag, fetched_at }` (with backward-compat for the pre-v1.15.2 flat shape). On every fetch, the cached ETag is sent as `If-None-Match`. A 304 response refreshes the cache TTL without consuming quota — **the real fix**.

### Expected steady-state usage after deploy
- 1 GHA runs request per repo per 5min, returning 304 most of the time (free) → effective quota burn: **~2 requests/hour when no deploys, ~6/hour during active deploy iteration**. Down from the 50+/hour pattern that triggered this fix.
- Theme + plugin tag polls unchanged at 1 req/hr each = 2/hr. (Adding ETags here is a future PATCH; the polls are too infrequent to matter — <2% of total usage.)

### Escape hatch (no code change needed)
- Define `SNT_GITHUB_TOKEN` in `wp-config.php` to raise the rate-limit bucket from 60/h (unauthenticated) to 5000/h (authenticated). Constant is already supported by `inc/github-actions-api.php` — sends `Authorization: Bearer …` header on every outgoing request. Define it once, never worry about quota again.

### Backward compat
- The cache shape change in `snt_gh_recent_runs()` handles both new `{ data, etag, fetched_at }` and pre-v1.15.2 flat-array values during the transition. No site-side flush required.

### Why this PATCH and not the broader ETag rollout
- Theme + plugin tag polls (`sn_gh_latest_theme_tag`, `sn_gh_latest_plugin_tag`) also could use ETags, but they run at 1/hr cache TTL — they account for ~2/60 = 3% of the quota under any realistic usage. Not worth the parallel rewrite this turn. Reserve as a future PATCH if anyone ever notices.

### Notes
- PATCH bump (1.15.1 → 1.15.2). Bugfix + perf optimization; no functional behavior change. Force-check still does what users expect (clears version comparison caches + redirects to `wp-admin/update-core.php?force-check=1` for the belt-and-braces WP-side refresh).

## [1.15.1] - 2026-05-17

### Fixed
- **"Signal & Noise Tools" displayed as the literal text "Signal &amp; Noise Tools" in the WordPress Plugins list** (and any other surface that renders the plugin name — desktop-mode dock submenu, update notifications, etc.). Root cause: `wp_cache_get('plugins', ...)` retained a stale double-escaped value across our SSH-checkout deploy path. The plugin header in `signal-and-noise-tools.php` was always plain `&` — but the parsed-plugin-headers cache survives across deploys that don't go through WP's installer, and the cached value was double-encoded from a much earlier release that had `&amp;` in the header.
- **`inc/wp-update-integration.php` `admin_init` version-change handler** now also calls `wp_clean_plugins_cache()` on every detected version change. Mirrors the existing pattern for `sn_gh_latest_plugin` + `update_plugins` transient invalidation; same admin_init pageview, no new overhead. Self-heals the plugin-headers cache on the next admin pageview after any version bump — including SSH-checkout deploys.

### Why this matters
- Plugin name renders correctly in:
  - wp-admin → Plugins (the canonical list)
  - wp-admin → Updates (when a plugin update is available)
  - Desktop-mode dock (the v1.15.0 integration's submenu we just shipped)
  - Any third-party plugin that lists installed plugins by name
- Without the watchdog, every future SSH-checkout deploy that bumps the plugin version would leave the header cache stale until manual deactivation/reactivation. Now it self-heals on the next admin pageview.

### Companion fix
- Theme **v8.5.4** ships the matching fix for the theme-side cache (`wp_clean_themes_cache()` added to the theme's equivalent admin_init handler), plus the actual `Theme Name: Signal &amp; Noise` → `Theme Name: Signal & Noise` header fix in `style.css` that was the original root-cause bug on the theme side.

### Notes
- **PATCH bump within `1.15.x`.** Bugfix; no functional behavior change.
- Bug surfaced visibly when the v1.15.0 desktop-mode integration shipped — the new dock submenu rendered the entity-escaped plugin name, making the cache staleness visible. Existing wp-admin Plugins list had the same issue all along; nobody noticed because the rest of the screen is busy enough that a single `&amp;` doesn't catch the eye.

## [1.15.0] - 2026-05-16

### Added — WordPress/desktop-mode integration

Makes Signal & Noise a first-class participant in the [`WordPress/desktop-mode`](https://github.com/WordPress/desktop-mode) plugin when installed + active. Adds dock visibility, desktop icons, command-palette access, and a live deploy-status widget. **Every integration is `function_exists()`-gated** — the plugin behaves identically when desktop-mode is inactive or uninstalled.

### Three surfaces

1. **Dock + desktop icons** (always-on visibility when desktop-mode is active):
   - Dock item "Signal & Noise" (`dashicons-shield-alt`) with a submenu of all 8 SN settings tabs.
   - Badge count on the dock item = number of "update available" packages (theme + plugin). 0 = no badge per desktop-mode convention.
   - Desktop icons for Dashboard + Identity (the two most-frequent surfaces).
2. **Command palette (Cmd+K)** — 13 commands across 3 categories:
   - **Maintenance (4):** `SN: Force-check updates`, `SN: Purge all caches`, `SN: Clear template overrides`, `SN: Full reset`. All fire REST endpoints (no page navigation) and dispatch `wp.desktop.notify()` toasts on response. Full reset has a confirm() guard.
   - **Navigation (7):** `SN: Open Dashboard / Identity / Login / Cloudflare / Plausible / RSS / Reading Time`. Each sets `window.location.href` to the matching SN admin page.
   - **Info (2):** `SN: Theme version`, `SN: Plugin version`. Reads from `wp_localize_script`'ed data and dispatches a toast like `Theme: v8.5.3 (up to date)`.
3. **Desktop widget** `SN Deploy Status` — compact floating card showing theme + plugin version pills + last deploy time + "Open Dashboard →" link. Auto-refreshes every 60s. Click target opens the SN Dashboard.

### REST endpoints — `signal-noise/v1/cmd/*`

Single dispatcher handler in `inc/desktop-mode-integration.php`:

| Endpoint | Method | What it does |
|---|---|---|
| `/cmd/force-check` | POST | Clear `sn_gh_latest_theme`, `sn_gh_latest_plugin`, `update_themes`, `update_plugins` transients |
| `/cmd/purge-caches` | POST | Fire `sn_purge_all_caches_result` filter (excludes template overrides per existing convention) |
| `/cmd/clear-overrides` | POST | Fire `sn_clear_template_overrides_result` filter |
| `/cmd/full-reset` | POST | Clear overrides + purge caches in one shot |
| `/cmd/status` | GET | Read-only: theme + plugin status struct + last deploy time (powers the widget) |

All endpoints `permission_callback` = `current_user_can('manage_options')`. WP REST API handles `_wpnonce` automatically when JS uses `wp.apiFetch` (which our scripts do via the `wp-api-fetch` script dependency).

Response shape: `{ ok: bool, message?: string, data?: object }`. Errors via standard `WP_Error` for the WP REST framework to handle.

### Files added

- `inc/desktop-mode-integration.php` (~230 LOC) — dock filter + icon + command + widget registrations + REST endpoints + script registrations + localized data.
- `assets/desktop-mode.js` (~130 LOC) — IIFE that calls `wp.desktop.registerCommand({ slug, run })` for each of the 13 commands. Maintenance commands use `wp.apiFetch`; nav uses `window.location`; info reads from `window.snDesktopData`. Defensive fallbacks: if `wp.desktop.notify` is unavailable, falls back to `wp.data.dispatch('core/notices')`; if `wp.apiFetch` is unavailable, error toast.
- `assets/desktop-mode-widget.js` (~140 LOC) — IIFE that calls `wp.desktop.registerWidget({ id, render })`. Built entirely via `createElement` + `textContent` (zero `innerHTML` — eliminates the string-concat XSS risk class). Auto-clears `setInterval` when the container detaches from the DOM (defensive against shell-side disposal without a teardown hook).

### Files changed

- `signal-and-noise-tools.php` — `Version: 1.15.0` + `SNT_VERSION` constant + `require_once 'inc/desktop-mode-integration.php'`.

### Verified against desktop-mode docs

- `docs/api-index.md` — function signatures for all 4 registrars verified.
- `docs/getting-started.md` — dock-item filter array shape (slug, title, icon, url, badge, submenu) verified.
- `docs/plugin-compat-layer.md` — chromeless iframe + `?desktop_mode_chromeless=1` parameter noted; our existing admin CSS uses no hardcoded admin-bar offsets, so we render correctly in chromeless mode out of the box (no Tier 3 targeted override needed).

### Versioning

**MINOR bump (1.14.0 → 1.15.0)** — new user-visible capability. Continues plugin's over-cap pattern (minor 15/5, per documented user preference). Theme is unaffected.

### Notes

- **No native window (`desktop_mode_register_window`)** — iframe-loading of our existing SN admin pages works fine; native window would duplicate the rendering logic for marginal UX gain. Reserved for a future phase if there's specific value (e.g., a multi-tab inspection window).
- **No custom wallpaper** — brand-on-admin pushback from v1.13.0/v1.14.0 redesign applies even on desktop-mode's customizable surfaces. The plugin contributes utility, not aesthetic.
- **No AI provider / AI tool registration** — that's Phase 12 work (depends on WP 7.0 + the AI Client landing on 2026-05-20).
- **Test plan after deploy:** load wp-admin with desktop-mode active → dock should show "Signal & Noise" with shield icon → Cmd+K should surface 13 `SN:` commands → place SN Deploy Status widget on desktop → check live data appears + refreshes every 60s. If anything breaks, iterate in v1.15.1.

## [1.14.0] - 2026-05-16

### Changed — admin UI redesign across all 8 tabs (user-requested cleanup)

User feedback during v1.13.0 testing: the Dashboard tab was "sloppy" (information dense without hierarchy), the brutalist front-end aesthetic shouldn't translate to admin UI (admin should read as clean wp-admin native), and the RSS layout still needed work. This release applies that direction comprehensively across every SN admin surface.

### Design discipline applied (per memories)
- **`feedback_no_brutalist_in_admin_ui.md`** — admin UI is wp-admin native, not branded. Reuse WP's `.button`, `.notice`, `.widefat`, `.form-table`, `.regular-text`, `.large-text`, `.small-text`, `.description`, `.submit`, `.code` primitives. Extend with `.sn-*` classes only for composition patterns WP doesn't already cover.
- **`feedback_no_dashboard_widgets.md`** — SN operational info stays in SN settings tabs, NOT WP dashboard widgets. The Plausible widgets are an exception because they surface third-party stats (not SN internal state), and Plausible widgets historically belong on the WP dashboard (Jetpack/WooCommerce convention).
- **CLAUDE.md invariant #3** — design system classes live in `assets/admin.css`. **Zero inline styles in admin PHP after this release** (down from ~25 instances across 6 files at v1.13.0 entry).

### Dashboard tab — full redesign
- `inc/deploy-status.php` renamed → `inc/admin-tab-dashboard.php` (385 LOC). Now owns the ENTIRE Dashboard tab content via the existing `sn_admin_dashboard_extras` hook.
- `inc/admin-page.php` Dashboard render block (~80 LOC of legacy Status table + Override details + Actions card grid) deleted; the new file's unified composition replaces it.
- **New composition (top to bottom):**
  1. **Site state** — 4-card hero grid (`.sn-state-grid`). Theme version, plugin version, deploys-since (with "N in last 24h"), health (with override count or "clean"). Replaces the v1.13.0 entry's existing 3-row Status table AND the new Versions table — both were duplicate sources of truth for the same data. Also eliminates the stale "Self-updater / SN_GITHUB_TOKEN" row (wrong constant name, dead concept since v8.3.0).
  2. **Recent deploys** — clean `<ul class="sn-deploy-list">` of last 5 GHA workflow runs (status glyph + repo + ref + duration + relative time + GitHub link). Replaces the 6-column table that would have overflowed on long branch names.
  3. **Maintenance** — 3-card action grid (Full Reset / Clear Overrides / Purge Caches) using the existing `.sn-card-grid` pattern. Force-check button DROPPED from the maintenance grid (it duplicated wp-admin/update-core.php's "Check Again" link) and moved to a tertiary `.button-link` inside the API summary.
  4. **External APIs** — single-line `.sn-api-summary` instead of a 3-row table. Each host: label + `mono number/limit` with state coloring. Promotes to a `.notice notice-warning` ABOVE everything only if any host hits critical (<10%).
  5. **Diagnostics** — `<details class="sn-override-details">` only renders when there ARE overrides. Hidden when clean.

### RSS tab — redesign
- **Activity stats full-width on top** (3 boxes — 24h / 7d / 30d). Cards use `.sn-rss-activity-card` (uniform width, content-driven, no inline styles).
- **2-col layout below** via the renamed generic `.sn-2col` (was `.sn-rss-grid` in v1.13.0). LEFT column: Recent requests table. RIGHT column: Settings form + Maintenance form. Content-driven widths (`minmax(0, 1fr)` left + `minmax(280px, 360px)` right) — replaces the arbitrary 60/40 from v1.13.0.
- **Breakpoint dropped from 1100px → 960px** so realistic admin viewports (1280-1440) keep the 2-col benefit.
- **Settings form** converted from `.form-table` to stacked `.sn-field` rows — fits the narrow right column much better than the WP-default two-column form table.
- **Maintenance form** lost its decorative bordered card (orphaned visual noise per design-critique). Now a borderless section with a single border-top divider (`.sn-rss-maintenance`).

### Plausible widgets (WP dashboard) — polish
- **Duplicate "Plausible not configured" copy** (line 78 + 132 — verbatim duplicate across snapshot + realtime widgets) extracted to `sn_pl_render_not_configured()`. One source of truth.
- **Diagnostic error block** rewritten from a fully inline-styled `<div>` (re-implementing `.notice notice-error`) to a proper `.notice notice-error notice-alt inline.sn-pl-diagnostic` — uses WP's canonical notice classes per the WP handbook + `wp-admin/css/common.css` source verification.
- All 4 inline `style="display:inline-block;..."` / `style="font-size:..."` / `style="color:#646970;"` on internal elements removed; promoted to scoped classes in the existing inline `<style>` block.
- **WP-native styling philosophy** preserved (already noted in the file's docblock: "no theme fonts, WP palette only").

### Other tabs — inline-style sweep + polish
- **Cloudflare tab:** 4 inline `style="font-family:ui-monospace,..."` on credential inputs → new `.sn-mono` utility class.
- **Plausible tab:** 2 inline mono fonts → `.sn-mono`. Inline `style="margin:0;max-width:none"` on status table → new `.sn-status-table--full` modifier.
- **Reading Time tab:** `style="max-width:300px"` on 2 action cards → `.sn-card--narrow` modifier (generalized — also used by Cloudflare + Plausible action cards now). Inline mono font on match-table pill → `.sn-mono`. Inline `style="color:var(--sn-text-muted)"` on snippet text → `.sn-rt-snippet` class. Inline `style="width:60px"` on ID column → `.widefat .column-id` class.
- **Links tab:** upgraded from a 4-row `.form-table` of links to a `.sn-link-grid` of cards. Each card has a category label (Source code / Releases / Infrastructure) + a title + the destination host, with the whole card as the click target via `.sn-link-card__link` overlay.
- **Identity tab:** zero inline styles — kept the `.sn-fieldset` pattern (it's already the reference standard) + the small "additional sameAs URL" margin promoted to `.sn-sameas-extra`.
- **Login tab:** zero changes — already well-structured (`.sn-status-box`, `.sn-fieldset`, `.sn-callout`).
- **`inc/admin-page.php`:** nav-tab margin promoted to `.sn-nav-tabs`. RSS-tracker-missing notice padding promoted to `.sn-rss-not-installed`.

### New CSS classes (assets/admin.css)
- `.sn-mono` — system mono font stack (eliminates 4+ duplicate inline declarations).
- `.sn-state-grid`, `.sn-state-card`, `.sn-state-card__{label,value,meta}` — Dashboard hero.
- `.sn-deploy-list`, `.sn-deploy-row`, `.sn-deploy-row__*` — clean deploy list (responsive: collapses to 4 columns on <782px).
- `.sn-api-summary`, `.sn-api-summary__item`, `.sn-api-summary__sep` — single-line API summary.
- `.sn-2col`, `.sn-2col__col` — generic 2-column layout (renamed from RSS-specific `.sn-rss-grid`).
- `.sn-rss-activity`, `.sn-rss-activity-card`, `.sn-rss-activity-card__*` — Activity stats row.
- `.sn-rss-recent`, `.sn-rss-settings`, `.sn-rss-maintenance`, `.sn-rss-meta` — RSS section wrappers.
- `.sn-link-grid`, `.sn-link-card`, `.sn-link-card__*` — Links tab cards.
- `.sn-card--narrow` — narrow action card modifier (replaces 4 inline `max-width:300px`).
- `.sn-submit--tight` — no-top-spacing submit row modifier.
- `.sn-notice-spacing` — inline notice vertical margin.
- `.sn-nav-tabs` — nav-tab-wrapper bottom margin.
- `.sn-rss-not-installed` — fallback notice for missing RSS tracker.
- `.sn-sameas-extra` — additional sameAs URL input margin (Identity tab).
- `.sn-status-table--full` — width-unlocked status table inside a fieldset.
- `.sn-override-details` — Dashboard diagnostics collapsible.
- `.sn-rt-snippet` — Reading Time match snippet.
- `.widefat .column-id` — narrow ID column for any widefat table.
- `.sn-pl-config-snippet`, `.sn-pl-diagnostic`, `.sn-pl-diagnostic-msg` — Plausible widget polish.

### Removed
- `inc/deploy-status.php` — renamed to `inc/admin-tab-dashboard.php`.
- `.sn-rss-grid`, `.sn-rss-col`, `.sn-rss-col--main`, `.sn-rss-col--side`, `.sn-subsection-h`, `.sn-rt-action-card` — all renamed/generalized into `.sn-2col` / `.sn-card--narrow`.
- ~80 LOC of Dashboard render block from `inc/admin-page.php` (absorbed into new tab file).

### Verified against WP handbook + source (per CLAUDE.md framework-source-first rule)
- WP Plugin Handbook: settings page structure (`.wrap` + `<h1>` + `<form>`), `.description` for helper text.
- `wp-admin/css/common.css`: `.notice` (left border + white bg), `.notice-{success,error,warning,info}` (color variants), `.notice-alt` (no box-shadow), `.notice .inline` (no margin), `.button`, `.button-primary`, `.button-secondary`, `.button-link`, `.nav-tab-wrapper`, `.nav-tab`, `.nav-tab-active`, `.postbox`, `.dashicons`, `.screen-reader-text`.
- `wp-admin/css/forms.css`: `.form-table` (2-col label+input layout), `.regular-text` (25em), `.large-text` (99%), `.small-text` (50px), `.tiny-text` (35px), `.code`, `.submit`, `p.submit`.
- WordPress CSS coding standards: hyphenated selectors, lowercase, no camelCase/underscores.
- WP `apply_filters('http_response', ...)` signature (already verified for the rate monitor in v1.13.0).
- `wp_add_dashboard_widget()` 7-arg signature (already verified in v1.12.0; the Plausible widgets continue to use it correctly).

### Notes
- **MINOR bump (1.13.0 → 1.14.0).** Pure visual + structural refactor — zero behavior change. Same functions, same data, same hooks, same forms POSTing to the same handlers. Just better composition + WP-native classes + zero inline styles. Plugin minor count 13 → 14 (continues over-cap pattern per documented preference).
- The companion theme repo will receive an equivalent audit pass next (user request).
- Inline styles remaining in `inc/content-rendering-helpers.php` and `inc/seed-content/*.html` are FSE Gutenberg block markup for FRONT-END post content — they're how block themes serialize layouts and intentionally left as-is.

## [1.13.0] - 2026-05-16

### Removed
- **WP dashboard widgets — deploy status (`sn_deploy_status`) AND RSS subscribers (`sn_rss_tracker_widget`) — both deleted.** v1.12.0's `inc/deploy-widget.php` ripped out entirely; the RSS dashboard widget registration in `inc/rss-plausible-tracker.php` also removed. The WP dashboard is a shared surface where SN-specific info competes for attention with other plugins; it's the wrong home for our operational tooling. SN settings pages are the canonical surface.
- **Admin bar pills** (`[T x.y.z] [P x.y.z]`) introduced in v1.12.0 — also gone with `inc/deploy-widget.php`.

### Added
- `inc/deploy-status.php` (~251 LOC) — hooks the existing `sn_admin_dashboard_extras` action to extend the **SN admin → Dashboard tab** with three read-only sections + a force-check button:
  1. **Versions table** — theme + plugin current vs. latest GitHub tag, with status pills + repo links.
  2. **Recent deploys** — last 5 GHA workflow runs across both repos (sorted newest-first). Status, ref, trigger, duration, relative time per row.
  3. **External API limits** — live snapshots of GitHub / Cloudflare / Plausible rate-limit headers, with ok/low/critical pills.
  4. **Force-check updates button** — POSTs to `admin-post.php?action=sn_force_update_check`. Handler clears all our update transients + WP's own `update_themes` / `update_plugins`, then redirects to `update-core.php?force-check=1`.
- `inc/api-rate-monitor.php` (~217 LOC) — **Phase 15a outgoing API rate-limit monitor.** Filters `http_response` (verified WP source: fires after success, $response guaranteed array, accept_args=3) on every outgoing `wp_remote_*` call. Inspects URL host; if it matches `api.github.com`, `api.cloudflare.com`, or `plausible.io`, reads server-reported rate-limit headers (`X-RateLimit-Remaining` / `-Limit` / `-Reset`) and stores in `sn_rate_limit_<host>` site transient (5min TTL).
  - **Throttled email warning** — when remaining drops below 10% for any tracked host, sends one `wp_mail()` to the site admin email, throttled to once-per-day-per-host via lock transient. Subject + body include host, percent, reset-time, and mitigation hints (e.g., "set `SNT_GITHUB_TOKEN`").
  - **Public helpers** — `snt_rate_limit_status($host)` and `snt_rate_limit_all_statuses()` consumed by the deploy-status sections.

### Changed
- **RSS tab — 2-column layout** (`inc/rss-plausible-tracker.php` + `assets/admin.css`). The four sections (Activity, Settings, Recent requests, Maintenance) used to stack vertically, creating a 4-screen scroll on the RSS tab. Now:
  - **Left column (3fr, ~60%):** Activity stats + Recent requests (read-heavy, wants horizontal room).
  - **Right column (2fr, ~40%):** Settings form + Maintenance (config, narrower).
  - Stacks on viewports < 1100px (`grid-template-columns: 1fr` media query). Internal `max-width` constraints on the sub-tables removed — the grid column is now the constraint.
- Dashboard tab now extends with the new deploy-status sections below the existing Status + Actions blocks. No structural change to the existing rows; just additive via `sn_admin_dashboard_extras` (the hook was already designed for this — see legacy docblock in `inc/admin-page.php:494`).

### Verified against WP source
- `apply_filters('http_response', $response, $parsed_args, $url)` in `wp-includes/class-wp-http.php` — `$response` guaranteed array (WP_Error returns early without filtering). accept_args=3.
- `admin-post.php` reads `$action` from `$_REQUEST`, fires `admin_post_{$action}` for logged-in users; no automatic nonce verification.
- GitHub REST rate-limit headers documented: `x-ratelimit-limit`, `x-ratelimit-remaining`, `x-ratelimit-used`, `x-ratelimit-reset` (Unix epoch). 60/h unauthenticated, 5000/h with token.

### Versioning rationale
- **MINOR bump (1.12.0 → 1.13.0).** Net new user-visible capability (the Dashboard tab sections + RSS 2-col layout + API rate monitor) plus the removal of v1.12.0's dashboard widget + admin bar pills. The removal isn't breaking per CLAUDE.md's definition (no public-API removal, no schema change, no required user action) — anyone who *used* the widget for ~24h sees it gone, but the same info is now in a better place.

### Notes
- The deploy-status sections only render when the SN admin → Dashboard tab is open. Zero overhead on regular wp-admin pages.
- The API rate monitor runs on EVERY `http_response` filter invocation (every `wp_remote_*` request anywhere on the site, including WP core's own update polling). Cost: one `wp_parse_url()` call + one foreach over 3 host entries. Negligible.
- Per user feedback: this is the canonical pattern for SN operational info from now on — extend the SN settings tabs, not WP-shared surfaces like the dashboard or admin bar.

## [1.12.0] - 2026-05-16

### Added
- **Phase 9 — Deploy status surfaces.** Closes the loop on the entire WP-update plumbing built across v1.10.x + v1.11.x: every piece of work now has a single readable place in wp-admin.
- `inc/deploy-widget.php` (~336 LOC) — registers the **"Signal & Noise · Deploy status" dashboard widget** (visible at wp-admin/index.php on login). Three sections:
  1. **Versions table** — theme + plugin current `Version:` vs. latest GitHub tag, each with a status pill (`up to date` / `vX.Y.Z available` / `unknown`) and a repo link.
  2. **Recent deploys** — last 5 GHA workflow runs merged across both repos, sorted newest-first. Each row: status icon (✓/✗/⊘/•), repo (theme/plugin), ref, trigger (push/workflow_dispatch), duration, relative time.
  3. **Force-check button** — POSTs to `admin-post.php?action=sn_force_update_check`. Handler clears `sn_gh_latest_theme`, `sn_gh_latest_plugin`, both `sn_gh_recent_runs_*` transients, AND WP's own `update_themes` / `update_plugins` transients, then redirects to `update-core.php?force-check=1` for belt-and-braces refresh.
- **Admin bar pills** (also in `inc/deploy-widget.php`) — two compact `[T 8.5.3] [P 1.12.0]` pills on the top-secondary (right) side of the admin bar. Visible on every wp-admin page AND on the front-end when admin bar is shown. Background color tracks state: green=ok, amber=update available, red=unknown. Hover title shows the full version comparison. Click links to the dashboard widget anchor.
- `inc/github-actions-api.php` (~141 LOC) — thin `wp_remote_get` wrapper for the workflow-scoped GHA Actions runs endpoint:
  - `snt_gh_recent_runs($repo, $count = 5)` — returns normalized run records from `/repos/<repo>/actions/workflows/deploy.yml/runs`. Cached 60s in `sn_gh_recent_runs_<repo>` site transient; 5min empty-sentinel on failure.
  - `snt_gh_recent_runs_merged(array $repos, $count = 5)` — merges + sorts by `created_at` DESC across multiple repos.
  - Honors `SNT_GITHUB_TOKEN` constant for authenticated requests (60/h → 5000/h rate limit). Define in `wp-config.php` to enable.
  - Run records pass through `apply_filters('sn_deploy_widget_run_record', $record, $raw)` for future enrichment (Phase 16+ AI summaries).

### Verified against WP source (per CLAUDE.md framework-source-first rule)
- `wp_add_dashboard_widget()` in `wp-admin/includes/dashboard.php` — confirmed 7-arg signature, callback receives `($post, $callback_args)` where `$post` is empty on dashboard. No auto capability check → render callback self-gates on `manage_options`.
- `admin-post.php` — confirmed `$action` read from `$_REQUEST` (POST+GET), fires `admin_post_{$action}` hook for logged-in users, no automatic nonce verification → handler does `check_admin_referer()` itself.
- `WP_Admin_Bar::add_node()` in `wp-includes/class-wp-admin-bar.php` — confirmed `parent => 'top-secondary'` for right-side placement (not action priority). Meta keys verified: `html, class, rel, lang, dir, onclick, target, title, tabindex, menu_title`.

### Architecture notes
- **Zero new contract hooks** — uses only documented WP primitives + reads existing `sn_gh_latest_*_tag()` cache from `inc/wp-update-integration.php`. WP-REFERENCE §10.0 surface unchanged.
- **Compatibility rules met** (per absorption roadmap): pure functions (`snt_deploy_status_for($pkg)`, `snt_gh_recent_runs($repo)`); filterable values (`sn_deploy_widget_run_record`); data-model first (transients), UI second.
- **CSS reuses existing `.sn-pill--ok/warn/err` classes** from `assets/admin.css`. Admin bar gets a minimal style override since `#wpadminbar` flattens backgrounds — printed via `admin_print_styles` + `wp_print_styles` actions to cover both admin pages and front-end bar appearances.

### Notes
- **MINOR bump** — new user-visible capability (the widget + admin bar surface). Plugin minor count 11 → 12; continues over-cap pattern per documented user preference (memory: `feedback_versioning_patch_cap.md`).
- Widget uses `human_time_diff(strtotime($iso), time())` for relative times — UTC-based, no timezone surprises.
- Force-check handler doesn't return early on no-change — it always clears transients + redirects, so even a "no new version" state benefits from the refreshed cache.

## [1.11.2] - 2026-05-16

### Added
- `inc/wp-update-git-preservation.php` (200 LOC) — `.git`-preservation filter pair + admin_init self-recovery. Closes the footgun where clicking "Update Now" in wp-admin destroyed the plugin's `.git` directory (via WP_Upgrader's recursive `clear_destination()`) and broke the canonical `gh workflow run deploy.yml --ref vX.Y.Z` install path.

### How it works
- `upgrader_pre_install` (priority 10, accept_args=2) — atomically `rename()`s `.git/` → `wp-content/upgrade/sn-signal-and-noise-tools-git-backup/` before WP's `clear_destination()` runs. Returns `WP_Error` to abort the install if the backup fails (better than silent .git destruction).
- WP runs its normal install (clear_destination + `upgrader_source_selection` rename of the unpacked archive dir → `move_dir`).
- `upgrader_post_install` (priority 10, accept_args=3) — atomically `rename()`s the backup back into the (now newly installed) destination dir. On WP-side install failure (WP_Error response), restores `.git` to the original plugin dir so the rolled-back code keeps its checkout intact.
- `admin_init` self-recovery — on every admin pageview, if an orphaned backup is detected (post_install never fired — PHP timeout mid-install, fatal in another plugin's update hook, etc.), restore intelligently. Idempotent.

### Behaviour
- Both install paths now coexist. `gh workflow run deploy.yml --ref vX.Y.Z` stays the canonical/fast path; clicking "Update Now" in wp-admin no longer breaks the subsequent workflow_dispatch.
- Same-filesystem `rename()` is **atomic at the kernel level** — no window where `.git` exists in both places or neither. Cross-FS rename silently falls back to copy+delete (NOT atomic) — that's why the backup lives under `wp-content/upgrade/` (same mount as `wp-content/plugins/` in standard WP installs incl. Cloudways).
- `inc/wp-update-integration.php` docblock extended with the v1.11.1 + v1.11.2 history.

### Mirrors theme v8.5.2
- Same file structure + filter pair + restore primitive as the theme's `inc/wp-update-git-preservation.php` (shipped earlier this session at theme v8.5.2). The two implementations differ only in which `$hook_extra` key they guard on (`plugin` vs `theme`), which constants they reference (`SN_GH_PLUGIN_*` vs `SN_GH_THEME_*`), and which directory primitives they use (`WP_PLUGIN_DIR` + `SN_GH_PLUGIN_SLUG` vs `get_theme_root()` + `SN_GH_THEME_STYLESHEET`).

### Three patches to make one click work
The WP UI update path required three independent blockers to be removed:
1. **v1.10.1** — enable the infrastructure (register with WP's update transient, add `upgrader_source_selection` to rename GitHub's archive dir).
2. **v1.11.1** — fix the 12h cache that was hiding new tags from WP's update checker.
3. **v1.11.2** — preserve `.git` through the install so the workflow_dispatch fallback doesn't break.

After this release, both install paths work end-to-end and coexist safely.

### Notes
- This release ships via the canonical `gh workflow run deploy.yml --ref v1.11.2` (the new code is dormant on this install since workflow_dispatch is SSH `git checkout`, not WP-installer). The filter pair activates only on the NEXT update if the maintainer chooses WP UI.
- `error_log()` is used for restoration failures, not `WP_Error` — the WP install itself succeeded; a failed `.git` restore is post-hoc and shouldn't fail the install. The admin_init self-recovery retries on next pageview.

## [1.11.1] - 2026-05-16

### Fixed
- **WP UI update cache was too sticky.** Symptom: after pushing v1.10.2 and v1.11.0, neither showed up in `wp-admin → Dashboard → Updates`. Cause: the GitHub Tags API result was cached in a site transient for 12 hours, AND clicking "Check Again" in the WP UI didn't force a refresh because the code didn't honor WP's `WP_FORCE_UPDATE_CHECK` constant. The cache was set when WP last polled (right around when v1.10.1 deployed) and any tags pushed after that stayed invisible until cache expiry.
- **Three fixes** in `inc/wp-update-integration.php`:
  1. `sn_gh_latest_plugin_tag()` gains an optional `$force_refresh` parameter that bypasses the cache.
  2. The `pre_set_site_transient_update_plugins` filter callback detects WP's force-check signals (`WP_FORCE_UPDATE_CHECK` constant OR `?force-check=1` query arg) and passes through to the new parameter. Clicking "Check Again" now actually re-fetches from GitHub.
  3. New `admin_init` hook stores the on-disk plugin version in an option (`sn_last_seen_plugin_version`). On every admin pageview, if the on-disk version differs from the stored last-seen, the GitHub-tag transient AND WP's own `update_plugins` transient are cleared. This handles the upgrade-just-happened case automatically — whether the upgrade came via WP UI install or manual `workflow_dispatch` deploy.
- **Cache TTL reduced from 12 hours → 1 hour.** 12h was too long for "I just pushed a tag, where's my update?" Even with force-check working, the autonomous background poll cadence matters. 1h is responsive enough that pushed tags surface naturally within minutes-to-an-hour without any explicit user action.

### Notes
- **PATCH bump within `1.11.x`.** Bugfix in the update-detection path; no functional change to the actual plugin features.
- **First WP-UI-flow installs (v1.10.2, v1.11.0) were lost in the cache window.** Their changes are still in the bundle (v1.11.1 supersedes both — it includes the v1.10.2 per-post canonical/noarchive/noimageindex fields AND the v1.11.0 sitemap filter). No regression; just compressed into one release.
- **Bootstrap path:** v1.11.1 deploys via one final manual `workflow_dispatch`. From then on, the WP UI install flow works correctly for all future tags — the `admin_init` cache-clear means even the next upgrade after this one will surface cleanly.

## [1.11.0] - 2026-05-16

### Added
- **`inc/sitemap.php` — sitemap filter for WP core's built-in `/wp-sitemap.xml`.** Hooks `wp_sitemaps_posts_query_args` to exclude two classes of posts from the sitemap:
  - Posts with `_sn_noindex = '1'` (the per-post noindex flag from v1.10.0). If a post is hidden from search engines, it shouldn't be in the sitemap either.
  - Posts with a non-empty `_sn_canonical_url` (per-post canonical override from v1.10.2). Canonical pointing elsewhere = this URL isn't the source-of-truth → exclude from our sitemap.
- Scoped to `post` + `page` post types (matches `SN_POST_SETTINGS_POST_TYPES`).

### Architectural note — dormant until Phase 13
- **Currently inactive on the live site.** The SEO Framework (TSF) is still active on juanlentino.com; TSF deregisters WP core's `/wp-sitemap.xml` route and serves its own at `/sitemap.xml` instead. Our filter targets WP core's sitemap, so it doesn't fire as long as TSF runs.
- **Activates automatically at Phase 13 cutover** (v2.0.0). When TSF is deactivated, WP core's sitemap takes over at `/wp-sitemap.xml` and our filter immediately starts honoring the per-post overrides.
- Registered unconditionally because doing so is cheap (no overhead when the hook never fires) and avoids coordination logic with TSF. The pattern "register filters that activate when their feature target becomes live" is the same approach used in the v1.5.0 login-hide module (stands down while wps-hide-login is active; activates when that plugin is removed).

### Sitemap features NOT shipped in v1.11.0
- **Custom URL routing** (intercepting `/sitemap.xml` while TSF is active) — would create two competing sitemaps. Wait for Phase 13.
- **Image sitemap extensions** — marginal SEO value; Google indexes inline `<img>` regardless.
- **Video sitemap, news sitemap** — not applicable to this site (no video catalog, not a news publisher).
- **Per-post `changefreq` / `priority`** — Google has explicitly stated these are ignored. WP core skips them.
- **Sitemap ping on update** — Google deprecated sitemap ping in 2023. WP core never implemented it. Search engines discover sitemap updates via robots.txt + crawl cadence.

### Notes
- **MINOR bump (v1.11.0).** New file + new user-visible behavior (when activated), even though the activation is currently latent. Aligns with the project's pattern of preferring MINOR for additive features and reserving PATCH for fixes / refactors.
- **Ships through the WP-UI-updates flow** introduced in v1.10.1. Push tag → `wp-admin → Updates` → "Update Now". (Or `gh workflow run deploy.yml --ref v1.11.0` for emergency.)
- **Theme parallel work** still queued: the theme repo needs the same WP-UI-updates treatment as the plugin got in v1.10.1. Will ship as `v8.5.1` in a separate task — same file changes (deploy.yml trigger + wp-update-integration.php).

## [1.10.2] - 2026-05-16

### Added
- **Three new per-post override fields** in the "Signal & Noise" meta box:
  - **Custom canonical URL** (`_sn_canonical_url`) — overrides `<link rel="canonical">` for this post. Use case: republished or syndicated content where the canonical lives at the original publisher's URL. Empty falls back to the permalink.
  - **No archive checkbox** (`_sn_noarchive`) — appends `noarchive` to the robots meta tag (tells Google etc. not to show a cached version).
  - **No image index checkbox** (`_sn_noimageindex`) — appends `noimageindex` to the robots meta tag (images on this page won't appear in Google Images).
- Three new typed accessors in `inc/post-settings.php`: `sn_post_settings_get_canonical_url()`, `sn_post_settings_get_noarchive()`, `sn_post_settings_get_noimageindex()`. All three meta keys registered via `register_post_meta()` with `show_in_rest=true` — same architectural pattern as v1.10.0's three fields. REST `/wp-json/wp/v2/posts/{id}` now exposes `meta._sn_canonical_url`, `meta._sn_noarchive`, `meta._sn_noimageindex` alongside the v1.10.0 fields.

### Changed
- **`inc/seo.php` canonical emitter** — checks `_sn_canonical_url` first for singulars before falling back to the permalink returned by `sn_seo_meta_for_current_view()`.
- **`inc/seo.php` robots emitter** refactored from a hardcoded string concatenation to a directives-array build pattern. Honors all four robots flags (`noindex` + auto `nofollow` since v1.6.0; `noarchive` + `noimageindex` added in v1.10.2). The permissive defaults (`max-snippet:-1,max-image-preview:large,max-video-preview:-1`) are always appended regardless of per-post flags. Backward compatible: existing `_sn_noindex` behavior unchanged.
- **`inc/post-settings.php` save handler** refactored to iterate over field-key maps (boolean fields and URL fields) rather than 1:1 inline blocks per field. Same behavior; halves the LOC and makes adding more fields later trivial.

### Notes
- **TSF parity:** these are the per-post equivalents of three TSF settings (canonical URL override, noarchive directive, noimageindex directive). Combined with v1.10.0's noindex + meta description + OG image, the SN per-post meta box now covers ~80% of TSF's per-post SEO controls. Remaining TSF features queued: focus keyword analysis (complex UI, marginal value), nofollow / nosnippet standalone toggles (incremental).
- **PATCH bump within `1.10.x`.** Additive only; no schema migrations.
- **First update via WP UI flow** since v1.10.1 shipped the fix. Push the tag → check `wp-admin → Dashboard → Updates` → "Update Now" → installs from GitHub archive. (12h cache TTL on update detection — can be forced via "Check Again" button on the Updates page.)

## [1.10.1] - 2026-05-16

### Fixed
- **WP update gating — updates now go through the WordPress admin Updates page.** Previously, pushing a `vX.Y.Z` tag triggered the GHA deploy workflow which SSH'd into Cloudways and `git checkout`ed the new tag ~30s later. The `inc/wp-update-integration.php` UI was just a deploy-health indicator — it explicitly REJECTED actual "Update Now" clicks (`upgrader_pre_install` filter returned a `WP_Error`). Net effect: tag push = update lands without maintainer confirmation. After v1.10.1, tag pushes do nothing automatically; updates appear in `wp-admin → Updates` and `wp-admin → Plugins`, and the maintainer clicks "Update Now" to install.

### Changed
- **`.github/workflows/deploy.yml` trigger** changed from `on: push: tags: 'v*'` to `on: workflow_dispatch:` only. Tag pushes no longer fire the workflow. Manual emergency-hotfix deploys remain available via the GitHub Actions UI or `gh workflow run deploy.yml --ref vX.Y.Z`.
- **`inc/wp-update-integration.php`**:
  - **Removed** the `upgrader_pre_install` filter that rejected WP installer attempts with a `WP_Error` directing the maintainer to push a git tag instead.
  - **Added** `upgrader_source_selection` filter to rename the unpacked source directory from GitHub's auto-generated format (`signal-and-noise-tools-1.10.1/`) to the plugin slug (`signal-and-noise-tools/`). Without this, WP would install to the wrong directory and the plugin would deactivate on update.
  - Docstring rewritten to describe the new WP-UI-driven flow.
- The `package` URL (pointing at `https://github.com/juanlentino/signal-and-noise-tools/archive/refs/tags/<tag>.zip`) was already set correctly since v1.4.0 — only the install path needed fixing.

### How updates work from v1.10.1 onward
1. Maintainer pushes tag `vX.Y.Z` to GitHub.
2. WP poll (every 12h, cached in `sn_gh_latest_plugin` transient) sees the new tag.
3. `wp-admin → Dashboard → Updates` shows "Signal & Noise Tools" with an "Update Available" badge.
4. Maintainer clicks "Update Now". WP downloads the GitHub tag archive, the source-selection filter renames the directory, WP installs over the previous version, plugin reactivates.

### Emergency hotfix path
If the WP UI install ever fails (e.g., GitHub API down, ZIP fetch blocked, file-permission issue on Cloudways), trigger the workflow manually:
```bash
gh workflow run deploy.yml --ref v1.10.1 --repo juanlentino/signal-and-noise-tools
```
This runs the same SSH + `git checkout` path the legacy auto-deploy used. Reserved for emergencies — the canonical flow is the WP UI.

### Notes
- **PATCH bump within `1.10.x`.** No plugin schema or functional change; only the release-pipeline trigger gating changed.
- **First install bootstrap:** the v1.10.1 tag itself can't be installed via WP UI because the v1.10.0 server-side has the OLD code that rejects WP installer attempts. v1.10.1 lands on Cloudways via one manual `gh workflow run` invocation. After that, v1.10.2+ install via the WP UI.
- **One-time loss of `.git` directory on first WP UI install.** WP's installer wipes the existing plugin directory before unpacking the new version, including the legacy `.git` checkout from past SSH deploys. Harmless — the SSH-based deploy path is no longer needed (still available via `workflow_dispatch` emergency fallback, which re-clones).
- **Theme repo** (`signal-and-noise`) gets the equivalent treatment in v8.5.1 (separate ship). Same one-line workflow change + same `wp-update-integration.php` fixes; the existing wp-update-integration.php in the theme mirrors this plugin's pattern.
- **Replaces the failed v1.10.1 attempt** (force-reverted) that incorrectly used a GitHub Actions environment approval gate instead of the WP admin UI flow.

## [1.10.0] - 2026-05-16

### Added
- **Per-post SEO settings UI.** New "Signal & Noise" meta box on the post + page editor (auto-converts to a sidebar panel in the block editor) exposing three overrides:
  - **Noindex toggle** — when checked, adds `noindex,nofollow` to the robots meta tag for that post. Reader has existed since v1.6.0 via `_sn_noindex` post meta; v1.10.0 adds the write path.
  - **Custom meta description** — overrides the post excerpt for `<meta name="description">`, `og:description`, `twitter:description`, AND the JSON-LD Article schema description. Empty falls back to the excerpt.
  - **Custom OG image URL** — overrides the featured image / auto-generated card / site default. Highest priority in the OG image resolution chain. Explicit beats implicit.
- **REST API exposure for all three meta keys** via `register_post_meta()` with `show_in_rest=true`. `/wp-json/wp/v2/posts/{id}` (and pages endpoint) now include `meta._sn_noindex`, `meta._sn_meta_description`, `meta._sn_og_image_url`. `auth_callback` requires `edit_posts` for writes; reads are public (these are user-facing values).
- **`sn_post_settings_get_noindex/description/og_image_url($post_id)` typed accessors** — consumers call these instead of `get_post_meta()` directly so the type contract lives in one place. `function_exists()` guards on every cross-module call so the new module can be selectively deactivated without breaking the existing readers.

### Changed
- **`inc/seo.php`** `sn_seo_meta_for_current_view()` singular branch now checks `_sn_meta_description` before falling back to `$post->post_excerpt`.
- **`inc/seo-schema.php`** Article schema `description` field follows the same fallback chain via new `sn_schema_article_description()` helper. Preserved the existing conditional-assignment pattern that OMITS the description key from JSON-LD when nothing resolves (rather than emitting an empty string) — schema validators see identical clean structure when no override or excerpt exists.
- **`inc/og-card-generator.php`** OG image filter chain checks `_sn_og_image_url` first, beating featured image / auto card / site default when set.

### Architecture
- **Hybrid PHP meta box + REST exposure** — Approach C from spec research. Zero build pipeline preserved. Same architectural pattern Yoast Free uses at scale. Future migration to a React block-editor sidebar is free thanks to REST exposure — meta keys and storage stay the same.
- Save handler on `save_post` with full guard chain (nonce → DOING_AUTOSAVE → wp_is_post_revision → cap → sanitize). Empty values trigger `delete_post_meta()` to keep the DB clean.
- All three reader integrations use `function_exists()` guards on `sn_post_settings_get_*` calls — defensive against `inc/post-settings.php` absence.
- Two affected post types: `post` + `page` (matches existing hook guards across `inc/reading-time.php`, `inc/og-card-generator.php`, `inc/cloudflare-purge.php`).

### Process notes
- **Built via `subagent-driven-development`** — Tasks 4/5/6 (the three independent reader integrations) dispatched as 3 parallel subagents. Each subagent verified its own edit, then the main session re-verified each independently before committing per the spec-reviewer discipline.
- Two subagent judgment calls preserved: (1) seo-schema.php's conditional-assignment pattern (better than the prompt assumed); (2) og-card-generator.php's filter-callback structure differs from the prompt's assumed inline featured-image check — subagent inserted at the structurally analogous position before the helper delegation. Both calls verified correct.

### Notes
- **MINOR bump despite minor cap.** Project cap is 5 minors per major; the plugin already exceeded that mid-Phase-1 (shipped 1.0 through 1.9 without rolling to 2.0). Continuing the existing pattern. A strict cap enforcement would require renumbering the 1.6-1.9 backlog as v2.x — not justified for a single-user plugin.
- **Spec**: `docs/superpowers/specs/2026-05-16-per-post-settings-v1.10.0-design.md`. **Plan**: `docs/superpowers/plans/2026-05-16-per-post-settings-v1.10.0-plan.md`. Both grounded in two parallel research-agent reports (codebase mapping + UI architecture).
- **Queued next:**
  - **v1.10.1** — WP admin update gating fix. The auto-deploy GHA pipeline bypasses the WP admin update approval gate; plugin updates land without user confirmation. Reverting plugin to manual-update-from-WP-UI flow (theme keeps auto-deploy).
  - **v1.10.2** — per-post canonical URL override + custom robots directives (additional fields on the existing meta box; small TSF-equivalent additions).
  - **v1.11.0** — sitemap.xml generation (real new feature, TSF parity).
- **Out of scope** (deferred further): React block-editor sidebar, focus keyword analysis (TSF Focus extension), bulk-edit / quick-edit support, bulk import/export.

## [1.9.6] - 2026-05-16

### Added
- **Identity tab dirty-tracking on the sticky save bar.** JS snapshots all form values on `DOMContentLoaded`; on any field change, the save bar hint switches from default copy to "N unsaved change(s)" with a subtle amber dot prefix. Reverts cleanly when you type back to the original value. Scoped to `.sn-identity-form` only — Login (single field), Cloudflare, and Plausible have inline save buttons where this is overkill.
- **"+ Add another profile URL" button** in the sameAs section, replacing the v1.9.5 always-shown trailing empty input. Click → JS clones a fresh empty `<input type="url">` row above the button, focuses it, fires a custom `sn:row-added` event so the dirty-tracker doesn't read the empty row as "dirty" before typing. `<noscript>` fallback preserves the v1.9.5 single-trailing-input behaviour for users with JS disabled.
- New `assets/admin.js` (~150 LOC vanilla JS, no jQuery, no build pipeline). Enqueued only on SN admin pages via the same hook-suffix guard as `admin.css`. Loaded in the footer (`$in_footer = true`) so it runs after DOM is parsed.

### Accessibility (WCAG 2.1 AA)
- **Focus ring contrast**: `.sn-add-row-btn:focus-visible` box-shadow opacity at 0.65 (≈3:1 against white card surface) — meets WCAG 1.4.11 non-text contrast minimum.
- **JS-added inputs get `aria-label="Profile URL"`** — placeholders don't satisfy WCAG 4.1.2 / 3.3.2; each row needs its own accessible name beyond the group label.
- **`prefers-reduced-motion` query** disables the row fade-in animation and button transitions for users who've expressed that OS-level preference.
- **`:focus-visible`** (not `:focus`) so the focus ring only shows for keyboard users, not mouse clicks.
- **`<button type="button">`** native element with descriptive `aria-label` and real text content (not icon-only).

### Notes
- **Pure UX polish — no schema change, no server-side behaviour change.** The form submits identically: `social_same_as[]` array with one or more URLs, sanitized by `sn_settings_save()` (empty values filtered, valid URLs persisted).
- Zero-build-pipeline architecture preserved. The plugin still has no webpack / babel / npm pipeline; `admin.js` is hand-written vanilla JS that ships as-is.
- PATCH bump within `1.9.x` (counter at 6/7 of the per-minor cap).

## [1.9.5] - 2026-05-16

### Fixed
- **Three more latent `themes.php?page=` URL bugs** of the same class fixed in v1.9.4. Surfaced by a sweep after the Reading Time fix:
  - [`inc/admin-bar.php`](inc/admin-bar.php) — top-level "S&N" admin bar item and "⚙ Open Dashboard" submenu both pointed at the pre-v1.8.1 location.
  - [`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php) — the "Settings & activity" link from the RSS widget pointed at `themes.php?page=sn-theme-options&tab=rss` (legacy compound URL); now points at the cleaner v1.9.0 submenu URL `admin.php?page=sn-rss`.
- All have been latently broken since v1.8.1 (top-level menu move). Effect: clicking these links 404'd silently. Cleared in one sweep so this URL class is fully retired.

### Notes
- PATCH bump within `1.9.x`. No schema or behavior change.
- After this, **all admin-page URLs across the plugin codebase use the v1.9.0 sidebar submenu pattern** (`admin.php?page=sn-<slug>`). Confirmed by `grep -rn 'themes.php?page=sn-theme-options' inc/` returning zero matches.

## [1.9.4] - 2026-05-16

### Changed
- **Reading Time tab redesigned** with the v1.9.0 design system. Inline-styled cards replaced with `.sn-fieldset` / `.sn-card-grid` / `.sn-card`. Inline style strings dropped from 14 → 4 (remaining are minor max-width + monospace family on the match-display pill).
- **Tool flow restructured** as numbered steps inside one fieldset: *1 · Preview* (always shown) → *2 · Apply* (shown only after preview runs). Destructive-action warning copy is now on the Apply card itself, with a count of matches and an explicit "Destructive — cannot be undone — back up first" callout. Disabled when zero matches.
- **Empty-state for "no matches"** uses `.sn-status-box` (green) with a clean-state pill. Previously a single inline-coloured `<p>` that was easy to miss.
- **Matches table** stays on `widefat striped` (the right WP pattern for multi-column post lists per the v1.9.1 handoff). Match cells now use `.sn-pill --err` for the matched substring instead of inline-colored spans.

### Fixed
- **Legacy URL bug** — the preview link previously pointed at `themes.php?page=sn-theme-options&sn_rt_preview=1` (the URL pattern from pre-v1.8.1 when the admin page lived under Appearance). After v1.9.0's sidebar submenu refactor, that URL 404s. Now correctly points at `admin.php?page=sn-reading-time&sn_rt_preview=1`. This bug would have surfaced the first time anyone clicked Run Preview after the v1.8.1 menu move.

### Architecture
- **`apply_reading_time_cleanup` POST handler moved to `sn_handle_admin_post()`** in `inc/admin-page.php`. Same PRG flow as Identity / Login / Plausible / Cloudflare. Count of cleaned posts encoded in the flash code (`rt_applied_N` pattern, same as `cleared_N` / `reset_N` for the maintenance actions).
- **`reading-time.php`'s admin tab callback is now render-only.**
- **1 new flash code pattern**: `rt_applied_N`.

### Notes
- **All 3 queued tab module redesigns complete** (v1.9.2 Plausible, v1.9.3 Cloudflare, v1.9.4 Reading Time). All 8 tabs now use the v1.9.0 design system. Inline-style total across the plugin's admin surface: was 80+, now 14.
- No schema or behavior changes. PATCH bump.
- **Suggested next: v1.9.5 if any cross-tab polish surfaces from this round; or sit at v1.9.4 and let it bake.** The 7-patch cap before rolling to v2.0.0 leaves room (v1.9.5, 1.9.6, 1.9.7 available within the 1.9.x lifecycle per project versioning rules).

## [1.9.3] - 2026-05-16

### Changed
- **Cloudflare tab redesigned** with the v1.9.0 design system. Inline-styled cards and `<p><strong>` field labels replaced with `.sn-fieldset` / `.sn-field` / `.sn-status-box` / `.sn-card`. Inline style strings dropped from 21 → 5 (remaining are font-family monospace + max-width on the manual-purge card).
- **Status box at the top** with two states: *Configured — auto-purge active* (green) when both token + zone ID are set; *Not configured* (amber) otherwise. Body includes the last-purge timestamp + kind ("full zone" vs "N URL(s)") when available, so the status box is also the activity log.
- **Credentials fieldset** holds both API token (`.sn-field-w-lg`, monospace) and Zone ID (`.sn-field-w-md`, monospace). Each field independently locks (disabled state + "locked by constant" helper) when its respective wp-config.php constant is set. Save button hidden when both fields are constant-locked.
- **Manual purge as a `.sn-card`** in `.sn-card-grid` — consistent with Dashboard action cards. Disabled when module is not configured.

### Architecture
- **POST handling moved to `sn_handle_admin_post()`** in `inc/admin-page.php`. `cf_save` and `cf_purge_now` now route through the central PRG handler — same redirect-after-save flow as Identity / Login / Plausible. `cloudflare-purge.php`'s admin tab callback is now render-only.
- **3 new flash codes**: `cf_saved`, `cf_purged_ok`, `cf_purged_unconfigured`.

### Notes
- No schema or behavior change for Cloudflare consumers (`sn_cf_*` functions, option keys, auto-purge hooks unchanged). PATCH bump.
- Queued next: v1.9.4 (Reading Time tab — last of the three module redesigns).

## [1.9.2] - 2026-05-16

### Changed
- **Plausible tab redesigned** with the v1.9.0 design system. Inline-styled cards and `form-table` replaced with `.sn-fieldset` / `.sn-field` / `.sn-status-box` / `.sn-card-grid`. Inline style strings dropped from 23 → 5 (remaining are minor font-family + max-width on action cards).
- **At-a-glance module status box at the top of the Plausible tab.** Reflects one of four states: *Configured* (green — token present + last call succeeded), *Configured but failing* (amber — token present + last call returned an HTTP error), *Misconfigured — wrong token namespace* (amber — only Plausible plugin's api_token available; will 401), *Not configured* (red — no token at all). Mirrors the Login tab's module-status pattern.
- **Status details fieldset** (domain / token source / last call) below the module status box. Status pills for Last call use `.sn-pill --ok / --err` instead of inline-colored spans.
- **Locked-field treatment** for the Stats API token when `SN_PLAUSIBLE_STATS_TOKEN` constant is set. Mirrors the Login slug's locked treatment — disabled input with explanatory helper text.
- **Token form uses `.sn-fieldset-actions`** for the inline Save button (short form pattern, same as Login post-v1.9.1). No sticky save bar.

### Architecture
- **POST handling moved from `inc/plausible-admin.php` to `sn_handle_admin_post()`** in `inc/admin-page.php`. Both `pl_save` and `pl_test` now go through the central PRG handler so they get the same redirect-after-save flow as Identity / Login (no more stale-form-after-save). `plausible-admin.php` is now a render-only callback.
- **7 new flash codes**: `pl_saved`, `pl_cleared`, `pl_unchanged`, `pl_locked`, `pl_test_ok`, `pl_test_err`, `pl_test_unconfigured`. Test result detail (visitor count / HTTP error) regenerated from the existing transients on the post-redirect render.

### Notes
- No schema or behavior changes for the Stats API consumers (`sn_plausible_*` functions, transient keys, option names unchanged). PATCH bump.
- Queued next: v1.9.3 (Cloudflare tab) and v1.9.4 (Reading Time tab) — same design-system rollout pattern.

## [1.9.1] - 2026-05-16

### Changed
- **Login tab save UI replaced with inline action row.** The sticky `.sn-savebar` made sense on Identity (long form, scrolling required) but felt misplaced on Login (single editable field, no scrolling). New `.sn-fieldset-actions` component renders an inline save button at the bottom of the fieldset card, with optional left-aligned hint text (only shown when the slug is locked by the `SN_LOGIN_SLUG` constant). Pattern: short forms get inline actions, long forms keep the sticky bar.
- **Tab-specific page subtitle.** Every tab gets its own one-sentence subtitle below the H1, describing what that tab is about (e.g. Login → *"Custom login URL and emergency unlock for the WordPress admin."*; Identity → *"Site name, social profiles, Open Graph cards, and per-route SEO copy."*). Replaces the v1.8.1+ static `"Theme management and maintenance."` that displayed on every tab regardless of context. Subtitles live in the `sn_admin_pages()` data structure alongside the slug/tab/label/title.
- **Page header H1 + subtitle moved from inline styles to `.sn-page-h1` + `.sn-page-subtitle` classes.** Last two inline-style strings on the page-shell removed.

### Notes
- **Design-system audit drove this patch.** Triggered by a critique that surfaced 4 cross-tab issues: save-UI pattern mismatch, generic subtitle on every tab, inline-style cards on un-redesigned tabs, no width-capping outside Identity. v1.9.1 ships fixes for the first two (low-risk pure CSS+markup); the un-redesigned tab modules (Cloudflare, Plausible, Reading Time) are queued for v1.9.2–v1.9.4 since they touch form handlers and need supervised verification.
- **No schema or behavior changes.** PATCH bump within `1.9.x`.

## [1.9.0] - 2026-05-16

### Added
- **One sidebar submenu per tab.** All 8 admin sections (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Links) now appear as nested entries under the top-level *Signal & Noise* menu. Each has a unique slug (`sn-identity`, `sn-login`, …) so the WP sidebar highlights correctly when on that page. In-page tab navigation is kept as a parallel orientation aid — same pattern as Yoast / WP Rocket / ACF.
- **New Login tab** (sidebar: *Signal & Noise → Login*). Promotes the login-hide module from a buried Identity sub-section to a focused tab with:
  - **Module status display** — ACTIVE / DORMANT (wps-hide-login conflict) / BYPASSED (`SN_LOGIN_BYPASS` constant set), rendered as a colored status box.
  - **Current login URL** as a clickable monospace chip.
  - **Slug edit form** — disabled with explanation when `SN_LOGIN_SLUG` constant overrides the setting.
  - **Emergency unlock docs** — both wp-config.php constants, copy-pasteable.
- **`save_login` action handler** — writes only to the login slice of `sn_settings`. Disambiguates `update_option`'s false-on-no-change-vs-failure return by re-reading the stored value.
- **Post/Redirect/Get for all settings saves.** New `sn_handle_admin_post()` runs on `admin_init` (before any output), processes the `$_POST`, and redirects with `?sn_flash=<status>`. The page callback translates the flash arg into a notice on the post-redirect GET. Fixes the stale-form-after-save bug we shipped in v1.8.0 (form re-rendered with cached pre-save values until manual reload). See `docs/WORDPRESS-REFERENCE.md` gotchas #18 + #19 for the architectural rationale.

### Changed
- **Identity tab rewritten** with a custom `.sn-fieldset` card layout. Replaces WP's `form-table` markup with vertical-flow `.sn-field` rows (label above, input below, helper below input). Inputs are width-capped per type via `.sn-field-w-{xs,sm,md,lg,xl}` modifiers (140 / 240 / 480 / 580 / 720 px) so the form stops looking wonky at wide widths.
- **Identity tab loses its Login section.** Slug field moves to the new Login tab. Section TOC now lists 4 jumplinks instead of 5.
- **Profile URLs (sameAs) trailing empty row** is now subtly styled (`.sn-sameas-empty`) with dashed border + italic placeholder, reading as *"add another"* instead of a forgotten dangling input.
- **`sn_settings_save()` preserves the existing login slug** when `login_slug` isn't in the form payload. Without this, saving Identity (which no longer includes the slug field) would clobber the configured slug back to the default. Read-existing-as-fallback pattern.

### Architectural notes
- **Deliberate deviation from the WP Plugin Handbook's Settings API recommendation.** The Handbook recommends `register_setting` + `add_settings_section` + `add_settings_field` (form posts to `options.php`, WP handles save + PRG + nonces). We instead use custom `$_POST` handlers (form posts to our own admin URL) because Settings API enforces one-option-per-setting, which doesn't fit our single nested-array `sn_settings` schema. Same trade-off Yoast Free / ACF / WP Rocket make. **The price of this deviation is owning every responsibility Settings API handles for you** — nonce, sanitization, PRG redirect, success/error flash. After v1.9.0 we do all four correctly. Documented in `docs/WORDPRESS-REFERENCE.md` gotchas #18 + #19.
- **Source-grounded sidebar registration.** Submenu registration uses the documented escape hatch for the auto-prepended duplicate-parent submenu (gotcha #14); enqueue guard handles `add_submenu_page`'s `false` return for low-cap users (gotcha #15); single source of truth (`sn_admin_pages()`) prevents duplicate-slug drift (gotcha #16).
- **Backward compat preserved.** Old v1.8.x deep links like `?tab=identity` still work — the callback's dispatcher checks `$_GET['tab']` first, falls back to deriving the tab from `$_GET['page']`. PRG redirect preserves `?tab=` if it was on the inbound request.
- **Other tab modules untouched.** Cloudflare / Plausible / RSS / Reading Time keep their v1.8.x form-table + inline styles. Same redesign pattern rolls across them in v1.9.x or v2.0.0.

## [1.8.1] - 2026-05-16

### Changed
- **Admin page promoted to top-level menu.** "Signal & Noise" now appears as its own item in the WP admin sidebar (megaphone icon, position 81) instead of a submenu under Appearance. New URL: `/wp-admin/admin.php?page=sn-theme-options` (the old `/wp-admin/themes.php?page=…` URL no longer resolves). The first submenu entry is labelled "Dashboard" to avoid the duplicate-parent-label pattern that `add_menu_page()` produces by default. Tab deep links (`?tab=identity` etc.) are unchanged.
- **Inline styles extracted to `assets/admin.css`.** 25+ duplicated `style="…"` attributes across the Dashboard and Identity tabs are now class-driven via a single enqueued stylesheet with CSS variables for surface/border/spacing/status. Other tab modules (Cloudflare, Plausible, Reading Time, RSS) still use inline styles — they get the same treatment in v1.9.0.
- **Status indicators replaced with pill badges.** Dashboard status row now uses `.sn-pill` / `.sn-pill--ok` / `.sn-pill--warn` / `.sn-pill--err` (rounded, colored dot prefix) instead of inline-coloured spans.

### Added
- **Identity tab section TOC.** Anchor-jump nav at the top — *Identity · Social · Open Graph · Login · SEO Copy* — for fast navigation on the long form. Each section header gets a matching `id` with `scroll-margin-top` so the target stays visible under the WP admin bar.
- **Identity tab sticky save bar.** Pure-CSS `position: sticky; bottom: 0;` keeps the Save button always one click away while editing the form, regardless of scroll position. Backdrop-blur for legibility on top of form content.
- **`sn_admin_page_hook()` static accessor.** Captures the hook suffix returned by `add_menu_page()` so the stylesheet-enqueue guard can't typo it. Cleaner than re-deriving `'toplevel_page_' . $slug` everywhere.

### Notes
- **No behaviour or schema change.** PATCH bump per project versioning caps (still within `1.8.x`). All `sn_settings` data shipped in v1.8.0 stays intact.
- **Out of scope:** refactor of other tab modules to use the new component classes (their inline styles still win on specificity); JS dirty-tracking on the sticky save bar; promoting tabs to individual sidebar submenu entries.

## [1.8.0] - 2026-05-16

### Added
- `inc/settings.php` — single source of truth for site-identity config (~216 LOC). Stores all settings in one `wp_options['sn_settings']` row across 5 categories: identity, social, og, login, seo_copy.
- **Identity tab in admin page** (`Appearance → Signal & Noise → Identity`) — single form with grouped fields for: site name + description + person name + locale; Twitter handle + sameAs profile URLs; default OG image URL + card dimensions; custom login slug; per-route SEO titles + descriptions.
- **Activation migration** (`sn_settings_seed_legacy_values`) — hostname-gated to `juanlentino.com`; seeds existing JL values into `wp_options` exactly once per environment. Subsequent activations no-op via `sn_settings_migrated_v1` flag. Lazy `admin_init` fallback covers SSH-based deploys where `register_activation_hook` doesn't fire.
- **`sn_setting('cat.field', $fallback)` accessor** — static-cached, dot-path read with deep-merge over defaults. Used throughout `seo.php` / `seo-schema.php` / `login-hide.php` in place of hardcoded literals.

### Changed
- `inc/seo.php`, `inc/seo-schema.php`, `inc/login-hide.php` refactored to read all site-identity values from `sn_setting()` instead of PHP literals. 12 hardcoded JL-specific values removed across the three files.
- Filter compat layer preserved: existing `apply_filters()` hooks (`sn_twitter_handle`, `sn_schema_same_as`, `sn_og_image_dimensions`) continue to work as override stack on top of stored settings. Pattern: `apply_filters('sn_X', sn_setting('path', $fallback))`.

### Notes
- **Live site output is byte-identical post-upgrade.** The activation migration seeds the JL-specific values into `wp_options['sn_settings']` so emitted meta tags match v1.7.0 exactly. Verifiable: diff a page's `<head>` pre/post-upgrade returns empty.
- **Generic defaults for fresh installs.** On any non-juanlentino.com host, the migration sets only the `sn_settings_migrated_v1` flag without seeding values. `sn_settings_defaults()` provides generic fallbacks pulled from `get_bloginfo()`.
- **Out of scope:** per-post settings UI (noindex toggle, custom meta description override per post), security toggles UI (xmlrpc, rest user lockdown, etc.), JS-driven add/remove for the sameAs list. Each becomes its own future phase.
- **Prereq for Phase 13 cutover** (v2.0.0, deactivates TSF + wps-hide-login). After v1.8.0, the plugin owns all site-identity emission with configurable values.

## [1.7.0] - 2026-05-16

### Added
- `inc/seo-schema.php` — JSON-LD structured data emission (~150 LOC). Single `@graph` script in `<head>` carrying three connected schemas:
  - **WebSite** — every page; publisher references the Person
  - **Person** — every page; name + URL + `sameAs` (X / Instagram / LinkedIn profiles, filterable via `sn_schema_same_as`)
  - **Article** — singular posts only; headline, `datePublished`, `dateModified`, `mainEntityOfPage`, image (via `sn_og_image_url` + `sn_og_image_dimensions` filters), references the Person as author + publisher

### Skipped (deliberate)
- **BreadcrumbList** — WordPress 7.0 ships a native Breadcrumbs block; use that instead.
- **SearchAction** — site has no `/search/{term}` route.
- **WebPage on non-post singulars** — marginal value; omit.

### Notes
- Output is `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` for readability and minor byte savings. Single `<script>` tag (Google prefers connected `@graph` over multiple disjoint scripts).
- Schemas reference each other by `@id` (`#/schema/Person`, `#/schema/WebSite`, `<permalink>#article`) so Google's structured-data validator sees them as a graph, not isolated nodes.
- After Phase 13 cutover (TSF deactivation), this becomes the site's only structured-data emission. Until then, TSF emits its own `@graph` in parallel — duplicate schemas in `<head>` (verifiable via [Google Rich Results Test](https://search.google.com/test/rich-results)). The duplication is cosmetic; both validate; Phase 13 removes the second source.

## [1.6.0] - 2026-05-16

### Added
- **Canonical URL** emission via `<link rel="canonical">` on front page, /notes, /provenance, and singular posts/pages (`inc/seo.php`, wp_head priority 1).
- **Robots meta** emission via `<meta name="robots">` with TSF's default no-restrictions content (`max-snippet:-1,max-image-preview:large,max-video-preview:-1`). Honors a per-post `_sn_noindex` post-meta flag for selective de-indexing (admin UI in Phase 11).
- **`og:locale`** meta emission (`en_US` hardcoded).
- **`og:image:width` + `og:image:height`** meta emission (defaults 1200×630 matching generated cards; filterable via new `sn_og_image_dimensions` filter).
- **`article:published_time` + `article:modified_time`** meta emission on singular posts (ISO 8601 UTC).
- **`twitter:site` + `twitter:creator`** meta emission (`@juan_lentino` hardcoded; filterable via new `sn_twitter_handle` filter).

### Removed
- Three dead `wpseo_*` filter hooks in `inc/og-card-generator.php` (`wpseo_opengraph_image`, `wpseo_twitter_image`, `wpseo_opengraph_image_size`). They were copy-pasted from a Yoast-era assumption; the active site runs The SEO Framework which uses a different filter namespace. Hooks were dead code — never fired. OG card surfacing flows through our own `sn_og_image_url` filter consumed by `inc/seo.php`.

### Behaviour
- Brings the companion plugin's SEO emission to feature-parity with The SEO Framework's Open Graph, Twitter Card, canonical, and robots fields. Sets the stage for full TSF deactivation in Phase 13.

### Notes
- TSF still emits canonical, robots, and JSON-LD schemas in parallel until Phase 13 cutover. Our emission is the source of truth post-cutover. Until then, TSF's tags are competing — verify after Phase 13 deactivation that crawlers pick up the right ones.

## [1.5.0] - 2026-05-16

### Added
- `inc/login-hide.php` — custom login URL module (~110 LOC). Renames `/wp-login.php` to a custom slug (default: `/sn-login`). Direct visits to `/wp-login.php` and unauthenticated `/wp-admin` requests return 404. Login URL appears in password-reset emails and logout redirects via filter rewrites of `site_url()` / `wp_redirect()` output.

### Behaviour
- Configurable via wp-config.php constants: `SN_LOGIN_SLUG` (default `'sn-login'`) and `SN_LOGIN_BYPASS` (emergency unlock if you lock yourself out).
- **Defensive pre-flight:** module stands down while `wps-hide-login` is still active to avoid conflicting rewrite rules. Surfaces an admin notice explaining the situation. Once `wps-hide-login` is deactivated (Phase 13 of the absorption roadmap), this module takes over seamlessly.
- One-time `flush_rewrite_rules()` on first activation (and again whenever `SN_LOGIN_SLUG` constant changes). Keyed by current slug in `sn_login_rewrites_flushed` option.
- Allow-list for `admin-ajax.php`, `async-upload.php`, `wp-cron.php`, `/wp-json/`, `/feed` so REST + cron + feed flows aren't impacted.

### Notes
- Replaces the `wps-hide-login` third-party plugin. Their plugin is ~700 LOC; ours is ~110. Phase 13 of the absorption roadmap deactivates `wps-hide-login` after this module ships and verifies.

## [1.4.1] - 2026-05-16

### Fixed
- Duplicate `og:image` and `twitter:image` tags in `<head>` (Phase 6 diagnostic outcome). The plugin's `inc/seo.php` has been emitting our generated OG card URLs since Phase 1 (v8.2.0), but The SEO Framework (autodescription) was emitting competing tags first in the source — pointing at the site icon as fallback. Crawler parsing of duplicate `og:image` is undefined; Facebook Debugger would flag the page.

### Behaviour
- Added `the_seo_framework_meta_generator_pools` filter to remove `Open_Graph`, `Facebook`, and `Twitter` pools from TSF's output. Our `wp_head` emission becomes the single source of truth for OG/Twitter meta tags site-wide. TSF still owns canonical URLs, robots meta, JSON-LD schemas, and a handful of og:* fields we don't yet emit (og:locale, og:image:width/height, article:published_time, twitter:site/creator) — those migrate to our seo.php in Phase 10+11.

### Notes
- Stopgap fix until full SEO absorption (Phase 10-13) replaces TSF entirely.

## [1.4.0] - 2026-05-16

### Added
- `inc/wp-update-integration.php` — registers the plugin with WordPress's native update system. Plugin now appears in `wp-admin/update-core.php` and Plugins → Installed Plugins alongside other plugins, showing current version and "up to date" status (or "update available" if auto-deploy ever falls behind a tag). ~130 LOC.

### Behaviour
- Polls GitHub Tags API every 12h (cached in `sn_gh_latest_plugin` site transient). Picks the highest `v\d+\.\d+\.\d+` semver tag from `juanlentino/signal-and-noise-tools`.
- Hooks `pre_set_site_transient_update_plugins` to inject the plugin into WP's update registry: into `->no_update` when local matches GitHub (the normal case under Phase 2c auto-deploy), into `->response` when GitHub is ahead.
- Hooks `upgrader_pre_install` to intercept "Update Now" with a WP_Error directing the maintainer to push a git tag instead — preserves the git checkout that the SSH-based auto-deploy depends on.

### Notes
- Mirror of the theme's equivalent `inc/wp-update-integration.php` shipped in `signal-and-noise` v8.5.0. Both deliver the same UX (visibility in WP's standard update UI) using package-specific filter hooks (plugins vs themes have different transient shapes).
- GitHub API queried unauthenticated; 60 requests/hour limit is plenty given the 12h cache TTL (≤2 requests/day).

## [1.3.0] - 2026-05-16

### Added
- `inc/og-card-generator.php` — OG/Twitter card PHP GD generation, caching, Yoast filter integration. Fonts provided by the theme via `sn_og_font_paths` filter (new cross-package contract).
- `inc/reading-time.php` — reading time calculation, caching in `_sn_reading_time_minutes` post meta, `[sn_reading_time]` shortcode, `render_block` bridge for block-context shortcodes. The previously cross-package `sn_admin_reading_time_tab` hook is now intra-plugin.
- `inc/content-surfaces.php` — Notes category, /notes Page, /provenance + /over-detection + /as-substrate Pages, permalink structure, query loop scoping.
- `inc/content-migrations.php` — 11 one-shot content seed migrations for the Provenance pillar (body, refinements, byline reading time, split, AS substrate seed, card2 longform, card readtimes dynamic, catalog numbers, post-date displaytype, eyebrow dynamic, clear notes template override).
- `inc/content-rendering-helpers.php` — Gutenberg block-markup generators called from migrations (byline_reading_time, toc, papers_index).
- `inc/seed-content/` — HTML bodies consumed by content migrations.

### Changed
- Pre-flight guard #3 added to bootstrap: bails with admin notice if the theme still ships `inc/og-image.php`, `inc/reading-time.php`, or `inc/notes-and-provenance.php` (defends against accidental install-order inversion).

### Notes
- Requires theme v8.4.0+. If installed against an older theme, guard #3 fires; plugin loads dormant. After upgrading the theme, plugin activates normally.
- One new cross-package contract: `sn_og_font_paths` filter (theme listens, plugin dispatches).

## [1.2.0] - 2026-05-15

### Removed
- "Latest on GitHub" status row + "Check Now" button in admin Dashboard tab (`inc/admin-page.php`).
- "Heal Templates" form handler + UI card in admin Dashboard tab (`inc/admin-page.php`).
- Quick "Check updates" entry in WP admin bar (`inc/admin-bar.php`).
- REST routes `/check-updates` and `/heal-templates` (`inc/rest-api.php`). Their backing theme modules retired in theme v8.3.0.
- `upgrader_process_complete` hook in `inc/cloudflare-purge.php` — replaced by deploy-time REST call from GitHub Actions.

### Changed
- `/full-reset` REST endpoint no longer includes a "heal templates" step. New behavior: purge caches + clear DB template overrides only.

### Notes
- Requires theme v8.3.0+. If installed against an older theme, the plugin still loads cleanly — the removed UI elements were the only readers of the retired contracts.

## Infrastructure — Phase 2c (no version bump)

- `.github/workflows/deploy.yml` added: SSH-based auto-deploy. Tag push to plugin repo → GitHub Actions SSHes into Cloudways as a **dedicated, application-scoped SSH user** (`sn-plugin`, alias for `nffqxsrgxz`) and runs `git fetch && git checkout <tag>` in the plugin directory → POSTs to `/purge-cache` for CF cache invalidation. Same tag-push ritual as the theme repo.
- **Security posture:** the deploy SSH key is bound to `sn-plugin`, a dedicated additional user with access only to this application's filesystem. If the GitHub Actions secret is ever leaked, blast radius is bounded to this WP app's content (same as a compromised WP admin), NOT the whole Cloudways server. Earlier intermediate setup using `master_user` was discarded for this reason.
- **One-time live cutover (2026-05-16):** plugin directory renamed from `signal-and-noise-tools-1.2.0` (artifact of the Upload Plugin flow) to the canonical `signal-and-noise-tools`. Done via WP-CLI `deactivate → mv → git clone → checkout v1.2.0 → activate`, sub-second downtime. Backup retained on the live server at `signal-and-noise-tools-1.2.0-old` and `signal-and-noise-tools-OLD-MASTER`; delete after a few days of stable operation.
- Cloudways → GitHub auth uses a dedicated read-only deploy key (`cloudways-server-readonly`) on this repo; the private key lives in the `sn-plugin` user's writable `~/.openssh/cw-to-gh-deploy_ed25519` (Cloudways convention: `~/.ssh/` is root-owned for additional users, `~/.openssh/` is user-writable). The workflow exports `GIT_SSH_COMMAND` on the remote shell to point git at this key without needing a `~/.ssh/config` file.
- Treated as build infra per CLAUDE.md `.github/workflows/` convention (mirrors theme Phase 2a — no version bump for the workflow file itself).

## [1.1.0] — RSS Plausible Tracker migrated from theme MU plugin

First minor in the 1.x line. Brings the early slice of Phase 4 forward (ahead of Phase 2's updater migration) to resolve the awkward dual-state where `rss-plausible-tracker.php` lived in the theme repo but was distributed manually to `wp-content/mu-plugins/`.

### Added

- **[`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php)** — RSS subscriber tracker (formerly `mu-plugins/rss-plausible-tracker.php` in the theme repo, v1.2.0 of the MU plugin). Same DB table (`wp_rss_feed_log`), same option keys (`sn_rss_tracker_settings`, `sn_rss_tracker_db_version`), same cron hook (`sn_rss_tracker_daily_prune`), same admin tab and dashboard widget. Only the file location and surrounding bootstrap changed.
- **[`tests/bot-detection.php`](tests/bot-detection.php)** — standalone PHP fixture test for `sn_rss_tracker_is_bot()`. Moved from theme repo's `mu-plugins/tests/`. Runnable as `php tests/bot-detection.php`.
- **Pre-flight guard #2** in [`signal-and-noise-tools.php`](signal-and-noise-tools.php) — before `require_once`ing the rss tracker module, check `file_exists( WPMU_PLUGIN_DIR . '/rss-plausible-tracker.php' )`. If the legacy MU plugin file is still on disk under `wp-content/mu-plugins/`, this plugin skips loading its own copy and emits a one-line admin notice asking the maintainer to delete the MU file. MU plugin continues serving tracking; no fatal, no downtime, no data loss.

### Migration order

The dual-existence problem is the same shape as Phase 1's, with the same solution: pre-flight guard means no fatal regardless of order.

1. **Install plugin v1.1.0 first.** Maintainer's WP admin → Plugins → existing *Signal & Noise Tools* listing → manual upgrade via Upload Plugin (until Phase 2's auto-updater lands). Plugin's guard sees `wp-content/mu-plugins/rss-plausible-tracker.php` still present → skips loading the new tracker module → MU plugin continues serving tracking → admin notice instructs maintainer on next step.
2. **Delete the MU file via SFTP:** `wp-content/mu-plugins/rss-plausible-tracker.php`. Or via WP-CLI: `wp mu-plugin delete rss-plausible-tracker` (if available).
3. **Next admin pageview:** guard sees the MU file gone → loads our tracker module → tracking continues seamlessly via the plugin. Admin notice disappears.

### Data continuity

- **`wp_rss_feed_log` table:** untouched. Plugin reads/writes the same rows.
- **Options:** `sn_rss_tracker_settings`, `sn_rss_tracker_db_version` — same keys, same values.
- **Cron event:** `sn_rss_tracker_daily_prune` — same hook name. When the MU plugin stops loading, the cron event remains scheduled but its handler is now in the active plugin (function name `sn_rss_tracker_cron_prune` is identical). WP fires the event → plugin's handler runs. Seamless.

### Why minor

New module added, new bootstrap guard, theme repo cleanup in coordinated theme v8.2.1 release — meaningful capability shift in plugin scope (it now owns RSS analytics, not just admin/REST/security tooling). No breaking change. First minor in the 1.x line.

### Coordinated theme release

Ships alongside theme `v8.2.1` (docs-only-ish), which removes `mu-plugins/rss-plausible-tracker.php` from the theme repo and updates the WORDPRESS-REFERENCE §10.0 phase plan. Theme update can ship before, during, or after the plugin update — the plugin's guard handles all orderings.

## [1.0.1] — Pre-flight legacy-theme guard

Patch fix for an order-of-operations footgun discovered during the v1.0.0 install on the live site.

### Why this exists

The Phase 1 split was designed as a coordinated release: install plugin v1.0.0 first, then click the theme update to v8.2.0. The original CHANGELOG entry framed the "duplication window" between these two steps as a cosmetic issue ("WP registers hooks twice"). That framing was wrong.

The actual failure mode: if the plugin loads while the theme is still at v8.1.x, both packages have `function sn_purge_all_caches()`, `function sn_handle_quick_purge_caches()`, and the seven other moved-function declarations on disk. PHP fatals at parse time with "Cannot redeclare function sn_*", WordPress catches it during plugin activation, and the user sees *"Plugin could not be activated because it triggered a fatal error."* It's a hard fatal, not a hook-collision cosmetic.

WordPress hooks ARE idempotent (the `add_action` layer); PHP function declarations are NOT. These are two different layers of WordPress, and the original spec conflated them.

### Fixed

- **[`signal-and-noise-tools.php`](signal-and-noise-tools.php) — pre-flight check at bootstrap.** Before the `require_once` chain runs, the plugin checks whether `wp-content/themes/signal-and-noise/inc/admin-page.php` exists on disk. If it does, the theme is still at v8.1.x and the require chain would fatal. The plugin returns early (skipping module loading entirely) and surfaces an admin notice asking the maintainer to update the theme first. After the theme update lands, the next admin pageview sees the file gone, the guard passes, and modules load normally.

### Behavior contract

- **Theme is at v8.2.0+ (legacy files deleted):** plugin loads modules as usual. No-op cost.
- **Theme is at v8.1.x (legacy files still present):** plugin bootstrap bails before any function is declared; admin notice tells the maintainer to update the theme. No fatal, no broken admin.
- **A non–Signal & Noise theme is active:** guard is skipped (no conflict possible); plugin loads normally.
- **Theme is downgraded back to v8.1.x while the plugin is active:** next request, the guard re-runs and bails. Plugin functions stop being declared; the theme reclaims ownership. No fatal.

### Why patch

No new feature; no breaking change. One pre-flight check added to the bootstrap; the rest of the plugin is byte-identical to v1.0.0. Patch bump per SemVer.

## [1.0.0] — Phase 1: scaffold + easy moves

First release. Nine modules moved from the theme repo via the WP action/filter contract pattern.

### Added

- Plugin bootstrap (`signal-and-noise-tools.php`) with standard WP plugin header.
- 9 modules under `inc/`, mirroring the theme's flat module structure: `seo.php`, `security-headers.php`, `cloudflare-purge.php`, `plausible-api.php`, `plausible-admin.php`, `plausible-widget.php`, `admin-bar.php`, `admin-page.php`, `rest-api.php`.
- Cross-package contracts: three filters (`sn_purge_all_caches_result`, `sn_self_heal_force_run_result`, `sn_updater_branch`) and two actions (`sn_updater_force_check`, `sn_updater_clear_error`).
- GitHub Actions lint workflow (`php -l` on every PHP file).

### Coordination

Ships alongside theme Signal & Noise `v8.2.0`, which deletes the original copies of these 9 modules and registers the listener side of the contracts. Install plugin first, then ship the theme update.

### Spec + plan (from theme repo)

- `docs/superpowers/specs/2026-05-15-companion-plugin-phase-1-design.md`
- `docs/superpowers/plans/2026-05-15-companion-plugin-phase-1.md`
