<?php
/**
 * Signal & Noise Tools — Login hardening audit log.
 *
 * Captures 6 login-related events:
 *
 *   login_success         per-event row (timestamp + username; no IP)
 *   login_failed          day-bucketed counter
 *   wp_login_404          day-bucketed counter (from inc/login-hide.php)
 *   wp_admin_unauth_404   day-bucketed counter (from inc/login-hide.php)
 *   lockout_triggered     day-bucketed counter (polling fallback — LLA fires no hook)
 *   password_reset        day-bucketed counter
 *
 * Plus a per-day `unique_ips_count` computed via an ephemeral hashed-IP
 * transient set with 25h TTL. The set rolls forward at day-flip into the
 * long-term counter; the hashes themselves never persist beyond 25h.
 *
 * Storage:
 *   - Long-term: single autoloaded option `sn_audit_log_v1` (JSON-encoded,
 *     schema-versioned). Worst-case envelope ~100 KB after 90 days.
 *   - Ephemeral: transient `sn_audit_today_ips` (25h TTL), associative
 *     array of `{ hashed_ip_fragment => 1 }`.
 *
 * Retention: 90 days, enforced by daily cron `sn_audit_log_prune`.
 *
 * 4-surface dispatch (per project pattern; converges on snt_audit_*_impl()):
 *   - wp-admin form (Security → Audit log sub-tab, in inc/audit-log-admin.php)
 *   - REST routes signal-noise/v1/audit/* (co-located below)
 *   - Abilities (registered in inc/abilities-registration.php)
 *   - desktop-mode ⌘K commands (registered in inc/desktop-mode-integration.php)
 *
 * @package SignalNoiseTools
 * @since 3.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AUDIT_OPTION         = 'sn_audit_log_v1';
const SN_AUDIT_TRANSIENT_IPS  = 'sn_audit_today_ips';
const SN_AUDIT_IPS_TTL        = 25 * HOUR_IN_SECONDS;
const SN_AUDIT_RETENTION_DAYS = 90;
const SN_AUDIT_LOGIN_SUCCESS_CAP = 500;
const SN_AUDIT_PRUNE_HOOK     = 'sn_audit_log_prune';
const SN_AUDIT_LLA_LAST_COUNT_OPT = 'sn_audit_lla_last_lockout_count';

const SN_AUDIT_COUNTER_TYPES = array(
	'login_failed',
	'wp_login_404',
	'wp_admin_unauth_404',
	'lockout_triggered',
	'password_reset',
);

/**
 * Get the audit log blob, lazy-initializing if missing.
 *
 * @return array Always returns the valid schema-v1 shape.
 */
function snt_audit_get_blob() {
	$blob = get_option( SN_AUDIT_OPTION, null );
	if ( ! is_array( $blob ) || ! isset( $blob['schema_version'] ) ) {
		$blob = array(
			'schema_version' => 1,
			'created_at'     => time(),
			'counters'       => array(),
			'login_success'  => array(),
		);
	}
	return $blob;
}

/**
 * Hash an IP to a 16-char hex fragment, salted with wp_salt('auth').
 *
 * `wp_salt('auth')` rotates with the WP auth salt, so collision search is
 * bounded to a 16-char namespace per salt epoch. We never need cross-epoch
 * retention since the transient set lives only 25h.
 *
 * @param string $ip Raw IP address.
 * @return string 16-char hex hash.
 */
function snt_audit_hash_ip( $ip ) {
	$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
	return substr( hash( 'sha256', (string) $ip . $salt ), 0, 16 );
}

/**
 * Get today's date key (YYYY-MM-DD) in the site timezone.
 *
 * @return string
 */
function snt_audit_today_key() {
	return wp_date( 'Y-m-d' );
}

/* ════════════════════════════════════════════════════════════════════════
 * Pure-function impls (4-surface dispatch convergence).
 * Every dispatch surface (admin form, REST, Abilities, desktop-mode ⌘K)
 * calls these. No surface duplicates business logic.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Increment a per-day counter. Lazy-initializes the blob if missing.
 * Also updates the ephemeral unique-IPs transient set if $ip is provided.
 *
 * @param string      $event_type One of SN_AUDIT_COUNTER_TYPES.
 * @param string|null $ip         Raw IP (will be hashed). Optional.
 */
function snt_audit_increment_counter_impl( $event_type, $ip = null ) {
	if ( ! in_array( $event_type, SN_AUDIT_COUNTER_TYPES, true ) ) {
		return;
	}

	$blob  = snt_audit_get_blob();
	$today = snt_audit_today_key();

	if ( ! isset( $blob['counters'][ $today ] ) ) {
		$blob['counters'][ $today ] = array_fill_keys( SN_AUDIT_COUNTER_TYPES, 0 );
		$blob['counters'][ $today ]['unique_ips_count'] = 0;
	}

	$blob['counters'][ $today ][ $event_type ] = (int) ( $blob['counters'][ $today ][ $event_type ] ?? 0 ) + 1;

	// Update unique-IPs transient set if we have an IP.
	if ( null !== $ip && '' !== $ip ) {
		$hash = snt_audit_hash_ip( $ip );
		$set  = get_transient( SN_AUDIT_TRANSIENT_IPS );
		if ( ! is_array( $set ) ) {
			$set = array();
		}
		if ( ! isset( $set[ $hash ] ) ) {
			$set[ $hash ] = 1;
			set_transient( SN_AUDIT_TRANSIENT_IPS, $set, SN_AUDIT_IPS_TTL );
			$blob['counters'][ $today ]['unique_ips_count'] = (int) ( $blob['counters'][ $today ]['unique_ips_count'] ?? 0 ) + 1;
		}
	}

	update_option( SN_AUDIT_OPTION, $blob, true );
}

/**
 * Record a successful login as a per-event row.
 * No IP stored — just timestamp + username.
 *
 * @param int    $user_id   The logged-in user's ID.
 * @param string $username  The login username (preserved as-stored, even if user later deleted).
 */
function snt_audit_record_login_success_impl( $user_id, $username ) {
	$blob = snt_audit_get_blob();
	$blob['login_success'][] = array(
		'ts'   => time(),
		'user' => (string) $username,
	);

	// Cap to SN_AUDIT_LOGIN_SUCCESS_CAP — drop oldest entries.
	if ( count( $blob['login_success'] ) > SN_AUDIT_LOGIN_SUCCESS_CAP ) {
		$blob['login_success'] = array_slice( $blob['login_success'], -SN_AUDIT_LOGIN_SUCCESS_CAP );
	}

	update_option( SN_AUDIT_OPTION, $blob, true );
}

/**
 * Return the last N days of counter data, newest-first.
 *
 * @param int $days How many trailing days. Default 30, max 90.
 * @return array<int,array> Each row: { date, login_failed, wp_login_404, wp_admin_unauth_404, lockout_triggered, password_reset, unique_ips_count }
 */
function snt_audit_get_counters_impl( $days = 30 ) {
	$days  = max( 1, min( SN_AUDIT_RETENTION_DAYS, (int) $days ) );
	$blob  = snt_audit_get_blob();
	$out   = array();
	$today = time();

	for ( $i = 0; $i < $days; $i++ ) {
		$date   = wp_date( 'Y-m-d', $today - $i * DAY_IN_SECONDS );
		$bucket = $blob['counters'][ $date ] ?? array();
		$out[]  = array(
			'date'                => $date,
			'login_failed'        => (int) ( $bucket['login_failed']        ?? 0 ),
			'wp_login_404'        => (int) ( $bucket['wp_login_404']        ?? 0 ),
			'wp_admin_unauth_404' => (int) ( $bucket['wp_admin_unauth_404'] ?? 0 ),
			'lockout_triggered'   => (int) ( $bucket['lockout_triggered']   ?? 0 ),
			'password_reset'      => (int) ( $bucket['password_reset']      ?? 0 ),
			'unique_ips_count'    => (int) ( $bucket['unique_ips_count']    ?? 0 ),
		);
	}

	return $out;
}

/**
 * Return the last N days of successful-login rows, newest-first.
 *
 * @param int $days How many trailing days. Default 30, max 90.
 * @return array<int,array> Each row: { ts, user, formatted }
 */
function snt_audit_get_login_successes_impl( $days = 30 ) {
	$days   = max( 1, min( SN_AUDIT_RETENTION_DAYS, (int) $days ) );
	$blob   = snt_audit_get_blob();
	$cutoff = time() - $days * DAY_IN_SECONDS;
	$out    = array();

	foreach ( $blob['login_success'] as $row ) {
		if ( (int) $row['ts'] < $cutoff ) {
			continue;
		}
		$out[] = array(
			'ts'        => (int) $row['ts'],
			'user'      => (string) $row['user'],
			'formatted' => wp_date( 'Y-m-d H:i:s', (int) $row['ts'] ),
		);
	}

	// Newest first.
	usort( $out, function( $a, $b ) {
		return $b['ts'] <=> $a['ts'];
	} );

	return $out;
}

/**
 * Build the hero-card summary: last 24h totals, last 7d trend, unique attackers 24h, LLA status.
 *
 * @return array { last_24h: { failed_total, recon_total, all_total }, last_7d_vs_prior: { current, prior, pct_delta }, unique_attackers_24h: int, lla: array }
 */
function snt_audit_get_summary_impl() {
	$counters_7  = snt_audit_get_counters_impl( 7 );  // includes today
	$counters_14 = snt_audit_get_counters_impl( 14 ); // for prior-7 comparison

	// Today's row (index 0).
	$today_row = $counters_7[0] ?? array();
	$last_24h  = array(
		'failed_total' => (int) ( $today_row['login_failed'] ?? 0 ),
		'recon_total'  => (int) ( $today_row['wp_login_404'] ?? 0 ) + (int) ( $today_row['wp_admin_unauth_404'] ?? 0 ),
		'all_total'    => 0,
	);
	foreach ( SN_AUDIT_COUNTER_TYPES as $type ) {
		$last_24h['all_total'] += (int) ( $today_row[ $type ] ?? 0 );
	}

	// 7-day vs prior-7-day trend. Sum all counter types across each window.
	$current_sum = 0;
	$prior_sum   = 0;
	for ( $i = 0; $i < 7; $i++ ) {
		foreach ( SN_AUDIT_COUNTER_TYPES as $type ) {
			$current_sum += (int) ( $counters_14[ $i ][ $type ] ?? 0 );
			$prior_sum   += (int) ( $counters_14[ $i + 7 ][ $type ] ?? 0 );
		}
	}
	$pct_delta = 0;
	if ( $prior_sum > 0 ) {
		$pct_delta = (int) round( ( ( $current_sum - $prior_sum ) / $prior_sum ) * 100 );
	} elseif ( $current_sum > 0 ) {
		$pct_delta = 100; // From-zero growth.
	}

	return array(
		'last_24h'             => $last_24h,
		'last_7d_vs_prior'     => array(
			'current'   => $current_sum,
			'prior'     => $prior_sum,
			'pct_delta' => $pct_delta,
		),
		'unique_attackers_24h' => (int) ( $today_row['unique_ips_count'] ?? 0 ),
		'lla'                  => snt_audit_read_lla_summary_impl(),
	);
}

/**
 * Drop counter buckets and login_success rows older than SN_AUDIT_RETENTION_DAYS.
 * Returns prune stats.
 *
 * Also: implements the LLA polling fallback for lockout_triggered counter
 * (LLA fires no action hook — verified 2026-05-25). Reads current
 * `limit_login_lockouts` size; if greater than last-known, adds the delta
 * to today's lockout_triggered counter.
 *
 * @return array { counter_buckets_dropped: int, login_rows_dropped: int, lla_delta: int }
 */
function snt_audit_prune_impl() {
	$blob        = snt_audit_get_blob();
	$cutoff      = strtotime( '-' . SN_AUDIT_RETENTION_DAYS . ' days' );
	$cutoff_date = wp_date( 'Y-m-d', $cutoff );

	// Prune counter buckets.
	$dropped_buckets = 0;
	foreach ( array_keys( $blob['counters'] ) as $date_key ) {
		if ( $date_key < $cutoff_date ) {
			unset( $blob['counters'][ $date_key ] );
			$dropped_buckets++;
		}
	}

	// Prune login_success rows.
	$before                = count( $blob['login_success'] );
	$blob['login_success'] = array_values( array_filter( $blob['login_success'], function( $row ) use ( $cutoff ) {
		return (int) $row['ts'] >= $cutoff;
	} ) );
	$dropped_rows = $before - count( $blob['login_success'] );

	// LLA polling fallback for lockout_triggered.
	$lla_delta   = 0;
	$lla_current = is_array( get_option( 'limit_login_lockouts', array() ) )
		? count( get_option( 'limit_login_lockouts', array() ) )
		: 0;
	$lla_last = (int) get_option( SN_AUDIT_LLA_LAST_COUNT_OPT, 0 );
	if ( $lla_current > $lla_last ) {
		$lla_delta = $lla_current - $lla_last;
		$today     = snt_audit_today_key();
		if ( ! isset( $blob['counters'][ $today ] ) ) {
			$blob['counters'][ $today ] = array_fill_keys( SN_AUDIT_COUNTER_TYPES, 0 );
			$blob['counters'][ $today ]['unique_ips_count'] = 0;
		}
		$blob['counters'][ $today ]['lockout_triggered'] = (int) ( $blob['counters'][ $today ]['lockout_triggered'] ?? 0 ) + $lla_delta;
	}
	update_option( SN_AUDIT_LLA_LAST_COUNT_OPT, $lla_current, false );

	update_option( SN_AUDIT_OPTION, $blob, true );

	return array(
		'counter_buckets_dropped' => $dropped_buckets,
		'login_rows_dropped'      => $dropped_rows,
		'lla_delta'               => $lla_delta,
	);
}

/**
 * Safe read of LLA's `limit_login_lockouts` option.
 * Returns count + most-recent timestamp. Tolerates schema drift / missing option.
 *
 * @return array { active_lockouts: int, most_recent_lockout_ts: int|null }
 */
function snt_audit_read_lla_summary_impl() {
	$lockouts = get_option( 'limit_login_lockouts', array() );
	if ( ! is_array( $lockouts ) ) {
		return array( 'active_lockouts' => 0, 'most_recent_lockout_ts' => null );
	}

	$count       = count( $lockouts );
	$most_recent = null;
	foreach ( $lockouts as $ts ) {
		if ( is_numeric( $ts ) && (int) $ts > (int) $most_recent ) {
			$most_recent = (int) $ts;
		}
	}

	return array(
		'active_lockouts'        => $count,
		'most_recent_lockout_ts' => $most_recent,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * Event capture hook callbacks.
 *
 * NOTE: lockout_triggered uses a polling fallback (in snt_audit_prune_impl)
 * because LLA fires no action hook. Verified 2026-05-25 — only LLA
 * do_action calls in core are `llar_plugin_version_updated` and
 * `llar_mfa_generate_codes`. Polling kicks in via the daily prune tick.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * wp_login action callback. Records the per-event row.
 *
 * @param string  $user_login Username that logged in.
 * @param WP_User $user       User object.
 */
function snt_audit_capture_login_success_cb( $user_login, $user ) {
	$user_id = $user instanceof WP_User ? (int) $user->ID : 0;
	snt_audit_record_login_success_impl( $user_id, $user_login );
}
add_action( 'wp_login', 'snt_audit_capture_login_success_cb', 10, 2 );

/**
 * wp_login_failed action callback. Increments the daily counter + updates unique-IPs.
 *
 * @param string $username The username that failed auth (unused for now; future PII consideration).
 */
function snt_audit_capture_login_failed_cb( $username ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	snt_audit_increment_counter_impl( 'login_failed', $ip );
}
add_action( 'wp_login_failed', 'snt_audit_capture_login_failed_cb', 10, 1 );

/**
 * after_password_reset action callback. Increments the daily counter.
 * No IP capture — password_reset is post-auth, IP context not meaningful.
 *
 * @param WP_User $user The user whose password was reset (unused; future use possible).
 */
function snt_audit_capture_password_reset_cb( $user ) {
	snt_audit_increment_counter_impl( 'password_reset' );
}
add_action( 'after_password_reset', 'snt_audit_capture_password_reset_cb', 10, 1 );

/* ════════════════════════════════════════════════════════════════════════
 * Retention cron registration.
 *
 * Daily cron `sn_audit_log_prune` fires snt_audit_prune_impl(). Scheduled
 * via init hook (idempotent: check wp_next_scheduled before scheduling).
 * Cron-dashboard module (inc/cron-dashboard.php) will automatically pick
 * this up and display it in the SN admin Cron tab.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'init', function() {
	if ( ! wp_next_scheduled( SN_AUDIT_PRUNE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_AUDIT_PRUNE_HOOK );
	}
} );

add_action( SN_AUDIT_PRUNE_HOOK, 'snt_audit_prune_impl' );

/* ════════════════════════════════════════════════════════════════════════
 * REST routes — co-located here (not in inc/rest-api.php) for cohesion.
 * All require manage_options. Surface 2 of 4 in the 4-surface dispatch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	$manage_options_cap = function() {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'signal-noise/v1', '/audit/summary', array(
		'methods'             => 'GET',
		'callback'            => function() {
			return new WP_REST_Response( snt_audit_get_summary_impl(), 200 );
		},
		'permission_callback' => $manage_options_cap,
	) );

	register_rest_route( 'signal-noise/v1', '/audit/counters', array(
		'methods'             => 'GET',
		'callback'            => function( $request ) {
			$days = (int) ( $request->get_param( 'days' ) ?: 30 );
			return new WP_REST_Response( snt_audit_get_counters_impl( $days ), 200 );
		},
		'permission_callback' => $manage_options_cap,
		'args'                => array(
			'days' => array(
				'required'          => false,
				'default'           => 30,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	register_rest_route( 'signal-noise/v1', '/audit/login-successes', array(
		'methods'             => 'GET',
		'callback'            => function( $request ) {
			$days = (int) ( $request->get_param( 'days' ) ?: 30 );
			return new WP_REST_Response( snt_audit_get_login_successes_impl( $days ), 200 );
		},
		'permission_callback' => $manage_options_cap,
		'args'                => array(
			'days' => array(
				'required'          => false,
				'default'           => 30,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	register_rest_route( 'signal-noise/v1', '/audit/prune', array(
		'methods'             => 'POST',
		'callback'            => function() {
			return new WP_REST_Response( snt_audit_prune_impl(), 200 );
		},
		'permission_callback' => $manage_options_cap,
	) );
} );
