<?php
/**
 * Signal & Noise Tools — deterministic daily Operations morning brief.
 *
 * R2 channel review: no AI and no content prose enters this path. It narrates
 * bounded counts/states from owner-only operational readers, so an injected
 * post or remote label cannot become instructions in the owner's inbox.
 *
 * Transport note: the uptime and deploy readers serve short-lived transients
 * and fall back to LIVE fetches (5s-bounded) on a cold cache, which the 7:00
 * cron will usually hit. That is deliberate: the send runs in the spawned
 * wp-cron process, never a visitor request, and a brief that only ever said
 * "unavailable" at 7:00 would not be worth mailing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SNT_MORNING_BRIEF_CRON_HOOK', 'snt_morning_brief_daily' );
define( 'SNT_MORNING_BRIEF_LAST_SENT', 'snt_morning_brief_last_sent' );
define( 'SNT_MORNING_BRIEF_LAST_ERROR', 'snt_morning_brief_last_error' );

function snt_morning_brief_enabled() {
	return (bool) sn_setting( 'operations.morning_brief_enabled', false );
}

/** Collect cached/local readers behind the health, cron, uptime and deploy abilities. */
function snt_morning_brief_collect() {
	$data = array( 'health' => null, 'cron' => null, 'uptime' => null, 'deploy' => null, 'drift' => null );
	if ( function_exists( 'sn_health_last_scan' ) ) {
		$scan = sn_health_last_scan();
		if ( is_array( $scan ) ) {
			$checks = (array) ( $scan['checks'] ?? array() );
			$data['health'] = array(
				'scanned_at' => (int) ( $scan['scanned_at'] ?? 0 ),
				'findings'   => function_exists( 'sn_health_finding_total' ) ? sn_health_finding_total( $scan ) : 0,
				'advisories' => function_exists( 'sn_health_advisory_total' ) ? sn_health_advisory_total( $scan ) : 0,
				'checks'     => count( $checks ),
			);
		}
	}
	if ( function_exists( 'snt_cron_get_events_impl' ) ) {
		$rows = (array) snt_cron_get_events_impl();
		$owned = $orphans = $history = $failed = 0;
		$seen = array();
		foreach ( $rows as $row ) {
			$owned   += ! empty( $row['is_sn_owned'] ) ? 1 : 0;
			$orphans += empty( $row['has_handler'] ) ? 1 : 0;
			$hook = (string) ( $row['hook'] ?? '' );
			if ( ! empty( $row['is_sn_owned'] ) && '' !== $hook && empty( $seen[ $hook ] ) && function_exists( 'snt_cron_history_for_hook' ) ) {
				$seen[ $hook ] = true;
				foreach ( snt_cron_history_for_hook( $hook, 5 ) as $firing ) { $history++; $failed += empty( $firing['success'] ) ? 1 : 0; }
			}
		}
		$data['cron'] = compact( 'owned', 'orphans', 'history', 'failed' ) + array( 'total' => count( $rows ) );
	}
	if ( function_exists( 'sn_uptime_status_configured' ) && ! sn_uptime_status_configured() ) {
		$data['uptime'] = array( 'configured' => false, 'total' => 0, 'up' => 0, 'attention' => 0, 'error' => false );
	} elseif ( function_exists( 'sn_uptime_status_fetch' ) ) {
		$status = sn_uptime_status_fetch();
		$is_error = function_exists( 'is_wp_error' ) && is_wp_error( $status );
		$rows = ! $is_error && is_array( $status ) ? (array) ( $status['rows'] ?? array() ) : array();
		$up = 0;
		foreach ( $rows as $row ) { $up += 'up' === (string) ( $row['status'] ?? '' ) ? 1 : 0; }
		$data['uptime'] = array( 'configured' => true, 'total' => count( $rows ), 'up' => $up, 'attention' => count( $rows ) - $up, 'error' => $is_error );
	}
	if ( function_exists( 'snt_deploy_status_for' ) ) {
		$data['deploy'] = array( 'theme' => snt_deploy_status_for( 'theme' ), 'plugin' => snt_deploy_status_for( 'plugin' ), 'last_deploy' => '' );
		if ( function_exists( 'snt_deploy_history_merged' ) && function_exists( 'snt_deploy_runs_age_label' ) && defined( 'SNT_DEPLOY_REPOS' ) ) {
			$data['deploy']['last_deploy'] = snt_deploy_runs_age_label( snt_deploy_history_merged( array_values( SNT_DEPLOY_REPOS ), 1 ) );
		}
	}
	if ( function_exists( 'snt_config_drift_status' ) ) { $data['drift'] = snt_config_drift_status(); }
	return $data;
}

/** Pure prose composition: one operations paragraph, with drift silent when absent. */
function snt_morning_brief_compose( $data ) {
	$sentences = array();
	$h = is_array( $data['health'] ?? null ) ? $data['health'] : null;
		$advisory_note = 1 === (int) ( $h['advisories'] ?? 0 ) ? ', with one advisory noted' : sprintf( ', with %d advisories noted', (int) ( $h['advisories'] ?? 0 ) );
		$sentences[] = null === $h ? 'No cached health scan is available yet.' : ( (int) $h['findings'] > 0
		? sprintf( 'The latest health scan found %d fault-tier issues across %d checks, with %d additional advisories.', $h['findings'], $h['checks'], $h['advisories'] )
		: sprintf( 'The latest health scan found no fault-tier issues across %d checks%s.', $h['checks'], (int) $h['advisories'] > 0 ? $advisory_note : '' ) );
	$c = is_array( $data['cron'] ?? null ) ? $data['cron'] : null;
	$sentences[] = null === $c ? 'Cron state is unavailable.' : sprintf( 'WordPress has %d scheduled events, %d owned by Signal & Noise; %d have no handler, and %d of the %d recent owned firings checked here failed.', $c['total'], $c['owned'], $c['orphans'], $c['failed'], $c['history'] );
	$u = is_array( $data['uptime'] ?? null ) ? $data['uptime'] : null;
	if ( null === $u ) { $sentences[] = 'Uptime status is unavailable.'; }
	elseif ( empty( $u['configured'] ) ) { $sentences[] = 'Uptime monitoring is not configured.'; }
	elseif ( ! empty( $u['error'] ) ) { $sentences[] = 'Uptime monitoring is configured, but its current status could not be read.'; }
	else { $sentences[] = sprintf( 'Uptime reports %d of %d monitors and heartbeats up, with %d needing attention.', $u['up'], $u['total'], $u['attention'] ); }
	$d = is_array( $data['deploy'] ?? null ) ? $data['deploy'] : null;
	if ( null === $d ) { $sentences[] = 'Deploy status is unavailable.'; }
	else {
		$available = ( 'available' === ( $d['theme']['state'] ?? '' ) ? 1 : 0 ) + ( 'available' === ( $d['plugin']['state'] ?? '' ) ? 1 : 0 );
		$unknown = ( 'unknown' === ( $d['theme']['state'] ?? '' ) ? 1 : 0 ) + ( 'unknown' === ( $d['plugin']['state'] ?? '' ) ? 1 : 0 );
		$sentences[] = 0 === $available && 0 === $unknown ? 'The theme and plugin both match their latest known releases.' : sprintf( 'Deploy status shows %d update%s available and %d package%s with an unknown latest release.', $available, 1 === $available ? '' : 's', $unknown, 1 === $unknown ? '' : 's' );
		if ( '' !== (string) ( $d['last_deploy'] ?? '' ) ) { $sentences[] = 'The last recorded deploy was ' . $d['last_deploy'] . '.'; }
	}
	$drift = is_array( $data['drift'] ?? null ) ? $data['drift'] : null;
	if ( $drift && ! empty( $drift['has_drift'] ) ) {
		$sentences[] = sprintf( 'Configuration drift is present in %d setting%s since the acknowledged snapshot: %d changed, %d added, and %d removed.', $drift['count'], 1 === (int) $drift['count'] ? '' : 's', count( $drift['changed'] ), count( $drift['added'] ), count( $drift['removed'] ) );
	}
	return "Signal & Noise morning operations brief\n\n" . implode( ' ', $sentences ) . "\n";
}

function snt_morning_brief_subject( $data, $test = false ) {
	$attention = (int) ( $data['health']['findings'] ?? 0 ) + (int) ( $data['cron']['orphans'] ?? 0 ) + (int) ( $data['cron']['failed'] ?? 0 ) + (int) ( $data['uptime']['attention'] ?? 0 ) + ( ! empty( $data['drift']['has_drift'] ) ? 1 : 0 );
	$unknown = ! is_array( $data['health'] ?? null ) || ! is_array( $data['cron'] ?? null ) || ! is_array( $data['uptime'] ?? null ) || ! empty( $data['uptime']['error'] ) || ! is_array( $data['deploy'] ?? null );
	foreach ( array( 'theme', 'plugin' ) as $package ) { $attention += 'available' === ( $data['deploy'][ $package ]['state'] ?? '' ) ? 1 : 0; $unknown = $unknown || 'unknown' === ( $data['deploy'][ $package ]['state'] ?? '' ); }
	$site = (string) wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$headline = $attention ? $attention . ( 1 === $attention ? ' needs attention' : ' need attention' ) : ( $unknown ? 'status incomplete' : 'all clear' );
	return ( $test ? '[TEST] ' : '' ) . sprintf( '[%s] Morning operations brief: %s', $site, $headline );
}

function snt_morning_brief_send( $test = false ) {
	$email = (string) get_option( 'admin_email' );
	if ( '' === $email || ! is_email( $email ) ) { update_option( SNT_MORNING_BRIEF_LAST_ERROR, array( 'message' => 'admin_email missing or invalid', 'at' => time() ), false ); return false; }
	$data = snt_morning_brief_collect();
	$sent = (bool) wp_mail( $email, snt_morning_brief_subject( $data, $test ), snt_morning_brief_compose( $data ) );
	update_option( $sent ? SNT_MORNING_BRIEF_LAST_SENT : SNT_MORNING_BRIEF_LAST_ERROR, $sent ? time() : array( 'message' => 'wp_mail returned false', 'at' => time() ), false );
	if ( $sent ) { update_option( SNT_MORNING_BRIEF_LAST_ERROR, false, false ); }
	return $sent;
}

function snt_morning_brief_next_run() {
	$now = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
	$next = $now->setTime( 7, 0 );
	return ( $next <= $now ? $next->modify( '+1 day' ) : $next )->getTimestamp();
}
function snt_morning_brief_maybe_schedule_cron() {
	$scheduled = wp_next_scheduled( SNT_MORNING_BRIEF_CRON_HOOK );
	if ( snt_morning_brief_enabled() && $scheduled ) {
		// wp_schedule_event repeats at a fixed +86400s, so a DST transition
		// walks the firing to 6:00 or 8:00 site time permanently. Re-anchor
		// whenever the pending firing is no longer at 7:00 site time.
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$hour = (int) ( new DateTimeImmutable( '@' . $scheduled ) )->setTimezone( $tz )->format( 'G' );
		if ( 7 !== $hour ) {
			wp_unschedule_event( $scheduled, SNT_MORNING_BRIEF_CRON_HOOK );
			$scheduled = false;
		}
	}
	if ( snt_morning_brief_enabled() && ! $scheduled ) { wp_schedule_event( snt_morning_brief_next_run(), 'daily', SNT_MORNING_BRIEF_CRON_HOOK ); }
	elseif ( ! snt_morning_brief_enabled() && $scheduled ) { wp_unschedule_event( $scheduled, SNT_MORNING_BRIEF_CRON_HOOK ); }
}
add_action( 'init', 'snt_morning_brief_maybe_schedule_cron' );
function snt_morning_brief_daily_cron_cb() { snt_morning_brief_send(); }
add_action( SNT_MORNING_BRIEF_CRON_HOOK, 'snt_morning_brief_daily_cron_cb' );

function snt_morning_brief_render_settings() {
	$drift = function_exists( 'snt_config_drift_status' ) ? snt_config_drift_status() : array( 'has_drift' => false );
	$last_sent = (int) get_option( SNT_MORNING_BRIEF_LAST_SENT, 0 );
	$last_error = get_option( SNT_MORNING_BRIEF_LAST_ERROR );
	echo '<form method="post" class="sn-fieldset"><input type="hidden" name="sn_action" value="morning_brief_save" />'; wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Morning operations brief', 'signal-and-noise-tools' ) . '</h2>';
	echo '<label><input type="checkbox" name="snt_morning_brief_enabled" value="1" '; checked( snt_morning_brief_enabled() ); echo ' /> ' . esc_html__( 'Email a daily morning brief to the admin address', 'signal-and-noise-tools' ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html__( 'A deterministic prose reading of the latest health scan, cron history, uptime status, deploy state, and any unacknowledged settings drift. Scheduled for 7:00 a.m. site time.', 'signal-and-noise-tools' ) . '</p>';
	if ( $last_sent > 0 ) { echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Last sent %s ago.', human_time_diff( $last_sent, time() ) ) ) . '</p>'; }
	if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) { echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Last send failed: %s', $last_error['message'] ) ) . '</p>'; }
	if ( ! empty( $drift['has_drift'] ) ) { echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Configuration drift: %d settings differ from the acknowledged snapshot.', $drift['count'] ) ) . '</p>'; }
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button> <button type="submit" class="button" name="snt_morning_brief_test" value="1">' . esc_html__( 'Send test brief', 'signal-and-noise-tools' ) . '</button>';
	if ( ! empty( $drift['has_drift'] ) ) { echo ' <button type="submit" class="button" name="snt_config_drift_acknowledge" value="1">' . esc_html__( 'Acknowledge current settings', 'signal-and-noise-tools' ) . '</button>'; }
	echo '</div></form>';
}
add_action( 'sn_admin_cron_tab', 'snt_morning_brief_render_settings', 20 );
