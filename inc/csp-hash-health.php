<?php
/**
 * Signal & Noise Tools: the csp_hashes watch and its Site Health test.
 *
 * Both read the last stored run (inc/csp-hash-check.php) and never fetch.
 * Ripe when the strict policy is deployed and lacks a hash a page carries
 * (the note gives the script-src line to paste into the Transform Rule), when
 * most pages could not be read, when no page carried any CSP header, or when
 * the check has not run for two days. No strict policy deployed is the
 * expected state until rollout: not ripe, and never critical.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verdict over one stored run.
 *
 * @param array $last Stored run.
 * @param int   $now  Unix time.
 * @return array{ripe:bool,note:string,status:string}
 */
function snt_csp_hash_verdict( $last, $now ) {
	if ( ! is_array( $last ) || empty( $last['at'] ) ) {
		return array( 'ripe' => false, 'note' => 'not run yet', 'status' => 'recommended' );
	}
	$at = gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC';
	if ( (int) $now - (int) $last['at'] > 2 * DAY_IN_SECONDS ) {
		return array( 'ripe' => true, 'note' => 'the daily check has not run since ' . $at, 'status' => 'recommended' );
	}
	if ( 2 * (int) $last['errors'] > (int) $last['checked'] ) {
		return array( 'ripe' => true, 'note' => sprintf( 'the last check could read only %d of %d pages, too few to call it clean', (int) $last['checked'] - (int) $last['errors'], (int) $last['checked'] ), 'status' => 'recommended' );
	}
	if ( empty( $last['csp_seen'] ) ) {
		return array( 'ripe' => true, 'note' => 'no page carried a CSP header, so the check is not reading the site through Cloudflare and cannot see the policy', 'status' => 'recommended' );
	}
	if ( 'none' === $last['mode'] ) {
		return array( 'ripe' => false, 'note' => 'strict policy not deployed (every script-src still allows unsafe-inline); checked ' . $at, 'status' => 'good' );
	}
	$header  = 'enforced' === $last['mode'] ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
	$missing = (array) $last['missing'];
	if ( ! $missing ) {
		return array( 'ripe' => false, 'note' => sprintf( 'the strict %s pins every inline script on %d pages; checked %s', $header, (int) $last['checked'] - (int) $last['errors'], $at ), 'status' => 'good' );
	}
	$seen  = (array) $last['seen'];
	$named = array();
	foreach ( $missing as $h ) {
		$named[] = ( $seen[ $h ]['label'] ?? 'other' ) . ' on ' . implode( ' ', array_slice( array_unique( (array) ( $seen[ $h ]['pages'] ?? array() ) ), 0, 5 ) );
	}
	$line = sn_csp_replacement_line( (array) $last['sources'], array_keys( $seen ) );
	return array(
		'ripe'   => true,
		'note'   => sprintf( 'the strict %s lacks %d inline script hash(es): %s. Purge the cache, then replace script-src in the Cloudflare Transform Rule with: %s', $header, count( $missing ), implode( '; ', $named ), $line ),
		'status' => 'enforced' === $last['mode'] ? 'critical' : 'recommended',
	);
}

/** Watch: csp_hashes. */
function snt_watch_ripe_csp_hashes( $watch, $now ) {
	unset( $watch );
	$v = snt_csp_hash_verdict( get_option( SN_CSP_HASH_OPT, array() ), $now );
	return array( 'ripe' => $v['ripe'], 'note' => $v['note'] );
}

/** Core Site Health registration (direct test). */
function snt_csp_hash_register_site_health_test( $tests ) {
	$tests['direct']['sn_csp_hashes'] = array(
		'label' => __( 'Strict CSP matches the inline scripts', 'signal-and-noise-tools' ),
		'test'  => 'snt_csp_hash_site_health_result',
	);
	return $tests;
}

/** The Site Health row, from the last stored run. */
function snt_csp_hash_site_health_result() {
	$v      = snt_csp_hash_verdict( get_option( SN_CSP_HASH_OPT, array() ), time() );
	$labels = array(
		'good'        => __( 'Strict CSP matches the inline scripts', 'signal-and-noise-tools' ),
		'recommended' => __( 'Strict CSP check needs attention', 'signal-and-noise-tools' ),
		'critical'    => __( 'Strict CSP blocks an inline script', 'signal-and-noise-tools' ),
	);
	return array(
		'label'       => $labels[ $v['status'] ],
		'status'      => $v['status'],
		'badge'       => array(
			'label' => __( 'Security', 'signal-and-noise-tools' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $v['note'] ) . '</p>',
		'test'        => 'sn_csp_hashes',
	);
}

if ( ! defined( 'SN_CSP_HASH_TEST' ) || ! SN_CSP_HASH_TEST ) {
	add_filter( 'site_status_tests', 'snt_csp_hash_register_site_health_test' );
}
