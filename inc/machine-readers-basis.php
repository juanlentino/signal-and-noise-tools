<?php
/**
 * Signal & Noise Tools, Machine Readers: the evidence basis of a count.
 *
 * Every count attributed to an identity says how that identity is known, in
 * one of five words:
 *
 *   signed      a cryptographic signature checked out: Web Bot Auth `valid`,
 *               or the provenance ledger's own signature.
 *   verified    Cloudflare's verified-bot category is set on the hit
 *               (cf.verified_bot_category, the sensor's verified_bot field),
 *               on a day the sensor recorded it. Not a signature.
 *   claimed     the client said so: a user agent the taxonomy matched.
 *   inferred    a rule or a score decided it: the human/suspect/bot classes,
 *               the beacon's likely-automated score.
 *   unmeasured  the signal was not recorded for these hits.
 *
 * "verified" keeps the rights evidence meaning on purpose: the class of a row
 * comes from sn_rights_evidence_identity_class() and its since date, called
 * here, never copied, so the two readings cannot drift. PURE: no WordPress
 * calls, no I/O; rows in, fresh arrays out.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The five evidence bases, strongest first. */
const SN_MR_BASIS_VALUES = array( 'signed', 'verified', 'claimed', 'inferred', 'unmeasured' );

/**
 * Taxonomy ids of robots.txt control tokens: names a vendor reads in robots.txt
 * to decide how content it already collected may be used. Neither is a user
 * agent of its own, so a request presenting one is never the vendor's crawler.
 * Google: https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers
 * Apple:  https://support.apple.com/en-us/119829
 * The taxonomy marks both `observable: false`; rows carry only the agent id,
 * so the list is mirrored here.
 */
const SN_MR_CONTROL_TOKENS = array(
	'google-extended'    => 'Google-Extended',
	'apple-bot-extended' => 'Applebot-Extended',
);

/**
 * Which Cloudflare verified-bot categories fit a claimed purpose. A hit is
 * `verified_matched` when it is verified AND its category is listed for its
 * purpose: Cloudflare vouched for the client, and for this kind of client.
 *
 * Built from the taxonomy's purpose vocabulary and Cloudflare's documented
 * category strings (https://developers.cloudflare.com/bots/concepts/bot/verified-bots/).
 * Each row lists the legacy string cf.verified_bot_category returns and the
 * July 2026 behavior name, because the strings the edge actually stores could
 * not be read back through any tool when this was written. `dev` (libraries)
 * and `unknown` (unmapped) have no row: they never match.
 */
const SN_MR_PURPOSE_CATEGORIES = array(
	'train'     => array( 'AI Crawler', 'Training' ),
	'search'    => array( 'Search Engine Crawler', 'AI Search', 'Search' ),
	'retrieval' => array( 'AI Assistant', 'AI Search', 'Agent', 'Search' ),
	'user'      => array( 'AI Assistant', 'Agent' ),
	'archive'   => array( 'Archiver', 'Academic Research' ),
	'ops'       => array( 'Monitoring & Analytics', 'Webhooks', 'Monitoring & Operations' ),
	'seo'       => array( 'Search Engine Optimization', 'SEO' ),
	'feed'      => array( 'Feed Fetcher', 'Aggregator', 'Feed Fetching' ),
	'social'    => array( 'Page Preview', 'Social Media Marketing', 'Social / Link Preview' ),
	'security'  => array( 'Security', 'Security Testing' ),
	'ads'       => array( 'Advertising & Marketing', 'Ads Verification' ),
);

/**
 * Whether a row presents a control token (never credited to the vendor).
 *
 * @param array $row Aggregate row.
 * @return bool
 */
function snt_mr_is_impersonation( $row ) {
	return is_array( $row ) && isset( SN_MR_CONTROL_TOKENS[ (string) ( $row['agent'] ?? '' ) ] );
}

/**
 * A row's verification class, by the rights evidence helper: verified,
 * unverified, or unverifiable (a day before the sensor recorded the category,
 * the partial day it began, or no day at all).
 *
 * @param array $row Aggregate row (day, verified_bot).
 * @return string
 */
function snt_mr_row_verification( $row ) {
	$day = is_array( $row ) ? (string) ( $row['day'] ?? '' ) : '';
	// A row the normalizer could not date cannot be placed before or after the
	// since date ('' would sort after it and read as verified).
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! function_exists( 'sn_rights_evidence_identity_class' ) ) {
		return 'unverifiable';
	}
	return sn_rights_evidence_identity_class( $day, (string) ( $row['verified_bot'] ?? '' ) );
}

/**
 * Verified AND verified as the kind of client its purpose claims.
 *
 * @param array $row Aggregate row (day, verified_bot, purpose).
 * @return bool
 */
function snt_mr_row_verified_matched( $row ) {
	if ( 'verified' !== snt_mr_row_verification( $row ) ) {
		return false;
	}
	$fits = SN_MR_PURPOSE_CATEGORIES[ (string) ( $row['purpose'] ?? '' ) ] ?? array();
	return in_array( strtolower( trim( (string) $row['verified_bot'] ) ), array_map( 'strtolower', $fits ), true );
}

/**
 * The empty split.
 *
 * @return array{claimed:int,verified:int,verified_matched:int,unverified:int,unverifiable:int}
 */
function snt_mr_verification_zero() {
	return array( 'claimed' => 0, 'verified' => 0, 'verified_matched' => 0, 'unverified' => 0, 'unverifiable' => 0 );
}

/**
 * A split with one row added. claimed = verified + unverified + unverifiable;
 * verified_matched is the part of verified whose category fits the purpose.
 *
 * @param array $tally From snt_mr_verification_zero().
 * @param array $row   Aggregate row.
 * @return array A new split.
 */
function snt_mr_verification_add( array $tally, $row ) {
	$hits                  = max( 0, (int) ( $row['hits'] ?? 0 ) );
	$tally['claimed']     += $hits;
	$tally[ snt_mr_row_verification( $row ) ] += $hits;
	$tally['verified_matched'] += snt_mr_row_verified_matched( $row ) ? $hits : 0;
	return $tally;
}

/**
 * The split per value of one row field (family, purpose), as a JSON object.
 *
 * @param array  $rows  Aggregate rows.
 * @param string $field Row key to group by.
 * @return object Keyed splits ({} when no rows, never []).
 */
function snt_mr_verification_by( array $rows, $field ) {
	$out = array();
	foreach ( $rows as $row ) {
		$key         = (string) ( $row[ $field ] ?? '' );
		$out[ $key ] = snt_mr_verification_add( $out[ $key ] ?? snt_mr_verification_zero(), $row );
	}
	ksort( $out );
	return (object) $out;
}

/**
 * Hits presenting a control token, per token: never the vendor's crawler.
 *
 * @param array $rows Aggregate rows.
 * @return array<int,array{agent:string,token:string,hits:int,networks:array,basis:string}> Hits descending.
 */
function snt_mr_impersonations( array $rows ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( ! snt_mr_is_impersonation( $row ) ) {
			continue;
		}
		$agent = (string) $row['agent'];
		$hits  = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$net   = (string) ( $row['network'] ?? '' );
		$by[ $agent ]['hits'] = ( $by[ $agent ]['hits'] ?? 0 ) + $hits;
		if ( '' !== $net ) {
			$by[ $agent ]['networks'][ $net ] = ( $by[ $agent ]['networks'][ $net ] ?? 0 ) + $hits;
		}
	}
	$out = array();
	foreach ( $by as $agent => $a ) {
		$nets = (array) ( $a['networks'] ?? array() );
		arsort( $nets );
		$list = array();
		foreach ( array_slice( $nets, 0, 5, true ) as $net => $h ) {
			$list[] = array( 'network' => (string) $net, 'hits' => (int) $h );
		}
		$out[] = array( 'agent' => (string) $agent, 'token' => SN_MR_CONTROL_TOKENS[ $agent ], 'hits' => (int) $a['hits'], 'networks' => $list, 'basis' => 'claimed' );
	}
	usort( $out, static fn( $x, $y ) => $y['hits'] <=> $x['hits'] );
	return $out;
}

/**
 * How a purpose reads on screen. The stored value `unknown` means the taxonomy
 * has no entry for the reader, so it displays as "unmapped"; the key is
 * unchanged everywhere it is stored or returned.
 *
 * @param string $purpose Stored purpose.
 * @return string
 */
function snt_mr_purpose_label( $purpose ) {
	$purpose = (string) $purpose;
	return ( '' === $purpose || 'unknown' === $purpose ) ? 'unmapped' : $purpose;
}
