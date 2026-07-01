<?php
/**
 * Signal & Noise — REST API surface.
 *
 * Historically this file exposed the theme's maintenance actions
 * (purge-cache, clear-overrides, full-reset), the Cron Dashboard
 * endpoints (cron/run, cron/unschedule, cron/history), and the
 * Insights scan endpoints (insights/run, insights/last) behind the
 * `signal-noise/v1` REST namespace.
 *
 * v7.0.0: every one of those legacy routes was removed. They were
 * deprecated across the v4.6.0 → v6.56.0 arc and their callers all
 * migrated to the Abilities run-path
 * (/wp-abilities/v1/abilities/signal-noise/<ability>/run). The
 * implementations live in their own modules (template-maintenance in
 * the theme, inc/cron-dashboard.php, inc/cron-history.php,
 * inc/insights.php) and are dispatched by the Abilities modules
 * (inc/abilities-system.php, inc/abilities-cron.php,
 * inc/abilities-insights.php).
 *
 * What remains here is the shared `SN_REST_NAMESPACE` constant, still
 * consumed by inc/analytics-rest.php.
 *
 * @package SignalNoise
 * @since 7.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_REST_NAMESPACE = 'signal-noise/v1';
