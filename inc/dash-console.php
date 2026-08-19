<?php
/**
 * Signal & Noise — the Dashboard console.
 *
 * Direction B with C's band, from the 2026-08-19 mockups: a briefing line the
 * page opens with, a permanent systems rail down the left, and a stage that
 * belongs to whatever you came to look at.
 *
 * WHY THIS SHAPE. v11.28.0 built the collapse rule faithfully and produced a
 * page that was 53% empty when the site was healthy — because "state earns
 * space" describes what ALARMS do and says nothing about what the page IS when
 * nothing is wrong, which is nearly always. A console is dense at rest and lets
 * alarms assert themselves over that density; it is not empty when calm.
 *
 * The rail is always on, so the answer is readable without expanding anything.
 * Nothing here collapses.
 *
 * @package SignalNoiseTools
 * @since 11.29.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The maintenance actions, compact, inside the stage.
 *
 * The approved mockup demotes these to a toolbar. On the v11.28.0 page they
 * were four large cards taking a third of the viewport — the least-used thing
 * on screen with the most weight. Same form, same nonce, same action values.
 *
 * @since 11.29.1
 * @param string $check_updates_url
 * @return void
 */
function sn_dash_render_toolbar( $check_updates_url = '' ) {
	echo '<form class="sn-toolbar" method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<span class="sn-toolbar__k">' . esc_html__( 'Maintenance', 'signal-and-noise-tools' ) . '</span>';
	echo '<button type="submit" name="sn_action" value="purge_caches" class="button">'
		. esc_html__( 'Purge all caches', 'signal-and-noise-tools' ) . '</button>';
	echo '<button type="submit" name="sn_action" value="clear_overrides" class="button">'
		. esc_html__( 'Clear overrides', 'signal-and-noise-tools' ) . '</button>';
	if ( '' !== $check_updates_url ) {
		echo '<a class="button" href="' . esc_url( $check_updates_url ) . '">'
			. esc_html__( 'Check for updates', 'signal-and-noise-tools' ) . '</a>';
	}
	echo '<button type="submit" name="sn_action" value="full_reset" class="button button-link-delete">'
		. esc_html__( 'Full reset', 'signal-and-noise-tools' ) . '</button>';
	echo '</form>';
}

/**
 * The screen: verdict, exceptions, signals, trend, systems, detail, actions.
 *
 * ONE PAGE, ONE DECISION (Google SRE). The order is strictly how fast you need
 * each thing, and scale contrast — not colour, not borders — carries the
 * hierarchy: the verdict is the largest type on the page and the detail the
 * smallest, so the answer arrives before you have decided to read.
 *
 * SIZED TO FIT, NOT STRETCHED TO FILL. v11.29.2 gave the console a viewport
 * min-height and let the chart absorb the slack — the inverse of Few's
 * single-screen rule. Filling admits unlimited content; fitting forces the cut.
 * Nothing here stretches.
 *
 * @since 11.30.0
 * @param array<int,array<string,mixed>> $checks
 * @param array<int,array<string,mixed>> $components
 * @param array<int,array<string,mixed>> $signals
 * @param array<string,mixed>            $opts series, panels, check_updates_url, subline.
 * @return void
 */
function sn_dash_render_screen( array $checks, array $components, array $signals, array $opts = array() ) {
	$verdict = sn_dash_verdict( array_merge( array_values( $checks ), array_values( $components ) ) );
	$state   = (string) $verdict['state'];

	// A healthy screen carries NO state class, so colour keeps its meaning for
	// the day it appears.
	echo '<div class="' . esc_attr( 'sn-scr' . ( 'ok' === $state ? '' : ' sn-scr--' . $state ) ) . '">';

	echo '<p class="sn-scr__verdict">' . esc_html( $verdict['headline'] ) . '</p>';
	if ( '' !== (string) ( $opts['subline'] ?? '' ) ) {
		echo '<p class="sn-scr__sub">' . esc_html( (string) $opts['subline'] ) . '</p>';
	}

	if ( ! empty( $verdict['exceptions'] ) ) {
		echo '<ul class="sn-scr__exceptions">';
		foreach ( $verdict['exceptions'] as $ex ) {
			echo '<li class="sn-scr__ex sn-scr__ex--' . esc_attr( $ex['kind'] ) . '">';
			echo '<b>' . esc_html( $ex['label'] ) . '</b> ' . esc_html( $ex['detail'] );
			echo '</li>';
		}
		echo '</ul>';
	}

	sn_dash_render_signals( $signals );

	if ( ! empty( $opts['series'] ) && is_array( $opts['series'] ) ) {
		sn_dash_render_trend( $opts['series'] );
	}

	sn_dash_render_systems( $checks, $components );
	sn_dash_render_ops( isset( $opts['panels'] ) && is_array( $opts['panels'] ) ? $opts['panels'] : array() );
	sn_dash_render_toolbar( (string) ( $opts['check_updates_url'] ?? '' ) );

	echo '</div>';
}
