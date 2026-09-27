<?php
/**
 * The analytics worker's network lists, mirrored so the plugin can decide the
 * traffic class at READ time from stored fields (blob7 class, blob8 browser,
 * blob12 ASN org) instead of trusting the class written at ingest. A classifier
 * change then applies to all retained history (~92 days), not only new hits.
 *
 * SOURCE OF TRUTH: signal-and-noise-analytics-worker src/index.js (origin/main
 * 2c5a268, worker 1.21.6): DC_ASN, RELAY_ASN, HOSTING_ASN. Changing a worker
 * list means updating this mirror (the regex source AND its term list) and the
 * sha256 pins in tests/analytics-network-terms.php in the same arc.
 *
 * AE SQL has no regex (match() is refused), so each regex is mirrored as a list
 * of case-insensitive substrings for ILIKE. Alternations expand into terms
 * ("google (llc|cloud)" is two). \b word boundaries cannot be expressed: aws,
 * ovh, oracle, m247 and code200 match as plain substrings, a known looseness
 * ("Lawson" would match aws). The test proves the terms agree with the regex on
 * every ASN org seen in 90 days; a new org that trips the looseness shows up as
 * a failing fixture once added.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// AE refuses a statement over 10,000 characters; sn_analytics_query fails closed first.
const SNT_ANALYTICS_SQL_MAX_CHARS = 10000;

const SNT_ANALYTICS_DC_ASN_SOURCE      = 'amazon|\baws\b|google (llc|cloud)|microsoft|azure|hetzner|\bovh\b|digitalocean|linode|vultr|scaleway|leaseweb|contabo|\boracle\b|alibaba|tencent|gcore|choopa|\bm247\b|datacamp|akamai|fastly|colocrossing|quadranet|hostwinds';
const SNT_ANALYTICS_RELAY_ASN_SOURCE   = 'akamai|fastly';
const SNT_ANALYTICS_HOSTING_ASN_SOURCE = 'palo alto networks|chiron software|hostroyale|logicweb|trafficforce|\bcode200\b|egihosting|intac services|pebblehost|kaopu cloud|for idc & cloud';

/**
 * Lower-case substring terms per list. Allowed characters: a-z 0-9 space &
 * (no ILIKE wildcard, no quote), pinned by the test.
 *
 * @return array{dc:string[],relay:string[],hosting:string[]}
 */
function sn_analytics_network_terms() {
	return array(
		'dc'      => array( 'amazon', 'aws', 'google llc', 'google cloud', 'microsoft', 'azure', 'hetzner', 'ovh', 'digitalocean', 'linode', 'vultr', 'scaleway', 'leaseweb', 'contabo', 'oracle', 'alibaba', 'tencent', 'gcore', 'choopa', 'm247', 'datacamp', 'akamai', 'fastly', 'colocrossing', 'quadranet', 'hostwinds' ),
		'relay'   => array( 'akamai', 'fastly' ),
		'hosting' => array( 'palo alto networks', 'chiron software', 'hostroyale', 'logicweb', 'trafficforce', 'code200', 'egihosting', 'intac services', 'pebblehost', 'kaopu cloud', 'for idc & cloud' ),
	);
}

/**
 * "(blob12 ILIKE '%a%' OR ...)" for a term list. PURE.
 *
 * @param string[] $terms Substring terms.
 * @return string
 */
function sn_analytics_org_ilike_any( array $terms ) {
	$out = array();
	foreach ( $terms as $t ) {
		$out[] = "blob12 ILIKE '%" . preg_replace( '/[^a-z0-9 &]/', '', strtolower( (string) $t ) ) . "%'";
	}
	return '(' . implode( ' OR ', $out ) . ')';
}

/**
 * The network half of the worker's classify(), for a non-bot row: true when
 * the row reads human. Relay Safari is human; a DC or hosting org is suspect;
 * anything else (including an empty org) is human. PURE.
 *
 * @return string
 */
function sn_analytics_network_human_sql() {
	$t = sn_analytics_network_terms();
	return "(blob8 = 'Safari' AND " . sn_analytics_org_ilike_any( $t['relay'] ) . ') OR NOT '
		. sn_analytics_org_ilike_any( array_merge( $t['dc'], $t['hosting'] ) );
}

/**
 * The read-time class as a SELECT expression, for builders that group by
 * class: SELECT <this> AS class ... GROUP BY class. PURE.
 *
 * @return string
 */
function sn_analytics_class_select() {
	return "if(blob7 = 'bot', 'bot', if(" . sn_analytics_network_human_sql() . ", 'human', 'suspect'))";
}

/**
 * Whether a statement exceeds AE's character cap. PURE.
 *
 * @param string $sql Statement.
 * @return bool
 */
function sn_analytics_sql_too_long( $sql ) {
	return strlen( (string) $sql ) > SNT_ANALYTICS_SQL_MAX_CHARS;
}
