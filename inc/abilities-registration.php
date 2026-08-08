<?php
/**
 * Signal & Noise Tools — Abilities API orchestrator (Phase 14 → v4.1.3 split).
 *
 * Loads the per-feature ability registration files. Was a 1660-line monolith
 * before the v4.1.3 split (audit B-11) — now a thin loader that requires the
 * 8 feature-scoped files below.
 *
 * Architecture:
 *   - inc/abilities-permission-helpers.php   — named permission callbacks
 *     (snt_ability_perm_manage_options, _edit_post, _edit_attachment,
 *     _delete_attachment) that replace the inline closure pattern.
 *   - inc/abilities-categories.php           — 5 category registrations on
 *     `wp_abilities_api_categories_init` (idempotent vs. theme).
 *   - inc/abilities-system.php                — 5 abilities: cache/template
 *     overrides + deploy status + draft-release-notes (v4.11.0).
 *   - inc/abilities-content.php               — 5 abilities: OG card regen,
 *     RSS feed activity stats + content ops.
 *   - inc/abilities-cron.php                  — 4 abilities: WP-Cron dashboard
 *     + run-cron-event (v4.6.0).
 *   - inc/abilities-insights.php              — 2 abilities: Content Opportunity
 *     Advisor scan + last-result.
 *   - inc/abilities-narration.php             — 2 abilities: weekly analytics
 *     digest run + get (v7.0.0).
 *   - inc/abilities-audit.php                 — 3 abilities: consolidated
 *     audit-log read (view=summary|counters|logins) + prune + CSV/JSON export.
 *   - inc/abilities-block-migrations.php      — 3 abilities: block-migration
 *     scan/suggest/apply ('tools' category).
 *   - inc/abilities-ai-post-editor.php       — 3 abilities: meta-description,
 *     OG card title, excerpt (post-editor AI buttons).
 *   - inc/abilities-ai-health.php             — 9 abilities: Health-tab AI
 *     Suggest+Apply (alt text, drift phrases, inline alt, orphan media).
 *   - inc/abilities-ai-pattern-adoption.php  — 2 abilities: pattern-adoption
 *     Suggest+Apply (pull-quote + steps-enumerated). Added v4.3.0.
 *   - inc/abilities-pattern-adoption.php     — 1 ability: pattern-adoption
 *     scan (structural 'tools' category, v4.6.0).
 *   - inc/abilities-dismiss.php              — 1 ability: unified
 *     dismiss-candidate (v7.7.0; the per-surface dismisses' replacement).
 *   - inc/abilities-prepop-dismiss.php       — 1 ability (v6.55.0).
 *   - inc/abilities-health.php               — 1 ability (v7.0.0).
 *   - inc/abilities-machine-readers.php      1 ability (v10.1.0): the
 *     read-only Machine Readers glance (the agent twin of the Desktop Mode
 *     tile route). Cites the same `analytics` category as the analytics pair.
 *   (+ inc/abilities-analytics.php — 2 read-only analytics abilities —
 *   required directly from signal-and-noise-tools.php, not this loader.)
 *
 * Total: 52 unique abilities + 6 categories, site-wide (v10.1.0 recount: the
 * standing "44" dated from v8.0.0 and had drifted as collector-status,
 * provenance, run-health-scan, uptime-status, get-404-log,
 * provenance-integrity-status and machine-readers landed; several of those
 * register outside this loader, so count slugs, not require lines).
 * The v7.7.0 deprecation ladder CLOSED in
 * v8.0.0: the nine deprecated abilities, the `updates` category, and
 * inc/abilities-deprecations.php were removed (see CHANGELOG v8.0.0 for the
 * old → new mapping; tests/abilities-removals-v8.php guards the removal).
 * Each feature file owns its
 * `add_action( 'wp_abilities_api_init', ... )` registration block plus the
 * thin impl wrappers that delegate to the underlying module helpers.
 *
 * Bootstrap requires this file from signal-and-noise-tools.php:155 — keeping
 * the filename ensures a drop-in swap. Load order doesn't affect hook firing
 * (WordPress queues all `add_action` callbacks for a hook regardless of file
 * order), but permission helpers are required first so `permission_callback`
 * string callables resolve at invocation time.
 *
 * @package SignalNoiseTools
 * @since 2.0.4 (split in 4.1.3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abilities-permission-helpers.php';
require_once __DIR__ . '/abilities-categories.php';
require_once __DIR__ . '/abilities-system.php';
require_once __DIR__ . '/abilities-content.php';
require_once __DIR__ . '/abilities-cron.php';
require_once __DIR__ . '/abilities-insights.php';
require_once __DIR__ . '/abilities-narration.php';        // v7.0.0: 2 abilities (run + get the weekly analytics digest)
require_once __DIR__ . '/abilities-audit.php';
require_once __DIR__ . '/abilities-ai-post-editor.php';
require_once __DIR__ . '/abilities-ai-health.php';
require_once __DIR__ . '/abilities-ai-cache-probe.php';    // v10.69.0: 1 ability (read-only prompt-cache probe verdict)
require_once __DIR__ . '/abilities-ai-pattern-adoption.php';
require_once __DIR__ . '/abilities-pattern-adoption.php';  // v4.6.0: 1 ability (scan; dismiss unified into dismiss-candidate)
require_once __DIR__ . '/abilities-dismiss.php';           // v7.7.0: 1 ability (unified dismiss-candidate)
require_once __DIR__ . '/abilities-prepop-dismiss.php';    // v6.55.0: 1 ability (prepop notice dismiss)
require_once __DIR__ . '/abilities-health.php';            // v7.0.0: 1 ability (read-only Content-Health scan summary)
require_once __DIR__ . '/abilities-machine-readers.php';   // v10.1.0: 1 ability (read-only Machine Readers glance)
