<?php
/**
 * Signal & Noise Tools — the Cloudflare firewall event log, read daily.
 *
 * 15.1.0. The raw `firewallEventsAdaptive` dataset is open to every plan and
 * carries one row per sample with the fields the origin can never see: what
 * Cloudflare stopped before WordPress ran. The monitor keeps the counts; this
 * keeps the rows (last 24 hours, one page) so two readers can use them: the
 * WAF witness in the health check (a custom-rule block on an abilities path
 * IS the perimeter firing, measured from Cloudflare's own side) and the
 * Monitor leaf (top blocked paths and countries).
 *
 * The dataset is adaptively sampled: under load a row stands for
 * `sampleInterval` events, so every count here weighs the row by it.
 * Read-only against the API; never purges, never writes there.
 *
 * @package SignalNoiseTools
 * @since 15.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_FW_EVENTS_OPT = 'sn_cf_firewall_events';
// ponytail: one page; a zone past 10,000 samples a day reads a floor (`truncated`). Page when it happens.
const SN_CF_FW_EVENTS_LIMIT = 10000;
const SN_CF_FW_EVENTS_FIELDS = 'datetime action source ruleId description clientIP clientAsn clientCountryName clientRequestPath clientRequestQuery userAgent rayName sampleInterval';
// The custom rule that guards the abilities API, by the name it carries in
// the Security Events log (`description`); see sn_health_cf_waf_abilities_probe().
const SN_CF_FW_ABILITIES_RULE = 'abilities';

/**
 * The GraphQL query for one page of the last 24 hours, newest first.
 *
 * @return string
 */
function sn_cf_firewall_events_query() {
	return 'query ($zone: String!, $since: Time!) { viewer { zones(filter: {zoneTag: $zone}) { firewallEventsAdaptive(limit: ' . SN_CF_FW_EVENTS_LIMIT . ', filter: {datetime_geq: $since}, orderBy: [datetime_DESC]) { ' . SN_CF_FW_EVENTS_FIELDS . ' } } } }';
}

/**
 * The reading: pure over the GraphQL body. Rows keep every field the query
 * asked for plus `weight` (the sampleInterval, never below 1).
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @return array{available:bool,needs_permission:bool,error:string,rows:array<int,array<string,mixed>>,truncated:bool}
 */
function sn_cf_firewall_events_from( array $res ) {
	$gap = static function ( $why, $perm = false ) {
		return array( 'available' => false, 'needs_permission' => $perm, 'error' => (string) $why, 'rows' => array(), 'truncated' => false );
	};
	if ( 0 === (int) $res['http'] ) {
		return $gap( $res['error'] );
	}
	if ( sn_cf_graphql_needs_permission( $res['body'] ) ) {
		return $gap( $res['body']['errors'][0]['message'] ?? '', true );
	}
	$rows = $res['body']['data']['viewer']['zones'][0]['firewallEventsAdaptive'] ?? null;
	if ( ! is_array( $rows ) ) {
		return $gap( $res['body']['errors'][0]['message'] ?? ( 'HTTP ' . (int) $res['http'] ) );
	}
	$out = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$row['weight'] = max( 1, (int) ( $row['sampleInterval'] ?? 1 ) );
		// The UA is the one field that can run long; the readers only glance at it.
		$row['userAgent'] = substr( (string) ( $row['userAgent'] ?? '' ), 0, 160 );
		$out[]            = $row;
	}
	return array( 'available' => true, 'needs_permission' => false, 'error' => '', 'rows' => $out, 'truncated' => count( $rows ) >= SN_CF_FW_EVENTS_LIMIT );
}

/**
 * Fetch the last 24 hours and store the reading (one option, never autoloaded).
 *
 * @return array<string,mixed> The stored record.
 */
function sn_cf_firewall_events_refresh() {
	if ( ! sn_cf_is_configured() ) {
		$record = array( 'fetched_at' => time(), 'configured' => false, 'available' => false, 'needs_permission' => false, 'error' => '', 'rows' => array(), 'truncated' => false );
		update_option( SN_CF_FW_EVENTS_OPT, $record, false );
		return $record;
	}
	$res    = sn_cf_graphql( sn_cf_firewall_events_query(), array( 'zone' => sn_cf_get_zone(), 'since' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ) );
	$record = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_firewall_events_from( $res );
	update_option( SN_CF_FW_EVENTS_OPT, $record, false );
	return $record;
}
add_action( SN_CF_MONITOR_HOOK, 'sn_cf_firewall_events_refresh', 20 );

/**
 * The stored reading, or null when never run. Never fetches.
 *
 * @return array<string,mixed>|null
 */
function sn_cf_firewall_events_read() {
	$stored = get_option( SN_CF_FW_EVENTS_OPT );
	return is_array( $stored ) ? $stored : null;
}

/**
 * Weighted counts of one field across rows, largest first.
 *
 * @param array<int,array<string,mixed>> $rows  Rows from the reading.
 * @param string                         $field clientRequestPath, clientCountryName, action, ...
 * @param int                            $n     How many to keep.
 * @return array<string,int> value => weighted count.
 */
function sn_cf_firewall_events_top( array $rows, $field, $n = 8 ) {
	$counts = array();
	foreach ( $rows as $row ) {
		$key            = (string) ( $row[ $field ] ?? '' );
		$key            = '' !== $key ? $key : '(none)';
		$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + max( 1, (int) ( $row['weight'] ?? 1 ) );
	}
	arsort( $counts );
	return array_slice( $counts, 0, max( 1, (int) $n ), true );
}

/**
 * The rows that ARE the abilities WAF rule firing: a custom rule
 * (`source: firewallCustom`) whose name carries the rule's word, blocking a
 * request whose path or query names the abilities API. A managed-rule or
 * bot block on the same path is not this rule and does not count; neither
 * does this rule matching another path (a negative control the test pins).
 *
 * @param array<int,array<string,mixed>> $rows Rows from the reading.
 * @return array<int,array<string,mixed>>
 */
function sn_cf_firewall_events_abilities_blocks( array $rows ) {
	$hits = array();
	foreach ( $rows as $row ) {
		$target = (string) ( $row['clientRequestPath'] ?? '' ) . '?' . (string) ( $row['clientRequestQuery'] ?? '' );
		if ( 'block' !== (string) ( $row['action'] ?? '' ) || 'firewallCustom' !== (string) ( $row['source'] ?? '' ) ) {
			continue;
		}
		if ( false === stripos( (string) ( $row['description'] ?? '' ), SN_CF_FW_ABILITIES_RULE ) || false === stripos( $target, 'wp-abilities' ) ) {
			continue;
		}
		$hits[] = $row;
	}
	return $hits;
}
