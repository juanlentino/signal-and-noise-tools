<?php
/**
 * Signal & Noise Tools: the edge posture and the firewall in words, split
 * from inc/cloudflare-posture.php (house line cap, review on #2022). Both
 * painters (the Security › Firewall leaf and the classic card) read them, so
 * a drift says what was expected and an action reads as words on both.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A judged check as both painters say it: the value, and when it drifted,
 * what was expected ("Flexible, expected Full (strict)").
 *
 * @param array{value:string,ok:bool,expected?:string} $check One `checks` row of sn_cf_posture_model().
 * @return string
 */
function sn_cf_posture_check_text( array $check ) {
	if ( ! empty( $check['ok'] ) || '' === (string) ( $check['expected'] ?? '' ) ) {
		return (string) $check['value'];
	}
	/* translators: 1: the setting's value, 2: the value the check expects. */
	return sprintf( __( '%1$s, expected %2$s', 'signal-and-noise-tools' ), (string) $check['value'], (string) $check['expected'] );
}

/**
 * A firewall action key as words (2026-10-12 audit: `managed_challenge` and
 * `jschallenge` were painted as the API spells them). An unknown key is kept.
 *
 * @param string $action The API's action key.
 * @return string
 */
function sn_cf_action_word( $action ) {
	$words = array(
		'block'             => __( 'blocked', 'signal-and-noise-tools' ),
		'managed_challenge' => __( 'managed challenge', 'signal-and-noise-tools' ),
		'challenge'         => __( 'interactive challenge', 'signal-and-noise-tools' ),
		'jschallenge'       => __( 'JavaScript challenge', 'signal-and-noise-tools' ),
		'log'               => __( 'logged only', 'signal-and-noise-tools' ),
		'skip'              => __( 'skipped', 'signal-and-noise-tools' ),
		'allow'             => __( 'allowed', 'signal-and-noise-tools' ),
		'bypass'            => __( 'bypassed', 'signal-and-noise-tools' ),
	);
	return $words[ (string) $action ] ?? (string) $action;
}
