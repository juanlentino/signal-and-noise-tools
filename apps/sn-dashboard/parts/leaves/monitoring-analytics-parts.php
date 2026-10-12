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
 * Whether this paint asked for a live probe. Re-check now is a `go` back to
 * this leaf carrying `sn_worker_recheck=1` (2026-10-12 audit: it was a door
 * to the classic page, which opened wp-admin in another window and never
 * re-read this one). Capability is checked by the `go` action itself.
 *
 * The flag is taken on the paint that reads it (PR #2025 review): left in the
 * state, every later Refresh of this leaf probed the Worker live.
 *
 * @param array<string,mixed> $ctx Leaf context.
 * @return bool
 */
function analytics_recheck_requested( array $ctx ) {
	$state  = $ctx['state'] ?? null;
	$params = ( is_object( $state ) && method_exists( $state, 'get' ) ) ? (array) $state->get( 'params' ) : array();
	if ( empty( $params['sn_worker_recheck'] ) ) {
		return false;
	}
	if ( method_exists( $state, 'set' ) ) {
		unset( $params['sn_worker_recheck'] );
		$state->set( 'params', $params );
	}
	return true;
}

/**
 * The Pipeline box (2026-10-12 audit: four boxes, Pipeline status, Edge
 * worker, Identity salt window and Credentials, answered one question, "is
 * the pipeline wired?", and two of them read the same probe). The five pills
 * and their notes, then one facts list: the Worker, the salt window, what
 * the reads use; then Re-check now and Test connection.
 *
 * @param array<string,mixed> $ctx Leaf context.
 * @return string
 */
function analytics_pipeline_html( array $ctx ) {
	$recheck = analytics_recheck_requested( $ctx );
	$out     = '';
	if ( function_exists( '\snt_analytics_pipeline_pills' ) ) {
		$badges = '';
		$warns  = '';
		foreach ( \snt_analytics_pipeline_pills() as $p ) {
			$kind    = (string) ( $p[0] ?? '' );
			$badges .= \snt_kit_badge( $kind, (string) ( $p[1] ?? '' ) );
			if ( 'warn' === $kind && '' !== (string) ( $p[2] ?? '' ) ) {
				$warns .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( (string) $p[1] ) . '</b> ' . \snt_kit_esc( (string) $p[2] ) );
			}
		}
		$out .= '<os-cluster gap="8">' . $badges . '</os-cluster>' . $warns;
	}
	list( $worker_rows, $worker_note ) = analytics_worker_rows( $recheck );
	$out .= $worker_note . analytics_reads_notice();
	$out .= \snt_kit_kv( array_merge( $worker_rows, analytics_salt_rows( $recheck ), analytics_reads_rows() ) );
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The API token and the Account ID are set with every other key under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => (string) ( $ctx['tab'] ?? 'monitoring' ) ) ) . '. ' . \snt_kit_esc( __( 'Analytics Engine reads use the Cloudflare API token (Account › Account Analytics › Read on it) unless an analytics token override is set there.', 'signal-and-noise-tools' ) ) . '</p>';
	$configured = function_exists( '\sn_analytics_config' ) && \sn_analytics_config();
	$out       .= '<footer><os-cluster gap="8">'
		. \snt_kit_button( __( 'Re-check now', 'signal-and-noise-tools' ), 'go', array( 'args' => array( 'sub' => (string) ( $ctx['sub'] ?? 'analytics' ), 'sn_worker_recheck' => '1' ) ) )
		. \snt_kit_action_button( __( 'Test connection', 'signal-and-noise-tools' ), 'analytics_test', array( 'disabled' => ! $configured ) )
		. '</os-cluster></footer>';
	return \snt_kit_section(
		__( 'Pipeline', 'signal-and-noise-tools' ),
		$out,
		__( 'Beacon → worker → Analytics Engine → cron → edge. Presence checks only: secret values are never shown. Re-check now reads the Worker live; Test connection reads Analytics Engine.', 'signal-and-noise-tools' )
	);
}

/**
 * The Worker's facts rows, from its /_sn/version probe: live, the last value
 * reached (when the live probe just failed), or not read, with the note that
 * goes above the facts.
 *
 * @param bool $recheck Probe live, bypassing the cache.
 * @return array{0:array<int,array<string,mixed>>,1:string} Rows, note.
 */
function analytics_worker_rows( $recheck ) {
	if ( ! function_exists( '\sn_worker_version_get' ) ) {
		return array( array( array( 'label' => __( 'Worker', 'signal-and-noise-tools' ), 'value' => __( 'not read: the version probe is not loaded', 'signal-and-noise-tools' ) ) ), '' );
	}
	$result    = \sn_worker_version_get( $recheck );
	$last_good = \get_option( defined( 'SN_WORKER_VERSION_LASTGOOD' ) ? constant( 'SN_WORKER_VERSION_LASTGOOD' ) : 'sn_worker_version_last_good', array() );
	$stale     = empty( $result['ok'] ) && is_array( $last_good ) && ! empty( $last_good['ok'] );
	if ( empty( $result['ok'] ) && ! $stale ) {
		$note = \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'Worker version unknown.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( function_exists( '\sn_worker_version_unknown_text' ) ? \sn_worker_version_unknown_text( (string) ( $result['url'] ?? '' ) ) : '' ) );
		return array( array( array( 'label' => __( 'Worker', 'signal-and-noise-tools' ), 'value' => __( 'not read', 'signal-and-noise-tools' ), 'tone' => 'warn' ) ), $note );
	}
	$read     = $stale ? (array) $last_good : (array) $result;
	$data     = isset( $read['data'] ) && is_array( $read['data'] ) ? $read['data'] : array();
	$version  = (string) ( $data['version'] ?? '' );
	$cf_id    = (string) ( $data['cf_version_id'] ?? '' );
	$cf_tag   = (string) ( $data['cf_version_tag'] ?? '' );
	$deployed = function_exists( '\sn_worker_version_format_deployed' ) ? \sn_worker_version_format_deployed( $data['deployed_at'] ?? '' ) : '';
	$fetched  = (int) ( $read['fetched_at'] ?? 0 );
	$rows     = array( array( 'label' => __( 'Worker', 'signal-and-noise-tools' ), 'value' => (string) ( $data['worker'] ?? 'sn-analytics' ) . ( '' !== $version ? ' v' . $version : ' (' . ( function_exists( '\sn_worker_version_unreported_text' ) ? \sn_worker_version_unreported_text() : '' ) . ')' ), 'tone' => $stale ? 'warn' : null ) );
	if ( '' !== $cf_id ) {
		$rows[] = array( 'label' => __( 'Cloudflare version', 'signal-and-noise-tools' ), 'value' => $cf_id . ( '' !== $cf_tag ? ' · ' . $cf_tag : '' ) );
	}
	if ( '' !== $deployed ) {
		$rows[] = array( 'label' => __( 'Deployed', 'signal-and-noise-tools' ), 'value' => $deployed );
	}
	$checked = $fetched > 0 ? \snt_kit_relative_time( $fetched ) : \snt_kit_esc( __( 'time not recorded', 'signal-and-noise-tools' ) );
	$rows[]  = array(
		'label' => __( 'Checked', 'signal-and-noise-tools' ),
		'html'  => true,
		/* translators: %s: when the last value was reached. */
		'value' => $stale ? sprintf( \snt_kit_esc( __( 'Live check failed just now: showing the last value reached %s.', 'signal-and-noise-tools' ) ), $checked ) : $checked,
		'tone'  => $stale ? 'warn' : null,
	);
	if ( ! empty( $read['url'] ) ) {
		$rows[] = array( 'label' => __( 'Source', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_code( (string) $read['url'], false ) );
	}
	return array( $rows, '' );
}

/**
 * The identity salt window as one facts row, in the classic card's words
 * (sn_salt_window_lines()).
 *
 * @param bool $recheck Probe live, bypassing the cache.
 * @return array<int,array<string,mixed>>
 */
function analytics_salt_rows( $recheck ) {
	if ( ! function_exists( '\sn_salt_window_get' ) || ! function_exists( '\sn_salt_window_lines' ) ) {
		return array();
	}
	$said = \sn_salt_window_lines( \sn_salt_window_get( $recheck ) );
	return array( array( 'label' => __( 'Identity salt', 'signal-and-noise-tools' ), 'value' => implode( ' ', $said['lines'] ) ) );
}

/**
 * What the dashboard reads Analytics Engine with, and the Account ID.
 *
 * @return array<int,array<string,mixed>>
 */
function analytics_reads_rows() {
	$acct_locked = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) constant( 'SN_CF_ACCOUNT_ID' );
	$acct_opt    = (string) \get_option( defined( 'SN_CF_ACCOUNT_ID_OPT' ) ? constant( 'SN_CF_ACCOUNT_ID_OPT' ) : 'sn_cf_account_id', '' );
	$override    = function_exists( '\sn_cf_analytics_override_source' ) ? (string) \sn_cf_analytics_override_source() : '';
	$central     = function_exists( '\sn_cf_get_token' ) && '' !== (string) \sn_cf_get_token();
	return array(
		array( 'label' => __( 'Reads with', 'signal-and-noise-tools' ), 'value' => '' !== $override ? __( 'the analytics token override', 'signal-and-noise-tools' ) : ( $central ? __( 'the Cloudflare API token', 'signal-and-noise-tools' ) : __( 'nothing', 'signal-and-noise-tools' ) ), 'tone' => '' !== $override ? 'warn' : ( $central ? null : 'err' ) ),
		array( 'label' => __( 'Account ID', 'signal-and-noise-tools' ), 'value' => $acct_locked ? __( 'locked by SN_CF_ACCOUNT_ID', 'signal-and-noise-tools' ) : ( '' !== $acct_opt ? $acct_opt : __( 'not set', 'signal-and-noise-tools' ) ) ),
	);
}

/**
 * The reads' one problem worth a notice: an override in force, or no token.
 *
 * @return string
 */
function analytics_reads_notice() {
	$override = function_exists( '\sn_cf_analytics_override_source' ) ? (string) \sn_cf_analytics_override_source() : '';
	if ( '' !== $override ) {
		return \snt_kit_notice( 'warning', \snt_kit_esc( __( 'An analytics token override is in force; the Cloudflare API token is not used for these reads. Clear the override on the keyring to use one token.', 'signal-and-noise-tools' ) ) );
	}
	if ( ! ( function_exists( '\sn_cf_get_token' ) && '' !== (string) \sn_cf_get_token() ) ) {
		return \snt_kit_notice( 'error', \snt_kit_esc( __( 'No token anywhere: set the Cloudflare API token under Connections › Credentials.', 'signal-and-noise-tools' ) ) );
	}
	return '';
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
