<?php
/**
 * Signal & Noise Tools — identity-salt window readout (Measurement → Analytics).
 *
 * A PASSIVE date-window readout (not a Health check, not an alarm) of the
 * analytics Worker's daily-rotating visitor-identity salt — the forward-secrecy
 * transparency artifact: yesterday's salt is deleted at rotation, so the window
 * of what the operator could ever correlate is visible as plain dates.
 *
 * Data source: the SAME read-only `GET /_sn/version` endpoint the worker-version
 * card probes (derived from the admin-configured collector base, hairpin-safe).
 * Worker v1.14.0+ adds a top-level public "salt" object:
 *   { rotate_tz, today_day, today_present, today_expires_at, prev_day,
 *     prev_present, prev_expires_at, next_present, key_count }
 * Salt VALUES never appear anywhere — key names are dates, expirations are unix
 * seconds; this is public by design. On a KV list failure the whole "salt"
 * member is null (never a fabricated shape).
 *
 * Absent-vs-null discipline (the realtime-zero-vs-null rule, via
 * array_key_exists throughout): a MISSING "salt" member means the deployed
 * worker predates the readout; "salt": null means the worker answered but could
 * not list its keys. Different answers, rendered differently. Within the
 * window, an absent field degrades to null — the renderer skips the line rather
 * than inventing a value.
 *
 * Security: same outbound gate as every probe here — https-only +
 * wp_http_validate_url() + the shared sn_ssrf_host_blocked() + redirection=0.
 * Read-only GET, admin-only render, ~10-min transient cache (readout freshness,
 * not monitoring).
 *
 * @package SignalNoiseTools
 * @since 9.71.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SALT_WINDOW_TRANSIENT = 'sn_salt_window_probe';
const SN_SALT_WINDOW_TTL_OK    = 600; // 10 min — readout freshness, not monitoring.
const SN_SALT_WINDOW_TTL_FAIL  = 120; // 2 min  — retry sooner after a failure.
const SN_SALT_WINDOW_TIMEOUT   = 4;   // seconds — keep the settings page responsive.

/**
 * The Worker's /_sn/version URL — reuses the worker-version card's derivation
 * (collector-base origin + sibling path) so the two readouts can never probe
 * different origins. '' when underivable (caller fails closed).
 *
 * @since 9.71.0
 * @return string
 */
function sn_salt_window_endpoint_url() {
	return function_exists( 'sn_worker_version_endpoint_url' ) ? sn_worker_version_endpoint_url() : '';
}

/**
 * Normalize the raw "salt" object into the fixed-contract window. Pure.
 * array_key_exists discipline: an absent field is null (unknown), never an
 * invented value; a present null stays null; types are strict — a non-bool
 * presence flag or non-numeric expiry degrades to null rather than coercing.
 *
 * @since 9.71.0
 * @param array $salt Decoded "salt" object from the Worker.
 * @return array Normalized window (all nine contract keys always present).
 */
function sn_salt_window_normalize( $salt ) {
	$str = static function ( $key ) use ( $salt ) {
		return array_key_exists( $key, $salt ) && is_scalar( $salt[ $key ] ) && ! is_bool( $salt[ $key ] )
			? sanitize_text_field( (string) $salt[ $key ] )
			: '';
	};
	$bool_or_null = static function ( $key ) use ( $salt ) {
		return array_key_exists( $key, $salt ) && is_bool( $salt[ $key ] ) ? $salt[ $key ] : null;
	};
	$int_or_null = static function ( $key ) use ( $salt ) {
		return array_key_exists( $key, $salt ) && is_numeric( $salt[ $key ] ) ? (int) $salt[ $key ] : null;
	};
	return array(
		'rotate_tz'        => $str( 'rotate_tz' ),
		'today_day'        => $str( 'today_day' ),
		'today_present'    => $bool_or_null( 'today_present' ),
		'today_expires_at' => $int_or_null( 'today_expires_at' ),
		'prev_day'         => $str( 'prev_day' ),
		'prev_present'     => $bool_or_null( 'prev_present' ),
		'prev_expires_at'  => $int_or_null( 'prev_expires_at' ),
		'next_present'     => $bool_or_null( 'next_present' ),
		'key_count'        => $int_or_null( 'key_count' ),
	);
}

/**
 * Parse a /_sn/version HTTP response into a salt-window result. Pure — no I/O.
 * States (each rendered differently — absent and null are different answers):
 *   - 'unreachable': non-200 / non-JSON body (transport or proxy failure).
 *   - 'old-worker':  valid JSON with NO "salt" member — pre-v1.14.0 worker.
 *   - 'kv-failed':   "salt": null (the worker answered but could not list its
 *                    keys) or a contract-violating non-array — never a window.
 *   - 'ok':          a normalized window.
 *
 * @since 9.71.0
 * @param int    $code HTTP status.
 * @param string $body Response body.
 * @return array{state:string,window:?array}
 */
function sn_salt_window_parse_response( $code, $body ) {
	if ( 200 !== (int) $code ) {
		return array(
			'state'  => 'unreachable',
			'window' => null,
		);
	}
	$json = json_decode( (string) $body, true );
	if ( ! is_array( $json ) ) {
		return array(
			'state'  => 'unreachable',
			'window' => null,
		);
	}
	// array_key_exists, NOT isset/??: "salt": null must land in kv-failed, not here.
	if ( ! array_key_exists( 'salt', $json ) ) {
		return array(
			'state'  => 'old-worker',
			'window' => null,
		);
	}
	if ( ! is_array( $json['salt'] ) ) {
		// null (the contract's KV-failure signal) or a shape violation — either
		// way the window could not be read; never fabricate one.
		return array(
			'state'  => 'kv-failed',
			'window' => null,
		);
	}
	return array(
		'state'  => 'ok',
		'window' => sn_salt_window_normalize( $json['salt'] ),
	);
}

/**
 * Probe /_sn/version NOW (no cache). Same outbound gate as the worker-version
 * probe: https-only + core URL validation + the shared resolve-then-range-check
 * SSRF guard + redirection=0.
 *
 * @since 9.71.0
 * @return array{state:string,window:?array,url:string,fetched_at:int}
 */
function sn_salt_window_probe() {
	$url   = sn_salt_window_endpoint_url();
	$stamp = array(
		'url'        => $url,
		'fetched_at' => time(),
	);

	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( '' === $url
		|| ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return array_merge(
			array(
				'state'  => 'unreachable',
				'window' => null,
			),
			$stamp
		);
	}

	$resp = wp_remote_get(
		$url,
		array(
			'timeout'     => SN_SALT_WINDOW_TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : 'dev' ) . ' salt-window',
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return array_merge(
			array(
				'state'  => 'unreachable',
				'window' => null,
			),
			$stamp
		);
	}

	return array_merge(
		sn_salt_window_parse_response(
			(int) wp_remote_retrieve_response_code( $resp ),
			(string) wp_remote_retrieve_body( $resp )
		),
		$stamp
	);
}

/**
 * Transient-cached probe result (~10 min on success, shorter after a failure so
 * a blip retries sooner). $force bypasses the cache — the renderer wires it to
 * the worker-version card's nonce-verified "Re-check now", so one click
 * refreshes BOTH readouts of the shared /_sn/version endpoint (adjacent cards
 * from one endpoint must never disagree after an explicit re-check).
 *
 * @since 9.71.0
 * @param bool $force Bypass the transient and probe live.
 * @return array
 */
function sn_salt_window_get( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( SN_SALT_WINDOW_TRANSIENT );
		if ( is_array( $cached ) && array_key_exists( 'state', $cached ) ) {
			return $cached;
		}
	}

	$result = sn_salt_window_probe();
	set_transient(
		SN_SALT_WINDOW_TRANSIENT,
		$result,
		'ok' === $result['state'] ? SN_SALT_WINDOW_TTL_OK : SN_SALT_WINDOW_TTL_FAIL
	);
	return $result;
}

/**
 * Format a unix expiry as a site-local timestamp plus a relative phrase —
 * "2026-07-18 03:00 (in 2 days)" / "… (2 days ago)" (the deploy widget's
 * "X ago" voice).
 *
 * @since 9.71.0
 * @param int $ts Unix seconds.
 * @return string
 */
function sn_salt_window_format_expiry( $ts ) {
	$ts  = (int) $ts;
	$now = time();
	$rel = $ts >= $now
		/* translators: %s: human-readable time interval, e.g. "2 days" */
		? sprintf( __( 'in %s', 'signal-and-noise-tools' ), human_time_diff( $now, $ts ) )
		/* translators: %s: human-readable time interval, e.g. "2 days" */
		: sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( $ts, $now ) );
	return wp_date( 'Y-m-d H:i', $ts ) . ' (' . $rel . ')';
}

/**
 * Render the "Identity salt window" readout into the analytics settings
 * reference column (after the worker-version card). Calm and informational —
 * an info notice at best, an em-dash line per failure state; never a red
 * alarm, never a fabricated date. The failure states stay DISTINCT: kv-failed
 * (the worker answered; its own KV list failed) never wears the failed-fetch
 * copy — "could not read the worker" would be factually false there and send
 * the operator curling a healthy endpoint. The worker-version card's
 * nonce-verified "Re-check now" (rendered directly above) forces a live probe
 * here too. Admin-only.
 *
 * @since 9.71.0
 */
function sn_salt_window_render_card() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result = sn_salt_window_get(
		function_exists( 'sn_worker_version_recheck_requested' ) && sn_worker_version_recheck_requested()
	);
	$state  = is_array( $result ) && array_key_exists( 'state', $result ) ? (string) $result['state'] : 'unreachable';

	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Identity salt window', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'The visitor-identity salt rotates daily at the edge and yesterday’s is deleted: forward secrecy by construction. Key names are dates and expiry times only; salt values never leave the Worker.', 'signal-and-noise-tools' ) . '</p>';

	if ( 'old-worker' === $state ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'Worker predates the salt window readout (needs v1.14.0+).', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	if ( 'kv-failed' === $state ) {
		// The worker WAS read — a 200 with valid JSON whose "salt" is null means
		// its own KV list failed at the edge. Saying "could not read the worker"
		// here would be false and misdirect the diagnosis (a curl of /_sn/version
		// comes back clean while the card claims unreachable).
		echo '<p class="sn-an-empty">' . esc_html__( 'worker reachable, but it could not list its salt keys (KV read failed at the edge).', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	if ( 'ok' !== $state || ! is_array( $result['window'] ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'could not read the worker.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	$w  = $result['window'];
	$tz = '' !== $w['rotate_tz'] ? $w['rotate_tz'] : 'UTC';

	echo '<div class="notice notice-info notice-alt inline">';

	// Today's salt day + when it rotates. The not-minted parenthetical renders
	// only on an explicit false — a null (absent upstream) presence stays quiet.
	$today_day = '' !== $w['today_day'] ? $w['today_day'] : '—';
	if ( false === $w['today_present'] ) {
		/* translators: 1: today's salt day (YYYY-MM-DD), 2: the worker's rotation timezone */
		echo '<p>' . esc_html( sprintf( __( 'Today’s salt: %1$s (not minted yet (it appears with the first visit of the day)) rotates at midnight (%2$s).', 'signal-and-noise-tools' ), $today_day, $tz ) ) . '</p>';
	} else {
		/* translators: 1: today's salt day (YYYY-MM-DD), 2: the worker's rotation timezone */
		echo '<p>' . esc_html( sprintf( __( 'Today’s salt: %1$s: rotates at midnight (%2$s).', 'signal-and-noise-tools' ), $today_day, $tz ) ) . '</p>';
	}

	// Yesterday's salt: expiring, already gone, or expiry unrecorded. A null
	// presence (absent upstream) skips the line — nothing is invented.
	if ( false === $w['prev_present'] ) {
		/* translators: %s: yesterday's salt day (YYYY-MM-DD) */
		echo '<p>' . esc_html( sprintf( __( 'Yesterday’s salt (%s) has already expired: forward secrecy holding.', 'signal-and-noise-tools' ), '' !== $w['prev_day'] ? $w['prev_day'] : '—' ) ) . '</p>';
	} elseif ( true === $w['prev_present'] ) {
		if ( null !== $w['prev_expires_at'] ) {
			/* translators: 1: yesterday's salt day (YYYY-MM-DD), 2: site-local expiry time with a relative phrase */
			echo '<p>' . esc_html( sprintf( __( 'Yesterday’s salt (%1$s) expires %2$s.', 'signal-and-noise-tools' ), $w['prev_day'], sn_salt_window_format_expiry( $w['prev_expires_at'] ) ) ) . '</p>';
		} else {
			/* translators: %s: yesterday's salt day (YYYY-MM-DD) */
			echo '<p>' . esc_html( sprintf( __( 'Yesterday’s salt (%s) has no expiry recorded.', 'signal-and-noise-tools' ), $w['prev_day'] ) ) . '</p>';
		}
	}

	if ( null !== $w['key_count'] ) {
		/* translators: %s: number of salt keys currently at the edge */
		echo '<p>' . esc_html( sprintf( _n( '%s salt key at the edge.', '%s salt keys at the edge.', $w['key_count'], 'signal-and-noise-tools' ), number_format_i18n( $w['key_count'] ) ) ) . '</p>';
	}

	$fetched_at = isset( $result['fetched_at'] ) ? (int) $result['fetched_at'] : 0;
	if ( $fetched_at > 0 ) {
		/* translators: %s: human-readable time interval, e.g. "2 minutes" */
		echo '<p class="sn-an-empty">' . esc_html( sprintf( __( 'Checked %s ago.', 'signal-and-noise-tools' ), human_time_diff( $fetched_at, time() ) ) ) . '</p>';
	}

	echo '</div>';
}
