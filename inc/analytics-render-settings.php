<?php
/**
 * Signal & Noise — Analytics settings-hub partials (Monitoring → Analytics):
 * the read-credentials form, the owner/role exclusion card, the read-only
 * Cloudflare Worker setup reference, and the v9.36.0 hub additions — pipeline
 * status strip, engine-tuning form, read-only shared-config mirrors, and the
 * developer filter reference. Native wp-admin forms; every dynamic value is
 * escaped at the point of output. Extracted from analytics-admin-render.php
 * (v8.9.x split); the one-time Plausible-CSV import panel it once held was
 * retired at v9.0.0 (see the note at the end of this file).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-credentials form: Account ID + Account Analytics Read token, with
 * wp-config-constant precedence (a locked field when the constant is set) and the
 * Save / Test-connection actions. Extracted from the former composite
 * snt_analytics_render_settings() in v6.44.0 so the open-and-wide settings section
 * can place it in its own column (the active-settings card) independent of the
 * edge-worker reference column.
 *
 * @since 6.44.0 (was inline in snt_analytics_render_settings since 3.x)
 */
function snt_analytics_render_credentials() {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	$acct_opt     = (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' );
	$token_opt    = (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' );
	$configured   = (bool) ( function_exists( 'sn_analytics_config' ) && sn_analytics_config() );

	echo '<form method="post" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Credentials', 'signal-and-noise-tools' ) . '</h3>';
	/* translators: 1: the read-token wp-config constant name, wrapped in <code>; 2: the account-ID wp-config constant name, wrapped in <code>. */
	echo '<p class="sn-an-settings-help">' . sprintf( esc_html__( 'Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (%1$s / %2$s) overrides these and locks the field.', 'signal-and-noise-tools' ), '<code>SN_CF_ANALYTICS_TOKEN</code>', '<code>SN_CF_ACCOUNT_ID</code>' ) . '</p>';

	// Account ID.
	echo '<p><label for="sn_cf_account_id"><strong>' . esc_html__( 'Account ID', 'signal-and-noise-tools' ) . '</strong></label><br>';
	if ( $acct_locked ) {
		echo '<input type="text" id="sn_cf_account_id" value="' . esc_attr__( '(set in wp-config)', 'signal-and-noise-tools' ) . '" disabled class="regular-text">';
		/* translators: %s: the wp-config constant name, wrapped in <code>. */
		echo '<br><span class="sn-an-empty">' . sprintf( esc_html__( 'Locked by the %s constant.', 'signal-and-noise-tools' ), '<code>SN_CF_ACCOUNT_ID</code>' ) . '</span>';
	} else {
		echo '<input type="text" id="sn_cf_account_id" name="sn_cf_account_id" value="' . esc_attr( $acct_opt ) . '" class="regular-text" placeholder="' . esc_attr__( '32-char Cloudflare account ID', 'signal-and-noise-tools' ) . '">';
	}
	echo '</p>';

	// Read token (masked).
	echo '<p><label for="sn_cf_analytics_token"><strong>' . esc_html__( 'Account Analytics Read token', 'signal-and-noise-tools' ) . '</strong></label><br>';
	if ( $token_locked ) {
		echo '<input type="text" id="sn_cf_analytics_token" value="••••" disabled class="regular-text">';
		/* translators: %s: the wp-config constant name, wrapped in <code>. */
		echo '<br><span class="sn-an-empty">' . sprintf( esc_html__( 'Locked by the %s constant.', 'signal-and-noise-tools' ), '<code>SN_CF_ANALYTICS_TOKEN</code>' ) . '</span>';
	} else {
		echo '<input type="text" id="sn_cf_analytics_token" name="sn_cf_analytics_token" value="' . esc_attr( sn_mask_secret( $token_opt ) ) . '" class="regular-text" placeholder="' . esc_attr__( 'Paste a fresh token; type ‘clear’ to remove', 'signal-and-noise-tools' ) . '">';
	}
	echo '</p>';

	if ( ! ( $token_locked && $acct_locked ) ) {
		echo '<p><button type="submit" name="sn_action" value="analytics_save" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button> ';
		echo '<button type="submit" name="sn_action" value="analytics_test" class="button"' . ( $configured ? '' : ' disabled' ) . '>' . esc_html__( 'Test connection', 'signal-and-noise-tools' ) . '</button></p>';
	}
	echo '</form>';
}

/**
 * The Monitoring → Analytics "Exclude my own visits" card — a Plausible-style
 * role allow-list. Ticking a role stops the front-end beacon for its logged-in
 * users (the sn_beacon_enabled filter in inc/beacon-owner-exclusion.php suppresses
 * the pixel). Native wp-admin styling; one <form> POSTing analytics_exclude_save.
 *
 * @since 6.23.0
 */
function snt_analytics_render_exclusion() {
	$roles    = function_exists( 'sn_beacon_excludable_roles' ) ? sn_beacon_excludable_roles() : array();
	$excluded = (array) sn_setting( 'analytics.exclude_roles', array() );

	echo '<form method="post" class="sn-an-settings sn-an-exclude">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Exclude my own visits', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Stop counting logged-in users in the selected roles. The front-end beacon is never printed for them, so nothing reaches the collector. Cookieless and forward-only — visits already recorded are unaffected.', 'signal-and-noise-tools' ) . '</p>';

	if ( empty( $roles ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'No roles available on this site.', 'signal-and-noise-tools' ) . '</p></form>';
		return;
	}

	echo '<fieldset>';
	foreach ( $roles as $slug => $name ) {
		$id = 'sn_exclude_role_' . $slug;
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="sn_exclude_roles[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $excluded, true ), true, false ) . '> ' . esc_html( $name ) . ' <code>' . esc_html( $slug ) . '</code></label><br>';
	}
	echo '</fieldset>';

	if ( function_exists( 'sn_beacon_owner_current_user_excluded' ) && sn_beacon_owner_current_user_excluded() ) {
		echo '<p class="sn-an-status"><strong>' . esc_html__( 'You are currently excluded from analytics.', 'signal-and-noise-tools' ) . '</strong></p>';
	} else {
		echo '<p class="sn-an-status">' . esc_html__( 'You are currently counted in analytics.', 'signal-and-noise-tools' ) . '</p>';
	}

	echo '<p class="sn-an-settings-help">' . esc_html__( 'Your own visits are also dropped at the edge: the collector ignores any beacon that carries a logged-in WordPress cookie, so you are excluded even when the page was served from cache. The role list above adds per-role control on uncached requests — to extend that role filter to cached pages too, add a Cloudflare rule that bypasses cache when the request carries a wordpress_logged_in_ cookie.', 'signal-and-noise-tools' ) . '</p>';

	echo '<p><button type="submit" name="sn_action" value="analytics_exclude_save" class="button button-primary">' . esc_html__( 'Save exclusion', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}

/**
 * Read-only Cloudflare Worker setup reference. The plugin can't run wrangler;
 * this shows the exact steps so the Cloudflare side is copy-paste, not guesswork.
 */
function snt_analytics_render_worker_setup() {
	echo '<details class="sn-an-worker"><summary>' . esc_html__( 'Cloudflare Worker setup (manual, one-time)', 'signal-and-noise-tools' ) . '</summary>';
	echo '<ol class="sn-an-steps">';
	echo '<li><strong>' . esc_html__( 'Read token', 'signal-and-noise-tools' ) . '</strong> ';
	/* translators: 1: the Cloudflare API-token permission scope, wrapped in <code>; 2: the Cloudflare dashboard URL pattern, wrapped in <code>. */
	echo sprintf( esc_html__( '(for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with %1$s. The Account ID is in the dashboard URL: %2$s.', 'signal-and-noise-tools' ), '<code>Account · Analytics · Read</code>', '<code>dash.cloudflare.com/&lt;account_id&gt;</code>' ) . '</li>';
	echo '<li><strong>' . esc_html__( 'Deploy the edge Worker + its secrets', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( '(from the analytics-worker repo — this can’t be done from WordPress):', 'signal-and-noise-tools' ) . '<pre class="sn-an-pre">wrangler secret put SN_PX_TOKEN' . "\n" . 'wrangler secret put SN_PX_SALT_SEED' . "\n" . 'wrangler deploy</pre></li>';
	echo '<li><strong>' . esc_html__( 'Theme beacon', 'signal-and-noise-tools' ) . '</strong>: ';
	/* translators: 1: the beacon-token wp-config constant name, wrapped in <code>; 2: the wp-config file name, wrapped in <code>; 3: the worker’s token constant name, wrapped in <code>. */
	echo sprintf( esc_html__( 'set %1$s in %2$s to the SAME value as the Worker’s %3$s so the front-end beacon is accepted.', 'signal-and-noise-tools' ), '<code>SN_BEACON_TOKEN</code>', '<code>wp-config.php</code>', '<code>SN_PX_TOKEN</code>' ) . '</li>';
	echo '<li>';
	/* translators: %s: the "Test connection" button label, wrapped in <strong>. */
	echo sprintf( esc_html__( 'Hit %s above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.', 'signal-and-noise-tools' ), '<strong>' . esc_html__( 'Test connection', 'signal-and-noise-tools' ) . '</strong>' ) . '</li>';
	echo '</ol></details>';
}

/**
 * Settings-hub pipeline status strip (v9.36.0): five presence pills in
 * data-flow order — beacon → worker → read → cron → edge. States: ok | warn |
 * unknown (probe-miss only). Secrets are never echoed; every check is a
 * presence boolean resolved through the SAME helper the consuming feature
 * uses, so the pill can't drift from the real behavior. Missing helpers
 * (isolated harnesses / partial installs) degrade per-pill, never fatal.
 */
function snt_analytics_render_pipeline_status() {
	$pills = array(); // each: array( state, label, warn-note )

	// 1. Beacon token — the resolution sn_rss_tracker_token()/the theme beacon use.
	$beacon = function_exists( 'sn_rss_tracker_token' )
		? sn_rss_tracker_token()
		: ( defined( 'SN_BEACON_TOKEN' ) ? (string) SN_BEACON_TOKEN : '' );
	$pills[] = ( '' !== $beacon )
		? array( 'ok', __( 'Beacon token set', 'signal-and-noise-tools' ), '' )
		: array( 'warn', __( 'Beacon token missing', 'signal-and-noise-tools' ), __( 'The front-end beacon can’t authenticate to the collector — set SN_BEACON_TOKEN in wp-config.php (same value as the Worker’s SN_PX_TOKEN).', 'signal-and-noise-tools' ) );

	// 2. Edge worker — the existing SWR probe; config booleans are presence-only.
	if ( function_exists( 'sn_worker_version_get' ) ) {
		$wv = sn_worker_version_get();
		if ( ! empty( $wv['ok'] ) ) {
			$ver   = (string) ( $wv['data']['version'] ?? '' );
			$cfg   = isset( $wv['data']['config'] ) && is_array( $wv['data']['config'] ) ? $wv['data']['config'] : array();
			$label = '' !== $ver
				/* translators: %s: deployed worker version */
				? sprintf( __( 'Worker v%s', 'signal-and-noise-tools' ), $ver )
				: __( 'Worker reachable', 'signal-and-noise-tools' );
			$missing = array();
			if ( array() !== $cfg ) {
				if ( empty( $cfg['px_token_set'] ) ) { $missing[] = 'SN_PX_TOKEN'; }
				if ( empty( $cfg['ae_bound'] ) ) { $missing[] = 'SN_AE'; }
			}
			$pills[] = empty( $missing )
				? array( 'ok', $label, '' )
				/* translators: %s: comma-separated missing worker bindings */
				: array( 'warn', $label, sprintf( __( 'Worker is missing %s — beacons may be rejected or unrecorded (wrangler secret put / binding).', 'signal-and-noise-tools' ), implode( ', ', $missing ) ) );
		} else {
			$pills[] = array( 'unknown', __( 'Worker unreachable', 'signal-and-noise-tools' ), '' );
		}
	} else {
		$pills[] = array( 'unknown', __( 'Worker status unavailable', 'signal-and-noise-tools' ), '' );
	}

	// 3. Read credentials — what the dashboard queries AE with.
	$configured = function_exists( 'sn_analytics_config' ) && sn_analytics_config();
	$pills[]    = $configured
		? array( 'ok', __( 'Read credentials', 'signal-and-noise-tools' ), '' )
		: array( 'warn', __( 'Read credentials missing', 'signal-and-noise-tools' ), __( 'The dashboard can’t read Analytics Engine — add the Cloudflare credentials below.', 'signal-and-noise-tools' ) );

	// 4. Server token — the */15 cron-refresh auth (fails CLOSED when unset,
	// which today is completely invisible; this pill is that failure's only UI).
	// The RSS srv-trust clause resolves through its OWN filter seam
	// (sn_server_token, inc/rss-feed-tracker.php), so it is checked separately —
	// the two default to the same constant but can diverge under filters.
	$srv = function_exists( 'sn_analytics_refresh_secret' )
		? sn_analytics_refresh_secret()
		: ( defined( 'SN_SRV_TOKEN' ) ? (string) SN_SRV_TOKEN : '' );
	$rss_srv = function_exists( 'sn_rss_tracker_server_token' )
		? sn_rss_tracker_server_token()
		: $srv;
	if ( '' !== $srv ) {
		$pills[] = array( 'ok', __( 'Server token set', 'signal-and-noise-tools' ), '' );
	} else {
		$note = __( 'The */15 cron refresh is disabled (it fails closed) — set SN_SRV_TOKEN in wp-config.php.', 'signal-and-noise-tools' );
		if ( '' === $rss_srv ) {
			$note .= ' ' . __( 'RSS srv hits also lose their trusted class.', 'signal-and-noise-tools' );
		}
		$pills[] = array( 'warn', __( 'SN_SRV_TOKEN missing', 'signal-and-noise-tools' ), $note );
	}

	// 5. Zone ID — gates the dashboard's Edge view (constant > option).
	$zone = ( defined( 'SN_CF_ZONE' ) && '' !== (string) SN_CF_ZONE )
		? (string) SN_CF_ZONE
		: ( defined( 'SN_CF_ZONE_OPT' ) ? (string) get_option( SN_CF_ZONE_OPT, '' ) : '' );
	$pills[] = ( '' !== $zone )
		? array( 'ok', __( 'Zone ID set', 'signal-and-noise-tools' ), '' )
		: array( 'warn', __( 'Zone ID missing', 'signal-and-noise-tools' ), __( 'The Edge view stays dormant — configure the zone on Connections → Cloudflare.', 'signal-and-noise-tools' ) );

	$marks = array(
		'ok'      => '✓',
		'warn'    => '!',
		'unknown' => '?',
	);
	echo '<div class="sn-fieldset sn-an-pipeline">';
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Pipeline status', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Beacon → worker → Analytics Engine → cron. Presence checks only — secret values are never shown.', 'signal-and-noise-tools' ) . '</p>';
	echo '<div class="sn-an-pipeline-pills">';
	foreach ( $pills as $p ) {
		echo '<span class="sn-an-pill sn-an-pill--' . esc_attr( $p[0] ) . '"><span class="sn-an-pill-mark">' . esc_html( $marks[ $p[0] ] ) . '</span> ' . esc_html( $p[1] ) . '</span>';
	}
	echo '</div>';
	foreach ( $pills as $p ) {
		if ( 'warn' === $p[0] && '' !== $p[2] ) {
			echo '<p class="sn-an-pipeline-warn">' . esc_html( $p[1] ) . ' — ' . esc_html( $p[2] ) . '</p>';
		}
	}
	echo '</div>';
}

/**
 * Settings-hub engine tuning (v9.36.0): the two owner-tunable predictive knobs
 * — baseline window (days of history behind the anomaly baseline, clamped
 * 14–90 server-side) and anomaly sensitivity as a preset (relaxed/standard/
 * strict → z 2.5/3.5/4.5 in sn_analytics_signal_opts()). Presets instead of a
 * raw σ field: the label explains the consequence, not the math. Everything
 * else stays filter- or const-tier (design spec §7). Saved by
 * sn_handle_analytics_tuning_save() via sn_action=analytics_tuning_save.
 */
function snt_analytics_render_engine_tuning() {
	$baseline = (int) sn_setting( 'analytics.signal_baseline_days', 30 );
	$preset   = (string) sn_setting( 'analytics.anomaly_sensitivity', 'standard' );
	$presets  = array(
		'relaxed'  => __( 'Relaxed — fewer flags (≈2.5σ)', 'signal-and-noise-tools' ),
		'standard' => __( 'Standard — designed default (≈3.5σ)', 'signal-and-noise-tools' ),
		'strict'   => __( 'Strict — only extremes (≈4.5σ)', 'signal-and-noise-tools' ),
	);
	// Display the engine's effective preset: an unknown stored value falls back
	// to 'standard' in sn_analytics_signal_opts(), so the form mirrors that.
	$preset = isset( $presets[ $preset ] ) ? $preset : 'standard';

	echo '<form method="post" class="sn-an-settings sn-an-tuning">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Engine tuning', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'How the predictive signal engine (anomalies, trends, forecasts) reads your history. Developers can override more via the sn_analytics_signal_config filter — see the reference in the right column.', 'signal-and-noise-tools' ) . '</p>';

	echo '<p><label for="sn_signal_baseline_days"><strong>' . esc_html__( 'Baseline window', 'signal-and-noise-tools' ) . '</strong></label><br>';
	echo '<input type="number" id="sn_signal_baseline_days" name="sn_signal_baseline_days" value="' . esc_attr( (string) $baseline ) . '" min="14" max="90" step="1" class="small-text"> ' . esc_html__( 'days', 'signal-and-noise-tools' );
	echo '<br><span class="sn-an-settings-help">' . esc_html__( 'Days of history behind the anomaly baseline. 14–90; shorter reacts faster, longer is steadier.', 'signal-and-noise-tools' ) . '</span></p>';

	// T4-review a11y carry-over: the radios are a real <fieldset> with a <legend>
	// (the exclusion card's grouping idiom), so screen readers announce the group.
	echo '<fieldset class="sn-an-tuning-radios"><legend><strong>' . esc_html__( 'Anomaly sensitivity', 'signal-and-noise-tools' ) . '</strong></legend>';
	foreach ( $presets as $slug => $label ) {
		echo '<label class="sn-an-radio"><input type="radio" name="sn_anomaly_sensitivity" value="' . esc_attr( $slug ) . '"' . checked( $preset, $slug, false ) . '> ' . esc_html( $label ) . '</label>';
	}
	echo '<p class="sn-an-settings-help">' . esc_html__( 'How unusual a day must be before it’s flagged as an anomaly.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'This preset governs both anomaly families: the predictive signals engine and the per-page skim/dwell detector.', 'signal-and-noise-tools' ) . '</p>';
	echo '</fieldset>';

	echo '<p><button type="submit" name="sn_action" value="analytics_tuning_save" class="button button-primary">' . esc_html__( 'Save tuning', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}

/**
 * Settings-hub session funnels card (S2 §3, Task 3): a zero-JS textarea, one
 * named conversion funnel per line ("Name: /entry > /step > /goal"), prefilled
 * from the CURRENT analytics.funnels setting via sn_analytics_funnels_to_text()
 * so the owner edits what is actually live. Saved by
 * sn_handle_analytics_funnels_save() via sn_action=analytics_funnels_save;
 * parsed by sn_analytics_parse_funnels() (both inc/analytics-sessions.php).
 *
 * Only funnels whose serialized line parses back to itself are expressible in
 * this textarea (see sn_analytics_funnels_to_text) — a funnel carrying a
 * custom-event goal, a prefix-match step (like the two hardcoded defaults), or
 * an out-of-band step value/title the line format can't carry is OMITTED from
 * the prefill rather than invented into a comment syntax the parser would
 * reject. FILTER-defined funnels survive saves (the filter always runs last);
 * a setting-STORED unrepresentable funnel is replaced on the next save like
 * everything else in the option — the help text promises only the former.
 */
function snt_analytics_render_funnels() {
	$funnels = (array) sn_setting( 'analytics.funnels', array() );
	$text    = sn_analytics_funnels_to_text( $funnels );

	echo '<form method="post" class="sn-an-settings sn-an-funnels">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Session funnels', 'signal-and-noise-tools' ) . '</h3>';
	// Both clamps render from their constants (SN_ANALYTICS_FUNNELS_MAX_STEPS /
	// SN_ANALYTICS_FUNNELS_MAX, inc/analytics-sessions.php) so the copy can't
	// drift from the parser again (T3 review: it shipped saying "2–10 steps"
	// while the real step clamp was 8).
	echo '<p class="sn-an-settings-help">' . esc_html( sprintf(
		/* translators: 1: max steps per funnel, 2: max funnel count */
		__( 'Named conversion paths for the Visits view — one per line: "Name: /entry > /step > /goal" (2–%1$d steps, up to %2$d funnels). A bare path gets a leading slash added automatically.', 'signal-and-noise-tools' ),
		SN_ANALYTICS_FUNNELS_MAX_STEPS,
		SN_ANALYTICS_FUNNELS_MAX
	) ) . '</p>';
	echo '<p><label for="sn_funnels" class="screen-reader-text">' . esc_html__( 'Session funnels, one per line', 'signal-and-noise-tools' ) . '</label>';
	echo '<textarea id="sn_funnels" name="sn_funnels" rows="6" class="large-text code" placeholder="' . esc_attr__( 'Home flow: /entry > /step > /goal', 'signal-and-noise-tools' ) . '">' . esc_textarea( $text ) . '</textarea></p>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Saving any funnel here replaces the built-in defaults for the Visits view — including their custom-event goals. Those defaults remain available via the sn_analytics_session_funnels filter, which always runs last and wins over whatever is saved here.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Only exact-match path steps can be expressed here. Funnels this box can’t express — prefix matching, custom-event goals — are not shown above and are managed in code via the filter, which always wins last.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p><button type="submit" name="sn_action" value="analytics_funnels_save" class="button button-primary">' . esc_html__( 'Save funnels', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}

/**
 * Settings-hub "Configured elsewhere" mirrors (v9.36.0): read-only rows for the
 * analytics-load-bearing settings that live on other tabs, each with a deep
 * link to its real home. HARD RULE: no inputs here, ever — one write surface
 * per option (single source of truth; the drift class the hub exists to avoid).
 */
function snt_analytics_render_mirrors() {
	echo '<div class="sn-an-mirrors">';
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Configured elsewhere', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Settings analytics depends on that live on other tabs — shown read-only; follow a link to change one.', 'signal-and-noise-tools' ) . '</p>';

	// AI model + monthly budget (drives the digest tier).
	$model  = (string) sn_setting( 'theme.ai_model', 'claude-sonnet-5' );
	$models = function_exists( 'sn_theme_ai_models' ) ? sn_theme_ai_models() : array();
	$label  = isset( $models[ $model ] ) ? (string) $models[ $model ] : $model;
	$budget = (float) sn_setting( 'theme.ai_monthly_budget', 0 );
	$spent  = function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0;
	echo '<div class="sn-an-mirror-row"><strong>' . esc_html__( 'AI model', 'signal-and-noise-tools' ) . ':</strong> ' . esc_html( $label );
	if ( $budget > 0 ) {
		$pct_true  = (int) round( ( $spent / $budget ) * 100 );
		$pct_width = max( 0, min( 100, $pct_true ) );
		echo '<br>' . sprintf(
			/* translators: 1: spend this month, 2: budget, 3: percent used */
			esc_html__( '$%1$s of $%2$s budget this month (%3$s%%)', 'signal-and-noise-tools' ),
			esc_html( number_format_i18n( $spent, 2 ) ),
			esc_html( number_format_i18n( $budget, 2 ) ),
			esc_html( number_format_i18n( $pct_true ) )
		);
		echo '<span class="sn-an-mirror-meter"><span style="width:' . esc_attr( (string) $pct_width ) . '%"></span></span>';
	} else {
		echo ' · ' . esc_html__( 'no monthly budget cap', 'signal-and-noise-tools' );
	}
	echo '<br><a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=content&sub=front-end' ) ) . '">' . esc_html__( 'Content → Front-End →', 'signal-and-noise-tools' ) . '</a></div>';

	// Weekly digest cron (the AI-insights sibling leaf).
	$cron_on = function_exists( 'snt_insights_weekly_cron_enabled' ) && snt_insights_weekly_cron_enabled();
	echo '<div class="sn-an-mirror-row"><strong>' . esc_html__( 'Weekly insights cron', 'signal-and-noise-tools' ) . ':</strong> '
		. esc_html( $cron_on ? __( 'On', 'signal-and-noise-tools' ) : __( 'Off', 'signal-and-noise-tools' ) )
		. '<br><a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=insights' ) ) . '">' . esc_html__( 'Monitoring → Insights →', 'signal-and-noise-tools' ) . '</a></div>';

	// Collector URL (also this screen's worker-version probe base).
	$rss       = function_exists( 'sn_rss_tracker_settings' ) ? (array) sn_rss_tracker_settings() : array();
	$collector = (string) ( $rss['collector_url'] ?? '' );
	echo '<div class="sn-an-mirror-row"><strong>' . esc_html__( 'Collector URL', 'signal-and-noise-tools' ) . ':</strong> '
		. ( '' !== $collector ? '<code>' . esc_html( $collector ) . '</code>' : esc_html__( '(default)', 'signal-and-noise-tools' ) )
		. '<br><span class="sn-an-settings-help">' . esc_html__( 'Also the base the worker-version card above probes.', 'signal-and-noise-tools' ) . '</span>'
		. '<br><a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=content&sub=rss' ) ) . '">' . esc_html__( 'Content → RSS →', 'signal-and-noise-tools' ) . '</a></div>';

	echo '</div>';
}

/**
 * Settings-hub developer filter reference (v9.36.0): the filter-tier seams from
 * the knob-exposure policy (design spec §7), one line each, collapsed by
 * default. Static i18n-wrapped content — the <details> idiom mirrors the
 * worker-setup box.
 */
function snt_analytics_render_filter_reference() {
	$filters = array(
		'sn_analytics_signal_config'   => __( 'Predictive engine opts: baseline_days, z (post-filter clamped).', 'signal-and-noise-tools' ),
		'sn_analytics_session_config'  => __( 'Session engine: idle gap, engaged thresholds, row cap.', 'signal-and-noise-tools' ),
		'sn_analytics_session_funnels' => __( 'Named conversion funnels for the Visits view.', 'signal-and-noise-tools' ),
		'sn_analytics_narrator'        => __( 'Override the compact AI narrative.', 'signal-and-noise-tools' ),
		'sn_analytics_digest'          => __( 'Override the weekly executive digest.', 'signal-and-noise-tools' ),
		'sn_analytics_recommender'     => __( 'Override the recommendations payload.', 'signal-and-noise-tools' ),
		'sn_analytics_refresh_secret'  => __( 'Override the cron-refresh auth secret (default SN_SRV_TOKEN).', 'signal-and-noise-tools' ),
		'sn_beacon_token'              => __( 'Override the beacon/collector token (default SN_BEACON_TOKEN).', 'signal-and-noise-tools' ),
		'sn_analytics_self_hosts'      => __( 'Hosts folded as self-referrals in Sources.', 'signal-and-noise-tools' ),
		'snt_ai_model_preference'      => __( 'Route AI features to a specific model.', 'signal-and-noise-tools' ),
		'snt_ai_economy_features'      => __( 'Which AI features ride the economy tier.', 'signal-and-noise-tools' ),
		'snt_ai_economy_model'         => __( 'Which model the economy tier uses.', 'signal-and-noise-tools' ),
	);
	echo '<details class="sn-an-worker sn-an-filters"><summary>' . esc_html__( 'Advanced: filter reference (developers)', 'signal-and-noise-tools' ) . '</summary>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Code-level seams for everything the two knobs above don’t cover. Constants beyond these stay internal by policy.', 'signal-and-noise-tools' ) . '</p><ul class="sn-an-steps">';
	foreach ( $filters as $tag => $desc ) {
		echo '<li><code>' . esc_html( $tag ) . '</code> — ' . esc_html( $desc ) . '</li>';
	}
	echo '</ul></details>';
}

// v9.0.0 (D1): the one-time Plausible-CSV history importer (snt_analytics_render_import
// + inc/analytics-import.php) was retired here. Plausible itself was removed at v6.0.0;
// the CSV back-fill it shipped alongside has had three major versions to run and is
// no longer carried. See CHANGELOG "action required".
