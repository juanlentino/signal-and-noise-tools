<?php
/**
 * Signal & Noise Tools: /stats, the Humans and machines column.
 *
 * 2026-10-11: the column speaks the readership evidence the dashboard does.
 * The headline bar sets presumed-human views against machine READERS (this
 * site's own monitors and checks are not readers; the full total is said in
 * the note), AI training is shown claimed against proof, robots.txt control
 * tokens are credited to no one, and the visitor-days the beacon scores
 * likely automated sit beside the views, never subtracted. Every figure
 * comes from the hourly snapshot or the analytics store; a part that was not
 * read is left out, never painted as 0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/machine-readers-impostor-copy.php'; // the impostor line, shared with the evidence lead.

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
	$split = is_array( $m['split'] ) ? array_filter( $m['split'] ) : array();
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
	$all = array_sum( $split );
	if ( $all > 0 ) {
		$names = array(
			'verified'     => __( 'Verified by Cloudflare', 'signal-and-noise-tools' ),
			'unverified'   => __( 'Named themselves, not verified', 'signal-and-noise-tools' ),
			'not_measured' => __( 'Not measured', 'signal-and-noise-tools' ),
			'first_party'  => __( 'This site’s own tools', 'signal-and-noise-tools' ),
		);
		$rows = array();
		foreach ( $names as $k => $label ) {
			if ( isset( $split[ $k ] ) ) {
				$rows[] = array( 'label' => $label, 'text' => (int) round( 100 * $split[ $k ] / $all ) . '%', 'pct' => 100 * $split[ $k ] / $all );
			}
		}
		$out .= '<h3>' . esc_html__( 'Who the machines are', 'signal-and-noise-tools' ) . '</h3>' . sn_public_stats_bars_html( $rows );
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
 * sentence, one decorative bar, a key that carries every part as a count,
 * then the control tokens. '' for an older snapshot or another window.
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
	$parts   = array(
		'verified' => array( __( 'Verified by Cloudflare', 'signal-and-noise-tools' ), (int) ( $t['verified'] ?? 0 ) ),
		'claimed'  => array( __( 'Named themselves, not verified', 'signal-and-noise-tools' ), (int) ( $t['unverified'] ?? 0 ) ),
		'before'   => array( __( 'Before verification was recorded', 'signal-and-noise-tools' ), (int) ( $t['unverifiable'] ?? 0 ) ),
	);
	$bar = '';
	$key = '';
	foreach ( $parts as $part => list( $label, $n ) ) {
		if ( $n < 1 ) {
			continue;
		}
		$bar .= '<span data-part="' . $part . '" style="flex-grow:' . esc_attr( (string) $n ) . '"></span>';
		$key .= '<li><span class="sn-public-stats__key-sw" data-part="' . $part . '"></span>' . esc_html( $label ) . ' <span class="sn-public-stats__key-n">' . esc_html( number_format_i18n( $n ) ) . '</span></li>';
	}
	/* translators: 1: reads claiming AI training, 2: verified by Cloudflare. */
	$lead = sprintf( esc_html__( '%1$s reads came from crawlers that say they gather AI training data. Cloudflare verified %2$s of them.', 'signal-and-noise-tools' ), '<strong>' . esc_html( number_format_i18n( $claimed ) ) . '</strong>', '<strong>' . esc_html( number_format_i18n( (int) ( $t['verified'] ?? 0 ) ) ) . '</strong>' );
	return '<section class="sn-public-stats__training"><h2>' . esc_html__( 'AI training, claimed against proof', 'signal-and-noise-tools' ) . '</h2><p class="sn-public-stats__claim">' . $lead . '</p>'
		. '<span class="sn-public-stats__proof" aria-hidden="true">' . $bar . '</span><ul class="sn-public-stats__key">' . $key . '</ul>'
		. sn_public_stats_tokens_html( $m['tokens'] ?? null ) . sn_public_stats_impostors_html( $m['impostors'] ?? null, $m['impostor_reasons'] ?? null ) . '</section>';
}

/**
 * Reads that presented a robots.txt control token as their name: no crawler
 * of their own, so credited to no one. '' when none or not read.
 *
 * @param array|null $tokens [ ['token' => string, 'hits' => int], ... ].
 * @return string
 */
function sn_public_stats_tokens_html( $tokens ) {
	$tokens = array_values( array_filter( (array) $tokens, static fn( $i ) => is_array( $i ) && (int) ( $i['hits'] ?? 0 ) > 0 ) );
	if ( array() === $tokens ) {
		return '';
	}
	$names = implode( ' and ', array_map( static fn( $i ) => (string) $i['token'], $tokens ) );
	$hits  = number_format_i18n( array_sum( array_map( static fn( $i ) => (int) $i['hits'], $tokens ) ) );
	$line  = 1 === count( $tokens )
		/* translators: 1: the token, 2: reads. */
		? sprintf( __( '%1$s is a robots.txt token, not a crawler: %2$s reads presented it as their name and are credited to no one.', 'signal-and-noise-tools' ), $names, $hits )
		/* translators: 1: the tokens, 2: reads. */
		: sprintf( __( '%1$s are robots.txt tokens, not crawlers: %2$s reads presented them as their name and are credited to no one.', 'signal-and-noise-tools' ), $names, $hits );
	return '<p class="sn-public-stats__note">' . esc_html( $line ) . '</p>';
}

/**
 * Reads that named a known crawler and failed a check (Cloudflare's, the
 * vendor's IP ranges or network, or a rotating network): counted, credited to
 * no one. '' when none; no breakdown from an older snapshot.
 *
 * @param int|null   $n       Impostor reads.
 * @param array|null $reasons Reads per reason.
 * @return string
 */
function sn_public_stats_impostors_html( $n, $reasons = null ) {
	return (int) $n < 1 ? '' : '<p class="sn-public-stats__note">' . esc_html( snt_mr_impostor_line( (int) $n, $reasons, __( 'counted and credited to no one', 'signal-and-noise-tools' ) ) ) . '</p>';
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
