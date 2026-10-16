<?php
/**
 * Signal & Noise Tools — settings storage + accessors.
 *
 * Single wp_option ('sn_settings') stores all site-identity
 * configuration as a structured array across 5 categories:
 * identity, social, og, login, seo_copy. Code throughout the
 * plugin reads via `sn_setting('cat.field')` with dot-paths.
 *
 * Defaults are generic — pulled from WP built-ins (get_bloginfo)
 * where possible — keeping the plugin portable for new installs.
 * The current production site (juanlentino.com) gets its specific
 * legacy values seeded on first v1.8.0 activation via a hostname-
 * gated migration. A lazy admin_init fallback covers SSH-based
 * upgrades where register_activation_hook doesn't fire.
 *
 * Added in v1.8.0 (2026-05-16, Phase 11.5).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The aliases the note Reply row may use — the five /contact aliases the
 * mailbox actually filters.
 *
 * A FUNCTION, not a constant, and deliberately: three files need this list
 * (the settings form, the save handler, the theme filter) and they load in
 * three different orders — inc/theme-filters.php comes AFTER
 * inc/admin-post-actions.php, and the standalone suites each load a different
 * subset. A constant in any one of them is absent for the others, which is a
 * FATAL rather than a wrong answer. Every caller guards with function_exists
 * and falls back to research-only: fail CLOSED, because the failure being
 * guarded is minting an address the mailbox does not filter.
 *
 * @since 13.17.0
 * @return string[] Allowed local parts.
 */
function sn_note_reply_aliases() {
	return array( 'research', 'press', 'speaking', 'role', 'music' );
}


const SN_SETTINGS_OPTION       = 'sn_settings';
const SN_SETTINGS_MIGRATED_FLAG = 'sn_settings_migrated_v1';
const SN_LEGACY_HOST            = 'juanlentino.com';

// The rest of the module lives in three parts files (house line cap, 2026-10-15),
// loaded in the order it always ran so the init hook keeps its place.
require_once __DIR__ . '/settings-defaults-parts.php';
require_once __DIR__ . '/settings-accessors-parts.php';
require_once __DIR__ . '/settings-save-parts.php';
