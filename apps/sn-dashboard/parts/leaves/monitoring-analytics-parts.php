<?php
/**
 * S&N Dashboard — Monitoring → Analytics, helper painters.
 *
 * Split out of monitoring-analytics.php to keep that file under the leaf size
 * guideline: this leaf is the composite settings hub (inc/analytics-admin.php
 * `snt_analytics_render_settings_section()` + inc/analytics-render-settings.php),
 * six forms and four read-only cards. One function per classic card/form;
 * every reader is the SAME function the classic renderer calls
 * (`snt_an_*_snapshot()`, `snt_analytics_pipeline_pills()`,
 * `sn_analytics_pipeline_complete()` are pure and reused verbatim). Where the
 * classic function mixes reading and echoing (the credentials/collector/
 * exclusion/tuning/funnels forms), the reading is mirrored line for line here
 * — the classic file itself is never edited.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The five-pill pipeline strip: reuses the classic's own pure pill list.
 *
 * @return string
 */
function analytics_pipeline_html() {
	if ( ! function_exists( '\snt_analytics_pipeline_pills' ) ) {
		return '';
	}
	$badges = '';
	$warns  = '';
	foreach ( \snt_analytics_pipeline_pills() as $p ) {
		$kind  = (string) ( $p[0] ?? '' );
		$label = (string) ( $p[1] ?? '' );
		$note  = (string) ( $p[2] ?? '' );
		$badges .= \snt_kit_badge( $kind, $label );
		if ( 'warn' === $kind && '' !== $note ) {
			$warns .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( $label ) . '</b> ' . \snt_kit_esc( $note ) );
		}
	}
	return \snt_kit_section(
		__( 'Pipeline status', 'signal-and-noise-tools' ),
		'<os-cluster gap="8">' . $badges . '</os-cluster>' . $warns,
		__( 'Beacon → worker → Analytics Engine → cron → edge. Presence checks only: secret values are never shown.', 'signal-and-noise-tools' )
	);
}

/**
 * The Credentials fold: Account ID + read token, wp-config-constant locking,
 * Save + (a separate) Test-connection action.
 *
 * CHANGED SHAPE: the classic form carries two submit buttons
 * (`sn_action=analytics_save` / `analytics_test`) sharing one set of fields —
 * an `<os-form>` fires exactly one action, so Test-connection is painted as a
 * standalone one-click action beside the form rather than a second submit
 * inside it. Its own values aren't re-submitted (the classic test button
 * re-posts whatever is currently in the two fields; here it fires with no
 * field payload), which is a real behavior change, not just a markup one.
 *
 * @return string
 */
function analytics_credentials_html() {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) constant( 'SN_CF_ANALYTICS_TOKEN' );
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) constant( 'SN_CF_ACCOUNT_ID' );
	$acct_opt     = (string) \get_option( defined( 'SN_CF_ACCOUNT_ID_OPT' ) ? constant( 'SN_CF_ACCOUNT_ID_OPT' ) : 'sn_cf_account_id', '' );
	$token_opt    = (string) \get_option( defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ? constant( 'SN_CF_ANALYTICS_TOKEN_OPT' ) : 'sn_cf_analytics_token', '' );
	$configured   = function_exists( '\sn_analytics_config' ) && \sn_analytics_config();

	$fields = $acct_locked
		? \snt_kit_tag( 'os-field-row', array( 'label' => __( 'Account ID', 'signal-and-noise-tools' ), 'hint' => sprintf( __( 'Locked by the %s constant.', 'signal-and-noise-tools' ), 'SN_CF_ACCOUNT_ID' ) ), \snt_kit_tag( 'os-text-field', array( 'value' => __( '(set in wp-config)', 'signal-and-noise-tools' ), 'disabled' => true ) ) )
		: \snt_kit_field( 'text', 'sn_cf_account_id', __( 'Account ID', 'signal-and-noise-tools' ), $acct_opt, array( 'placeholder' => __( '32-char Cloudflare account ID', 'signal-and-noise-tools' ) ) );
	$fields .= $token_locked
		? \snt_kit_tag( 'os-field-row', array( 'label' => __( 'Account Analytics Read token', 'signal-and-noise-tools' ), 'hint' => sprintf( __( 'Locked by the %s constant.', 'signal-and-noise-tools' ), 'SN_CF_ANALYTICS_TOKEN' ) ), \snt_kit_tag( 'os-text-field', array( 'value' => '••••', 'disabled' => true ) ) )
		: \snt_kit_field( 'text', 'sn_cf_analytics_token', __( 'Account Analytics Read token', 'signal-and-noise-tools' ), function_exists( '\sn_mask_secret' ) ? \sn_mask_secret( $token_opt ) : $token_opt, array( 'placeholder' => __( 'Paste a fresh token; type ‘clear’ to remove', 'signal-and-noise-tools' ) ) );

	/* translators: 1: read-token constant name; 2: account-ID constant name. */
	$body = '<p class="snt-prose">' . \snt_kit_esc( sprintf( __( 'Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (%1$s / %2$s) overrides these and locks the field.', 'signal-and-noise-tools' ), 'SN_CF_ANALYTICS_TOKEN', 'SN_CF_ACCOUNT_ID' ) ) . '</p>';
	if ( $token_locked && $acct_locked ) {
		$body .= $fields;
	} else {
		$body .= \snt_kit_form( 'analytics_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
		$body .= \snt_kit_action_button( __( 'Test connection', 'signal-and-noise-tools' ), 'analytics_test', array( 'disabled' => ! $configured ) );
	}

	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => __( 'Credentials', 'signal-and-noise-tools' ),
			'hint'    => \snt_an_credentials_snapshot(),
			'open'    => \snt_an_credentials_fold_open(),
		),
		$body
	);
}

/**
 * The Collector endpoint fold (v10.46.0 on the classic leaf).
 *
 * @return string
 */
function analytics_collector_html() {
	$rss       = function_exists( '\sn_rss_tracker_settings' ) ? (array) \sn_rss_tracker_settings() : array();
	$collector = (string) ( $rss['collector_url'] ?? '' );
	$default   = function_exists( '\home_url' ) ? \home_url( '/_sn/px' ) : '';

	$fields = \snt_kit_field(
		'url',
		'sn_an_collector_url',
		__( 'Endpoint URL', 'signal-and-noise-tools' ),
		$collector,
		array(
			'required' => true,
			'hint'     => __( 'Defaults to this site’s own endpoint. If WordPress cannot reach the site domain through the edge, point this at the Worker’s *.workers.dev URL instead.', 'signal-and-noise-tools' ),
		)
	);
	if ( '' !== $default && $collector !== $default ) {
		/* translators: %s: the default collector URL for this site. */
		$fields .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Site default: %s', 'signal-and-noise-tools' ) ), '<os-code>' . \snt_kit_esc( $default ) . '</os-code>' ) . '</p>';
	}

	/* translators: 1: the worker route; 2: the shared-token constant name. */
	$body = '<p class="snt-prose">' . \snt_kit_esc( sprintf( __( 'Where every first-party beacon on this site posts: the Cloudflare Worker’s %1$s route. Authenticated with the shared %2$s constant from wp-config.php.', 'signal-and-noise-tools' ), '/_sn/px', 'SN_BEACON_TOKEN' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_collector_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );

	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => __( 'Collector endpoint', 'signal-and-noise-tools' ),
			'hint'    => \snt_an_collector_snapshot(),
			'open'    => '' === $collector,
		),
		$body
	);
}

/**
 * The "Exclude my own visits" fold: a role allow-list.
 *
 * @return string
 */
function analytics_exclusion_html() {
	$roles    = function_exists( '\sn_beacon_excludable_roles' ) ? \sn_beacon_excludable_roles() : array();
	$excluded = (array) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.exclude_roles', array() ) : array() );

	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'Stop counting logged-in users in the selected roles. The front-end beacon is never printed for them, so nothing reaches the collector. Cookieless and forward-only: visits already recorded are unaffected.', 'signal-and-noise-tools' ) ) . '</p>';

	if ( empty( $roles ) ) {
		$body = $intro . '<p class="snt-hint">' . \snt_kit_esc( __( 'No roles available on this site.', 'signal-and-noise-tools' ) ) . '</p>';
		return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Exclude my own visits', 'signal-and-noise-tools' ), 'hint' => \snt_an_exclusion_snapshot() ), $body );
	}

	// A shared `sn_exclude_roles[]` name per checkbox (the classic markup) is
	// silent-by-construction in the runtime: OsForm.getValues() keys by
	// getAttribute('name'), so N controls sharing one name collapse to ONE
	// key, and os-checkbox-label has no `value` prop (only 'label','checked',
	// 'disabled'), so _readField() returns a bare boolean and the slug is
	// dropped entirely — a save would always CLEAR every excluded role. Each
	// role gets its OWN indexed name instead, carrying the slug as an
	// <os-select> VALUE (which the runtime does read), so expand() yields
	// `sn_exclude_roles => array('subscriber', …)` — exactly what
	// sn_handle_analytics_exclude_save() expects.
	$fields = '';
	$i      = 0;
	foreach ( $roles as $slug => $name ) {
		$fields .= \snt_kit_field(
			'select',
			'sn_exclude_roles[' . $i++ . ']',
			$name . ' (' . $slug . ')',
			in_array( $slug, $excluded, true ) ? $slug : '',
			array(
				'options' => array(
					''    => __( 'Counted', 'signal-and-noise-tools' ),
					$slug => __( 'Excluded', 'signal-and-noise-tools' ),
				),
			)
		);
	}
	$status = ( function_exists( '\sn_beacon_owner_current_user_excluded' ) && \sn_beacon_owner_current_user_excluded() )
		? '<p class="snt-hint"><b>' . \snt_kit_esc( __( 'You are currently excluded from analytics.', 'signal-and-noise-tools' ) ) . '</b></p>'
		: '<p class="snt-hint">' . \snt_kit_esc( __( 'You are currently counted in analytics.', 'signal-and-noise-tools' ) ) . '</p>';
	$footnote = '<p class="snt-hint">' . \snt_kit_esc( __( 'Your own visits are also dropped at the edge: the collector ignores any beacon that carries a logged-in WordPress cookie, so you are excluded even when the page was served from cache. The role list above adds per-role control on uncached requests: to extend that role filter to cached pages too, add a Cloudflare rule that bypasses cache when the request carries a wordpress_logged_in_ cookie.', 'signal-and-noise-tools' ) ) . '</p>';

	$body = $intro . \snt_kit_form( 'analytics_exclude_save', $fields . $status . $footnote, array( 'submit' => __( 'Save exclusion', 'signal-and-noise-tools' ) ) );

	return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Exclude my own visits', 'signal-and-noise-tools' ), 'hint' => \snt_an_exclusion_snapshot() ), $body );
}

/**
 * The Engine tuning fold: baseline window (number) + anomaly sensitivity.
 *
 * CHANGED SHAPE: the classic radios (a real `<fieldset><legend>` of three
 * mutually-exclusive presets) become a kit `select` — the kit has no
 * radio-group primitive in kit-help.md. Same field name, same three values.
 *
 * @return string
 */
function analytics_tuning_html() {
	$baseline = (int) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.signal_baseline_days', 30 ) : 30 );
	$preset   = (string) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.anomaly_sensitivity', 'standard' ) : 'standard' );
	$presets  = \snt_an_tuning_presets();
	$preset   = isset( $presets[ $preset ] ) ? $preset : 'standard';

	$fields = \snt_kit_field( 'number', 'sn_signal_baseline_days', __( 'Baseline window', 'signal-and-noise-tools' ), $baseline, array( 'min' => 14, 'max' => 90, 'step' => 1, 'hint' => __( 'Days of history behind the anomaly baseline. 14–90; shorter reacts faster, longer is steadier.', 'signal-and-noise-tools' ) ) );
	$fields .= \snt_kit_field( 'select', 'sn_anomaly_sensitivity', __( 'Anomaly sensitivity', 'signal-and-noise-tools' ), $preset, array( 'options' => $presets, 'hint' => __( 'Governs both anomaly families: the predictive signals engine and the per-page skim/dwell detector.', 'signal-and-noise-tools' ) ) );
	$fields .= '<p class="snt-hint">' . \snt_kit_esc( __( 'How unusual a day must be before it’s flagged as an anomaly.', 'signal-and-noise-tools' ) ) . '</p>';

	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'How the anomaly detectors read your history. Trend and forecast signals aren’t tunable here. Developers can override more via the sn_analytics_signal_config filter: see the reference in the right column.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_tuning_save', $fields, array( 'submit' => __( 'Save tuning', 'signal-and-noise-tools' ) ) );

	return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Engine tuning', 'signal-and-noise-tools' ), 'hint' => \snt_an_tuning_snapshot() ), $body );
}

/**
 * The Session funnels fold: one named funnel per line.
 *
 * @return string
 */
function analytics_funnels_html() {
	$funnels = (array) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.funnels', array() ) : array() );
	$text    = function_exists( '\sn_analytics_funnels_to_text' ) ? \sn_analytics_funnels_to_text( $funnels ) : '';
	$max_steps = defined( 'SN_ANALYTICS_FUNNELS_MAX_STEPS' ) ? constant( 'SN_ANALYTICS_FUNNELS_MAX_STEPS' ) : 8;
	$max_count = defined( 'SN_ANALYTICS_FUNNELS_MAX' ) ? constant( 'SN_ANALYTICS_FUNNELS_MAX' ) : 10;

	$fields = \snt_kit_field(
		'textarea',
		'sn_funnels',
		__( 'Session funnels, one per line', 'signal-and-noise-tools' ),
		$text,
		array(
			'rows'        => 6,
			'placeholder' => __( 'Home flow: /entry > /step > /goal', 'signal-and-noise-tools' ),
			/* translators: 1: max steps per funnel; 2: max funnel count. */
			'hint'        => sprintf( __( 'Named conversion paths for the Sessions view: one per line: "Name: /entry > /step > /goal" (2–%1$d steps, up to %2$d funnels). A bare path gets a leading slash added automatically.', 'signal-and-noise-tools' ), $max_steps, $max_count ),
		)
	);

	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'Saving any funnel here replaces the built-in defaults for the Sessions view: including their custom-event goals. Those defaults remain available via the sn_analytics_session_funnels filter, which always runs last and wins over whatever is saved here.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Only exact-match path steps can be expressed here. Funnels this box can’t express (prefix matching, custom-event goals) are not shown above and are managed in code via the filter, which always wins last.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_funnels_save', $fields, array( 'submit' => __( 'Save funnels', 'signal-and-noise-tools' ) ) );

	return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Session funnels', 'signal-and-noise-tools' ), 'hint' => \snt_an_funnels_snapshot() ), $body );
}

/**
 * The edge-Worker version card: live probe, last-good fallback, or unreachable.
 *
 * @return string
 */
function analytics_worker_html() {
	if ( ! function_exists( '\sn_worker_version_get' ) ) {
		return \snt_kit_empty( __( 'Edge worker status is unavailable.', 'signal-and-noise-tools' ) );
	}
	$recheck   = function_exists( '\sn_worker_version_recheck_requested' ) && \sn_worker_version_recheck_requested();
	$result    = \sn_worker_version_get( $recheck );
	$lastgood_key = defined( 'SN_WORKER_VERSION_LASTGOOD' ) ? constant( 'SN_WORKER_VERSION_LASTGOOD' ) : 'sn_worker_version_last_good';
	$last_good = \get_option( $lastgood_key, array() );

	if ( ! empty( $result['ok'] ) ) {
		$body = analytics_worker_data_html( $result, false );
	} elseif ( is_array( $last_good ) && ! empty( $last_good['ok'] ) ) {
		$body = analytics_worker_data_html( $last_good, true );
	} else {
		$where = ! empty( $result['url'] ) ? ' ' . sprintf( \snt_kit_esc( __( 'at %s', 'signal-and-noise-tools' ) ), '<os-code>' . \snt_kit_esc( (string) $result['url'] ) . '</os-code>' ) : '';
		$body  = \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'Worker version unknown.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( __( 'Couldn’t reach the /_sn/version endpoint', 'signal-and-noise-tools' ) ) . $where . '. ' . \snt_kit_esc( __( 'The Worker may not be deployed yet (it needs worker v1.4.0+), or this host can’t reach it — point the Collector endpoint (Content → RSS) at the Worker’s *.workers.dev URL if the origin doesn’t hairpin to the edge.', 'signal-and-noise-tools' ) ) );
	}
	if ( function_exists( '\sn_worker_version_recheck_url' ) ) {
		$body .= \snt_kit_door( __( 'Re-check now', 'signal-and-noise-tools' ), \sn_worker_version_recheck_url(), array( 'variant' => 'secondary' ) );
	}
	return \snt_kit_section( __( 'Edge worker', 'signal-and-noise-tools' ), $body, __( 'The deployed version of the analytics collector Worker, read live from its /_sn/version endpoint (derived from the configured collector base, so it follows a *.workers.dev override automatically).', 'signal-and-noise-tools' ) );
}

/**
 * @param array<string,mixed> $result From sn_worker_version_get().
 * @param bool                $stale  Shown as a fallback after a live probe failed.
 * @return string
 */
function analytics_worker_data_html( array $result, $stale ) {
	$data     = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	$worker   = (string) ( $data['worker'] ?? 'sn-analytics' );
	$version  = (string) ( $data['version'] ?? '' );
	$cf_id    = (string) ( $data['cf_version_id'] ?? '' );
	$cf_tag   = (string) ( $data['cf_version_tag'] ?? '' );
	$deployed = function_exists( '\sn_worker_version_format_deployed' ) ? \sn_worker_version_format_deployed( $data['deployed_at'] ?? '' ) : '';

	$rows   = array();
	$rows[] = array( 'label' => __( 'Worker', 'signal-and-noise-tools' ), 'value' => $worker . ( '' !== $version ? ' v' . $version : ' (' . __( 'semver unreported: deploy with npm run deploy', 'signal-and-noise-tools' ) . ')' ) );
	if ( '' !== $cf_id ) {
		$rows[] = array( 'label' => __( 'Cloudflare version', 'signal-and-noise-tools' ), 'value' => $cf_id . ( '' !== $cf_tag ? ' · ' . $cf_tag : '' ) );
	}
	if ( '' !== $deployed ) {
		$rows[] = array( 'label' => __( 'Deployed', 'signal-and-noise-tools' ), 'value' => $deployed );
	}
	$fetched_at = isset( $result['fetched_at'] ) ? (int) $result['fetched_at'] : 0;

	$out = \snt_kit_notice( $stale ? 'warn' : 'info', \snt_kit_kv( $rows ) );
	if ( $stale ) {
		$out .= $fetched_at > 0
			/* translators: %s: human-readable time interval. */
			? '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Live check failed just now: showing the last value reached %s ago.', 'signal-and-noise-tools' ) ), \human_time_diff( $fetched_at, time() ) ) . '</p>'
			: '<p class="snt-hint">' . \snt_kit_esc( __( 'Live check failed just now: showing the last value reached.', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( $fetched_at > 0 ) {
		/* translators: %s: human-readable time interval. */
		$out .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Checked %s ago.', 'signal-and-noise-tools' ) ), \human_time_diff( $fetched_at, time() ) ) . '</p>';
	}
	if ( ! empty( $result['url'] ) ) {
		/* translators: %s: the /_sn/version URL the card just probed. */
		$out .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Source: %s', 'signal-and-noise-tools' ) ), '<os-code>' . \snt_kit_esc( (string) $result['url'] ) . '</os-code>' ) . '</p>';
	}
	return $out;
}

/**
 * The identity-salt window card: same worker probe, a distinct top-level field.
 *
 * @return string
 */
function analytics_salt_html() {
	if ( ! function_exists( '\sn_salt_window_get' ) ) {
		return '';
	}
	$recheck = function_exists( '\sn_worker_version_recheck_requested' ) && \sn_worker_version_recheck_requested();
	$result  = \sn_salt_window_get( $recheck );
	$state   = is_array( $result ) && array_key_exists( 'state', $result ) ? (string) $result['state'] : 'unreachable';

	if ( 'old-worker' === $state ) {
		$body = '<p class="snt-hint">' . \snt_kit_esc( __( 'Worker predates the salt window readout (needs v1.14.0+).', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( 'kv-failed' === $state ) {
		$body = '<p class="snt-hint">' . \snt_kit_esc( __( 'worker reachable, but it could not list its salt keys (KV read failed at the edge).', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( 'ok' !== $state || ! is_array( $result['window'] ?? null ) ) {
		$body = '<p class="snt-hint">' . \snt_kit_esc( __( 'could not read the worker.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$body = analytics_salt_window_html( $result );
	}
	return \snt_kit_section( __( 'Identity salt window', 'signal-and-noise-tools' ), $body, __( 'The visitor-identity salt rotates daily at the edge and yesterday’s is deleted: forward secrecy by construction. Key names are dates and expiry times only; salt values never leave the Worker.', 'signal-and-noise-tools' ) );
}

/**
 * @param array<string,mixed> $result A state=ok result from sn_salt_window_get().
 * @return string
 */
function analytics_salt_window_html( array $result ) {
	$w  = (array) $result['window'];
	$tz = '' !== (string) ( $w['rotate_tz'] ?? '' ) ? (string) $w['rotate_tz'] : 'UTC';
	$today_day = '' !== (string) ( $w['today_day'] ?? '' ) ? (string) $w['today_day'] : '—';

	$lines   = array();
	$lines[] = false === ( $w['today_present'] ?? null )
		/* translators: 1: today's salt day; 2: rotation timezone. */
		? sprintf( __( 'Today’s salt: %1$s (not minted yet (it appears with the first visit of the day)) rotates at midnight (%2$s).', 'signal-and-noise-tools' ), $today_day, $tz )
		/* translators: 1: today's salt day; 2: rotation timezone. */
		: sprintf( __( 'Today’s salt: %1$s: rotates at midnight (%2$s).', 'signal-and-noise-tools' ), $today_day, $tz );

	if ( false === ( $w['prev_present'] ?? null ) ) {
		/* translators: %s: yesterday's salt day. */
		$lines[] = sprintf( __( 'Yesterday’s salt (%s) has already expired: forward secrecy holding.', 'signal-and-noise-tools' ), '' !== (string) ( $w['prev_day'] ?? '' ) ? $w['prev_day'] : '—' );
	} elseif ( true === ( $w['prev_present'] ?? null ) ) {
		if ( null !== ( $w['prev_expires_at'] ?? null ) ) {
			$expiry  = function_exists( '\sn_salt_window_format_expiry' ) ? \sn_salt_window_format_expiry( $w['prev_expires_at'] ) : (string) $w['prev_expires_at'];
			/* translators: 1: yesterday's salt day; 2: expiry readout. */
			$lines[] = sprintf( __( 'Yesterday’s salt (%1$s) expires %2$s.', 'signal-and-noise-tools' ), $w['prev_day'], $expiry );
		} else {
			/* translators: %s: yesterday's salt day. */
			$lines[] = sprintf( __( 'Yesterday’s salt (%s) has no expiry recorded.', 'signal-and-noise-tools' ), $w['prev_day'] );
		}
	}
	if ( null !== ( $w['key_count'] ?? null ) ) {
		/* translators: %s: number of salt keys currently at the edge. */
		$lines[] = sprintf( _n( '%s salt key at the edge.', '%s salt keys at the edge.', (int) $w['key_count'], 'signal-and-noise-tools' ), \number_format_i18n( $w['key_count'] ) );
	}

	$inner = '';
	foreach ( $lines as $line ) {
		$inner .= '<p>' . \snt_kit_esc( $line ) . '</p>';
	}
	$out = \snt_kit_notice( 'info', $inner );
	$fetched_at = isset( $result['fetched_at'] ) ? (int) $result['fetched_at'] : 0;
	if ( $fetched_at > 0 ) {
		/* translators: %s: human-readable time interval. */
		$out .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Checked %s ago.', 'signal-and-noise-tools' ) ), \human_time_diff( $fetched_at, time() ) ) . '</p>';
	}
	return $out;
}

/**
 * The "Configured elsewhere" read-only mirrors: AI model + budget, weekly
 * digest cron, Zone ID — each with a deep link to its real home.
 *
 * @param string $tab The painting tab (for same-tab go targets).
 * @return string
 */
function analytics_mirrors_html( $tab ) {
	$model  = (string) ( function_exists( '\sn_setting' ) ? \sn_setting( 'theme.ai_model', 'claude-sonnet-5' ) : 'claude-sonnet-5' );
	$models = function_exists( '\sn_theme_ai_models' ) ? \sn_theme_ai_models() : array();
	$label  = isset( $models[ $model ] ) ? (string) $models[ $model ] : $model;
	$budget = (float) ( function_exists( '\sn_setting' ) ? \sn_setting( 'theme.ai_monthly_budget', 0 ) : 0 );
	$spent  = function_exists( '\snt_ai_spend_this_month' ) ? (float) \snt_ai_spend_this_month() : 0.0;
	$ai_value = $label;
	$ai_meter = '';
	if ( $budget > 0 ) {
		$pct = (int) round( ( $spent / $budget ) * 100 );
		/* translators: 1: spend this month; 2: budget; 3: percent used. */
		$ai_value .= ' · ' . sprintf( __( '$%1$s of $%2$s budget this month (%3$s%%)', 'signal-and-noise-tools' ), \number_format_i18n( $spent, 2 ), \number_format_i18n( $budget, 2 ), \number_format_i18n( $pct ) );
		$ai_meter  = \snt_kit_tag(
			'os-progress-bar',
			array(
				'value'        => (string) max( 0, min( 100, $pct ) ),
				'max'          => '100',
				'label'        => __( 'Budget used this month', 'signal-and-noise-tools' ),
				'show-percent' => true,
			)
		);
	} else {
		$ai_value .= ' · ' . __( 'no monthly budget cap', 'signal-and-noise-tools' );
	}

	$cron_on = function_exists( '\snt_insights_weekly_cron_enabled' ) && \snt_insights_weekly_cron_enabled();
	$zone    = function_exists( '\sn_cf_get_zone' ) ? (string) \sn_cf_get_zone() : '';
	$zone_locked = defined( 'SN_CLOUDFLARE_ZONE_ID' ) && '' !== (string) constant( 'SN_CLOUDFLARE_ZONE_ID' );

	$rows = array(
		array( 'label' => __( 'AI model', 'signal-and-noise-tools' ), 'value' => $ai_value ),
		array( 'label' => __( 'Weekly insights cron', 'signal-and-noise-tools' ), 'value' => $cron_on ? __( 'On', 'signal-and-noise-tools' ) : __( 'Off', 'signal-and-noise-tools' ) ),
		array( 'label' => __( 'Zone ID', 'signal-and-noise-tools' ), 'value' => '' !== $zone ? $zone : __( 'Not set', 'signal-and-noise-tools' ), 'tone' => $zone_locked ? 'warn' : '' ),
	);

	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Settings analytics depends on that live on other tabs: shown read-only; follow a link to change one.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= \snt_kit_kv( $rows );
	$out .= $ai_meter;
	if ( $zone_locked ) {
		/* translators: %s: the wp-config constant name. */
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Locked by the %s constant.', 'signal-and-noise-tools' ), 'SN_CLOUDFLARE_ZONE_ID' ) ) . '</p>';
	}
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Also gates cache purge and the Edge view.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= '<os-cluster gap="8">'
		. \snt_kit_go( __( 'AI → Models & Budget →', 'signal-and-noise-tools' ), array( 'tab' => 'ai', 'sub' => 'models-budget', 'current' => $tab ) )
		. \snt_kit_go( __( 'Measurement → Insights →', 'signal-and-noise-tools' ), array( 'tab' => 'monitoring', 'sub' => 'insights', 'current' => $tab ) )
		. \snt_kit_go( __( 'Connections → Cloudflare →', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'cloudflare', 'current' => $tab ) )
		. '</os-cluster>';

	return \snt_kit_section( __( 'Configured elsewhere', 'signal-and-noise-tools' ), $out );
}

/**
 * The developer filter-reference deep link.
 *
 * @return string
 */
function analytics_filter_reference_html() {
	return '<p class="snt-prose">' . \snt_kit_link( __( 'Developer filter seams →', 'signal-and-noise-tools' ), 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/FILTERS.md' ) . '</p>';
}

/**
 * The read-only Cloudflare Worker setup reference — renders only while the
 * pipeline is incomplete, the same seam the credentials fold's open state uses.
 *
 * @return string
 */
function analytics_worker_setup_html() {
	if ( function_exists( '\sn_analytics_pipeline_complete' ) && \sn_analytics_pipeline_complete() ) {
		return '';
	}
	$body = '<ol class="snt-plain">'
		. '<li>' . sprintf( \snt_kit_esc( __( 'Read token (for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with %1$s. The Account ID is in the dashboard URL: %2$s.', 'signal-and-noise-tools' ) ), '<os-code>Account · Analytics · Read</os-code>', '<os-code>dash.cloudflare.com/&lt;account_id&gt;</os-code>' ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Deploy the edge Worker + its secrets (from the analytics-worker repo: this can’t be done from WordPress):', 'signal-and-noise-tools' ) ) . \snt_kit_code( "wrangler secret put SN_PX_TOKEN\nwrangler secret put SN_PX_SALT_SEED\nwrangler deploy" ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Theme beacon: set SN_BEACON_TOKEN in wp-config.php to the SAME value as the Worker’s SN_PX_TOKEN so the front-end beacon is accepted.', 'signal-and-noise-tools' ) ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Hit Test connection above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.', 'signal-and-noise-tools' ) ) . '</li>'
		. '</ol>';
	return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Cloudflare Worker setup (manual, one-time)', 'signal-and-noise-tools' ) ), $body );
}
