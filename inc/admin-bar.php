<?php
/**
 * Signal & Noise — Admin bar quick actions.
 *
 * Adds a top-bar dropdown labeled "S&N" with one-click access to the
 * maintenance actions that previously required navigating to
 * Appearance → Signal & Noise → Dashboard. Available from any admin
 * page AND from the front-end (when the admin bar is shown).
 *
 * Actions exposed:
 *   - Clear DB Overrides    (this theme's wp_template / wp_template_part)
 *                           DESTRUCTIVE: force-delete, no trash. Hidden in the
 *                           Site Editor — see sn_admin_bar_destructive_allowed().
 *   - Check for Updates     (re-poll GitHub for theme update)
 *
 * Each action runs over admin-ajax with a per-action nonce. JS shows a
 * toast notification so the user doesn't navigate. Successes are green,
 * failures red. Toasts auto-dismiss after 3.5s; clicking a toast
 * dismisses it immediately.
 *
 * On the OpenStation shell document (#1623) the same items run
 * through the ability twins over window.sntAbilityRun instead: the shell
 * is a page with no bounded lifetime, and a page-load nonce dies after a
 * day. There the station's own dialog and toast take over from
 * window.confirm and the fixed div.
 *
 * Capability gate: all actions require `manage_options`. The admin bar
 * items aren't even rendered for users without that capability, and the
 * AJAX handlers re-check it server-side.
 *
 * Security:
 *   - Per-action nonces verify each AJAX request
 *   - capability check on every handler
 *   - JS uses textContent (not innerHTML) when manipulating link text
 *   - No user-controlled strings flow into the DOM
 *
 * @package SignalNoise
 * @since 7.0.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/admin-bar-items-parts.php';
require_once __DIR__ . '/admin-bar-menu-parts.php';
require_once __DIR__ . '/admin-bar-assets-parts.php';
