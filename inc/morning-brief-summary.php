<?php
/**
 * Signal & Noise Tools: the morning brief's summary as short labeled lines,
 * the trust checks line, and the admin screens a watch points at.
 *
 * The summary used to be one paragraph. It is now one line per subject
 * (Health, Trust checks, Cron, Uptime, Versions, Last deploy, Search, Drift),
 * with the same sentences and numbers. The trust checks line exists because
 * the health total counts the `health` surface only, so a Ledger CI finding
 * (surface `integrity`) sat under "no fault-tier issues". It reads the stored
 * scan through snt_trust_reading(), the one reading both Trust checks screens
 * use; it never runs a check. Pure apart from admin_url().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/morning-brief-pointers.php';

/**
 * The four trust checks from a stored scan, or null when nothing can be read.
 *
 * @param mixed $scan sn_health_last_scan().
 * @return array<int,array{label:string,state:string,count:int,text:string}>|null
 */
function snt_morning_brief_trust( $scan ) {
	if ( ! is_array( $scan ) || ! function_exists( 'snt_trust_check_keys' ) || ! function_exists( 'snt_trust_reading' ) ) {
		return null;
	}
	$out = array();
	foreach ( snt_trust_check_keys() as $key => $meta ) {
		$r     = snt_trust_reading( $scan['checks'][ $key ] ?? null );
		$out[] = array( 'label' => (string) $meta['label'], 'state' => (string) $r['state'], 'count' => (int) $r['count'], 'text' => (string) $r['text'] );
	}
	return $out;
}

/** The trust checks line's words: "all clear", "not read", or each check that is not clear. */
function snt_morning_brief_trust_words( $trust ) {
	if ( ! is_array( $trust ) ) {
		return 'not read.'; // no stored scan, or no trust reader loaded: unknown, never clear.
	}
	$off = array_values( array_filter( $trust, static fn( $t ) => 'clear' !== $t['state'] ) );
	if ( array() === $off ) {
		return sprintf( 'all clear (%d of %d).', count( $trust ), count( $trust ) );
	}
	$words = array();
	foreach ( $off as $t ) {
		$words[] = $t['label'] . ': ' . ( 'not_run' === $t['state'] ? 'not read' : $t['text'] );
	}
	$clear = count( $trust ) - count( $off );
	return implode( '; ', $words ) . ( $clear > 0 ? sprintf( '; the other %d clear.', $clear ) : '.' );
}

/** Findings across the trust checks, for the subject's attention count. */
function snt_morning_brief_trust_findings( $trust ) {
	$n = 0;
	foreach ( (array) $trust as $t ) {
		$n += 'findings' === ( $t['state'] ?? '' ) ? (int) ( $t['count'] ?? 0 ) : 0;
	}
	return $n;
}

/** "last 4 weeks" for a 28-day window, "last N days" otherwise. */
function snt_morning_brief_window_label( array $window ) {
	$days = (int) round( ( strtotime( (string) ( $window['end'] ?? '' ) ) - strtotime( (string) ( $window['start'] ?? '' ) ) ) / 86400 ) + 1;
	return 0 === $days % 7 ? sprintf( 'last %d weeks', $days / 7 ) : sprintf( 'last %d days', $days );
}

/**
 * The summary as labeled lines: label, sentence, and an optional screen link.
 *
 * @param array $data From snt_morning_brief_collect().
 * @return array<int,array{0:string,1:string,2?:array{label:string,url:string}}>
 */
function snt_morning_brief_summary_rows( $data ) {
	$s    = snt_morning_brief_sentences( $data );
	$rows = array();
	foreach ( $s as $label => $parts ) {
		$rows[] = array( $label, sn_mail_sentences( (array) $parts ) );
	}
	if ( array_key_exists( 'trust', (array) $data ) ) {
		$trust_row = array( 'Trust checks', snt_morning_brief_trust_words( $data['trust'] ), array( 'label' => 'Integrity › Trust checks', 'url' => snt_morning_brief_screen_url( 'tools', 'trust' ) ) );
		array_splice( $rows, 1, 0, array( $trust_row ) );
	}
	return $rows;
}

/** The summary's text part: "Label: sentence", and a link as "Screen: URL" on its own line. */
function snt_morning_brief_summary_text( $data ) {
	$out = '';
	foreach ( snt_morning_brief_summary_rows( $data ) as $r ) {
		$out .= $r[0] . ': ' . $r[1] . "\n" . ( isset( $r[2] ) ? $r[2]['label'] . ': ' . $r[2]['url'] . "\n" : '' );
	}
	return $out;
}

/** The summary's HTML part: a table, the label as each row's header. */
function snt_morning_brief_summary_html( $data ) {
	$rows = array();
	foreach ( snt_morning_brief_summary_rows( $data ) as $r ) {
		$rows[] = array( $r[0], esc_html( $r[1] ) . ( isset( $r[2] ) ? ' ' . sn_mail_link_html( $r[2]['label'], $r[2]['url'] ) : '' ) );
	}
	return sn_mail_rows_html( $rows );
}
