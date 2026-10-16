<?php
/**
 * Signal & Noise Tools — Abilities API: system maintenance + deploy state.
 *
 * Five abilities covering cache/template/update housekeeping plus the
 * deploy-status surfaces:
 *   - signal-noise/purge-all-caches         (destructive, idempotent; its
 *     include_template_overrides flag replaced the removed full-reset)
 *   - signal-noise/clear-template-overrides (destructive, idempotent)
 *   - signal-noise/list-template-overrides  (readonly, idempotent)
 *   - signal-noise/get-deploy-status         (readonly, idempotent; force_refresh
 *     clears the update transients first — replaced the removed
 *     force-check-updates)
 *     recategorized diagnostics → ai-generation in 7.7.0)
 *
 * v8.0.0 removed the three 7.7.0-deprecated abilities this file carried
 * (full-reset, force-check-updates, list-abilities — the last replaced by
 * the core catalogue GET /wp-abilities/v1/abilities).
 *
 * Categories: maintenance / diagnostics. File grouping is by feature
 * (system housekeeping) rather than category so related impls stay
 * co-located.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 2.0.4 + 2.5.0 + 3.7.5)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/abilities-system-register-parts.php';
require_once __DIR__ . '/abilities-system-purge-parts.php';
require_once __DIR__ . '/abilities-system-deploy-parts.php';
