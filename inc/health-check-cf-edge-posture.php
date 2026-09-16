<?php
/**
 * Signal & Noise Tools -- Content Health check: cf edge posture.
 *
 * 15.4.0. Check 6 (cf security headers) reads what the edge sends; this one
 * reads what the edge is set to, from the daily posture record
 * (inc/cloudflare-posture.php): SSL mode, minimum TLS, Always Use HTTPS,
 * Development Mode, DNSSEC. Never fetches; a record older than two days or
 * never written makes the check SKIPPED (a gap in evidence), and a refused
 * read is a FINDING that names the scope, never a pass.
 *
 * Detection-only: the fix is a Cloudflare dashboard change.
 *
 * @package SignalNoiseTools
 * @since 15.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array { count, findings, label, fix_hint, skipped }
 */
function sn_health_check_cf_edge_posture() {
	$label    = 'Cloudflare edge posture';
	$fix_hint = 'These are zone settings at the Cloudflare edge (SSL/TLS, Security, Caching, DNS › DNSSEC), read daily with the Cloudflare monitor. A drift is changed in the Cloudflare dashboard; an "unread" finding names the token scope to add (My Profile › API Tokens).';

	if ( ! function_exists( 'sn_cf_posture_read' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'the Cloudflare posture module is not loaded.' );
	}
	$record = sn_cf_posture_read();
	if ( ! is_array( $record ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'the edge posture has never been read; it reads daily with the Cloudflare monitor, or on Refresh now (Security › Firewall).' );
	}
	if ( empty( $record['configured'] ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Cloudflare is not configured (Connections › Credentials).' );
	}
	if ( (int) ( $record['fetched_at'] ?? 0 ) < time() - 2 * DAY_IN_SECONDS ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'the edge posture record is older than two days; the daily read did not run.' );
	}
	$errors = array();
	foreach ( array( 'settings', 'dnssec', 'rules' ) as $what ) {
		$r = $record[ $what ] ?? null;
		if ( is_array( $r ) && empty( $r['available'] ) && empty( $r['needs_permission'] ) ) {
			$errors[] = $what . ': ' . (string) ( $r['error'] ?? '' );
		}
	}
	if ( count( $errors ) === 3 ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Cloudflare could not be read (' . implode( '; ', $errors ) . '); the check retries on the next read.' );
	}

	$findings = array();
	$home_url = home_url( '/' );
	foreach ( sn_cf_posture_findings( $record ) as $f ) {
		$findings[] = array(
			'subject_type'  => 'edge_posture',
			'subject_id'    => 0,
			'subject_url'   => $home_url,
			'subject_label' => $f['label'],
			'note'          => $f['why'],
		);
	}
	return sn_health_pack_check( $label, $findings, $fix_hint );
}
