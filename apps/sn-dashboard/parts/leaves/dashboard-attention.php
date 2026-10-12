<?php
/**
 * S&N Home: the headline under the greeting, Needs attention, and Continue
 * working.
 *
 * 2026.10.4 audit (batch 4). Needs attention lists each problem ONCE: a
 * system that needs a look (the same test the Systems finding uses,
 * system_state()) is one row, and the attention items that explain it
 * (inc/dash-attention.php, "3 health findings in the last scan.") are its
 * description; an item with no card of its own (database overrides, a failed
 * deploy) is its own row. The headline counts those rows, so it can never say
 * the site is healthy over a list of problems. Split out of dashboard.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** Needs attention rows that still pair with Continue working (the 1.5x rule). */
const SNT_HOME_PAIR_MAX = 4;

/**
 * What a card that needs a look says is wrong: its pill (the problem), then
 * its figure. "2 orphans", never "41 events" alone.
 *
 * @param array<string,mixed> $card A check or fleet card.
 * @return string
 */
function card_problem( array $card ) {
	$pill  = trim( (string) ( $card['pill']['text'] ?? '' ) );
	$value = trim( (string) ( $card['value'] ?? '' ) );
	$text  = '' !== $pill ? $pill . ( '' !== $value ? ' (' . $value . ')' : '' ) : $value;
	// Capitalize a word, never a version ("v2.1 available").
	return (string) preg_replace_callback( '/^[a-z](?=[a-z ])/', static fn( $m ) => strtoupper( $m[0] ), $text ) . '.';
}

/**
 * Every problem on Home, once, worst first.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @return array<int,array{severity:string,label:string,description:string,href:string}>
 */
function home_attention_rows( array $data ) {
	$items = array();
	foreach ( (array) ( $data['attention'] ?? array() ) as $item ) {
		if ( is_array( $item ) ) {
			$items[ (string) ( $item['label'] ?? '' ) ][] = $item;
		}
	}
	$rows = array();
	foreach ( array_merge( array_values( (array) ( $data['checks'] ?? array() ) ), array_values( (array) ( $data['components'] ?? array() ) ) ) as $card ) {
		if ( ! is_array( $card ) || 'look' !== system_state( $card ) ) {
			continue;
		}
		$label = (string) ( $card['label'] ?? '' );
		$mine  = $items[ $label ] ?? array();
		unset( $items[ $label ] );
		$rows[] = array(
			'severity'    => 'err' === (string) ( $card['pill']['kind'] ?? '' ) ? 'err' : 'warn',
			'label'       => $label,
			'description' => array() !== $mine ? implode( ' ', array_column( $mine, 'text' ) ) : card_problem( $card ),
			'href'        => (string) ( $card['href'] ?? ( $mine[0]['href'] ?? '' ) ),
		);
	}
	foreach ( $items as $label => $list ) {
		$rows[] = array(
			'severity'    => 'Deploys' === $label ? 'err' : 'warn',
			'label'       => (string) $label,
			'description' => implode( ' ', array_column( $list, 'text' ) ),
			'href'        => (string) ( $list[0]['href'] ?? '' ),
		);
	}
	usort( $rows, static fn( $a, $b ) => ( 'err' === $b['severity'] ) <=> ( 'err' === $a['severity'] ) );
	return $rows;
}

/**
 * The line under the greeting: how many things need attention, in the worst
 * one's tone, or a calm line only when nothing does. The shared verdict is the
 * floor: "nothing reported in" is never read as healthy.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @return array{state:string,text:string}
 */
function home_orientation_info( array $data ) {
	$rows = home_attention_rows( $data );
	$n    = count( $rows );
	if ( $n > 0 ) {
		$err = in_array( 'err', array_column( $rows, 'severity' ), true ) || 'err' === ( $data['verdict']['state'] ?? '' );
		return array(
			'state' => $err ? 'err' : 'warn',
			/* translators: %s: problems listed under Needs attention. */
			'text'  => sprintf( _n( '%s thing needs attention.', '%s things need attention.', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ) ),
		);
	}
	if ( 'unknown' === ( $data['verdict']['state'] ?? '' ) ) {
		return array( 'state' => 'unknown', 'text' => __( 'Nothing has reported in, so nothing is measured.', 'signal-and-noise-tools' ) );
	}
	$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
	return array(
		'state' => 'ok',
		'text'  => '' !== $site
			/* translators: %s: site name */
			? sprintf( __( 'Pick up where you left off on %s. Nothing needs attention.', 'signal-and-noise-tools' ), $site )
			: __( 'Pick up where you left off. Nothing needs attention.', 'signal-and-noise-tools' ),
	);
}

/**
 * One Needs attention row: the status in words (a screen reader hears
 * "Error" or "Warning", not a colored icon), the system, what is wrong, and a
 * way to it. A row with nowhere to go is not a link.
 *
 * @param array<string,string> $row From home_attention_rows().
 * @param string               $tab Current tab.
 * @return string
 */
function home_attention_row_html( array $row, $tab ) {
	$err    = 'err' === $row['severity'];
	$offsite = 0 === strpos( $row['href'], 'https://github.com/' );
	$body   = '<span class="snt-home__attention-badge snt-home__attention-badge--' . esc_attr( $row['severity'] ) . '" role="img" aria-label="' . esc_attr( $err ? __( 'Error', 'signal-and-noise-tools' ) : __( 'Warning', 'signal-and-noise-tools' ) ) . '">'
		. '<span class="dashicons ' . ( $err ? 'dashicons-warning' : 'dashicons-info' ) . '" aria-hidden="true"></span>'
		. '</span>'
		. '<span class="snt-home__attention-copy"><strong>' . esc_html( $row['label'] ) . '</strong><span>' . esc_html( $row['description'] ) . '</span></span>';
	$class  = 'snt-home__attention-row snt-home__attention-row--' . $row['severity'];
	if ( '' === $row['href'] ) {
		return '<div class="' . esc_attr( $class ) . '">' . $body . '</div>';
	}
	$action = $offsite ? __( 'Open the run', 'signal-and-noise-tools' ) : ( $err ? __( 'Review now', 'signal-and-noise-tools' ) : __( 'Inspect', 'signal-and-noise-tools' ) );
	$body  .= '<span class="snt-home__attention-action">' . esc_html( $action ) . ' <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></span>';
	$attrs  = $offsite ? array( 'class' => $class, 'href' => $row['href'], 'target' => '_blank', 'rel' => 'noopener noreferrer' ) : go_attrs( array( 'class' => $class, 'href' => $row['href'] ), $row['href'], $tab );
	return \snt_kit_tag( 'a', $attrs, $body );
}

/**
 * Needs attention: every problem, once. Nothing needs you: no section; the
 * line under the greeting already says so.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_attention_html( array $data, $tab ) {
	$rows = home_attention_rows( $data );
	if ( array() === $rows ) {
		return '';
	}
	$out = '';
	foreach ( $rows as $row ) {
		$out .= home_attention_row_html( $row, $tab );
	}
	return '<section class="snt-home__section" aria-labelledby="snt-home-attention-heading">'
		. '<div class="snt-home__section-heading"><h2 id="snt-home-attention-heading">' . esc_html__( 'Needs attention', 'signal-and-noise-tools' ) . '</h2></div>'
		. '<div class="snt-home__attention">' . $out . '</div></section>';
}

/**
 * Needs attention and Continue working share a row while the problems are
 * few enough to sit beside three recent items (2026.10.4 audit); a longer
 * list takes the width. A missing side leaves the other bare.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_desk_html( array $data, $tab ) {
	$attention = home_attention_html( $data, $tab );
	$work      = home_continue_working_html( $tab );
	return count( home_attention_rows( $data ) ) <= SNT_HOME_PAIR_MAX
		? \snt_kit_grid( array( $attention, $work ), 360, 32, array( 'class' => 'snt-home__desk' ) )
		: $attention . $work;
}
