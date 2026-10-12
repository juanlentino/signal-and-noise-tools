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

require_once dirname( __DIR__, 4 ) . '/inc/ai-model-catalog.php'; // the model defaults and lists (21.0.0).

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
			? '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Live check failed just now: showing the last value reached %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( $fetched_at ) ) . '</p>'
			: '<p class="snt-hint">' . \snt_kit_esc( __( 'Live check failed just now: showing the last value reached.', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( $fetched_at > 0 ) {
		/* translators: %s: human-readable time interval. */
		$out .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Checked %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( $fetched_at ) ) . '</p>';
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
	$today_day = '' !== (string) ( $w['today_day'] ?? '' ) ? (string) $w['today_day'] : \snt_kit_unread();

	$lines   = array();
	$lines[] = false === ( $w['today_present'] ?? null )
		/* translators: 1: today's salt day; 2: rotation timezone. */
		? sprintf( __( 'Today’s salt: %1$s (not minted yet (it appears with the first visit of the day)) rotates at midnight (%2$s).', 'signal-and-noise-tools' ), $today_day, $tz )
		/* translators: 1: today's salt day; 2: rotation timezone. */
		: sprintf( __( 'Today’s salt: %1$s: rotates at midnight (%2$s).', 'signal-and-noise-tools' ), $today_day, $tz );

	if ( false === ( $w['prev_present'] ?? null ) ) {
		/* translators: %s: yesterday's salt day. */
		$lines[] = sprintf( __( 'Yesterday’s salt (%s) has already expired: forward secrecy holding.', 'signal-and-noise-tools' ), '' !== (string) ( $w['prev_day'] ?? '' ) ? $w['prev_day'] : \snt_kit_unread() );
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
		$out .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Checked %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( $fetched_at ) ) . '</p>';
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
	$model  = (string) ( function_exists( '\sn_setting' ) ? \sn_setting( 'theme.ai_model', \sn_ai_default_model() ) : \sn_ai_default_model() );
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
		. \snt_kit_go( __( 'Connections → Credentials →', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => $tab ) ) // 15.3.1: the zone id is a keyring row
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
