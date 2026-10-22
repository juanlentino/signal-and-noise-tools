<?php
/**
 * Signal & Noise Tools — weekly security-digest email.
 *
 * The LLAR assessment's sanctioned build A2 (2026-06-17), upgraded with the
 * login-guard telemetry that shipped after it: a DETERMINISTIC (no AI) weekly
 * plain-text email summarizing the last 7 days of security activity — audit
 * events (failed logins, recon 404s), login-guard edge decisions (blocks,
 * and since 2026-10-18 its lockouts and throttles), and guard denylist freshness. Sends every week including quiet
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
		'guard_unavailable' => false, // a failed guard read, said as such (never zero lockouts).
		'status' => null,
		'breach' => null, // v13.60.0: the breached-password check's own numbers.
	);

	// Audit side: 7d-vs-prior totals + per-type 7d sums. Lockouts are the login
	// guard's (below); the retired lockout_triggered counter is not read.
	if ( function_exists( 'snt_audit_get_summary_impl' ) && function_exists( 'snt_audit_get_counters_impl' ) ) {
		$summary = snt_audit_get_summary_impl();
		$days    = snt_audit_get_counters_impl( 7 );
		$failed  = 0;
		$recon   = 0;
		$mfa     = array( 'mfa_failed' => 0, 'mfa_throttled' => 0, 'mfa_other' => 0 );
		foreach ( (array) $days as $row ) {
			foreach ( $mfa as $key => $count ) {
				$mfa[ $key ] += (int) ( $row[ $key ] ?? 0 );
			}
			$failed += (int) ( $row['login_failed'] ?? 0 );
			$recon  += (int) ( $row['wp_login_404'] ?? 0 ) + (int) ( $row['wp_admin_unauth_404'] ?? 0 );
		}
		$trend = isset( $summary['last_7d_vs_prior'] ) && is_array( $summary['last_7d_vs_prior'] )
			? $summary['last_7d_vs_prior']
			: array();
		$data['audit'] = array(
			'events_7d'   => (int) ( $trend['current'] ?? 0 ),
			'prior_7d'    => (int) ( $trend['prior'] ?? 0 ),
			'pct_delta'   => (int) ( $trend['pct_delta'] ?? 0 ),
			'failed_7d'   => $failed,
			'mfa_7d'      => $mfa,
			'recon_7d'    => $recon,
		);
		// 2026-10-18: account and site changes, the administrator ones counted out.
		if ( function_exists( 'snt_audit_get_changes_impl' ) ) {
			$changes                           = (array) snt_audit_get_changes_impl( 7 );
			$data['audit']['changes_7d']       = count( $changes );
			$data['audit']['admin_changes_7d'] = count( array_filter( array_column( $changes, 'high' ) ) );
		}
	}

	// Guard side: the cached 7-day headline (+1 top-country query when active).
	if ( function_exists( 'sn_login_defense_headline' ) ) {
		$lg = sn_login_defense_headline();
		$data['guard_unavailable'] = ! empty( $lg['configured'] ) && ! empty( $lg['unavailable'] );
		if ( ! empty( $lg['configured'] ) && empty( $lg['unavailable'] ) ) { // #1204: a failed read is not zeros
			$guard = array(
				'checked'           => (int) ( $lg['checked'] ?? 0 ),
				'blocked'           => (int) ( $lg['blocked'] ?? 0 ),
				'block_rate'        => (int) ( $lg['block_rate'] ?? 0 ),
				'top_network'       => (string) ( $lg['top_network'] ?? '' ),
				'top_country'       => '',
				'locked_out'        => (int) ( $lg['locked_out'] ?? 0 ),
				'throttled'         => (int) ( $lg['throttled'] ?? 0 ),
				'account_throttled' => (int) ( $lg['account_throttled'] ?? 0 ),
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
				// null, not 0: the worker answers null when its list meta is unreadable.
				'denylist_count' => is_numeric( $status['denylistCount'] ?? null ) ? (int) $status['denylistCount'] : null,
				'age_hours'      => isset( $status['ageHours'] ) && null !== $status['ageHours'] ? (int) $status['ageHours'] : null,
				'stale'          => (bool) ( $status['stale'] ?? true ),
				'version'        => (string) ( $status['version'] ?? '' ),
			);
		}
	}

	// Breached-password check (v13.60.0): Mode A's counts + Mode B's memos, the
	// same derivation the Site Health row reads. null when the module is absent.
	if ( function_exists( 'sn_hibp_surface_data' ) && function_exists( 'sn_hibp_health' ) ) {
		$sd = sn_hibp_surface_data();
		$hv = sn_hibp_health( $sd, time() );
		$data['breach'] = array(
			'status'            => (string) $hv['status'],
			'summary'           => (string) $hv['summary'],
			'flagged'           => (int) $hv['flagged'],
			'checked'           => (int) $hv['checked'],
			'set_breached'      => (int) ( $sd['set']['breached_count'] ?? 0 ),
			'set_unavailable'   => (int) ( $sd['set']['unavailable_count'] ?? 0 ),
			'unavailable_recent' => (bool) $hv['unavailable_recent'],
		);
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
	$breach = isset( $data['breach'] ) && is_array( $data['breach'] ) ? $data['breach'] : null;

	$quiet = ( null === $audit || ( 0 === $audit['events_7d'] && 0 === $audit['failed_7d'] && 0 === $audit['recon_7d'] && 0 === (int) ( $audit['changes_7d'] ?? 0 ) ) )
		&& ( null === $guard || ( 0 === $guard['blocked'] && 0 === (int) ( $guard['locked_out'] ?? 0 ) ) );

	$lines   = array();
	$lines[] = 'Signal & Noise weekly security digest (last 7 days)';
	$lines[] = str_repeat( '=', 51 );
	$lines[] = '';
	if ( $quiet ) {
		$lines[] = 'Quiet week: no failed logins, no recon probes, no account or site changes, no guard blocks or lockouts.';
		$lines[] = 'This email is the heartbeat: the pipeline is alive and watching.';
		$lines[] = '';
	}

	// Audit section.
	if ( null === $audit ) {
		$lines[] = 'Audit log: unavailable (module not loaded).';
	} else {
		$sign    = $audit['pct_delta'] >= 0 ? '+' : '';
		$lines[] = 'Site audit (WordPress layer)';
		$lines[] = '  Failed logins: ' . number_format_i18n( $audit['failed_7d'] );
		if ( isset( $audit['mfa_7d'] ) ) {
			$mfa = $audit['mfa_7d'];
			$lines[] = '  Included MFA errors: ' . (int) $mfa['mfa_failed'] . ' rejected, ' . (int) $mfa['mfa_throttled'] . ' rate-limited, ' . (int) $mfa['mfa_other'] . ' other. Older failures are unclassified.';
		}
		$lines[] = '  Recon probes (login/admin 404s): ' . number_format_i18n( $audit['recon_7d'] );
		if ( isset( $audit['changes_7d'] ) ) {
			$admin   = (int) ( $audit['admin_changes_7d'] ?? 0 );
			$lines[] = '  Account and site changes: ' . number_format_i18n( (int) $audit['changes_7d'] )
				. ( $admin > 0 ? ' (' . number_format_i18n( $admin ) . ' involving administrator access)' : '' );
		}
		$lines[] = '  All audit events: ' . number_format_i18n( $audit['events_7d'] )
			. ' (prior 7 days: ' . number_format_i18n( $audit['prior_7d'] ) . ', ' . $sign . $audit['pct_delta'] . '%)';
	}
	$lines[] = '';

	// Guard section.
	if ( null === $guard && ! empty( $data['guard_unavailable'] ) ) {
		$lines[] = 'Login guard: unavailable (its log could not be read this week).';
	} elseif ( null === $guard ) {
		$lines[] = 'Login guard: not configured (Analytics Engine credentials absent).';
	} else {
		$lines[] = 'Login guard (edge layer)';
		$lines[] = '  Checked: ' . number_format_i18n( $guard['checked'] );
		$lines[] = '  Blocked: ' . number_format_i18n( $guard['blocked'] ) . ' (' . $guard['block_rate'] . '% of checked)';
		$lines[] = '  Lockouts: ' . number_format_i18n( (int) ( $guard['locked_out'] ?? 0 ) );
		$lines[] = '  Throttled: ' . number_format_i18n( (int) ( $guard['throttled'] ?? 0 ) );
		$lines[] = '  Account throttled: ' . number_format_i18n( (int) ( $guard['account_throttled'] ?? 0 ) );
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
		$age     = null === $status['age_hours'] ? 'unknown age' : 'refreshed ' . $status['age_hours'] . ( 1 === (int) $status['age_hours'] ? ' hour' : ' hours' ) . ' ago';
		$size    = null === $status['denylist_count'] ? 'size not reported by the worker' : number_format_i18n( $status['denylist_count'] ) . ' ranges';
		$lines[] = 'Guard denylist: ' . $size . ', ' . $age
			. ( $status['stale'] ? '. STALE (older than 48 hours or empty; check the worker cron)' : '' )
			. ( '' !== $status['version'] ? ' (worker v' . $status['version'] . ')' : '' );
	}
	$lines[] = '';

	// Breached-password section (v13.60.0). Counts are lifetime, not 7d: the
	// set-time stats have no window and the memo is a standing state.
	if ( null === $breach ) {
		$lines[] = 'Breached-password check: unavailable (module not loaded).';
	} else {
		$lines[] = 'Breached-password check (' . ( 'good' === $breach['status'] ? 'ok' : 'ATTENTION' ) . ')';
		$lines[] = '  Accounts flagged at login: ' . number_format_i18n( $breach['flagged'] ) . ' of ' . number_format_i18n( $breach['checked'] ) . ' checked';
		$lines[] = '  Breached passwords refused at set-time: ' . number_format_i18n( $breach['set_breached'] );
		$lines[] = '  Fail-closed rejections (API unreachable): ' . number_format_i18n( $breach['set_unavailable'] ) . ( $breach['unavailable_recent'] ? '. RECENT: the API may be degrading' : '' );
		if ( 'good' !== $breach['status'] ) {
			$lines[] = '  ' . $breach['summary'];
		}
	}
	$lines[] = '';
	$lines[] = 'Enforcement happens at the edge in real time; this digest is observability.';
	$lines[] = 'Turn it off in Security › Login defense.';

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

/**
 * Collect, compose, and send the digest to admin_email. Records a durable
 * last-sent timestamp on success and a durable last-error on failure (options
 * with autoload=no — transients are flush-volatile under the persistent object
 * cache, and this is exactly the state we need to survive a cache flush).
 *
 * @param bool $test Test-send (subject prefixed, everything else identical).
 * @return bool Sent.
 */
function snt_security_digest_send( $test = false ) {
	$admin_email = (string) get_option( 'admin_email' );
	if ( '' === $admin_email || ! is_email( $admin_email ) ) {
		update_option( SN_SECURITY_DIGEST_LAST_ERROR, array( 'message' => __( 'the site\'s admin email address is missing or not valid', 'signal-and-noise-tools' ), 'at' => time() ), false );
		return false;
	}
	$data = snt_security_digest_collect();
	$sent = (bool) wp_mail( $admin_email, snt_security_digest_subject( $data, $test ), snt_security_digest_compose( $data ) );
	if ( $sent ) {
		update_option( SN_SECURITY_DIGEST_LAST_SENT, time(), false );
		update_option( SN_SECURITY_DIGEST_LAST_ERROR, false, false );
	} else {
		// 2026-10-12 audit: the screen printed "wp_mail returned false".
		update_option( SN_SECURITY_DIGEST_LAST_ERROR, array( 'message' => __( 'WordPress could not hand the email to the mail server', 'signal-and-noise-tools' ), 'at' => time() ), false );
	}
	return $sent;
}

/**
 * Self-healing weekly cron sync — the same pattern the Insights weekly scan uses
 * (inc/insights.php): schedule when enabled+unscheduled, unschedule when
 * disabled+scheduled; runs on init so a settings toggle takes effect on the next
 * request without a dedicated save hook.
 *
 * @return void
 */
function snt_security_digest_maybe_schedule_cron() {
	$on        = snt_security_digest_enabled();
	$scheduled = wp_next_scheduled( SN_SECURITY_DIGEST_CRON_HOOK );
	if ( $on && ! $scheduled ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', SN_SECURITY_DIGEST_CRON_HOOK );
	} elseif ( ! $on && $scheduled ) {
		wp_unschedule_event( $scheduled, SN_SECURITY_DIGEST_CRON_HOOK );
	}
}
add_action( 'init', 'snt_security_digest_maybe_schedule_cron' );

/**
 * Cron handler: the real weekly send.
 *
 * @return void
 */
function snt_security_digest_weekly_cron_cb() {
	snt_security_digest_send();
}
add_action( SN_SECURITY_DIGEST_CRON_HOOK, 'snt_security_digest_weekly_cron_cb' );

/**
 * Settings section for the Security → Login defense sub-tab: the opt-in toggle,
 * last-sent/last-error readout, save + test-send buttons. Posts through the
 * central dispatcher (sn_action=security_digest_save; nonce + caps + page
 * allowlist enforced centrally by sn_handle_admin_post()).
 *
 * v7.2.1: rendered as its own `.sn-fieldset` card (the Retention-form pattern,
 * inc/audit-log-admin.php) and mounted AFTER the status box — `.sn-status-box`
 * is a flex row (assets/admin.css:445), so any child appended inside it becomes
 * a squeezed column. Never render this inside the status box.
 *
 * @return void
 */
function snt_security_digest_render_settings() {
	$last_sent = (int) get_option( SN_SECURITY_DIGEST_LAST_SENT, 0 );
	$last_err  = get_option( SN_SECURITY_DIGEST_LAST_ERROR );
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset">';
	wp_nonce_field( 'sn_security_digest_save' );
	echo '<input type="hidden" name="action" value="sn_security_digest_save" />';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Weekly security digest', 'signal-and-noise-tools' ) . '</h2>';
	echo '<label><input type="checkbox" name="sn_digest_enabled" value="1" ';
	checked( snt_security_digest_enabled() );
	echo ' /> ' . esc_html__( 'Email a weekly security digest to the admin address', 'signal-and-noise-tools' ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html__( 'Failed logins, recon probes, account and site changes, lockouts, login-guard blocks, and denylist freshness for the last 7 days. Sends every week, including quiet weeks: the quiet email is the heartbeat.', 'signal-and-noise-tools' ) . '</p>';
	if ( $last_sent > 0 ) {
		echo '<p class="sn-field-helper">' . esc_html(
			sprintf(
				/* translators: %s: human time diff */
				__( 'Last sent %s ago.', 'signal-and-noise-tools' ),
				human_time_diff( $last_sent, time() )
			)
		) . '</p>';
	}
	if ( is_array( $last_err ) && ! empty( $last_err['message'] ) ) {
		echo '<p class="sn-field-helper" style="color:#b32d2e;">' . esc_html(
			sprintf(
				/* translators: %s: error message */
				__( 'Last send failed: %s', 'signal-and-noise-tools' ),
				(string) $last_err['message']
			)
		) . '</p>';
	}
	echo '<p class="sn-field-helper">' . esc_html__( 'Sends this week’s digest to the admin address now, whether or not the weekly email is on.', 'signal-and-noise-tools' ) . '</p>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button> ';
	echo '<button type="submit" class="button" name="sn_digest_test" value="1">' . esc_html__( 'Send test digest', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';
	echo '</form>';
}
