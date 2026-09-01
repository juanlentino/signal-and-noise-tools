<?php
/**
 * Signal & Noise Tools — breached-credential rejection, Mode B: LOGIN-TIME,
 * ADVISORY, FAIL-OPEN, MEMOIZED (Phase 2 of
 * docs/proposals/breached-credential-check-2026-08-31.md).
 *
 * Mode A (v13.58.0) cannot see a password set before it shipped, which on day
 * one is every password on the site. The stored value is a hash and can never
 * be checked at rest; the only other moment the plaintext exists is the login
 * submit. So this runs there — and its constraints are the INVERSE of Mode A's:
 * frequent, latency-sensitive, and it must never lock anyone out.
 *
 *  - It WARNS, never blocks. The `authenticate` filter result is returned
 *    untouched on every path; a breached password logs in and sees a notice.
 *  - A failed lookup is DROPPED silently (fail-open), and a short site-wide
 *    backoff stops an outage from adding a network round-trip to every login.
 *  - MEMOIZED against the stored hash: a user-meta record keyed to a short
 *    digest of `user_pass` means the check runs at most once per password, not
 *    once per login, and self-invalidates when the password changes (the
 *    digest changes with it). Keying on the stored hash stores nothing that is
 *    not already in the database — nothing is keyed on, derived from, or
 *    persisted from the plaintext.
 *
 * Which plaintext: only one that verifies against the account's own hash
 * (wp_check_password). An application password reaching this filter verifies
 * against nothing here, so it is skipped by construction — those are
 * generated, never in a corpus, and memoizing their verdict on the ACCOUNT
 * hash would be a wrong answer recorded under the wrong key.
 *
 * What leaves the origin: the 5-char SHA-1 prefix (inc/breached-credentials.php).
 * Never the plaintext, never its full SHA-1, never logged.
 *
 * Kill switch: define( 'SN_HIBP_LOGIN_DISABLED', true ).
 *
 * @package SignalNoiseTools
 * @since 13.59.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_HIBP_LOGIN_MEMO_META     = 'sn_hibp_login_memo';
const SN_HIBP_LOGIN_BACKOFF_KEY   = 'sn_hibp_login_backoff';
const SN_HIBP_LOGIN_BACKOFF_SECS  = 900; // 15 minutes: an outage costs at most one round-trip per window, site-wide.
const SN_HIBP_LOGIN_TIMEOUT_SECS  = 2;   // Shorter than Mode A's 4s: this sits on the login path.
const SN_HIBP_LOGIN_DIGEST_CHARS  = 16;

/** The kill switch: a constant, never an option. */
function sn_hibp_login_disabled() {
	return defined( 'SN_HIBP_LOGIN_DISABLED' ) && SN_HIBP_LOGIN_DISABLED;
}

/**
 * The memo key for a stored hash. PURE. Short on purpose: it identifies WHICH
 * password the verdict belongs to, and nothing more.
 *
 * @param string $user_pass The value already in wp_users.user_pass.
 * @return string
 */
function sn_hibp_login_digest( $user_pass ) {
	return substr( hash( 'sha256', (string) $user_pass ), 0, SN_HIBP_LOGIN_DIGEST_CHARS );
}

/**
 * Should this login trigger a lookup? PURE.
 *
 * No when the memo already answers for THIS hash (any verdict), or when the
 * site-wide backoff is active. Yes otherwise — including when the memo is for
 * a previous password: the digest mismatch is exactly the self-invalidation.
 *
 * @param array|null $memo    The stored memo {digest, verdict, count, checked_at}.
 * @param string     $digest  Digest of the current stored hash.
 * @param bool       $backoff Backoff transient present.
 * @return bool
 */
function sn_hibp_login_should_check( $memo, $digest, $backoff ) {
	if ( $backoff ) {
		return false;
	}
	if ( is_array( $memo ) && (string) ( $memo['digest'] ?? '' ) === (string) $digest && '' !== $digest ) {
		return false;
	}
	return true;
}

/**
 * The memo to store for a verdict, or null when nothing must be stored. PURE.
 *
 * UNAVAILABLE stores NOTHING: memoizing it would either read as clean (a lie)
 * or pin "unknown" past the outage. The next login after the backoff retries.
 *
 * @param string $verdict SN_HIBP_* verdict.
 * @param int    $count
 * @param string $digest
 * @param int    $now
 * @return array{digest:string,verdict:string,count:int,checked_at:int}|null
 */
function sn_hibp_login_memo_for( $verdict, $count, $digest, $now ) {
	if ( SN_HIBP_BREACHED !== $verdict && SN_HIBP_NOT_BREACHED !== $verdict ) {
		return null;
	}
	return array(
		'digest'     => (string) $digest,
		'verdict'    => (string) $verdict,
		'count'      => SN_HIBP_BREACHED === $verdict ? (int) $count : 0,
		'checked_at' => (int) $now,
	);
}

/**
 * authenticate @30: after core has verified the password (priority 20).
 *
 * Returns $user UNTOUCHED on every path. This function has no way to fail a
 * login and must never grow one.
 *
 * @param WP_User|WP_Error|null $user
 * @param string                $username
 * @param string                $password
 * @return WP_User|WP_Error|null
 */
function sn_hibp_on_authenticate( $user, $username = '', $password = '' ) {
	unset( $username );
	if ( sn_hibp_login_disabled() || ! ( $user instanceof WP_User ) || '' === (string) $password ) {
		return $user;
	}
	if ( ! function_exists( 'sn_hibp_check_password' ) || ! function_exists( 'wp_check_password' ) || ! function_exists( 'get_user_meta' ) ) {
		return $user;
	}
	$stored = (string) ( $user->user_pass ?? '' );
	if ( '' === $stored || ! wp_check_password( (string) $password, $stored, (int) $user->ID ) ) {
		return $user; // Not the account password (an application password, or a stub): nothing to memoize under this hash.
	}

	$digest  = sn_hibp_login_digest( $stored );
	$memo    = get_user_meta( (int) $user->ID, SN_HIBP_LOGIN_MEMO_META, true );
	$backoff = function_exists( 'get_transient' ) ? (bool) get_transient( SN_HIBP_LOGIN_BACKOFF_KEY ) : false;
	if ( ! sn_hibp_login_should_check( is_array( $memo ) ? $memo : null, $digest, $backoff ) ) {
		return $user;
	}

	$result = sn_hibp_check_password( (string) $password, SN_HIBP_LOGIN_TIMEOUT_SECS );
	$record = sn_hibp_login_memo_for( (string) ( $result['verdict'] ?? '' ), (int) ( $result['count'] ?? 0 ), $digest, time() );
	if ( null === $record ) {
		// Fail-OPEN, silently — and back off site-wide so an outage costs one
		// round-trip per window, not one per login.
		if ( function_exists( 'set_transient' ) ) {
			set_transient( SN_HIBP_LOGIN_BACKOFF_KEY, 1, SN_HIBP_LOGIN_BACKOFF_SECS );
		}
		return $user;
	}
	if ( function_exists( 'update_user_meta' ) ) {
		update_user_meta( (int) $user->ID, SN_HIBP_LOGIN_MEMO_META, $record );
	}
	return $user;
}

/**
 * The current user's standing verdict, for the notice and the Phase 3 surface.
 *
 * @param int $user_id
 * @return array{digest:string,verdict:string,count:int,checked_at:int}|null null = never checked for the current password.
 */
function sn_hibp_login_memo( $user_id ) {
	if ( ! function_exists( 'get_user_meta' ) ) {
		return null;
	}
	$memo = get_user_meta( (int) $user_id, SN_HIBP_LOGIN_MEMO_META, true );
	return is_array( $memo ) && isset( $memo['verdict'] ) ? $memo : null;
}

/**
 * The notice text for a breached memo, or '' when there is nothing to say. PURE.
 *
 * @param array|null $memo
 * @param string     $profile_url
 * @return string HTML-safe text with one link.
 */
function sn_hibp_login_notice_html( $memo, $profile_url ) {
	if ( ! is_array( $memo ) || SN_HIBP_BREACHED !== (string) ( $memo['verdict'] ?? '' ) ) {
		return '';
	}
	return sprintf(
		/* translators: 1: number of breaches the password appears in, 2: profile URL. */
		esc_html__( 'Your current password appears %1$s times in known data breaches. It still works, but change it — %2$s.', 'signal-and-noise-tools' ),
		esc_html( number_format_i18n( (int) ( $memo['count'] ?? 0 ) ) ),
		'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'open your profile', 'signal-and-noise-tools' ) . '</a>'
	);
}

/** admin_notices: only the user whose password it is ever sees it. */
function sn_hibp_login_admin_notice() {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		return;
	}
	$html = sn_hibp_login_notice_html( sn_hibp_login_memo( (int) get_current_user_id() ), function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#password' : '' );
	if ( '' === $html ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . $html . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in sn_hibp_login_notice_html().
}

add_filter( 'authenticate', 'sn_hibp_on_authenticate', 30, 3 );
add_action( 'admin_notices', 'sn_hibp_login_admin_notice' );
