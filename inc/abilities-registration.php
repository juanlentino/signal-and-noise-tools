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
 *   - inc/abilities-system.php                — 8 abilities: cache/template
 *     overrides + force-check-updates + deploy status + list-abilities
 *     + draft-release-notes (v4.11.0).
 *   - inc/abilities-content.php               — 2 abilities: OG card regen
 *     + RSS feed activity stats.
 *   - inc/abilities-cron.php                  — 5 abilities: WP-Cron dashboard
 *     + run-cron-event (v4.6.0).
 *   - inc/abilities-insights.php              — 2 abilities: Content Opportunity
 *     Advisor scan + last-result.
 *   - inc/abilities-audit.php                 — 5 abilities: login-hardening
 *     audit log read + prune + CSV/JSON export.
 *   - inc/abilities-block-migrations.php      — 4 abilities: block-migration
 *     scan/suggest/apply/dismiss ('tools' category).
 *   - inc/abilities-ai-post-editor.php       — 3 abilities: meta-description,
 *     OG card title, excerpt (post-editor AI buttons).
 *   - inc/abilities-ai-health.php             — 7 abilities: Health-tab AI
 *     Suggest+Apply (alt text, drift phrases, inline alt, orphan media).
 *   - inc/abilities-ai-pattern-adoption.php  — 2 abilities: pattern-adoption
 *     Suggest+Apply (pull-quote + steps-enumerated). Added v4.3.0.
 *   - inc/abilities-pattern-adoption.php     — 2 abilities: pattern-adoption
 *     scan + dismiss (structural 'tools' category, v4.6.0).
 *
 * Total: 40 abilities + 5 categories. Each feature file owns its
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
require_once __DIR__ . '/abilities-audit.php';
require_once __DIR__ . '/abilities-ai-post-editor.php';
require_once __DIR__ . '/abilities-ai-health.php';
require_once __DIR__ . '/abilities-ai-pattern-adoption.php';
require_once __DIR__ . '/abilities-pattern-adoption.php';  // v4.6.0: 2 abilities (scan + dismiss)
