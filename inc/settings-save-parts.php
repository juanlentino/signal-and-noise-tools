<?php
/**
 * Signal & Noise Tools: settings storage + accessors, the writers (sn_settings_save, the legacy seed, the lazy migration check).
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
		'locale'           => sanitize_text_field( (string) ( $raw['identity_locale'] ?? 'en_US' ) ),
		// v6.17.0 (D5): availability line. Part of the Identity form payload, so
		// it persists directly with the rest of the identity subtree.
		'availability'     => sanitize_text_field( (string) ( $raw['identity_availability'] ?? '' ) ),
	);

	// job_title / knows_about have no entry in sn_settings_defaults() — the
	// fallback lives entirely in each sn_setting() call site's own $default
	// arg (e.g. seo-schema.php's Person builder). array_replace_recursive()
	// in sn_setting() keeps a present-but-empty key instead of falling
	// through, so storing '' / [] here permanently overrides that fallback.
	// Omit the key on a blank save so the field reverts to the caller
	// default instead of emitting "jobTitle": "" / "knowsAbout": [] forever.
	$job_title = sanitize_text_field( (string) ( $raw['identity_job_title'] ?? '' ) );
	if ( '' !== $job_title ) {
		$sanitized['identity']['job_title'] = $job_title;
	}
	if ( ! empty( $knows_about_clean ) ) {
		$sanitized['identity']['knows_about'] = $knows_about_clean;
	}

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
				// 16.7.2: the February PNG was replaced by a WebP upload on 2026-09-19; the file is named, not read.
				'default_image_url' => home_url( '/wp-content/uploads/2026/09/cropped-jl_logo-min-300x300.webp' ),
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
