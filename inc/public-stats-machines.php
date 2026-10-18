<?php
/**
 * Signal & Noise Tools: /stats, the Humans and machines column.
 *
 * 2026-10-11: the column speaks the readership evidence the dashboard does.
 * The headline bar sets presumed-human views against machine READERS (this
 * site's own monitors and checks are not readers; the full total is said in
 * the note), AI training is shown claimed against proof, who the machines
 * are reads as rows (inc/public-stats-who.php: control tokens and reads that
 * failed a check nested where they sit), and the visitor-days the beacon scores
 * likely automated sit beside the views, never subtracted. Every figure
 * comes from the hourly snapshot or the analytics store; a part that was not
 * read is left out, never painted as 0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/public-stats-who.php'; // who the machines are, as rows, in the shared words.

/**
 * Humans and machines, the third column. '' when no snapshot of this window.
 *
 * @param array $data The assembled payload.
 * @return string
 */
function sn_public_stats_machines_html( $data ) {
	$m = $data['machines'] ?? null;
	if ( ! is_array( $m ) ) {
		return '';
	}
	$views = (int) ( $data['views'] ?? 0 );
	$total = (int) $m['total'];
	// Own tools are known only from a measured split; without one the bar stays every machine read.
	$own     = isset( $m['split']['first_party'] ) ? (int) $m['split']['first_party'] : null;
	$readers = null === $own ? $total : max( 0, $total - $own );
	$max     = max( 1, $views, $readers );
	$out     = '<section class="sn-public-stats__col sn-public-stats__machines"><h2>' . esc_html__( 'Humans and machines', 'signal-and-noise-tools' ) . '</h2>'
		. sn_public_stats_bars_html( array(
			array( 'label' => __( 'Presumed human views', 'signal-and-noise-tools' ), 'text' => number_format_i18n( $views ), 'pct' => 100 * $views / $max ),
			array( 'label' => null === $own ? __( 'Machine reads', 'signal-and-noise-tools' ) : __( 'Machine readers', 'signal-and-noise-tools' ), 'text' => number_format_i18n( $readers ), 'pct' => 100 * $readers / $max ),
		) )
		. sn_public_stats_automated_html( $data['likely_automated'] ?? null );
	// 2026-10-18: the parts as rows, each with the rows that sit inside it.
	$who = sn_public_stats_who_rows( $m );
	if ( array() !== $who ) {
		$out .= '<h3>' . esc_html__( 'Who the machines are', 'signal-and-noise-tools' ) . '</h3>' . sn_public_stats_bars_html( $who ) . sn_public_stats_who_note( $m );
	}
	/* translators: 1: every machine read, 2: this site's own. */
	$all_line = null === $own ? '' : sprintf( __( '%1$s machine reads in all; %2$s were this site’s own monitors and checks, not readers.', 'signal-and-noise-tools' ), number_format_i18n( $total ), number_format_i18n( $own ) ) . ' ';
	return $out . '<p class="sn-public-stats__note">' . esc_html( $all_line . __( 'Automated counts bot pageviews that reached the tracker. Machine reads count every request the edge sensor saw. They are different measures.', 'signal-and-noise-tools' ) )
		. ' <a href="https://github.com/juanlentino/signal-and-noise-provenance">' . esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . '</a></p></section>';
}

/**
 * AI training, claimed against proof: its own section under the columns, so
 * the bar runs the page's width (the verified sliver reads at its true size)
 * and Humans and machines stays a column the height of its neighbors. One
 * sentence, one decorative bar, a key that carries every part as a count
 * (verified by source once vendor ranges are measured). The control tokens
 * and the reads that failed a check are rows under Who the machines are.
 * '' for an older snapshot or another window.
 *
 * @param array $data The assembled payload.
 * @return string
 */
function sn_public_stats_training_html( $data ) {
	$m = $data['machines'] ?? null;
	$t = is_array( $m ) ? ( $m['train'] ?? null ) : null;
	if ( ! is_array( $t ) || (int) ( $t['claimed'] ?? 0 ) < 1 ) {
		return '';
	}
	$claimed = (int) $t['claimed'];
	$l       = snt_mr_reader_labels();
	// Vendor ranges are their own part of verified once the sensor measures
	// them (the split says so); before that every verified read is Cloudflare's.
	$vm    = isset( $m['split']['vendor_verified'] );
	$vv    = $vm ? (int) ( $t['vendor_verified'] ?? 0 ) : 0;
	$parts = array(
		array( 'verified', $l['verified']['label'] . ' ' . $l['by_cloudflare']['label'], (int) ( $t['verified'] ?? 0 ) - $vv, false ),
		array( 'verified', $l['verified']['label'] . ' ' . $l['by_vendor']['label'], $vv, $vm ),
		array( 'claimed', $l['unverified']['label'], (int) ( $t['unverified'] ?? 0 ), false ),
		array( 'before', __( 'Before verification was recorded', 'signal-and-noise-tools' ), (int) ( $t['unverifiable'] ?? 0 ), false ),
	);
	$bar = '';
	$key = '';
	foreach ( $parts as list( $part, $label, $n, $keep ) ) {
		$bar .= $n < 1 ? '' : '<span data-part="' . $part . '" style="flex-grow:' . esc_attr( (string) $n ) . '"></span>';
		$key .= $n < 1 && ! $keep ? '' : '<li><span class="sn-public-stats__key-sw" data-part="' . $part . '"></span>' . esc_html( $label ) . ' <span class="sn-public-stats__key-n">' . esc_html( number_format_i18n( $n ) ) . '</span></li>';
	}
	$lead = $vm
		/* translators: 1: reads claiming AI training, 2: verified. */
		? sprintf( esc_html__( '%1$s reads came from crawlers that say they gather AI training data. %2$s of them were verified.', 'signal-and-noise-tools' ), '<strong>' . esc_html( number_format_i18n( $claimed ) ) . '</strong>', '<strong>' . esc_html( number_format_i18n( (int) ( $t['verified'] ?? 0 ) ) ) . '</strong>' )
		/* translators: 1: reads claiming AI training, 2: verified by Cloudflare. */
		: sprintf( esc_html__( '%1$s reads came from crawlers that say they gather AI training data. Cloudflare verified %2$s of them.', 'signal-and-noise-tools' ), '<strong>' . esc_html( number_format_i18n( $claimed ) ) . '</strong>', '<strong>' . esc_html( number_format_i18n( (int) ( $t['verified'] ?? 0 ) ) ) . '</strong>' );
	return '<section class="sn-public-stats__training"><h2>' . esc_html__( 'AI training, claimed against proof', 'signal-and-noise-tools' ) . '</h2><p class="sn-public-stats__claim">' . $lead . '</p>'
		. '<span class="sn-public-stats__proof" aria-hidden="true">' . $bar . '</span><ul class="sn-public-stats__key">' . $key . '</ul></section>';
}

/**
 * The presumed-human visitor-days the beacon scores likely automated, beside
 * the views. '' until a day is measured: never a 0, never a promise.
 *
 * @param array|null $la sn_analytics_likely_automated().
 * @return string
 */
function sn_public_stats_automated_html( $la ) {
	if ( ! is_array( $la ) || ! is_int( $la['visitor_days'] ?? null ) ) {
		return '';
	}
	$measured = (int) ( $la['measured_days'] ?? 0 );
	$days     = $measured + (int) ( $la['unmeasured_days'] ?? 0 );
	/* translators: 1: likely automated, 2: scored visitor-days. */
	$line = sprintf( __( 'The beacon’s bot signals mark %1$s of %2$s scored visitor-days as likely automated', 'signal-and-noise-tools' ), number_format_i18n( $la['visitor_days'] ), number_format_i18n( (int) $la['scored_visitor_days'] ) )
		/* translators: 1: measured days, 2: days in the window. */
		. ( $measured < $days ? sprintf( __( ' (%1$s of %2$s days measured)', 'signal-and-noise-tools' ), number_format_i18n( $measured ), number_format_i18n( $days ) ) : '' )
		. __( '. Nothing is subtracted from the views.', 'signal-and-noise-tools' );
	return '<p class="sn-public-stats__note">' . esc_html( $line ) . '</p>';
}
