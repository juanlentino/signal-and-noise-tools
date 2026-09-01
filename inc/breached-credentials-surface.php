<?php
/**
 * Signal & Noise Tools — breached-credential rejection, Phase 3: THE SURFACE
 * (docs/proposals/breached-credential-check-2026-08-31.md).
 *
 * Modes A (v13.58.0) and B (v13.59.0) decide; nothing yet says how they are
 * doing. Two readers, one derivation:
 *
 *  - a core Site Health row (`sn_hibp_breach_check`, DIRECT test) — an
 *    environment posture, so NOT on the plugin's own curated health tab, which
 *    admits only defects that can reach zero (same reasoning as the SSRF
 *    pinning row, v13.53.0);
 *  - a section in the weekly security digest.
 *
 * What it reads: `sn_hibp_set_stats()` (Mode A's counts) and the Mode B memos
 * across all users. The two numbers that matter, and why each is there:
 *
 *  - ACCOUNTS FLAGGED: users whose CURRENT password is memoized as breached.
 *    Derived by walking users and reading the memo, never from a cached
 *    total — the memo self-invalidates on password change, so the count
 *    must be re-read, not remembered.
 *  - FAIL-CLOSED REJECTIONS: Mode A refusals caused by an UNAVAILABLE lookup.
 *    A non-zero, climbing count means the API is degrading and the site is
 *    quietly refusing legitimate password changes. This is the number the
 *    plan said had to be visible somewhere.
 *
 * Verdicts are `recommended`, never `critical`: Mode B is advisory by design,
 * and a critical Site Health row for a password the owner already saw a
 * warning about would be the same fact shouted twice.
 *
 * @package SignalNoiseTools
 * @since 13.60.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** An unavailable rejection younger than this counts as "the API is degrading now". */
const SN_HIBP_SURFACE_RECENT_SECS = 7 * DAY_IN_SECONDS;

/**
 * Walk users, read Mode B memos. {checked, breached, breached_ids}.
 *
 * checked = users with ANY memo for their current password (a login has run
 * the check); breached = the subset memoized as breached. Users who have not
 * logged in since Mode B shipped are neither — an unknown, not a clean.
 *
 * @return array{users:int,checked:int,breached:int,breached_ids:int[]}
 */
function sn_hibp_flagged_users() {
	$out = array( 'users' => 0, 'checked' => 0, 'breached' => 0, 'breached_ids' => array() );
	if ( ! function_exists( 'get_users' ) || ! function_exists( 'sn_hibp_login_memo' ) ) {
		return $out;
	}
	$ids = get_users( array( 'fields' => 'ID', 'number' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	foreach ( (array) $ids as $id ) {
		$out['users']++;
		$memo = sn_hibp_login_memo( (int) $id );
		if ( null === $memo ) {
			continue;
		}
		$out['checked']++;
		if ( SN_HIBP_BREACHED === (string) ( $memo['verdict'] ?? '' ) ) {
			$out['breached']++;
			$out['breached_ids'][] = (int) $id;
		}
	}
	return $out;
}

/**
 * Everything both readers need, in one read.
 *
 * @return array{set:array,login:array,set_enabled:bool,login_enabled:bool}
 */
function sn_hibp_surface_data() {
	return array(
		'set'           => function_exists( 'sn_hibp_set_stats' ) ? sn_hibp_set_stats() : array( 'breached_count' => 0, 'unavailable_count' => 0, 'last_breached_at' => 0, 'last_unavailable_at' => 0 ),
		'login'         => sn_hibp_flagged_users(),
		'set_enabled'   => function_exists( 'sn_hibp_set_disabled' ) ? ! sn_hibp_set_disabled() : false,
		'login_enabled' => function_exists( 'sn_hibp_login_disabled' ) ? ! sn_hibp_login_disabled() : false,
	);
}

/**
 * The verdict. PURE over sn_hibp_surface_data() + now.
 *
 * Order of concern: a mode switched off (the check is not running) > accounts
 * flagged (a breached password is in use) > recent fail-closed rejections
 * (the API is degrading) > good. The summary is derived from the same counts
 * it reports, so it cannot drift from them.
 *
 * @param array $d   sn_hibp_surface_data().
 * @param int   $now
 * @return array{status:string,summary:string,flagged:int,checked:int,unavailable_recent:bool}
 */
function sn_hibp_health( $d, $now ) {
	$set     = is_array( $d['set'] ?? null ) ? $d['set'] : array();
	$login   = is_array( $d['login'] ?? null ) ? $d['login'] : array();
	$flagged = (int) ( $login['breached'] ?? 0 );
	$checked = (int) ( $login['checked'] ?? 0 );
	$users   = (int) ( $login['users'] ?? 0 );
	$unavail = (int) ( $set['unavailable_count'] ?? 0 );
	$last_un = (int) ( $set['last_unavailable_at'] ?? 0 );
	$recent  = $unavail > 0 && $last_un > 0 && ( (int) $now - $last_un ) <= SN_HIBP_SURFACE_RECENT_SECS;

	$base = array( 'flagged' => $flagged, 'checked' => $checked, 'unavailable_recent' => $recent );

	if ( empty( $d['set_enabled'] ) || empty( $d['login_enabled'] ) ) {
		$off = array();
		if ( empty( $d['set_enabled'] ) ) { $off[] = 'set-time (SN_HIBP_SET_DISABLED)'; }
		if ( empty( $d['login_enabled'] ) ) { $off[] = 'login-time (SN_HIBP_LOGIN_DISABLED)'; }
		return $base + array(
			'status'  => 'recommended',
			'summary' => sprintf( 'The breached-password check is switched OFF for: %s. Nothing below this line is being measured for that mode.', implode( ' and ', $off ) ),
		);
	}
	if ( $flagged > 0 ) {
		return $base + array(
			'status'  => 'recommended',
			'summary' => sprintf( '%d of %d checked account(s) is using a password that appears in known data breaches. It still works (the login check is advisory); that user saw a notice and should change it.', $flagged, $checked ),
		);
	}
	if ( $recent ) {
		return $base + array(
			'status'  => 'recommended',
			'summary' => sprintf( 'The breach API was unreachable when a password was being set (%d fail-closed rejection(s), last within 7 days). Those password changes were refused, by design. If this count keeps climbing, the API is degrading and the site is quietly refusing legitimate changes.', $unavail ),
		);
	}
	return $base + array(
		'status'  => 'good',
		'summary' => sprintf( 'Both modes are on. %d of %d account(s) checked at login, none breached; %d breached password(s) refused at set-time; %d fail-closed rejection(s) ever, none recent.', $checked, $users, (int) ( $set['breached_count'] ?? 0 ), $unavail ),
	);
}

/** Core Site Health registration (direct test). */
function sn_hibp_register_site_health_test( $tests ) {
	$tests['direct']['sn_hibp_breach_check'] = array(
		'label' => __( 'Signal & Noise breached-password check', 'signal-and-noise-tools' ),
		'test'  => 'sn_hibp_site_health_result',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'sn_hibp_register_site_health_test' );

/** The Site Health row. */
function sn_hibp_site_health_result() {
	$verdict = sn_hibp_health( sn_hibp_surface_data(), time() );
	return array(
		'label'       => __( 'Signal & Noise breached-password check', 'signal-and-noise-tools' ),
		'status'      => 'good' === $verdict['status'] ? 'good' : 'recommended',
		'badge'       => array(
			'label' => __( 'Security', 'signal-and-noise-tools' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $verdict['summary'] ) . '</p>',
		'test'        => 'sn_hibp_breach_check',
	);
}
