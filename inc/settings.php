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
			'ai_model'               => 'claude-sonnet-5',
			'ai_monthly_budget'      => 0,
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
		),
		// v6.23.0: analytics owner/role exclusion (Plausible-style "exclude my
		// own visits"). exclude_roles lists role slugs whose logged-in users are
		// NOT counted — the theme's sn_beacon_enabled filter suppresses the
		// front-end pixel for them. Default EMPTY (dormant): a non-empty default
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
		'analytics' => array(
			'exclude_roles' => array(),
			// v9.36.0 settings hub: predictive-engine tuning knobs. Scalar
			// defaults are safe with array_replace_recursive (unlike the
			// exclude_roles list above). Written by
			// sn_handle_analytics_tuning_save(); the whole analytics subtree is
			// already preserved by sn_settings_save()'s v6.23.0 block.
			'signal_baseline_days' => 30,
			'anomaly_sensitivity'  => 'standard',
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

/**
 * Read a setting by dot-delimited path, deep-merged with defaults.
 *
 * Static-cached per request — one get_option() call regardless of
 * how many sn_setting() invocations.
 *
 * @param string $path    Dot-delimited path (e.g. 'identity.site_name').
 * @param mixed  $default Fallback if path doesn't resolve.
 * @return mixed
 */
function sn_setting( $path, $default = null ) {
	static $merged = null;

	// v4.2.0: sentinel path forces cache reset. Used by
	// sn_setting_reset_cache() — no other call site should pass this.
	if ( '__sn_reset_cache__' === $path ) {
		$merged = null;
		return null;
	}

	if ( null === $merged ) {
		$stored = get_option( SN_SETTINGS_OPTION, array() );
		$merged = array_replace_recursive(
			sn_settings_defaults(),
			is_array( $stored ) ? $stored : array()
		);
	}
	$value = $merged;
	foreach ( explode( '.', $path ) as $key ) {
		if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
			return $default;
		}
		$value = $value[ $key ];
	}
	return $value;
}

/**
 * Bust the per-request static cache held by sn_setting().
 *
 * Call after any write to the sn_settings option that happens outside
 * sn_setting_update() — for example, manual update_option() in a save
 * handler. Without this, subsequent sn_setting() reads in the same
 * request return the stale cached value (the D-06 audit failure mode).
 *
 * @since 4.2.0
 */
function sn_setting_reset_cache() {
	sn_setting( '__sn_reset_cache__' );
}

/**
 * Write a single dot-path setting + bust the cache + return success.
 *
 * Replaces the direct get_option/update_option pattern in admin save
 * handlers. Busting the cache makes the new value visible to any
 * sn_setting() call later in the same request.
 *
 * Note: this writes a SPARSE option — only the dot-path key is included in the
 * stored array. Read-side defaults from sn_settings_defaults() fill the gaps,
 * but if sn_settings_save() runs afterward and you've written a setting whose
 * top-level key isn't in the Identity-tab form payload, this sparse write may
 * be overwritten by sn_settings_save()'s whole-option replace.
 *
 * @since 4.2.0
 * @param string $path  Dot-delimited path (e.g. 'login.slug').
 * @param mixed  $value New value.
 * @return bool True if value is correctly present after the write
 *              (re-read disambiguates "no change" from "real failure" —
 *              same gotcha as the v3.x save_login handler comment).
 */
function sn_setting_update( $path, $value ) {
	$settings = (array) get_option( SN_SETTINGS_OPTION, array() );
	$segments = explode( '.', $path );
	$cursor   =& $settings;
	$last     = count( $segments ) - 1;
	foreach ( $segments as $i => $segment ) {
		if ( $i === $last ) {
			$cursor[ $segment ] = $value;
		} else {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}
			$cursor =& $cursor[ $segment ];
		}
	}
	unset( $cursor );

	update_option( SN_SETTINGS_OPTION, $settings );
	sn_setting_reset_cache();

	// gotcha #10 disambiguation: update_option returns false on both
	// "no change" and "real failure" — re-read to confirm.
	$re_read = (array) get_option( SN_SETTINGS_OPTION, array() );
	$cursor  = $re_read;
	foreach ( $segments as $segment ) {
		if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
			return false;
		}
		$cursor = $cursor[ $segment ];
	}
	return $cursor === $value;
}

/**
 * Sanitize + persist settings from a $_POST submission.
 *
 * @param array $raw Raw (still-slashed) $_POST data from the Identity tab form.
 * @return bool True on update_option success.
 */
function sn_settings_save( $raw ) {
	// WP core slashes all of $_POST (wp_magic_quotes()), and update_option()
	// — unlike update_post_meta() — does NOT unslash on write. Without this,
	// every apostrophe gains a backslash layer PER SAVE (n → 2n+1, since the
	// form re-echoes the stored value): the v9.36.x /provenance og:description
	// read "what\\\\…\'s human" in link previews after five Identity saves.
	$raw = wp_unslash( (array) $raw );

	$sanitized = array();

	// knows_about — textarea, one topic per line. Trim each, drop empties.
	$knows_about_raw   = (string) ( $raw['identity_knows_about'] ?? '' );
	$knows_about_lines = preg_split( '/\r\n|\r|\n/', $knows_about_raw );
	$knows_about_clean = array();
	foreach ( $knows_about_lines as $line ) {
		$clean = sanitize_text_field( trim( (string) $line ) );
		if ( '' !== $clean ) {
			$knows_about_clean[] = $clean;
		}
	}

	$sanitized['identity'] = array(
		'site_name'        => sanitize_text_field( (string) ( $raw['identity_site_name'] ?? '' ) ),
		'site_description' => sanitize_text_field( (string) ( $raw['identity_site_description'] ?? '' ) ),
		'person_name'      => sanitize_text_field( (string) ( $raw['identity_person_name'] ?? '' ) ),
		'job_title'        => sanitize_text_field( (string) ( $raw['identity_job_title'] ?? '' ) ),
		'knows_about'      => $knows_about_clean,
		'locale'           => sanitize_text_field( (string) ( $raw['identity_locale'] ?? 'en_US' ) ),
		// v6.17.0 (D5): availability line. Part of the Identity form payload, so
		// it persists directly with the rest of the identity subtree.
		'availability'     => sanitize_text_field( (string) ( $raw['identity_availability'] ?? '' ) ),
	);

	$same_as_raw   = (array) ( $raw['social_same_as'] ?? array() );
	$same_as_clean = array();
	foreach ( $same_as_raw as $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( $url ) {
			$same_as_clean[] = $url;
		}
	}
	$sanitized['social'] = array(
		'twitter_handle' => sanitize_text_field( (string) ( $raw['social_twitter_handle'] ?? '' ) ),
		'same_as'        => array_values( $same_as_clean ),
	);

	$sanitized['og'] = array(
		'default_image_url' => esc_url_raw( (string) ( $raw['og_default_image_url'] ?? '' ) ),
		'card_width'        => max( 1, (int) ( $raw['og_card_width'] ?? 1200 ) ),
		'card_height'       => max( 1, (int) ( $raw['og_card_height'] ?? 630 ) ),
	);

	// Preserve existing login slug when login_slug isn't in $raw — happens
	// when save_identity fires after v1.9.0 moved the slug field to its
	// own Login tab. Without this, saving Identity would clobber the
	// configured slug back to 'sn-login'.
	$existing_slug = (string) sn_setting( 'login.slug', 'sn-login' );
	$sanitized['login'] = array(
		'slug' => sanitize_title( (string) ( $raw['login_slug'] ?? $existing_slug ) ),
	);

	// Preserve the audit subtree — configured on the Security tab's Audit-log
	// sub-tab via sn_setting_update('audit.retention_days', …), NOT in this
	// Identity-tab form payload. Without this, saving Identity clobbers a
	// configured retention back to the 90-day default — the exact whole-option-
	// replace hazard documented in sn_setting_update()'s docblock, and the same
	// reason login.slug is preserved above. Re-include the whole subtree (rather
	// than a single key) so future audit settings survive too. (v4.5.2)
	$existing_settings = (array) get_option( SN_SETTINGS_OPTION, array() );
	if ( isset( $existing_settings['audit'] ) && is_array( $existing_settings['audit'] ) ) {
		$sanitized['audit'] = $existing_settings['audit'];
	}

	// v4.9.0 (T4): preserve the monitoring subtree — since v12.19.0 that is the
	// Better Stack and Spend-watch credentials, written on the Webhooks tab via
	// sn_setting_update('monitoring.*', …) and NOT present in this Identity-tab
	// form payload. Same whole-option-replace hazard as the audit subtree above.
	if ( isset( $existing_settings['monitoring'] ) && is_array( $existing_settings['monitoring'] ) ) {
		$sanitized['monitoring'] = $existing_settings['monitoring'];
	}

	// v4.10.0 (T6): preserve the perf subtree (Speculation Rules toggle),
	// configured on the Tools tab via sn_setting_update('perf.*', …), NOT in
	// this Identity-tab form payload. Same whole-option-replace hazard as the
	// audit/monitoring subtrees above.
	if ( isset( $existing_settings['perf'] ) && is_array( $existing_settings['perf'] ) ) {
		$sanitized['perf'] = $existing_settings['perf'];
	}

	// v4.12.0: preserve the theme subtree (Tools -> Front-End render knobs),
	// configured via sn_setting_update('theme.*', ...) by sn_handle_save_theme(),
	// NOT in this Identity-tab form payload. Without this, saving Identity
	// silently reverts every configured front-end knob to its default -- the same
	// whole-option-replace hazard as the audit/monitoring/perf subtrees above.
	if ( isset( $existing_settings['theme'] ) && is_array( $existing_settings['theme'] ) ) {
		$sanitized['theme'] = $existing_settings['theme'];
	}

	// v5.1.0: preserve the indexnow subtree (Automation → IndexNow enable
	// toggle), configured via sn_setting_update('indexnow.enabled', …), NOT in
	// this Identity-tab form payload. Same whole-option-replace hazard as the
	// audit/monitoring/perf/theme subtrees above.
	if ( isset( $existing_settings['indexnow'] ) && is_array( $existing_settings['indexnow'] ) ) {
		$sanitized['indexnow'] = $existing_settings['indexnow'];
	}

	// v6.20.2: preserve the insights subtree (Insights tab → weekly-digest cron
	// opt-in), configured via sn_setting_update('insights.weekly_cron_enabled', …)
	// by sn_handle_save_insights_settings(), NOT in this Identity-tab form payload.
	// Without this, saving Identity silently reverts a configured weekly-cron
	// opt-in back to its default OFF — the same whole-option-replace hazard as the
	// audit/monitoring/perf/theme/indexnow subtrees above, and the reason the
	// user's cron preference would diverge from the actually-scheduled event.
	if ( isset( $existing_settings['insights'] ) && is_array( $existing_settings['insights'] ) ) {
		$sanitized['insights'] = $existing_settings['insights'];
	}

	// R6a: preserve the Operations subtree (the opt-in morning brief), which
	// is written from Connections -> Cron rather than the Identity form.
	if ( isset( $existing_settings['operations'] ) && is_array( $existing_settings['operations'] ) ) {
		$sanitized['operations'] = $existing_settings['operations'];
	}

	// v6.23.0: preserve the analytics subtree (Measurement → Analytics → "Exclude
	// my own visits"), configured via sn_setting_update('analytics.exclude_roles',
	// …) by sn_handle_analytics_exclude_save(), NOT in this Identity-tab form
	// payload. Same whole-option-replace hazard as the audit/monitoring/perf/
	// theme/indexnow/insights subtrees above — without this, saving Identity
	// silently re-counts the owner by reverting exclude_roles to the empty default.
	if ( isset( $existing_settings['analytics'] ) && is_array( $existing_settings['analytics'] ) ) {
		$sanitized['analytics'] = $existing_settings['analytics'];
	}

	// v9.88.0: same hazard, machine_readers subtree (v9.85.0). Its read token is
	// written by sn_handle_machine_readers_save() and is WRITE-ONLY in the UI, so
	// an Identity save clobbering it destroys a value the owner cannot re-read —
	// they must re-derive it from the Worker secret. Fifth subtree in this class.
	if ( isset( $existing_settings['machine_readers'] ) && is_array( $existing_settings['machine_readers'] ) ) {
		$sanitized['machine_readers'] = $existing_settings['machine_readers'];
	}

	// R6b: same hazard, search_console subtree. Its credential is written from
	// Measurement -> Search Console and is WRITE-ONLY in the UI (the screen never
	// echoes it back), so an Identity save clobbering it destroys a value the owner
	// cannot re-read — they would have to re-download the key from Google Cloud.
	// Sixth subtree in this class.
	if ( isset( $existing_settings['search_console'] ) && is_array( $existing_settings['search_console'] ) ) {
		$sanitized['search_console'] = $existing_settings['search_console'];
	}

	// item 8: same hazard, ml subtree (the Workers AI token). Seventh in this class.
	if ( isset( $existing_settings['ml'] ) && is_array( $existing_settings['ml'] ) ) {
		$sanitized['ml'] = $existing_settings['ml'];
	}

	$sanitized['seo_copy'] = array(
		'home_title'             => sanitize_text_field( (string) ( $raw['seo_home_title'] ?? '' ) ),
		'home_description'       => sanitize_textarea_field( (string) ( $raw['seo_home_description'] ?? '' ) ),
		'notes_title'            => sanitize_text_field( (string) ( $raw['seo_notes_title'] ?? '' ) ),
		'notes_description'      => sanitize_textarea_field( (string) ( $raw['seo_notes_description'] ?? '' ) ),
		'provenance_title'       => sanitize_text_field( (string) ( $raw['seo_provenance_title'] ?? '' ) ),
		'provenance_description' => sanitize_textarea_field( (string) ( $raw['seo_provenance_description'] ?? '' ) ),
	);

	return (bool) update_option( SN_SETTINGS_OPTION, $sanitized );
}

/**
 * One-time seed of the JL-specific legacy values into wp_options.
 *
 * Hostname-gated: only seeds when home_url() host is juanlentino.com.
 * On any other host, sets only the migrated flag so the migration
 * doesn't re-attempt and generic defaults from sn_settings_defaults()
 * take over.
 *
 * Idempotent — guarded by SN_SETTINGS_MIGRATED_FLAG.
 */
function sn_settings_seed_legacy_values() {
	if ( get_option( SN_SETTINGS_MIGRATED_FLAG ) ) {
		return;
	}

	$host = parse_url( home_url(), PHP_URL_HOST );
	if ( $host === SN_LEGACY_HOST ) {
		update_option( SN_SETTINGS_OPTION, array(
			'identity' => array(
				'site_name'        => 'Juan Lentino',
				'site_description' => 'Music Production & Creative Strategy',
				'person_name'      => 'Juan Lentino',
				'locale'           => 'en_US',
			),
			'social' => array(
				'twitter_handle' => '@juan_lentino',
				'same_as'        => array(
					'https://x.com/juan_lentino',
					'https://instagram.com/juan_lentino',
					'https://linkedin.com/in/juanlentino',
				),
			),
			'og' => array(
				'default_image_url' => home_url( '/wp-content/uploads/2026/02/cropped-jl_logo-min-300x300.png' ),
				'card_width'        => 1200,
				'card_height'       => 630,
			),
			'login' => array(
				'slug' => 'sn-login',
			),
			'seo_copy' => array(
				'home_title'             => 'Juan Lentino. Music producer & creative strategist',
				'home_description'       => 'Music producer, mix engineer, and creative strategist based in Buenos Aires. Founder of Panacea recording studio.',
				'notes_title'            => 'Notes — Juan Lentino',
				'notes_description'      => 'Working notes on music, AI, and the infrastructure underneath. Written when there\'s something worth writing.',
				'provenance_title'       => 'Music has a verification problem. Detection isn\'t the answer.',
				'provenance_description' => "A short read on why the industry needs to prove what's human, not chase what isn't.",
			),
		) );
	}

	update_option( SN_SETTINGS_MIGRATED_FLAG, '1' );
}

/**
 * Lazy fallback handler for SSH-based upgrades.
 *
 * register_activation_hook() doesn't fire when the plugin is upgraded
 * via git checkout (Phase 2c auto-deploy uses SSH + git checkout).
 * Run the migration check on admin_init so it self-heals on the next
 * admin pageview.
 */
function sn_settings_lazy_migration_check() {
	if ( ! get_option( SN_SETTINGS_MIGRATED_FLAG ) ) {
		sn_settings_seed_legacy_values();
	}
}
