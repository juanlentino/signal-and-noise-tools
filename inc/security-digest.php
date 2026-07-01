<?php
/**
 * Signal & Noise Tools — weekly security-digest email.
 *
 * The LLAR assessment's sanctioned build A2 (2026-06-17), upgraded with the
 * login-guard telemetry that shipped after it: a DETERMINISTIC (no AI) weekly
 * plain-text email summarizing the last 7 days of security activity — audit
 * events (failed logins, recon 404s, LLA-polled lockouts), login-guard edge
 * decisions, and guard denylist freshness. Sends every week including quiet
 * weeks: the zero week is the heartbeat that proves the pipeline is alive.
 *
 * Opt-in, default OFF (settings toggle on Security → Login defense), weekly
 * cron with the self-healing schedule-sync pattern from inc/insights-narration.php,
 * wp_mail() cloned from inc/api-rate-monitor.php. No AI anywhere in this path:
 * security observability must not fail when the AI client fails, and the email
 * must be verbatim-trustworthy.
 *
 * @package SignalNoiseTools
 * @since 7.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_SECURITY_DIGEST_CRON_HOOK', 'sn_security_digest_weekly' );
define( 'SN_SECURITY_DIGEST_LAST_SENT', 'sn_security_digest_last_sent' );
define( 'SN_SECURITY_DIGEST_LAST_ERROR', 'sn_security_digest_last_error' );

/**
 * Is the weekly digest opt-in enabled? Default OFF (owner decision 2026-07-01).
 *
 * @return bool
 */
function snt_security_digest_enabled() {
	return (bool) sn_setting( 'audit.digest_email_enabled', false );
}

/**
 * Collect the 7-day digest data. Every source degrades to null (section) or 0
 * (counter) when its reader is unavailable — the composer renders truthful
 * "unavailable" lines rather than fictional zeros for null sections.
 *
 * @return array{window:array{days:int}, audit:?array, guard:?array, status:?array}
 */
function snt_security_digest_collect() {
	$data = array(
		'window' => array( 'days' => 7 ),
		'audit'  => null,
		'guard'  => null,
		'status' => null,
	);

	// Audit side: 7d-vs-prior totals + per-type 7d sums (lockout_triggered is the
	// LLA-polled counter, so LLA lockouts ride along without an LLA dependency).
	if ( function_exists( 'snt_audit_get_summary_impl' ) && function_exists( 'snt_audit_get_counters_impl' ) ) {
		$summary = snt_audit_get_summary_impl();
		$days    = snt_audit_get_counters_impl( 7 );
		$failed  = 0;
		$recon   = 0;
		$locked  = 0;
		foreach ( (array) $days as $row ) {
			$failed += (int) ( $row['login_failed'] ?? 0 );
			$recon  += (int) ( $row['wp_login_404'] ?? 0 ) + (int) ( $row['wp_admin_unauth_404'] ?? 0 );
			$locked += (int) ( $row['lockout_triggered'] ?? 0 );
		}
		$trend = isset( $summary['last_7d_vs_prior'] ) && is_array( $summary['last_7d_vs_prior'] )
			? $summary['last_7d_vs_prior']
			: array();
		$data['audit'] = array(
			'events_7d'   => (int) ( $trend['current'] ?? 0 ),
			'prior_7d'    => (int) ( $trend['prior'] ?? 0 ),
			'pct_delta'   => (int) ( $trend['pct_delta'] ?? 0 ),
			'failed_7d'   => $failed,
			'recon_7d'    => $recon,
			'lockouts_7d' => $locked,
		);
	}

	// Guard side: the cached 7-day headline (+1 top-country query when active).
	if ( function_exists( 'sn_login_defense_headline' ) ) {
		$lg = sn_login_defense_headline();
		if ( ! empty( $lg['configured'] ) ) {
			$guard = array(
				'checked'     => (int) ( $lg['checked'] ?? 0 ),
				'blocked'     => (int) ( $lg['blocked'] ?? 0 ),
				'block_rate'  => (int) ( $lg['block_rate'] ?? 0 ),
				'top_network' => (string) ( $lg['top_network'] ?? '' ),
				'top_country' => '',
			);
			if ( $guard['blocked'] > 0 && function_exists( 'sn_analytics_query' ) && function_exists( 'sn_login_defense_top_country_sql' ) ) {
				$rows                 = sn_analytics_query( sn_login_defense_top_country_sql( 7, 1 ) );
				$guard['top_country'] = is_array( $rows ) ? (string) ( $rows[0]['country'] ?? '' ) : '';
			}
			$data['guard'] = $guard;
		}
	}

	// Guard freshness: the SSRF-guarded /status probe (null when unreachable).
	if ( function_exists( 'sn_login_defense_status' ) ) {
		$status = sn_login_defense_status();
		if ( is_array( $status ) ) {
			$data['status'] = array(
				'denylist_count' => (int) ( $status['denylistCount'] ?? 0 ),
				'age_hours'      => isset( $status['ageHours'] ) && null !== $status['ageHours'] ? (int) $status['ageHours'] : null,
				'stale'          => (bool) ( $status['stale'] ?? true ),
				'version'        => (string) ( $status['version'] ?? '' ),
			);
		}
	}

	return $data;
}

/**
 * Compose the plain-text digest body from collected data. PURE: data in, text
 * out — no WP calls except i18n-safe number formatting. A null section renders
 * one truthful "unavailable" line, never fictional zeros. A zero-activity week
 * leads with the heartbeat line: silence is only trustworthy when explicit.
 *
 * @param array $data From snt_security_digest_collect().
 * @return string
 */
function snt_security_digest_compose( $data ) {
	$audit  = isset( $data['audit'] ) && is_array( $data['audit'] ) ? $data['audit'] : null;
	$guard  = isset( $data['guard'] ) && is_array( $data['guard'] ) ? $data['guard'] : null;
	$status = isset( $data['status'] ) && is_array( $data['status'] ) ? $data['status'] : null;

	$quiet = ( null === $audit || ( 0 === $audit['events_7d'] && 0 === $audit['failed_7d'] && 0 === $audit['recon_7d'] ) )
		&& ( null === $guard || 0 === $guard['blocked'] );

	$lines   = array();
	$lines[] = 'Signal & Noise weekly security digest (last 7 days)';
	$lines[] = str_repeat( '=', 51 );
	$lines[] = '';
	if ( $quiet ) {
		$lines[] = 'Quiet week: no failed logins, no recon probes, no guard blocks.';
		$lines[] = 'This email is the heartbeat — the pipeline is alive and watching.';
		$lines[] = '';
	}

	// Audit section.
	if ( null === $audit ) {
		$lines[] = 'Audit log: unavailable (module not loaded).';
	} else {
		$sign    = $audit['pct_delta'] >= 0 ? '+' : '';
		$lines[] = 'Site audit (WordPress layer)';
		$lines[] = '  Failed logins: ' . number_format_i18n( $audit['failed_7d'] );
		$lines[] = '  Recon probes (login/admin 404s): ' . number_format_i18n( $audit['recon_7d'] );
		$lines[] = '  Lockouts: ' . number_format_i18n( $audit['lockouts_7d'] );
		$lines[] = '  All audit events: ' . number_format_i18n( $audit['events_7d'] )
			. ' (prior 7d: ' . number_format_i18n( $audit['prior_7d'] ) . ', ' . $sign . $audit['pct_delta'] . '%)';
	}
	$lines[] = '';

	// Guard section.
	if ( null === $guard ) {
		$lines[] = 'Login guard: not configured (Analytics Engine credentials absent).';
	} else {
		$lines[] = 'Login guard (edge layer)';
		$lines[] = '  Checked: ' . number_format_i18n( $guard['checked'] );
		$lines[] = '  Blocked: ' . number_format_i18n( $guard['blocked'] ) . ' (' . $guard['block_rate'] . '% of checked)';
		if ( '' !== $guard['top_network'] ) {
			$lines[] = '  Top blocked network: ' . $guard['top_network'];
		}
		if ( '' !== $guard['top_country'] ) {
			$lines[] = '  Top blocked country: ' . $guard['top_country'];
		}
	}
	$lines[] = '';

	// Freshness section.
	if ( null === $status ) {
		$lines[] = 'Guard denylist: status unavailable (worker unreachable from this host).';
	} else {
		$age     = null === $status['age_hours'] ? 'unknown age' : 'refreshed ' . $status['age_hours'] . 'h ago';
		$lines[] = 'Guard denylist: ' . number_format_i18n( $status['denylist_count'] ) . ' ranges, ' . $age
			. ( $status['stale'] ? ' — STALE (older than 48h or empty; check the worker cron)' : '' )
			. ( '' !== $status['version'] ? ' (worker v' . $status['version'] . ')' : '' );
	}
	$lines[] = '';
	$lines[] = 'Enforcement happens at the edge in real time; this digest is observability.';
	$lines[] = 'Toggle it off under Security -> Login defense.';

	return implode( "\n", $lines ) . "\n";
}

/**
 * Digest subject line. Counts read as zero for null sections.
 *
 * @param array $data From snt_security_digest_collect().
 * @param bool  $test Prefix with [TEST] for the test-send button.
 * @return string
 */
function snt_security_digest_subject( $data, $test = false ) {
	$blocked = isset( $data['guard']['blocked'] ) ? (int) $data['guard']['blocked'] : 0;
	$failed  = isset( $data['audit']['failed_7d'] ) ? (int) $data['audit']['failed_7d'] : 0;
	$site    = (string) wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return ( $test ? '[TEST] ' : '' )
		. sprintf( '[%s] Weekly security digest: %d blocked, %d failed logins', $site, $blocked, $failed );
}
