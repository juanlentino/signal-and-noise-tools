<?php
/**
 * Signal & Noise Tools — Dashboard tab renderer.
 *
 * Owns the full Dashboard tab content via the `sn_admin_dashboard_extras`
 * action hook (fired in inc/admin-page.php). The legacy Status table +
 * Override details + Actions card grid that previously rendered inline
 * in admin-page.php were absorbed into this file in v1.14.0.
 *
 * Composition: v11.28.0 built this tab from collapsing zones, a measurement
 * strip, an attention strip, an external-APIs line and a Maintenance grid.
 * v11.29.1 and v11.30.0 replaced that screen with the console (inc/dash-*.php
 * compose it; this file gathers the inputs). The zone renderer, the strip
 * renderer and the Maintenance grid were removed once nothing called them.
 *
 * Design principles (per memory: feedback_no_brutalist_in_admin_ui.md):
 *   - WP-admin native (.button, .notice, .widefat, .form-table where it fits)
 *   - .sn-* classes for composition patterns that don't have a WP-native
 *     equivalent (.sn-glance, .sn-deploy-list, .sn-api-summary)
 *   - NO inline styles — all promoted to assets/admin.css
 *   - NO brutalist treatment (uppercase labels OK in muted secondary text,
 *     but no Bebas-feel display type, no display mono)
 *
 * Verified against WP source (per CLAUDE.md framework-source-first rule):
 *   - admin-post.php reads $action from $_REQUEST, fires admin_post_{$action}
 *     for logged-in users; no automatic nonce verification.
 *   - .button .button-primary .button-secondary are WP-canonical classes
 *     (wp-admin/css/common.css).
 *
 * Replaces inc/deploy-widget.php (v1.12.0, removed v1.13.0) and
 * inc/deploy-status.php (v1.13.0, renamed/redesigned this file in v1.14.0).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/dash-attention.php'; // what needs attention, said once on both twins (2026.10.4).

const SNT_DEPLOY_REPOS = array(
	'theme'  => 'juanlentino/signal-and-noise',
	'plugin' => 'juanlentino/signal-and-noise-tools',
);

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/admin-tab-dashboard-data-parts.php';
require_once __DIR__ . '/admin-tab-dashboard-cards-parts.php';
require_once __DIR__ . '/admin-tab-dashboard-actions-parts.php';
