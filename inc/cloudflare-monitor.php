<?php
/**
 * Signal & Noise Tools — the Cloudflare monitor: what the API will tell us.
 *
 * WHY. The API-limits row said "Cloudflare API: not seen yet" for months
 * while purges fired every day. The rate-limit monitor reads x-ratelimit-*
 * headers, and Cloudflare sends none (measured 2026-09-15: a v4 response
 * carries cf-ray and nothing else; the 1,200-per-five-minutes limit is
 * enforced by a 429 with Retry-After, never advertised). So the row could
 * never fill, and "not seen" read as "never used", which was false.
 *
 * WHAT. Three readings the API DOES offer, refreshed daily and on demand,
 * stored in one option, and read by the API row, the Connections ›
 * Cloudflare leaf and the `cloudflare` status section:
 *
 *   token     GET /user/tokens/verify. Works with ANY token: status
 *             (active / expired / disabled), expires_on, not_before. The
 *             one reading that needs no new permission.
 *   zone      GraphQL httpRequests1dGroups over the last seven days:
 *             requests, cached share, bytes, threats, 4xx / 5xx. Needs
 *             Zone › Analytics › Read on the token.
 *   firewall  GraphQL firewallEventsAdaptive over the last 24 hours:
 *             events by action and the top rules. Same permission. This
 *             is the witness the WAF memory asked for: a probe inside the
 *             perimeter cannot see the perimeter; the firewall log can.
 *
 * A reading the token cannot make says so, names the permission, and is
 * NEVER counted as zero. The purge token this plugin was configured with
 * carries Cache Purge only, so on first install zone and firewall read
 * `needs_permission` until the owner extends the token in the Cloudflare
 * dashboard (API Tokens › edit › Zone › Analytics › Read).
 *
 * Read-only against Cloudflare. The refresh never purges, and the readers
 * never refresh: a reader that measured would change what it reports by
 * being asked.
 *
 * @package SignalNoiseTools
 * @since 14.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_MONITOR_OPT  = 'sn_cf_monitor';
const SN_CF_MONITOR_HOOK = 'sn_cf_monitor_daily';
const SN_CF_MONITOR_DAYS = 7;
// ponytail: one page of raw events; a zone past 10,000 events a day reads truncated (the record says so). Page when it happens.
const SN_CF_MONITOR_RAW_LIMIT = 10000;

/**
 * A blocking GET against the v4 API. Same hardening as the purge POST: a
 * Bearer on a fixed host, so no redirect may re-send it.
 *
 * @param string $path Path starting with '/'.
 * @return array{http:int,body:array<string,mixed>,error:string}
 */
function sn_cf_api_get( $path, $token = null ) {
	// 15.2.2: an explicit token, so the keyring can verify the analytics override.
	$res = wp_remote_get( SN_CF_API_BASE . $path, array(
		'headers'     => array( 'Authorization' => 'Bearer ' . ( null === $token ? sn_cf_get_token() : (string) $token ) ),
		'timeout'     => 8,
		'sslverify'   => true,
		'redirection' => 0,
	) );
	if ( is_wp_error( $res ) ) {
		return array( 'http' => 0, 'body' => array(), 'error' => (string) $res->get_error_message() );
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return array( 'http' => (int) wp_remote_retrieve_response_code( $res ), 'body' => is_array( $body ) ? $body : array(), 'error' => '' );
}

/**
 * A GraphQL query against the analytics API.
 *
 * @param string              $query
 * @param array<string,mixed> $variables
 * @return array{http:int,body:array<string,mixed>,error:string}
 */
function sn_cf_graphql( $query, array $variables ) {
	$res = wp_remote_post( SN_CF_API_BASE . '/graphql', array(
		'headers'     => array(
			'Authorization' => 'Bearer ' . sn_cf_get_token(),
			'Content-Type'  => 'application/json',
		),
		'body'        => wp_json_encode( array( 'query' => $query, 'variables' => $variables ) ),
		'timeout'     => 10,
		'sslverify'   => true,
		'redirection' => 0,
	) );
	if ( is_wp_error( $res ) ) {
		return array( 'http' => 0, 'body' => array(), 'error' => (string) $res->get_error_message() );
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return array( 'http' => (int) wp_remote_retrieve_response_code( $res ), 'body' => is_array( $body ) ? $body : array(), 'error' => '' );
}

/**
 * Whether a GraphQL answer says the token may not read this dataset.
 * Cloudflare answers HTTP 200 with an errors[] entry (code 9109
 * "unauthorized to access requested resource", 10000 "authentication
 * error", or a message naming the dataset) rather than a 403.
 *
 * @param array<string,mixed> $body
 * @return bool
 */
function sn_cf_graphql_needs_permission( array $body ) {
	foreach ( (array) ( $body['errors'] ?? array() ) as $err ) {
		$code = (int) ( $err['extensions']['code'] ?? $err['code'] ?? 0 );
		$msg  = strtolower( (string) ( $err['message'] ?? '' ) );
		// 14.9.1: "zone '…' does not have access to the path" is how the
		// analytics API refuses a dataset the token was not granted (measured
		// live on firewallEventsAdaptiveGroups with an Analytics-only token).
		if ( in_array( $code, array( 9109, 10000 ), true ) || false !== strpos( $msg, 'unauthorized' ) || false !== strpos( $msg, 'not authorized' ) || false !== strpos( $msg, 'authentication' ) || false !== strpos( $msg, 'does not have access' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The token reading: pure over the verify body, testable without a socket.
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @return array<string,mixed>
 */
function sn_cf_monitor_token_from( array $res ) {
	if ( 0 === (int) $res['http'] ) {
		return array( 'verified' => false, 'status' => 'unreachable', 'error' => (string) $res['error'], 'expires_on' => '', 'not_before' => '' );
	}
	$r = is_array( $res['body']['result'] ?? null ) ? $res['body']['result'] : array();
	if ( 200 !== (int) $res['http'] || empty( $res['body']['success'] ) || '' === (string) ( $r['status'] ?? '' ) ) {
		$msg = (string) ( $res['body']['errors'][0]['message'] ?? ( 'HTTP ' . (int) $res['http'] ) );
		return array( 'verified' => false, 'status' => 'invalid', 'error' => $msg, 'expires_on' => '', 'not_before' => '' );
	}
	return array(
		'verified'   => true,
		'status'     => (string) $r['status'], // active | expired | disabled
		'error'      => '',
		'expires_on' => (string) ( $r['expires_on'] ?? '' ), // '' when the token never expires
		'not_before' => (string) ( $r['not_before'] ?? '' ),
	);
}

/**
 * The zone reading: pure over the GraphQL body.
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @return array<string,mixed>
 */
function sn_cf_monitor_zone_from( array $res ) {
	if ( 0 === (int) $res['http'] ) {
		return array( 'available' => false, 'needs_permission' => false, 'error' => (string) $res['error'], 'days' => array(), 'totals' => array() );
	}
	if ( sn_cf_graphql_needs_permission( $res['body'] ) ) {
		return array( 'available' => false, 'needs_permission' => true, 'error' => '', 'days' => array(), 'totals' => array() );
	}
	$groups = $res['body']['data']['viewer']['zones'][0]['httpRequests1dGroups'] ?? null;
	if ( ! is_array( $groups ) ) {
		$msg = (string) ( $res['body']['errors'][0]['message'] ?? ( 'HTTP ' . (int) $res['http'] ) );
		return array( 'available' => false, 'needs_permission' => false, 'error' => $msg, 'days' => array(), 'totals' => array() );
	}
	$days   = array();
	$totals = array( 'requests' => 0, 'cached' => 0, 'bytes' => 0, 'cached_bytes' => 0, 'threats' => 0, 'status_4xx' => 0, 'status_5xx' => 0 );
	// 14.9.1: the 5xx CODES, not just the class. Cloudflare's own 520/522/524
	// (it could not reach or wait for the origin) and the origin's 503
	// (Varnish backend fetch) are different problems that a class count
	// blends: the first week read 4,139 edge 5xx against 5 origin 503s a day.
	$codes = array();
	foreach ( $groups as $g ) {
		$sum = is_array( $g['sum'] ?? null ) ? $g['sum'] : array();
		$row = array(
			'date'       => (string) ( $g['dimensions']['date'] ?? '' ),
			'requests'   => (int) ( $sum['requests'] ?? 0 ),
			'cached'     => (int) ( $sum['cachedRequests'] ?? 0 ),
			'bytes'      => (int) ( $sum['bytes'] ?? 0 ),
			'cached_bytes' => (int) ( $sum['cachedBytes'] ?? 0 ),
			'threats'    => (int) ( $sum['threats'] ?? 0 ),
			'status_4xx' => 0,
			'status_5xx' => 0,
		);
		foreach ( (array) ( $sum['responseStatusMap'] ?? array() ) as $s ) {
			$code = (int) ( $s['edgeResponseStatus'] ?? 0 );
			$n    = (int) ( $s['requests'] ?? 0 );
			if ( $code >= 500 ) {
				$row['status_5xx']  += $n;
				$codes[ $code ] = ( $codes[ $code ] ?? 0 ) + $n;
			} elseif ( $code >= 400 ) {
				$row['status_4xx'] += $n;
			}
		}
		foreach ( $totals as $k => $v ) {
			$totals[ $k ] = $v + (int) $row[ $k ];
		}
		$days[] = $row;
	}
	usort( $days, static function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );
	$totals['cache_share'] = $totals['requests'] > 0 ? round( 100 * $totals['cached'] / $totals['requests'], 1 ) : null;
	arsort( $codes );
	$totals['status_5xx_codes'] = $codes;
	return array( 'available' => true, 'needs_permission' => false, 'error' => '', 'days' => $days, 'totals' => $totals );
}

/**
 * The firewall reading: pure over the GraphQL body.
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @return array<string,mixed>
 */
function sn_cf_monitor_firewall_from( array $res ) {
	if ( 0 === (int) $res['http'] ) {
		return array( 'available' => false, 'needs_permission' => false, 'error' => (string) $res['error'], 'events' => 0, 'by_action' => array(), 'top_rules' => array() );
	}
	if ( sn_cf_graphql_needs_permission( $res['body'] ) ) {
		// 14.9.2: keep the API's own sentence beside the verdict. The first
		// cut dropped it, and then the hint named a grant the docs never
		// state; two grants later the refusal read the same. The reader
		// deserves the sentence the API actually said.
		return array( 'available' => false, 'needs_permission' => true, 'error' => (string) ( $res['body']['errors'][0]['message'] ?? '' ), 'events' => 0, 'by_action' => array(), 'top_rules' => array() );
	}
	// 15.0.1: two shapes. `firewallEventsAdaptiveGroups` arrives pre-grouped
	// (count + dimensions); the raw `firewallEventsAdaptive`, the one every
	// plan has, arrives one row per SAMPLE and is grouped here. Adaptive
	// sampling (Cloudflare's own word for it) means a row stands for
	// `sampleInterval` events once the volume climbs, so a row weighs that,
	// which is what the grouped `count` would have summed.
	$zone    = $res['body']['data']['viewer']['zones'][0] ?? array();
	$dataset = isset( $zone['firewallEventsAdaptiveGroups'] ) ? 'groups' : ( isset( $zone['firewallEventsAdaptive'] ) ? 'raw' : '' );
	$groups  = '' !== $dataset ? $zone[ 'groups' === $dataset ? 'firewallEventsAdaptiveGroups' : 'firewallEventsAdaptive' ] : null;
	if ( ! is_array( $groups ) ) {
		$msg = (string) ( $res['body']['errors'][0]['message'] ?? ( 'HTTP ' . (int) $res['http'] ) );
		return array( 'available' => false, 'needs_permission' => false, 'error' => $msg, 'events' => 0, 'by_action' => array(), 'top_rules' => array() );
	}
	$by_action = array();
	$rules     = array();
	$events    = 0;
	foreach ( $groups as $g ) {
		$dims   = 'raw' === $dataset ? $g : (array) ( $g['dimensions'] ?? array() );
		$n      = 'raw' === $dataset ? max( 1, (int) ( $g['sampleInterval'] ?? 1 ) ) : (int) ( $g['count'] ?? 0 );
		$action = (string) ( $dims['action'] ?? 'unknown' );
		$rule   = (string) ( $dims['ruleId'] ?? '' );
		$source = (string) ( $dims['source'] ?? '' );
		$events += $n;
		$by_action[ $action ] = ( $by_action[ $action ] ?? 0 ) + $n;
		$key = $source . ':' . $rule;
		$rules[ $key ] = array( 'source' => $source, 'rule' => $rule, 'action' => $action, 'count' => ( $rules[ $key ]['count'] ?? 0 ) + $n );
	}
	arsort( $by_action );
	usort( $rules, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
	return array( 'available' => true, 'needs_permission' => false, 'error' => '', 'events' => $events, 'by_action' => $by_action, 'top_rules' => array_slice( array_values( $rules ), 0, 8 ), 'dataset' => $dataset, 'truncated' => 'raw' === $dataset && count( $groups ) >= SN_CF_MONITOR_RAW_LIMIT );
}

/**
 * Verify the token whatever its KIND. A User token answers
 * GET /user/tokens/verify; an Account token has no /user and answers
 * GET /accounts/{account}/tokens/verify instead (measured 2026-09-15: the
 * site ran one of each, in two tabs, until 14.10.0 made them one). Ask the
 * user route, and on refusal the account route when an account id is
 * known; record which kind answered.
 *
 * @param string      $zone_id Unused for verify; kept for symmetry with the readers.
 * @param string|null $token   15.2.2: a token other than the central one (the analytics override).
 * @return array<string,mixed> The token reading, plus `kind`: user | account | ''.
 */
function sn_cf_monitor_verify( $zone_id, $token = null ) {
	unset( $zone_id );
	$user = sn_cf_monitor_token_from( sn_cf_api_get( '/user/tokens/verify', $token ) );
	if ( ! empty( $user['verified'] ) ) {
		$user['kind'] = 'user';
		return $user;
	}
	$account = function_exists( 'sn_cf_get_account_id' ) ? (string) sn_cf_get_account_id() : '';
	if ( '' === $account || 'unreachable' === (string) ( $user['status'] ?? '' ) ) {
		$user['kind'] = '';
		return $user;
	}
	$acct = sn_cf_monitor_token_from( sn_cf_api_get( '/accounts/' . rawurlencode( $account ) . '/tokens/verify', $token ) );
	if ( ! empty( $acct['verified'] ) ) {
		$acct['kind'] = 'account';
		return $acct;
	}
	$user['kind']  = '';
	$user['error'] = trim( (string) $user['error'] . ' / ' . (string) $acct['error'], ' /' );
	return $user;
}

/**
 * Fetch all three readings and store them. The producer.
 *
 * @return array<string,mixed> The stored record.
 */
function sn_cf_monitor_refresh() {
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! sn_cf_is_configured() ) {
		$record = array( 'fetched_at' => time(), 'configured' => false, 'token' => null, 'zone' => null, 'firewall' => null );
		update_option( SN_CF_MONITOR_OPT, $record, false );
		return $record;
	}
	$zone_id = sn_cf_get_zone();
	$since   = gmdate( 'Y-m-d', time() - SN_CF_MONITOR_DAYS * DAY_IN_SECONDS );
	$until   = gmdate( 'Y-m-d' );
	$zone_q  = 'query ($zone: String!, $since: Date!, $until: Date!) { viewer { zones(filter: {zoneTag: $zone}) { httpRequests1dGroups(limit: 31, filter: {date_geq: $since, date_leq: $until}, orderBy: [date_ASC]) { dimensions { date } sum { requests cachedRequests bytes cachedBytes threats responseStatusMap { edgeResponseStatus requests } } } } } }';
	$fw_q    = 'query ($zone: String!, $since: Time!) { viewer { zones(filter: {zoneTag: $zone}) { firewallEventsAdaptiveGroups(limit: 200, filter: {datetime_geq: $since}, orderBy: [count_DESC]) { count dimensions { action source ruleId } } } } }';
	$fw_raw  = 'query ($zone: String!, $since: Time!) { viewer { zones(filter: {zoneTag: $zone}) { firewallEventsAdaptive(limit: ' . SN_CF_MONITOR_RAW_LIMIT . ', filter: {datetime_geq: $since}, orderBy: [datetime_DESC]) { action source ruleId sampleInterval } } } }';
	$fw_args = array( 'zone' => $zone_id, 'since' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) );
	$record  = array(
		'fetched_at' => time(),
		'configured' => true,
		'token'      => sn_cf_monitor_verify( $zone_id ),
		'zone'       => sn_cf_monitor_zone_from( sn_cf_graphql( $zone_q, array( 'zone' => $zone_id, 'since' => $since, 'until' => $until ) ) ),
		'firewall'   => sn_cf_monitor_firewall_from( sn_cf_graphql( $fw_q, $fw_args ) ),
	);
	// 15.0.1: "zone … does not have access to the path" is the PLAN, not a
	// grant: the grouped dataset is not on Free zones, and every zone read
	// grant Cloudflare offers (44 of them, 2026-09-15) left it refused. The
	// raw firewallEventsAdaptive is open to all plans, so read that instead
	// and group here. The grouped refusal is kept beside the reading.
	if ( ! empty( $record['firewall']['needs_permission'] ) ) {
		$refused = (string) $record['firewall']['error'];
		$raw     = sn_cf_monitor_firewall_from( sn_cf_graphql( $fw_raw, $fw_args ) );
		if ( ! empty( $raw['available'] ) ) {
			$raw['groups_refused'] = $refused;
			$record['firewall']    = $raw;
		} else {
			$record['firewall']['error_raw'] = (string) $raw['error'];
		}
	}
	update_option( SN_CF_MONITOR_OPT, $record, false );
	return $record;
}
add_action( SN_CF_MONITOR_HOOK, 'sn_cf_monitor_refresh' );

/**
 * The stored record, or null when the monitor has never run. Never fetches.
 *
 * @return array<string,mixed>|null
 */
function sn_cf_monitor_read() {
	$stored = get_option( SN_CF_MONITOR_OPT );
	return is_array( $stored ) ? $stored : null;
}

/**
 * Schedule the daily refresh, idempotently, on init.
 *
 * @return void
 */
function sn_cf_monitor_schedule() {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( SN_CF_MONITOR_HOOK ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', SN_CF_MONITOR_HOOK );
	}
}
add_action( 'init', 'sn_cf_monitor_schedule' );

/**
 * The permission sentence, one place.
 *
 * @return string
 */
function sn_cf_monitor_permission_hint( $dataset = 'zone' ) {
	// Zone analytics reads with Zone › Analytics › Read (measured).
	if ( 'firewall' === $dataset ) {
		// 15.0.1: the refusal names the ZONE, and that is a plan limit. The
		// grouped dataset is not on Free zones; measured refused under every
		// zone read grant Cloudflare offers (2026-09-15). The raw dataset is
		// open to all plans and the monitor reads it instead.
		return __( 'Cloudflare answers "does not have access to the path" for a dataset the zone\'s plan lacks, whatever the token holds; the grouped firewall dataset is not on this plan and the raw one, open to every plan, also refused. No grant changes that.', 'signal-and-noise-tools' );
	}
	return sprintf(
		/* translators: %s: the permission to add. */
		__( 'The token lacks %s. Edit the token in the Cloudflare dashboard (My Profile › API Tokens) and add it; the permissions it already has stay as they are.', 'signal-and-noise-tools' ),
		__( 'Zone › Analytics › Read', 'signal-and-noise-tools' )
	);
}

/**
 * The API-limits row for Cloudflare: a FIGURE-SIZED reading, like the
 * GitHub row beside it. 14.9.1: the first cut put the whole sentence here
 * (token, expiry, last call, the note about headers) and the kit list's
 * value never shrinks, so the label "Cloudflare API" was squeezed to nothing
 * on the live Dashboard. The sentence lives in the Monitor section; this
 * row says the one thing a glance needs.
 *
 * @param array<string,mixed>|null $record sn_cf_monitor_read().
 * @param array<string,mixed>      $last_purge get_option( 'sn_cf_last_purge' ).
 * @param int                      $now
 * @return array{value:string,dot:string,title:string}
 */
function sn_cf_monitor_api_row( $record, array $last_purge, $now ) {
	$title = __( 'Cloudflare publishes no rate-limit headers; this reads the daily monitor.', 'signal-and-noise-tools' );
	if ( ! empty( $last_purge['time'] ) ) {
		$title .= ' ' . sprintf( /* translators: %s: a relative time. */ __( 'Last call %s ago.', 'signal-and-noise-tools' ), human_time_diff( (int) $last_purge['time'], $now ) );
	}
	if ( ! is_array( $record ) ) {
		return array( 'value' => __( 'not run yet', 'signal-and-noise-tools' ), 'dot' => 'unknown', 'title' => $title );
	}
	if ( empty( $record['configured'] ) ) {
		return array( 'value' => __( 'not configured', 'signal-and-noise-tools' ), 'dot' => 'unknown', 'title' => $title );
	}
	$t = is_array( $record['token'] ) ? $record['token'] : array();
	if ( empty( $t['verified'] ) ) {
		return array( 'value' => sprintf( /* translators: %s: status. */ __( 'token %s', 'signal-and-noise-tools' ), (string) ( $t['status'] ?? 'unverified' ) ), 'dot' => 'err', 'title' => $title . ' ' . (string) ( $t['error'] ?? '' ) );
	}
	$status = (string) $t['status'];
	if ( 'active' !== $status ) {
		return array( 'value' => sprintf( __( 'token %s', 'signal-and-noise-tools' ), $status ), 'dot' => 'err', 'title' => $title );
	}
	$exp = '' !== (string) $t['expires_on'] ? strtotime( (string) $t['expires_on'] ) : 0;
	if ( $exp && $exp < $now + 14 * DAY_IN_SECONDS ) {
		return array( 'value' => sprintf( /* translators: %s: date. */ __( 'expires %s', 'signal-and-noise-tools' ), wp_date( 'Y-m-d', $exp ) ), 'dot' => 'warn', 'title' => $title );
	}
	return array( 'value' => __( 'token active', 'signal-and-noise-tools' ), 'dot' => '', 'title' => $title . ( $exp ? ' ' . sprintf( __( 'Expires %s.', 'signal-and-noise-tools' ), wp_date( 'Y-m-d', $exp ) ) : '' ) );
}
