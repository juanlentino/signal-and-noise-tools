<?php
/**
 * Signal & Noise Tools: settings storage + accessors, the schema (the credential mask, sn_settings_defaults).
 *
 * Split from inc/settings.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Length-aware mask for a stored credential rendered into an admin field.
 *
 * Long secrets show the last 4 chars (an affordance to recognize which key is
 * configured); short or empty secrets must NOT round-trip in cleartext —
 * `substr( $v, -4 )` on a value of 4 chars or fewer returns the WHOLE secret.
 * Secrets of 8 chars or fewer therefore get a fixed all-bullet placeholder that
 * leaks neither the value nor its exact length. Both branches keep a leading
 * '••••' so the masked-save guards (which detect an untouched field by its
 * leading bullets) keep working unchanged.
 *
 * Shared by the Music, Cloudflare, Plausible, and Webhooks credential fields.
 *
 * @since 4.14.2
 * @param string $value Stored credential (raw).
 * @return string Masked value for the field, or '' for an empty secret.
 */
function sn_mask_secret( $value ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return '';
	}
	return strlen( $value ) <= 8 ? '••••••••' : '••••' . substr( $value, -4 );
}

/**
 * Full settings schema with generic defaults.
 *
 * Defaults are intentionally NOT site-specific. The juanlentino.com
 * legacy values are seeded via sn_settings_seed_legacy_values()
 * exactly once per environment, hostname-gated.
 *
 * @return array<string,array<string,mixed>>
 */
function sn_settings_defaults() {
	return array(
		'identity' => array(
			'site_name'        => get_bloginfo( 'name' ),
			'site_description' => get_bloginfo( 'description' ),
			'person_name'      => get_bloginfo( 'name' ),
			'locale'           => 'en_US',
			// v6.17.0 (D5): owner-edited availability line surfaced in the
			// Contact/Services hero (theme's [sn_availability] shortcode). Empty
			// = hidden. Lives in the identity subtree (written by the Identity
			// form), so no preserve block is needed.
			'availability'     => '',
		),
		'social' => array(
			'twitter_handle' => '',
			'same_as'        => array(),
		),
		'og' => array(
			'default_image_url' => '',
			'card_width'        => 1200,
			'card_height'       => 630,
		),
		// v4.12.0: front-end render knobs the companion theme reads via filters.
		// Non-secret; autoloaded inside sn_settings. Defaults == the theme's
		// own hardcoded defaults, so the theme is unchanged when unset.
		'theme' => array(
			'related_count'          => 3,
			'palette_recent_count'   => 8,
			'palette_enabled'        => true,
			'json_feed_items'        => 20,
			'updated_threshold_days' => 14,
			'reading_wpm'            => 225,
			'notes_per_page'         => 20,
			// v13.17.0: which /contact alias the note Reply row writes to. The
			// default matches the theme's own constant, so an unset value leaves
			// the row exactly as it shipped. Constrained to
			// SN_TF_NOTE_REPLY_ALIASES above — never free text.
			'note_reply_alias'       => 'research',
			'ai_model'               => 'claude-sonnet-5-5', // equals sn_ai_default_model() (pinned in tests/ai-model-catalog.php)
			'ai_monthly_budget'      => 0,
			'jev_credit'             => 5,  // 16.6.0: TypeSafe's monthly credit, USD
			'jev_cycle_day'          => 17, // 16.6.0: the day the credit renews
		),
		'login' => array(
			'slug' => 'sn-login',
		),
		'audit' => array(
			'retention_days' => 90,
		),
		// v4.10.0 (T6): opt-in Speculation Rules tuning. Default ON so the
		// prerender/moderate config applies on every install (migration-free —
		// the array_replace_recursive deep-merge in sn_setting() fills this in).
		'perf' => array(
			'speculative_loading' => true,
		),
		// v12.19.0: the push heartbeat that owned this group's two keys was
		// removed, and with it the last Uptime Kuma reference in the plugin.
		// The GROUP stays and ships no defaults on purpose: inc/spend-watch.php
		// and inc/uptime-status.php write credentials under it at runtime, and
		// sn_settings_save() preserves the whole subtree (see below).
		'monitoring' => array(),
		'seo_copy' => array(
			'home_title'             => '',
			'home_description'       => '',
			'notes_title'            => '',
			'notes_description'      => '',
			'provenance_title'       => '',
			'provenance_description' => '',
		),
		// v5.1.0: IndexNow submission toggle. Default OFF (dormant until the
		// owner enables it on Automation → IndexNow). Migration-free via the
		// array_replace_recursive deep-merge in sn_setting(). The key itself
		// lives in its own sn_indexnow_key option (not here).
		'indexnow' => array(
			'enabled' => false,
		),
		// v6.20.2: weekly Insights digest cron opt-in. Default OFF (dormant
		// until the owner enables it on the Insights tab). Migration-free via
		// the array_replace_recursive deep-merge in sn_setting(). Written via
		// sn_setting_update('insights.weekly_cron_enabled', …) by
		// sn_handle_save_insights_settings() — NOT in the Identity form payload,
		// so it also needs a preserve block in sn_settings_save() below.
		'insights' => array(
			'weekly_cron_enabled' => false,
		),
		// R6a: the daily Operations morning brief is a new mail surface, so it
		// is opt-in and dormant on every existing install. scheduled_reads
		// lives in the same subtree (one preservation branch) even though the
		// board files it under AI: both are opt-in daily crons off this tab.
		'operations' => array(
			'morning_brief_enabled'   => false,
			'scheduled_reads_enabled' => false,
			// Unreleased: spike and break alerts. ON by default (owner, 2026-10-03).
			'alerts_enabled'          => true,
		),
		// v6.23.0: analytics owner/role exclusion (Plausible-style "exclude my
		// own visits"). exclude_roles lists role slugs whose logged-in users get
		// the legacy sn_owner=1 device cookie (SN Traffic Lanes removed the
		// server-side pixel suppression; the edge keeps owner views out). Default EMPTY (dormant): a non-empty default
		// would resurface via array_replace_recursive's index-keyed merge, making
		// "exclude nobody" impossible to store. Written via
		// sn_setting_update('analytics.exclude_roles', …) by
		// sn_handle_analytics_exclude_save() — NOT in the Identity form payload,
		// so it also needs a preserve block in sn_settings_save() below.
		// R6b: the Google Search Console service-account key. A SETTINGS leaf,
		// not its own option, because inc/config-drift.php snapshots only
		// SN_SETTINGS_OPTION — a credential stored elsewhere is drift-INVISIBLE,
		// and a silently-replaced credential is what drift detection exists for.
		// The leaf name ends in `credential` so the drift suffix regex hashes it
		// rather than copying a private key into a second option.
		// item 8: the Workers AI token for SHADOW embeddings. A settings leaf for
		// the same reason the GSC credential is one — inc/config-drift.php
		// snapshots only SN_SETTINGS_OPTION, so a credential stored elsewhere is
		// drift-INVISIBLE. The `token` suffix is already in the drift regex.
		'ml' => array(
			'embeddings_token' => '',
			// v11.26.0: which lexical signal the related-notes blend uses.
			// 'embeddings' | 'tfidf'. A toggle, not a release, so an adoption that
			// reads badly on the live site is reversible in one click.
			'related_source'   => 'embeddings',
		),
		'search_console' => array(
			'gsc_credential' => '',
			// The property string Google knows this site by. NOT derivable from the
			// site URL: a domain property ('sc-domain:example.com') and a URL-prefix
			// property ('https://example.com/') are different strings and only one
			// may be granted, so it is CHOSEN from what the credential can see.
			'property'       => '',
		),
		// 18.x: the north star (inc/north-star.php). Which page groups count as
		// the site's core reading, and what counts as reading one: scroll OR dwell.
		'north_star' => array(
			'sections' => array( 'notes', 'provenance', 'resume', 'about' ),
			'scroll'   => 50,
			'dwell_s'  => 30,
		),
		'analytics' => array(
			'exclude_roles' => array(),
			// v9.36.0 settings hub: predictive-engine tuning knobs. Scalar
			// defaults are safe with array_replace_recursive (unlike the
			// exclude_roles list above). Written by
			// sn_handle_analytics_tuning_save(); the whole analytics subtree is
			// already preserved by sn_settings_save()'s v6.23.0 block.
			'signal_baseline_days' => 30,
			'anomaly_sensitivity'  => 'standard',
			// 2026-10-16: how long a note's publish window lasts, in minutes
			// (inc/publish-window.php). Written by
			// sn_handle_analytics_publish_window_save(), clamped 5 to 1440.
			'publish_window_minutes' => 60,
			// S2 §3 (v9.42.0 arc): owner-defined session funnels (Visits view).
			// MUST default to an EMPTY array — same reasoning as exclude_roles
			// above: a non-empty default would resurface via array_replace_recursive
			// (index-keyed merge), making "no configured funnels" (i.e. "use the
			// hardcoded defaults") impossible to store once a funnel is deleted.
			// Written via sn_setting_update('analytics.funnels', …) by
			// sn_handle_analytics_funnels_save() — already covered by the whole-
			// analytics-subtree preserve block in sn_settings_save() below.
			'funnels' => array(),
		),
	);
}

