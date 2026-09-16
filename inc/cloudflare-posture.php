<?php
/**
 * Signal & Noise Tools — the edge's security posture, read daily.
 *
 * 15.4.0. Three REST reads the origin cannot make for itself, each on a
 * documented Free-plan endpoint with a documented token scope:
 *   - zone settings   GET /zones/{id}/settings                      Zone Settings Read
 *   - DNSSEC          GET /zones/{id}/dnssec                        DNS Read
 *   - custom WAF rules GET /zones/{id}/rulesets/phases/http_request_firewall_custom/entrypoint
 *                                                                   Zone WAF Read
 * The headers probe (health check 6) sees what the edge SENDS; this sees what
 * the edge IS SET TO. SSL mode Flexible and Development Mode on are invisible
 * to a headers probe; both are here. The ruleset read is the WAF witness's
 * first source: a rule that exists and is enabled is the fact, on a day with
 * no blocks too. Read-only; one option, never fetched at request time.
 *
 * A refused read is recorded as refused, naming the scope, never as empty.
 *
 * @package SignalNoiseTools
 * @since 15.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_POSTURE_OPT = 'sn_cf_posture';

/**
 * The settings the check reads, by API id. Judged ones carry the value the
 * check expects; the rest are readings, painted, not judged.
 *
 * @return array<string,string|null> id => expected value, or null for a reading.
 */
function sn_cf_posture_settings() {
	return array(
		'ssl'                      => 'strict', // Full (strict): the origin certificate is validated.
		'min_tls_version'          => '1.2',
		'always_use_https'         => 'on',
		'development_mode'         => 'off',   // On bypasses the cache for 3 hours; nothing in wp-admin says so.
		'tls_1_3'                  => null,
		'automatic_https_rewrites' => null,
		'opportunistic_encryption' => null,
		'security_level'           => null,
		'browser_check'            => null,
		'hotlink_protection'       => null,
		'email_obfuscation'        => null,
		'challenge_ttl'            => null,
	);
}

/**
 * The documented scope each read needs.
 *
 * @param string $what settings|dnssec|rules
 * @return string
 */
function sn_cf_posture_scope( $what ) {
	$scopes = array(
		'settings' => 'Zone › Zone Settings › Read',
		'dnssec'   => 'Zone › DNS › Read',
		'rules'    => 'Zone › Zone WAF › Read',
	);
	return $scopes[ $what ] ?? '';
}

/**
 * One REST answer to one reading: available, refused (the scope named), or
 * failed. Cloudflare refuses a missing scope with HTTP 403 and an errors[]
 * entry (10000 "Authentication error", 9109, 10001), never a 200.
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @param string                                                $what
 * @return array{available:bool,needs_permission:bool,error:string,result:mixed}
 */
function sn_cf_posture_read_of( array $res, $what ) {
	if ( 0 === (int) $res['http'] ) {
		return array( 'available' => false, 'needs_permission' => false, 'error' => (string) $res['error'], 'result' => null );
	}
	$errors = isset( $res['body']['errors'] ) && is_array( $res['body']['errors'] ) ? $res['body']['errors'] : array();
	$first  = is_array( $errors[0] ?? null ) ? $errors[0] : array();
	$code   = (int) ( $first['code'] ?? 0 );
	if ( in_array( (int) $res['http'], array( 401, 403 ), true ) || in_array( $code, array( 9109, 10000, 10001 ), true ) ) {
		return array( 'available' => false, 'needs_permission' => true, 'error' => sprintf( 'The token lacks %s. %s', sn_cf_posture_scope( $what ), (string) ( $first['message'] ?? '' ) ), 'result' => null );
	}
	if ( empty( $res['body']['success'] ) || ! isset( $res['body']['result'] ) ) {
		return array( 'available' => false, 'needs_permission' => false, 'error' => (string) ( $first['message'] ?? ( 'HTTP ' . (int) $res['http'] ) ), 'result' => null );
	}
	return array( 'available' => true, 'needs_permission' => false, 'error' => '', 'result' => $res['body']['result'] );
}

/**
 * The reading, pure over the three answers.
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $settings_res
 * @param array{http:int,body:array<string,mixed>,error:string} $dnssec_res
 * @param array{http:int,body:array<string,mixed>,error:string} $rules_res
 * @return array<string,mixed>
 */
function sn_cf_posture_from( array $settings_res, array $dnssec_res, array $rules_res ) {
	$settings = sn_cf_posture_read_of( $settings_res, 'settings' );
	$values   = array();
	if ( $settings['available'] ) {
		$wanted = sn_cf_posture_settings();
		foreach ( (array) $settings['result'] as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( array_key_exists( $id, $wanted ) ) {
				$values[ $id ] = is_scalar( $row['value'] ?? null ) ? (string) $row['value'] : wp_json_encode( $row['value'] ?? null );
			}
		}
	}
	unset( $settings['result'] );
	$settings['values'] = $values;

	$dnssec = sn_cf_posture_read_of( $dnssec_res, 'dnssec' );
	$dnssec['status'] = $dnssec['available'] ? (string) ( $dnssec['result']['status'] ?? '' ) : '';
	unset( $dnssec['result'] );

	$rules = sn_cf_posture_read_of( $rules_res, 'rules' );
	$list  = array();
	if ( $rules['available'] ) {
		foreach ( (array) ( $rules['result']['rules'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$list[] = array(
				'id'          => (string) ( $r['id'] ?? '' ),
				'description' => (string) ( $r['description'] ?? '' ),
				'action'      => (string) ( $r['action'] ?? '' ),
				'enabled'     => ! empty( $r['enabled'] ),
				'expression'  => substr( (string) ( $r['expression'] ?? '' ), 0, 400 ),
			);
		}
	}
	unset( $rules['result'] );
	$rules['rules'] = $list;

	return array( 'settings' => $settings, 'dnssec' => $dnssec, 'rules' => $rules );
}

/**
 * Fetch the three readings and store them (one option, never autoloaded).
 *
 * @return array<string,mixed> The stored record.
 */
function sn_cf_posture_refresh() {
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! sn_cf_is_configured() ) {
		$record = array( 'fetched_at' => time(), 'configured' => false, 'settings' => null, 'dnssec' => null, 'rules' => null );
		update_option( SN_CF_POSTURE_OPT, $record, false );
		return $record;
	}
	$zone   = '/zones/' . rawurlencode( sn_cf_get_zone() );
	$record = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from(
		sn_cf_api_get( $zone . '/settings' ),
		sn_cf_api_get( $zone . '/dnssec' ),
		sn_cf_api_get( $zone . '/rulesets/phases/http_request_firewall_custom/entrypoint' )
	);
	update_option( SN_CF_POSTURE_OPT, $record, false );
	return $record;
}
add_action( SN_CF_MONITOR_HOOK, 'sn_cf_posture_refresh', 30 );

/**
 * The stored reading, or null when never run. Never fetches.
 *
 * @return array<string,mixed>|null
 */
function sn_cf_posture_read() {
	$stored = get_option( SN_CF_POSTURE_OPT );
	return is_array( $stored ) ? $stored : null;
}

/**
 * A custom rule's name for its id, from the last ruleset read; '' when unknown.
 *
 * @param string $rule_id
 * @return string
 */
function sn_cf_posture_rule_name( $rule_id ) {
	$record = sn_cf_posture_read();
	foreach ( (array) ( $record['rules']['rules'] ?? array() ) as $r ) {
		if ( (string) $rule_id === (string) ( $r['id'] ?? '' ) ) {
			return (string) $r['description'];
		}
	}
	return '';
}

/**
 * The custom rule that guards the abilities API, from the ruleset read: the
 * one whose name carries the rule's word or whose expression names the path.
 * null when the ruleset was not read or no such rule exists.
 *
 * @param array<string,mixed>|null $record
 * @return array<string,mixed>|null
 */
function sn_cf_posture_abilities_rule( $record = null ) {
	$record = null === $record ? sn_cf_posture_read() : $record;
	if ( ! is_array( $record ) || empty( $record['rules']['available'] ) ) {
		return null;
	}
	foreach ( (array) $record['rules']['rules'] as $r ) {
		if ( false !== stripos( (string) $r['description'], SN_CF_FW_ABILITIES_RULE ) || false !== stripos( (string) $r['expression'], 'wp-abilities' ) ) {
			return $r;
		}
	}
	return null;
}

/**
 * The drifts: a judged setting off its expected value, DNSSEC not active,
 * and each refused read (a refusal is a verdict, never a pass). Pure.
 *
 * @param array<string,mixed> $record
 * @return array<int,array{key:string,label:string,value:string,why:string}>
 */
function sn_cf_posture_findings( array $record ) {
	$out = array();
	foreach ( array( 'settings', 'dnssec', 'rules' ) as $what ) {
		if ( ! empty( $record[ $what ]['needs_permission'] ) ) {
			$out[] = array( 'key' => $what, 'label' => $what . ': unread', 'value' => '', 'why' => 'the token lacks ' . sn_cf_posture_scope( $what ) . '; add it to the token and the next refresh reads it.' );
		}
	}
	if ( ! empty( $record['settings']['available'] ) ) {
		$values = (array) $record['settings']['values'];
		foreach ( sn_cf_posture_settings() as $id => $expected ) {
			if ( null === $expected || ! array_key_exists( $id, $values ) ) {
				continue;
			}
			$value = (string) $values[ $id ];
			$bad   = 'min_tls_version' === $id ? version_compare( $value, $expected, '<' ) : ( $value !== $expected );
			if ( $bad ) {
				$out[] = array( 'key' => $id, 'label' => $id, 'value' => $value, 'why' => sprintf( '%s is "%s"; expected "%s".', $id, $value, $expected ) );
			}
		}
	}
	if ( ! empty( $record['dnssec']['available'] ) && 'active' !== (string) $record['dnssec']['status'] ) {
		$out[] = array( 'key' => 'dnssec', 'label' => 'dnssec', 'value' => (string) $record['dnssec']['status'], 'why' => sprintf( 'DNSSEC is "%s", not active.', (string) $record['dnssec']['status'] ) );
	}
	return $out;
}

/**
 * The posture as rows a painter can lay out without knowing the API: the
 * judged settings and DNSSEC as `checks` (label, value in words, ok), the
 * readings as `also` (label, value), the custom rules as `rules` (name,
 * action, enabled), and per read a `refused` sentence naming the scope.
 * Both the native leaf and the classic card paint from this, so they say
 * the same thing.
 *
 * @param array<string,mixed>|null $record
 * @return array{state:string,checks:array<int,array{label:string,value:string,ok:bool}>,also:array<int,array{label:string,value:string}>,rules:array<int,array{name:string,action:string,enabled:bool}>,refused:array<int,string>}
 */
function sn_cf_posture_model( $record = null ) {
	$record = null === $record ? sn_cf_posture_read() : $record;
	$out    = array( 'state' => 'never', 'checks' => array(), 'also' => array(), 'rules' => array(), 'refused' => array() );
	if ( ! is_array( $record ) ) {
		return $out;
	}
	if ( empty( $record['configured'] ) ) {
		$out['state'] = 'unconfigured';
		return $out;
	}
	$out['state'] = 'read';
	$labels = array(
		'ssl'                      => __( 'SSL mode', 'signal-and-noise-tools' ),
		'min_tls_version'          => __( 'Minimum TLS', 'signal-and-noise-tools' ),
		'always_use_https'         => __( 'Always use HTTPS', 'signal-and-noise-tools' ),
		'development_mode'         => __( 'Development mode', 'signal-and-noise-tools' ),
		'tls_1_3'                  => __( 'TLS 1.3', 'signal-and-noise-tools' ),
		'automatic_https_rewrites' => __( 'HTTPS rewrites', 'signal-and-noise-tools' ),
		'opportunistic_encryption' => __( 'Opportunistic encryption', 'signal-and-noise-tools' ),
		'security_level'           => __( 'Security level', 'signal-and-noise-tools' ),
		'browser_check'            => __( 'Browser integrity check', 'signal-and-noise-tools' ),
		'hotlink_protection'       => __( 'Hotlink protection', 'signal-and-noise-tools' ),
		'email_obfuscation'        => __( 'Email obfuscation', 'signal-and-noise-tools' ),
		'challenge_ttl'            => __( 'Challenge TTL', 'signal-and-noise-tools' ),
	);
	$words  = array( 'strict' => __( 'Full (strict)', 'signal-and-noise-tools' ), 'full' => __( 'Full', 'signal-and-noise-tools' ), 'flexible' => __( 'Flexible', 'signal-and-noise-tools' ), 'off' => __( 'off', 'signal-and-noise-tools' ), 'on' => __( 'on', 'signal-and-noise-tools' ) );
	$drift  = array();
	foreach ( sn_cf_posture_findings( $record ) as $f ) {
		$drift[ $f['key'] ] = true;
	}
	foreach ( array( 'settings', 'dnssec', 'rules' ) as $what ) {
		if ( ! empty( $record[ $what ]['needs_permission'] ) ) {
			$out['refused'][] = (string) $record[ $what ]['error'];
		} elseif ( is_array( $record[ $what ] ?? null ) && empty( $record[ $what ]['available'] ) && '' !== (string) ( $record[ $what ]['error'] ?? '' ) ) {
			$out['refused'][] = sprintf( /* translators: 1: which read, 2: the error. */ __( '%1$s could not be read: %2$s', 'signal-and-noise-tools' ), $what, (string) $record[ $what ]['error'] );
		}
	}
	if ( ! empty( $record['settings']['available'] ) ) {
		$values = (array) $record['settings']['values'];
		foreach ( sn_cf_posture_settings() as $id => $expected ) {
			if ( ! array_key_exists( $id, $values ) ) {
				continue;
			}
			$raw  = (string) $values[ $id ];
			$word = $words[ $raw ] ?? ( 'min_tls_version' === $id ? 'TLS ' . $raw : ( 'challenge_ttl' === $id ? human_time_diff( 0, (int) $raw ) : $raw ) );
			if ( null !== $expected ) {
				$out['checks'][] = array( 'label' => $labels[ $id ], 'value' => $word, 'ok' => empty( $drift[ $id ] ) );
			} else {
				$out['also'][] = array( 'label' => $labels[ $id ], 'value' => $word );
			}
		}
	}
	if ( ! empty( $record['dnssec']['available'] ) ) {
		$out['checks'][] = array( 'label' => __( 'DNSSEC', 'signal-and-noise-tools' ), 'value' => (string) $record['dnssec']['status'], 'ok' => empty( $drift['dnssec'] ) );
	}
	foreach ( (array) ( $record['rules']['rules'] ?? array() ) as $r ) {
		$out['rules'][] = array( 'name' => '' !== (string) $r['description'] ? (string) $r['description'] : (string) $r['id'], 'action' => (string) $r['action'], 'enabled' => ! empty( $r['enabled'] ) );
	}
	return $out;
}
