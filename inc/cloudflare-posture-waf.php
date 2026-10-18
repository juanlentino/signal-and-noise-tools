<?php
/**
 * Signal & Noise Tools: the posture's blind spots, split from
 * inc/cloudflare-posture.php (house line cap). Three more reads, all on the
 * scope the custom rules read already needs (Zone › Zone WAF › Read):
 *   - managed rules   GET /zones/{id}/rulesets/phases/http_request_firewall_managed/entrypoint
 *   - rate limit      GET /zones/{id}/rulesets/phases/http_ratelimit/entrypoint
 *   - leaked creds    GET /zones/{id}/leaked-credential-checks
 * A 404 entrypoint means nothing is deployed in that phase (missing, a
 * finding), never a failed read. The rate-limit rule's expression is judged
 * for being non-empty (it silently matched nothing until 2026-09-27) and is
 * NEVER stored: it can carry the masked login path. Its description is not
 * stored either, for the same reason. Read-only.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rulesets that put the Free Managed Ruleset's rules in front of the zone (the full one supersedes it). */
const SN_CF_FREE_MANAGED_IDS = array( '77454fe2d30c4220b5701f6fdfb893ba', 'efb7b8c949ac4650a09736fc376e9aee' );

/**
 * The paths the three reads ask for, by key.
 *
 * @return array<string,string>
 */
function sn_cf_posture_waf_paths() {
	return array(
		'managed'   => '/rulesets/phases/http_request_firewall_managed/entrypoint',
		'ratelimit' => '/rulesets/phases/http_ratelimit/entrypoint',
		'leaked'    => '/leaked-credential-checks',
	);
}

/**
 * One answer read: a 404 on an entrypoint is "no rules", a refusal says
 * "unavailable (needs the scope)".
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @param string                                                $what managed|ratelimit|leaked
 * @return array<string,mixed>
 */
function sn_cf_posture_waf_read_of( array $res, $what ) {
	if ( 'leaked' !== $what && 404 === (int) $res['http'] ) {
		return array( 'available' => true, 'needs_permission' => false, 'error' => '', 'result' => array( 'rules' => array() ) );
	}
	$read = sn_cf_posture_read_of( $res, $what );
	if ( $read['needs_permission'] ) {
		$read['error'] = sprintf( '%s: unavailable (needs %s).', sn_cf_posture_waf_label( $what ), sn_cf_posture_scope( $what ) );
	}
	return $read;
}

/**
 * @param string $what managed|ratelimit|leaked
 * @return string
 */
function sn_cf_posture_waf_label( $what ) {
	$labels = array(
		'managed'   => __( 'Free Managed Ruleset', 'signal-and-noise-tools' ),
		'ratelimit' => __( 'Rate-limit rule', 'signal-and-noise-tools' ),
		'leaked'    => __( 'Leaked credentials detection', 'signal-and-noise-tools' ),
	);
	return $labels[ $what ] ?? $what;
}

/**
 * The three readings as record keys, pure. Facts only: booleans, a count
 * and an action, never an expression or a description.
 *
 * @param array $managed_res   Answer of the managed entrypoint read.
 * @param array $ratelimit_res Answer of the rate-limit entrypoint read.
 * @param array $leaked_res    Answer of the leaked-credential-checks read.
 * @return array{managed:array,ratelimit:array,leaked:array}
 */
function sn_cf_posture_waf_from( array $managed_res, array $ratelimit_res, array $leaked_res ) {
	$m     = sn_cf_posture_waf_read_of( $managed_res, 'managed' );
	$found = null;
	foreach ( (array) ( $m['result']['rules'] ?? array() ) as $r ) {
		if ( is_array( $r ) && 'execute' === ( $r['action'] ?? '' ) && in_array( (string) ( $r['action_parameters']['id'] ?? '' ), SN_CF_FREE_MANAGED_IDS, true ) && ( null === $found || ! empty( $r['enabled'] ) ) ) {
			$found = $r;
		}
	}
	unset( $m['result'] );
	$m['deployed'] = null !== $found;
	$m['enabled']  = null !== $found && ! empty( $found['enabled'] );

	$rl    = sn_cf_posture_waf_read_of( $ratelimit_res, 'ratelimit' );
	$list  = array_values( array_filter( (array) ( $rl['result']['rules'] ?? array() ), 'is_array' ) );
	$first = $list[0] ?? array();
	unset( $rl['result'] );
	$rl['count']          = count( $list );
	$rl['enabled']        = ! empty( $first['enabled'] );
	$rl['action']         = (string) ( $first['action'] ?? '' );
	$rl['has_expression'] = '' !== trim( (string) ( $first['expression'] ?? '' ) );

	$lc = sn_cf_posture_waf_read_of( $leaked_res, 'leaked' );
	$lc['enabled'] = $lc['available'] && isset( $lc['result']['enabled'] ) ? (bool) $lc['result']['enabled'] : null;
	unset( $lc['result'] );

	return array( 'managed' => $m, 'ratelimit' => $rl, 'leaked' => $lc );
}

/**
 * Each read rendered as one judged row, or null when it was not read.
 *
 * @param array<string,mixed> $record
 * @param string              $what managed|ratelimit|leaked
 * @return array{label:string,value:string,ok:bool,expected:string}|null
 */
function sn_cf_posture_waf_check( array $record, $what ) {
	$r = $record[ $what ] ?? null;
	if ( ! is_array( $r ) || empty( $r['available'] ) ) {
		return null;
	}
	if ( 'managed' === $what ) {
		$value = ! $r['deployed'] ? __( 'not deployed', 'signal-and-noise-tools' ) : ( $r['enabled'] ? __( 'deployed', 'signal-and-noise-tools' ) : __( 'deployed, disabled', 'signal-and-noise-tools' ) );
		return array( 'label' => sn_cf_posture_waf_label( $what ), 'value' => $value, 'ok' => $r['deployed'] && $r['enabled'], 'expected' => __( 'deployed and enabled', 'signal-and-noise-tools' ) );
	}
	if ( 'ratelimit' === $what ) {
		if ( 0 === (int) $r['count'] ) {
			$value = __( 'missing', 'signal-and-noise-tools' );
		} elseif ( ! $r['enabled'] ) {
			$value = __( 'disabled', 'signal-and-noise-tools' );
		} else {
			/* translators: %s: the rule's action in words. */
			$value = sprintf( __( 'enabled, %s', 'signal-and-noise-tools' ), sn_cf_action_word( $r['action'] ) );
		}
		if ( (int) $r['count'] > 0 && ! $r['has_expression'] ) {
			$value .= __( '; its expression is empty, so it matches nothing', 'signal-and-noise-tools' );
		}
		return array( 'label' => sn_cf_posture_waf_label( $what ), 'value' => $value, 'ok' => (int) $r['count'] > 0 && $r['enabled'] && $r['has_expression'], 'expected' => __( 'one enabled rule with an expression', 'signal-and-noise-tools' ) );
	}
	if ( null === $r['enabled'] ) {
		return null; // The API's `enabled` is optional: unknown is never "off".
	}
	return array( 'label' => sn_cf_posture_waf_label( $what ), 'value' => $r['enabled'] ? __( 'on', 'signal-and-noise-tools' ) : __( 'off', 'signal-and-noise-tools' ), 'ok' => $r['enabled'], 'expected' => __( 'on', 'signal-and-noise-tools' ) );
}

/**
 * The drifts among the three: a refusal (unavailable), missing, disabled,
 * an empty expression, detection off. Pure.
 *
 * @param array<string,mixed> $record
 * @return array<int,array{key:string,label:string,value:string,why:string}>
 */
function sn_cf_posture_waf_findings( array $record ) {
	$out = array();
	foreach ( array_keys( sn_cf_posture_waf_paths() ) as $what ) {
		if ( ! empty( $record[ $what ]['needs_permission'] ) ) {
			$out[] = array( 'key' => $what, 'label' => $what . ': unread', 'value' => '', 'why' => (string) $record[ $what ]['error'] . ' Add it to the token and the next refresh reads it.' );
			continue;
		}
		$c = sn_cf_posture_waf_check( $record, $what );
		if ( null !== $c && ! $c['ok'] ) {
			$out[] = array( 'key' => $what, 'label' => $what, 'value' => $c['value'], 'why' => sn_cf_posture_waf_label( $what ) . ': ' . sn_cf_posture_check_text( $c ) . '.' );
		}
	}
	return $out;
}
