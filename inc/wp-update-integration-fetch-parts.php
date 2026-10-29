<?php
/**
 * Signal & Noise Tools: WP-native update integration, the install check and the GitHub fetch (the basename mismatch and its notice, the header compat trio, the failure reason, the secret redaction, the failure record and its lifetimes).
 *
 * Split from inc/wp-update-integration.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this plugin is installed somewhere other than SN_GH_PLUGIN_BASENAME says.
 *
 * WHY THIS EXISTS (v12.25.0, 2026-08-24)
 *
 * SN_GH_PLUGIN_BASENAME is hardcoded, and everything in this file keys off it:
 * the entry we write into WP's `update_plugins` transient, and the gate on
 * upgrader_source_selection.
 * There are therefore two identities that must agree — the directory WordPress
 * actually loaded us from, and the basename we CLAIM — and WP core never
 * reconciles them.
 *
 * When they diverge the failure is silent by construction: we announce an
 * update for a plugin WordPress does not have installed, so no update row
 * renders anywhere, and clearing caches cannot help because the transient is
 * rebuilt with the same wrong key. The only route to new code becomes
 * delete-and-reinstall — which is precisely the path that CAUSES the
 * divergence, since GitHub's tag archive unpacks to
 * `signal-and-noise-tools-<version>/` and the rename filter below gates on
 * `$hook_extra['plugin']`, which is unset for a manual Upload Plugin.
 *
 * So: assert it, and say so out loud. Returns '' when correct, otherwise the
 * basename WordPress actually loaded — the wrong value is the useful one.
 *
 * @since 12.25.0
 * @return string '' when correct, else the actual basename.
 */
function sn_plugin_basename_mismatch() {
	if ( ! defined( 'SNT_PATH' ) || ! function_exists( 'plugin_basename' ) ) {
		return '';
	}

	$actual = (string) plugin_basename( SNT_PATH . 'signal-and-noise-tools.php' );
	if ( '' === $actual || SN_GH_PLUGIN_BASENAME === $actual ) {
		return '';
	}

	return $actual;
}

/**
 * The door for the assertion above. An instrument nobody reads is not a check,
 * so a mismatch gets a notice that names both directories and the fix.
 *
 * Scoped to users who could actually act on it — a subscriber seeing this
 * learns nothing and can do nothing about it.
 *
 * @since 12.25.0
 * @return void
 */
function sn_plugin_basename_mismatch_notice() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$actual = sn_plugin_basename_mismatch();
	if ( '' === $actual ) {
		return;
	}

	wp_admin_notice(
		'<p><strong>'
		. esc_html__( 'Signal & Noise Tools: self-updates are disabled.', 'signal-and-noise-tools' )
		. '</strong></p><p>'
		. sprintf(
			/* translators: 1: actual plugin basename, 2: expected plugin basename. */
			esc_html__( 'WordPress loaded this plugin from %1$s, but its updater is hardcoded to %2$s. Because those disagree, no update will ever appear on the Updates screen, and clearing caches will not help.', 'signal-and-noise-tools' ),
			'<code>' . esc_html( $actual ) . '</code>',
			'<code>' . esc_html( SN_GH_PLUGIN_BASENAME ) . '</code>'
		)
		. '</p><p>'
		. esc_html__( 'Fix: reinstall the plugin so its directory is named signal-and-noise-tools. A plain Upload Plugin of a GitHub tag archive keeps the version suffix, which is what causes this.', 'signal-and-noise-tools' )
		. '</p>',
		array( 'type' => 'error', 'paragraph_wrap' => false )
	);
}
add_action( 'admin_notices', 'sn_plugin_basename_mismatch_notice' );


/**
 * Turn a failed tags fetch into a short sentence a human can act on.
 *
 * WHY THIS EXISTS (v9.54.0, after a live incident)
 *
 * Both Dashboard cards showed a red "unknown" and nothing, anywhere, said why.
 * A 401 (dead/expired SNT_GITHUB_TOKEN), a 403 (rate limit), a 404 (repo gone)
 * and a network timeout all collapsed into the same `return null`. Diagnosing it
 * meant reading this source, timing the endpoint from a laptop, and probing
 * GitHub's 401 header behaviour — to recover a fact this function had in its
 * hand and dropped.
 *
 * It was worse than uninformative. The Dashboard's "GitHub API: 4,971/5,000"
 * readout still looked healthy, because the rate monitor only records a
 * snapshot from responses that CARRY x-ratelimit-* headers — and GitHub's 401
 * for a bad credential carries none, while a WP_Error never reaches the
 * http_response filter at all. So the one number on screen that looked like
 * evidence was a fossil of the last success. A cache that only updates on
 * success cannot report failure; it poses as healthy exactly when it isn't.
 *
 * Same rule as the v9.47.2 janitor: never silent. The fetch still fails the
 * same way — this only stops the REASON from being thrown away.
 *
 * @since 9.54.0
 * @param array|WP_Error $response The wp_remote_get() return.
 * @return string A short human-readable reason. Never contains a credential.
 */
/**
 * The compatibility trio the self-updater reports, read from the plugin
 * file HEADER. (v14.5.1)
 *
 * Two literals here said `tested = '7.0'` while the header said 7.1, and
 * core paints the updater's value: the Updates page read "Compatibility
 * with WordPress 7.1: Not tested" on a site running 7.1 with a plugin whose
 * header said it was. One source — the header — so the two cannot drift
 * again. A plain regex read, not get_file_data(): this runs inside update
 * filters and in the standalone harness alike.
 *
 * @return array{requires:string,tested:string,requires_php:string}
 */
function sn_gh_plugin_compat() {
	static $compat = null;
	if ( null !== $compat ) {
		return $compat;
	}
	$file   = defined( 'SNT_PATH' ) ? SNT_PATH . 'signal-and-noise-tools.php' : dirname( __DIR__ ) . '/signal-and-noise-tools.php';
	$head   = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 8192 ) : '';
	$read   = static function ( $label, $fallback ) use ( $head ) {
		return preg_match( '/^\s*\*\s*' . preg_quote( $label, '/' ) . ':\s*([0-9.]+)/mi', $head, $m ) ? $m[1] : $fallback;
	};
	$compat = array(
		'requires'     => $read( 'Requires at least', '7.0' ),
		'tested'       => $read( 'Tested up to', '7.0' ),
		'requires_php' => $read( 'Requires PHP', '8.3' ),
	);
	return $compat;
}

function sn_gh_fetch_failure_reason( $response ) {
	if ( is_wp_error( $response ) ) {
		// No HTTP response at all — timeout, DNS, TLS. This is the case the
		// frozen rate readout hides best, so carry the real driver message
		// ("cURL error 28: Operation timed out after 5001 ms") rather than a
		// generic "network error": the number in it is the actual diagnosis.
		return sn_gh_redact_secrets( sprintf(
			/* translators: %s: underlying HTTP error message. */
			__( 'could not reach GitHub. %s', 'signal-and-noise-tools' ),
			$response->get_error_message()
		) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	switch ( $code ) {
		case 401:
			// The one failure a site owner can fix in 30 seconds, and the one
			// the old code hid most completely. Name the constant.
			return __( 'GitHub rejected the credential (401). SNT_GITHUB_TOKEN in wp-config.php is invalid, expired, or revoked', 'signal-and-noise-tools' );
		case 403:
			return __( 'GitHub refused the request (403): usually a rate limit; set SNT_GITHUB_TOKEN in wp-config.php to raise 60/h to 5000/h', 'signal-and-noise-tools' );
		case 404:
			return __( 'GitHub returned 404: the repository was renamed, deleted, or made private', 'signal-and-noise-tools' );
		case 200:
			return __( 'GitHub returned 200 but the body was not a readable tag list', 'signal-and-noise-tools' );
		default:
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'GitHub returned an unexpected HTTP %d', 'signal-and-noise-tools' ),
				$code
			);
	}
}

/**
 * Strip anything token-shaped out of a message before it can reach a screen.
 *
 * The reason string is rendered in wp-admin and exposed over MCP/REST. An HTTP
 * driver message is not ours and could, in principle, quote a request header
 * back at us. Redact defensively rather than reason about whether cURL ever
 * does: the cost is a regex, and the failure mode is a leaked credential.
 *
 * @since 9.54.0
 * @param string $message
 * @return string
 */
function sn_gh_redact_secrets( $message ) {
	return (string) preg_replace(
		'/\b(gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,}|Bearer\s+\S+)/i',
		'[redacted]',
		(string) $message
	);
}

/**
 * Record why a fetch failed, alongside the empty-sentinel negative cache.
 *
 * @since 9.54.0
 * @param string $reason
 * @return null Always null — callers `return sn_gh_record_fetch_failure(...)`.
 */
function sn_gh_record_fetch_failure( $reason, $code = null ) {
	$ttl = sn_gh_failure_ttl( $code );
	set_site_transient( SN_GH_PLUGIN_CACHE_KEY, '', $ttl );
	set_site_transient( SN_GH_PLUGIN_ERROR_KEY, $reason, $ttl );
	return null;
}

/**
 * Will this failure plausibly have fixed itself in five minutes?
 *
 * WHY THIS EXISTS (v9.54.1, after a live incident)
 *
 * 2026-07-16 22:51 UTC, GitHub declared "Degraded REST API Availability" —
 * ~35% of REST requests failing, "not consistently reaching the application
 * layer". Our tags fetch caught a 503 and both version cards went red four
 * minutes later.
 *
 * The 503 was GitHub's. The SIXTY MINUTES of blindness was ours: any failure
 * cached the empty sentinel for HOUR_IN_SECONDS, so a blip lasting one second
 * cost a full hour — then the next hourly poll had another 35% chance of
 * re-arming it. The dashboard could stay dark for the entire incident and
 * beyond.
 *
 * The tell sat on the same screen the whole time: "Recent deploys" stayed live
 * and correct throughout, because its sibling fetch (github-actions-api.php)
 * caches failures for FIVE MINUTES and self-heals. Same host, same token, same
 * 5s timeout — only the failure TTL differed. (Worth remembering: we first
 * blamed the token, then the response size, then the timeout. The asymmetry was
 * a constant, one file over.)
 *
 * So classify by whether re-asking could plausibly get a different answer:
 *   - 5xx / network / timeout → the far end is unwell. It recovers on its own.
 *   - 401 / 404               → nothing changes in an hour. Don't hammer it.
 *
 * @since 9.54.1
 * @param int|null $code HTTP status, 0 for a WP_Error, null when unknown.
 * @return bool
 */
function sn_gh_failure_is_transient( $code ) {
	$code = (int) $code;
	// 0 = WP_Error: timeout, DNS, TLS, connection reset. The box may just be
	// busy — treat as transient. Same for anything the far end calls a server
	// error (500/502/503/504) and for 429 (explicit "come back shortly").
	return 0 === $code || 429 === $code || $code >= 500;
}

/**
 * How long to hold a failure before asking GitHub again.
 *
 * @since 9.54.1
 * @param int|null $code HTTP status, 0 for a WP_Error, null when unknown.
 * @return int Seconds.
 */
function sn_gh_failure_ttl( $code ) {
	// Transient: match github-actions-api.php's SNT_GH_RUNS_FAIL_TTL, the value
	// that demonstrably rode out the 2026-07-16 incident without ever going
	// dark. Durable: an hour, because a dead credential or a deleted repo will
	// answer identically five minutes from now and the poll is pure noise.
	return sn_gh_failure_is_transient( $code ) ? SN_GH_FAIL_TTL_TRANSIENT : SN_GH_FAIL_TTL_DURABLE;
}

/**
 * Why the last tag fetch failed, or '' if the last one succeeded.
 *
 * @since 9.54.0
 * @return string
 */
function sn_gh_latest_plugin_tag_error() {
	$reason = get_site_transient( SN_GH_PLUGIN_ERROR_KEY );
	return is_string( $reason ) ? $reason : '';
}

