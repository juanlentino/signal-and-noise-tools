<?php
/**
 * Signal & Noise Tools: the Weekday rules box on both Connections › Scheduled
 * twins and its save handler (2026-11-09, queue calendar phase 2, item 4).
 * The rules and the pure functions live in abilities-content-queue-weekdays.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The weekday names the form offers, ISO order (1 = Monday). */
const SN_CONTENT_QUEUE_WEEKDAY_NAMES = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' );

/** How the rules work, said over the box on both twins. */
function sn_content_queue_weekday_help() {
	return 'A scheduled note on a weekday its rule leaves out is an Off day line under Cadence, and a suggested slot lands only on a day its rule allows. Where two rules cover a day, the one that starts later wins. Saving a start day that has a rule replaces it; saving it with no weekday checked removes it. Leave the end empty for a rule that runs on.';
}

/**
 * Each stored rule in words, for both twins: "From Jan 4, 2027, notes go out
 * on Mon and Thu".
 *
 * @param string[] $rules Stored rules.
 * @return string[]
 */
function sn_content_queue_weekday_lines( array $rules ) {
	$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0, 0 );
	$out   = array();
	foreach ( $rules as $k ) {
		$r = is_string( $k ) && 1 === preg_match( SN_CONTENT_QUEUE_WEEKDAY_RE, $k, $m ) ? sn_content_queue_weekday_rule_for( $m[1], array( $k ) ) : null;
		if ( null !== $r ) {
			$out[ $r['from'] ] = ucfirst( sn_content_queue_weekday_rule_words( $r, $today ) );
		}
	}
	ksort( $out );
	return array_values( $out );
}

/** What both twins say when no rule is stored. */
function sn_content_queue_weekday_none() {
	return 'No weekday rule: a note may go out on any day.';
}

/**
 * The rule the form opens on: the latest-starting one, so editing it is a
 * change and a save; empty fields when none is stored.
 *
 * @param string[] $rules Stored rules.
 * @return array{from:string,to:string,days:int[]}
 */
function sn_content_queue_weekday_form_rule( array $rules ) {
	$best = array( 'from' => '', 'to' => '', 'days' => array() );
	foreach ( $rules as $k ) {
		if ( is_string( $k ) && 1 === preg_match( SN_CONTENT_QUEUE_WEEKDAY_RE, $k, $m ) && $m[1] >= $best['from'] ) {
			$best = array( 'from' => $m[1], 'to' => $m[2], 'days' => array_map( 'intval', str_split( $m[3] ) ) );
		}
	}
	return $best;
}

/**
 * Ops handler: saves or removes one weekday rule. The cap and nonce are
 * enforced by sn_handle_admin_post() before this runs.
 *
 * @param array $post The raw $_POST from the dispatcher (from, to, wd_1 to wd_7).
 * @return string An sn_flash code.
 */
function sn_handle_queue_weekday_save( array $post ) {
	$field = static fn( $k ) => isset( $post[ $k ] ) && is_string( $post[ $k ] ) ? trim( wp_unslash( $post[ $k ] ) ) : '';
	$days  = array();
	foreach ( array_keys( SN_CONTENT_QUEUE_WEEKDAY_NAMES ) as $n ) {
		if ( '' !== $field( 'wd_' . $n ) ) {
			$days[] = $n;
		}
	}
	return sn_content_queue_weekday_save( $field( 'from' ), $field( 'to' ), $days );
}

/**
 * The classic Connections › Scheduled page's Weekday rules box: the same
 * sentences as the native leaf (scheduled_content_weekdays_html()), then the
 * form. Open, never folded.
 *
 * @param string[] $rules Stored rules.
 * @return void
 */
function sn_admin_render_queue_weekdays( array $rules ) {
	echo '<section aria-labelledby="sn-queue-weekdays"><h3 id="sn-queue-weekdays">' . esc_html__( 'Weekday rules', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-field-helper">' . esc_html( sn_content_queue_weekday_help() ) . '</p>';
	$lines = sn_content_queue_weekday_lines( $rules );
	if ( array() === $lines ) {
		echo '<p>' . esc_html( sn_content_queue_weekday_none() ) . '</p>';
	} else {
		echo '<ul>';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}
	$f   = sn_content_queue_weekday_form_rule( $rules );
	$url = function_exists( 'sn_admin_post_url' ) ? sn_admin_post_url() : admin_url( 'admin-post.php?page=sn-connections' );
	echo '<form method="post" action="' . esc_url( $url ) . '">';
	wp_nonce_field( 'sn_queue_weekday_save' );
	echo '<input type="hidden" name="action" value="sn_queue_weekday_save">';
	echo '<p><label for="sn-wd-from">' . esc_html__( 'Starts', 'signal-and-noise-tools' ) . '</label> <input type="date" id="sn-wd-from" name="from" required value="' . esc_attr( $f['from'] ) . '"> ';
	echo '<label for="sn-wd-to">' . esc_html__( 'Ends (empty: runs on)', 'signal-and-noise-tools' ) . '</label> <input type="date" id="sn-wd-to" name="to" value="' . esc_attr( $f['to'] ) . '"></p>';
	echo '<fieldset><legend>' . esc_html__( 'Weekdays', 'signal-and-noise-tools' ) . '</legend>';
	foreach ( SN_CONTENT_QUEUE_WEEKDAY_NAMES as $n => $name ) {
		echo '<label><input type="checkbox" name="wd_' . (int) $n . '" value="1"' . ( in_array( $n, $f['days'], true ) ? ' checked' : '' ) . '> ' . esc_html( $name ) . '</label> ';
	}
	echo '</fieldset>';
	echo '<p><button type="submit" class="button">' . esc_html__( 'Save rule', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form></section>';
}
