<?php
/**
 * S&N Dashboard, Measurement > Search Console: Unanswered searches.
 *
 * The list snt_gsc_unanswered() derives (inc/search-console-unanswered.php),
 * painted as one card under the Sync box. A wide table takes its row alone
 * (docs/kit-leaf-anatomy.md), so its related readings (the window, the
 * floor, the count) ride in the card's status line. Its empty states are the
 * derive's own sentences, so this card and the classic twin say the same thing.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Unanswered searches card.
 *
 * @param array<string,mixed>|null $u From snt_gsc_unanswered(); null loads the live one.
 * @return string
 */
function search_console_unanswered_html( $u = null ) {
	if ( null === $u ) {
		if ( ! function_exists( 'snt_gsc_unanswered_live' ) ) {
			return '';
		}
		$u = \snt_gsc_unanswered_live();
	}
	$heading = __( 'Unanswered searches', 'signal-and-noise-tools' );
	$intro   = \snt_gsc_unanswered_intro( $u );
	if ( 'measured' !== $u['state'] || array() === $u['rows'] ) {
		$tone = 'fetch_failed' === $u['state'] ? 'warn' : 'neutral';
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_badge( $tone, (string) $u['note'] ) . '</p>', $intro );
	}
	$line = \snt_kit_status_line(
		array(
			array( 'label' => sprintf( /* translators: 1: start date, 2: end date. */ __( 'Window %1$s to %2$s', 'signal-and-noise-tools' ), (string) ( $u['window']['start'] ?? '?' ), (string) ( $u['window']['end'] ?? '?' ) ) ),
			array( 'label' => sprintf( /* translators: %d: impression floor. */ __( '%d+ impressions, 0 clicks', 'signal-and-noise-tools' ), (int) $u['floor'] ) ),
			array( 'tone' => $u['more'] > 0 ? 'warn' : 'neutral', 'label' => sprintf( /* translators: 1: shown, 2: total. */ __( '%1$d of %2$d queries', 'signal-and-noise-tools' ), count( $u['rows'] ), (int) $u['total'] ) ),
		)
	);
	$rows = array();
	foreach ( $u['rows'] as $r ) {
		$page = \snt_kit_code( (string) $r['page'], false );
		if ( ! empty( $r['no_note'] ) ) {
			$page .= ' ' . \snt_kit_badge( 'warn', __( 'No note answers this', 'signal-and-noise-tools' ) );
		}
		$rows[] = array(
			'_key'        => (string) $r['query'],
			'query'       => (string) $r['query'],
			'impressions' => (int) $r['impressions'],
			'position'    => (float) $r['position'],
			'page'        => array( 'html' => $page, 'text' => (string) $r['page'] ),
		);
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'query', 'label' => __( 'Query', 'signal-and-noise-tools' ) ),
			array( 'key' => 'impressions', 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'position', 'label' => __( 'Avg. position', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'page', 'label' => __( 'Page Google showed', 'signal-and-noise-tools' ) ),
		),
		$rows,
		array( 'stack_on_phone' => true, 'empty' => __( 'No query with impressions and no clicks in the window.', 'signal-and-noise-tools' ) )
	);
	$foot = '' !== (string) $u['note'] ? '<p class="snt-hint">' . \snt_kit_esc( (string) $u['note'] ) . '</p>' : '';
	return \snt_kit_section( $heading, $line . $table . $foot, $intro );
}
