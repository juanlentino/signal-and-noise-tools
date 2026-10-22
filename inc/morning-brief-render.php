<?php
/**
 * Signal & Noise Tools: the morning brief's watch section and its HTML part.
 *
 * A watch note used to be pasted into the paragraph verbatim, so the index_gap
 * watch arrived as one run-on sentence of raw Search Console URLs. Now a ripe
 * index_gap watch carries the ranked indexing request list (the one in
 * Integrity › Reports) as rows, and every watch renders as its own block: one
 * line per page in the text part, one list item with a short Inspect link in
 * the HTML part. A note with any other URL gets the shared link treatment
 * (inc/mail-text.php). Pure apart from the reads in the collector.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mail-text.php';

/**
 * Attach the ranked request list to a ripe index_gap watch. Stored reads only
 * (sn_indexing_report() never inspects or pings), so a cron send spends no quota.
 *
 * @param array $watches Ripe watch rows.
 * @return array
 */
function snt_morning_brief_watch_rows( array $watches ) {
	foreach ( $watches as $k => $w ) {
		if ( 'index_gap' !== ( $w['id'] ?? '' ) || ! function_exists( 'sn_indexing_report' ) ) {
			continue;
		}
		// The brief always sends: a failing report leaves the watch's own note.
		try {
			$report = sn_indexing_report();
		} catch ( \Throwable $e ) {
			$report = null;
		}
		if ( ! is_array( $report ) ) {
			continue;
		}
		$bing = function_exists( 'sn_bing_url_info_data' ) ? (array) sn_bing_url_info_data() : array();
		$rows = array();
		foreach ( (array) ( $report['request_list'] ?? array() ) as $r ) {
			$path   = (string) ( $r['path'] ?? '' );
			$label  = function_exists( 'sn_bing_url_info_label' ) ? sn_bing_url_info_label( $bing[ $path ] ?? null ) : null;
			$rows[] = array(
				'path'           => $path,
				'coverage_state' => (string) ( $r['coverage_state'] ?? '' ),
				'days'           => (int) ( $r['days'] ?? 0 ),
				'inspect_url'    => (string) ( $r['inspect_url'] ?? '' ),
				'bing'           => null === $label ? 'Bing not read yet' : (string) $label,
			);
		}
		$watches[ $k ]['rows']       = $rows;
		$watches[ $k ]['rows_total'] = (int) ( $report['request_total'] ?? count( $rows ) );
	}
	return $watches;
}

/**
 * The words around a watch: heading, the lead-in to its rows, the closing line.
 *
 * @param array $w A ripe watch.
 * @return array{head:string,lead:string,close:string,read:string}
 */
function snt_morning_brief_watch_words( array $w ) {
	$rows  = (array) ( $w['rows'] ?? array() );
	$total = max( count( $rows ), (int) ( $w['rows_total'] ?? 0 ) );
	$days  = defined( 'SNT_INDEXING_REQUEST_DAYS' ) ? SNT_INDEXING_REQUEST_DAYS : 7;
	$lead  = sprintf( '%d %s on the indexing request list in Integrity › Reports, not indexed %d or more days after publish', $total, 1 === $total ? 'page is' : 'pages are', $days );
	$lead .= $total > count( $rows ) ? sprintf( '. The top %d, most urgent first:', count( $rows ) ) : '. Most urgent first:';
	return array(
		'head'  => sn_mail_sentence( 'Watch due: ' . (string) ( $w['label'] ?? $w['id'] ?? 'unnamed watch' ) ),
		'lead'  => $lead,
		'close' => 'Search Console\'s API cannot request indexing: open each Inspect link and click Request indexing.',
		'read'  => '' !== (string) ( $w['read'] ?? '' ) ? sn_mail_sentence( 'Where to read it: ' . $w['read'] ) : '',
	);
}

/** One page as words: path, Google's state, days since publish, Bing. */
function snt_morning_brief_row_words( array $r ) {
	return sprintf( '%s: %s, %d %s since publish, %s', $r['path'], $r['coverage_state'], $r['days'], 1 === (int) $r['days'] ? 'day' : 'days', $r['bing'] );
}

/**
 * The text part's watch blocks.
 *
 * @param array $watches Ripe watches.
 * @return string '' when none are due.
 */
function snt_morning_brief_watches_text( array $watches ) {
	$out = '';
	foreach ( $watches as $w ) {
		$t = snt_morning_brief_watch_words( $w );
		if ( ! empty( $w['rows'] ) ) {
			$lines = array( $t['head'], $t['lead'] );
			foreach ( $w['rows'] as $r ) {
				$lines[] = '- ' . snt_morning_brief_row_words( $r );
				$lines[] = '  Inspect: ' . $r['inspect_url'];
			}
			$lines[] = $t['close'];
			$out    .= "\n" . implode( "\n", array_filter( array_merge( $lines, array( sn_mail_text_links( $t['read'] ) ) ), 'strlen' ) ) . "\n";
			continue;
		}
		$out .= "\n" . $t['head'] . "\n" . sn_mail_text_links( sn_mail_sentences( array( (string) ( $w['note'] ?? '' ) ) ) . ( '' !== $t['read'] ? "\n" . $t['read'] : '' ) ) . "\n";
	}
	return $out;
}

/**
 * The HTML part: the same sentences and watches, escaped, with short links.
 *
 * @param array $data From snt_morning_brief_collect().
 * @return string
 */
function snt_morning_brief_compose_html( $data ) {
	$title = 'Signal & Noise morning operations brief';
	$body  = '<h1 style="font-size:20px;margin:0 0 12px;">' . esc_html( $title ) . '</h1>'
		. '<p>' . esc_html( sn_mail_sentences( snt_morning_brief_sentences( $data ) ) ) . '</p>';
	foreach ( (array) ( $data['watches'] ?? array() ) as $w ) {
		$t     = snt_morning_brief_watch_words( (array) $w );
		$body .= '<h2 style="font-size:17px;margin:20px 0 8px;">' . esc_html( $t['head'] ) . '</h2>';
		if ( ! empty( $w['rows'] ) ) {
			$body .= '<p>' . esc_html( $t['lead'] ) . '</p><ul>';
			foreach ( $w['rows'] as $r ) {
				$body .= '<li>' . esc_html( snt_morning_brief_row_words( $r ) ) . '. <a href="' . esc_url( $r['inspect_url'] ) . '">Inspect</a></li>';
			}
			$body .= '</ul><p>' . esc_html( $t['close'] ) . '</p>';
		} else {
			$body .= '<p>' . sn_mail_html_links( sn_mail_sentences( array( (string) ( $w['note'] ?? '' ) ) ) ) . '</p>';
		}
		$body .= '' !== $t['read'] ? '<p>' . sn_mail_html_links( $t['read'] ) . '</p>' : '';
	}
	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title></head>'
		. '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:40em;">'
		. $body . "</body></html>\n";
}
