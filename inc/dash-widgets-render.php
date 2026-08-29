<?php
/**
 * Signal & Noise Tools — registration, shell render and assets for the four
 * fallback dashboard boxes. Definitions live in inc/dash-widgets.php.
 *
 * The render is a SHELL: labels and em-dash placeholders, plus the deep links,
 * all server-side and free. assets/dash-widgets.js fills the values from the
 * readonly abilities named in each section. That ordering is what keeps the
 * index.php zero-cost invariant true while the boxes still carry live numbers,
 * and it means a box degrades to labelled em dashes with working links rather
 * than to a blank card if the hydrator never runs.
 *
 * @package SignalNoiseTools
 * @since 13.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register each box its capability allows.
 *
 * Gated per box rather than once for the module: Audience is view_stats
 * business (readership is what that capability is for) while the other three
 * report operational state that only an admin can act on.
 *
 * @since 13.30.0
 * @return void
 */
function snt_dwx_register() {
	foreach ( snt_dwx_boxes() as $box ) {
		if ( ! current_user_can( (string) $box['cap'] ) ) {
			continue;
		}
		wp_add_dashboard_widget(
			(string) $box['id'],
			(string) $box['title'],
			static function () use ( $box ) {
				snt_dwx_render( $box );
			}
		);
	}
}
add_action( 'wp_dashboard_setup', 'snt_dwx_register' );

/**
 * Print one box's shell.
 *
 * @since 13.30.0
 * @param array<string,mixed> $box A row from snt_dwx_boxes().
 * @return void
 */
function snt_dwx_render( array $box ) {
	echo '<div class="sn-dwx">';

	if ( '' !== (string) ( $box['blurb'] ?? '' ) ) {
		echo '<p class="sn-dwx__blurb">' . esc_html( (string) $box['blurb'] ) . '</p>';
	}

	foreach ( (array) $box['sections'] as $sec ) {
		echo '<div class="sn-dwx__sec" data-sn-dwx-ability="' . esc_attr( (string) $sec['ability'] ) . '">';
		if ( '' !== (string) ( $sec['label'] ?? '' ) ) {
			echo '<h4 class="sn-dwx__h">' . esc_html( (string) $sec['label'] ) . '</h4>';
		}
		echo '<div class="sn-dwx__rows">';
		foreach ( (array) $sec['fields'] as $field ) {
			echo '<div class="sn-dwx__row" data-sn-dwx-path="' . esc_attr( (string) $field['path'] ) . '">';
			echo '<span class="sn-dwx__k">' . esc_html( (string) $field['label'] ) . '</span>';
			// The placeholder is an em dash, never a 0 — the Dashboard tab's
			// rule, and the reason an unhydrated box cannot be misread as a
			// measured zero.
			echo '<span class="sn-dwx__n">&mdash;</span>';
			echo '</div>';
		}
		echo '</div></div>';
	}

	if ( ! empty( $box['links'] ) ) {
		echo '<p class="sn-dwx__links">';
		$first = true;
		foreach ( (array) $box['links'] as $link ) {
			if ( ! $first ) {
				echo ' <span class="sn-dwx__dot">&middot;</span> ';
			}
			$first = false;
			echo '<a href="' . esc_url( admin_url( (string) $link['url'] ) ) . '">'
				. esc_html( (string) $link['label'] ) . '</a>';
		}
		echo '</p>';
	}

	echo '</div>';
}

/**
 * Stylesheet + hydrator, on index.php only.
 *
 * Gated on the hook suffix rather than on whether any box registered: the hook
 * is the cheap reliable signal, and assets shipped to a screen that never
 * renders these boxes are dead weight on every request. v11.30.2 shipped the
 * sibling box's CSS to a stylesheet that only loaded on S&N pages, so the box
 * rendered unstyled on the one screen it lives on.
 *
 * The script DEPENDS on snt-ability-run: without it sntAbilityRun is undefined
 * and every box would sit at its em dashes forever, silently.
 *
 * @since 13.30.0
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function snt_dwx_enqueue( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'sn-dash-widgets', SNT_URL . 'assets/dash-widgets.css', array(), SNT_VERSION );
	wp_enqueue_script( 'sn-dash-widgets', SNT_URL . 'assets/dash-widgets.js', array( 'snt-ability-run' ), SNT_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'snt_dwx_enqueue' );
