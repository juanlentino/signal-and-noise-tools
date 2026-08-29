<?php
/**
 * Signal & Noise Tools — registration, shell render and assets for the four
 * fallback dashboard boxes. Definitions live in inc/dash-widgets.php.
 *
 * The render is a SHELL: signal cells with em-dash placeholders, list headings,
 * action buttons and the deep links, all server-side and free.
 * assets/dash-widgets.js fills values, list rows and action results from the
 * readonly abilities named in the markup. That ordering is what keeps the
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
 * Register each box the current user may see.
 *
 * @since 13.30.0
 * @return void
 */
function snt_dwx_register() {
	foreach ( snt_dwx_boxes() as $box ) {
		// ANY-OF, never a single cap. `view_stats` is not a core WordPress
		// capability — a plain administrator does not hold it — which is why
		// every other consumer in this plugin gates `view_stats ||
		// manage_options`. v13.30.0 gated Audience on `view_stats` alone, so it
		// registered for nobody and was simply absent from the dashboard.
		$allowed = false;
		foreach ( (array) $box['caps'] as $cap ) {
			if ( current_user_can( (string) $cap ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
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
 * Print one box: blurb, signal grid, lists, actions, deep links.
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

	// The sibling box's grid vocabulary, deliberately: assets/dash-widget.css is
	// enqueued on this same screen, so reusing sn-dw__signals / __sig / __k / __n
	// / __c makes the five S&N boxes one visual family instead of five dialects.
	// A flat label-value list read as a settings table, not as a widget.
	echo '<div class="sn-dw__signals">';
	foreach ( (array) $box['sections'] as $sec ) {
		if ( ! empty( $sec['signals'] ) && is_callable( $sec['signals'] ) ) {
			foreach ( (array) call_user_func( $sec['signals'] ) as $sig ) {
				snt_dwx_cell(
					(string) ( $sig['label'] ?? '' ),
					(string) ( $sig['value'] ?? '' ),
					(string) ( $sig['compare'] ?? '' ),
					(string) ( $sig['dir'] ?? '' )
				);
			}
			continue;
		}
		foreach ( (array) $sec['fields'] as $field ) {
			snt_dwx_cell(
				(string) $field['label'],
				'',
				'',
				'',
				array(
					'ability' => (string) $sec['ability'],
					'path'    => (string) $field['path'],
					'compare' => isset( $field['compare'] ) ? (array) $field['compare'] : array(),
				)
			);
		}
	}
	echo '</div>';

	foreach ( (array) ( $box['lists'] ?? array() ) as $list ) {
		$spec = array(
			'path'  => (string) $list['path'],
			'limit' => (int) ( $list['limit'] ?? 5 ),
			'item'  => (array) $list['item'],
		);
		if ( ! empty( $list['keys'] ) ) {
			$spec['keys'] = (array) $list['keys'];
		}
		if ( ! empty( $list['empty'] ) ) {
			$spec['empty'] = (string) $list['empty'];
		}
		echo '<div class="sn-dwx__list" data-sn-dwx-ability="' . esc_attr( (string) $list['ability'] ) . '"'
			. ' data-sn-dwx-list="' . esc_attr( (string) wp_json_encode( $spec ) ) . '">';
		echo '<h4 class="sn-dwx__h">' . esc_html( (string) $list['label'] ) . '</h4>';
		// No skeleton rows: an invented row count would be a claim about data
		// nobody has read yet. The heading alone holds the space.
		echo '<div class="sn-dwx__rows"></div>';
		echo '</div>';
	}

	if ( ! empty( $box['actions'] ) ) {
		echo '<p class="sn-dwx__actions">';
		foreach ( (array) $box['actions'] as $action ) {
			echo '<button type="button" class="button button-small sn-dwx__btn"'
				. ' data-sn-dwx-action="' . esc_attr( (string) $action['ability'] ) . '"'
				. ' data-sn-dwx-busy="' . esc_attr( (string) $action['busy'] ) . '">'
				. esc_html( (string) $action['label'] ) . '</button> ';
		}
		echo '<span class="sn-dwx__result" role="status"></span>';
		echo '</p>';
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
 * One signal cell.
 *
 * Server-rendered cells arrive with their value; ability-backed cells arrive
 * empty and carry the hydration contract in data attributes. The placeholder is
 * an em dash, never a 0 — an unhydrated cell must not be readable as a measured
 * zero.
 *
 * @since 13.30.1
 * @param string              $label   Cell label.
 * @param string              $value   Value, or '' for an ability-backed cell.
 * @param string              $compare Sub-line, or ''.
 * @param string              $dir     'up'|'down'|'' for the comparison colour.
 * @param array<string,mixed> $hydrate Hydration contract, or array() when server-rendered.
 * @return void
 */
function snt_dwx_cell( $label, $value, $compare = '', $dir = '', array $hydrate = array() ) {
	$attrs = '';
	if ( $hydrate ) {
		$attrs = ' data-sn-dwx-ability="' . esc_attr( (string) $hydrate['ability'] ) . '"'
			. ' data-sn-dwx-path="' . esc_attr( (string) $hydrate['path'] ) . '"';
		if ( ! empty( $hydrate['compare'] ) ) {
			$attrs .= ' data-sn-dwx-compare="' . esc_attr( (string) wp_json_encode( $hydrate['compare'] ) ) . '"';
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every interpolated part of $attrs is esc_attr'd above.
	echo '<div class="sn-dw__sig"' . $attrs . '>';
	echo '<span class="sn-dw__k">' . esc_html( $label ) . '</span>';
	echo '<span class="sn-dw__n">' . ( '' === $value ? '&mdash;' : esc_html( $value ) ) . '</span>';
	$cls = 'sn-dw__c' . ( ( 'up' === $dir || 'down' === $dir ) ? ' sn-dw__c--' . $dir : '' );
	echo '<span class="' . esc_attr( $cls ) . '">' . esc_html( $compare ) . '</span>';
	echo '</div>';
}

/**
 * Audience traffic, server-side and free.
 *
 * snt_dashboard_measurement_data() is a DB-local read and the only source on
 * this screen that carries a PRIOR period, so it is the only place a delta here
 * can be honest. get-analytics-summary reports a single window with no
 * comparison, which is why the visits half of the box carries context lines
 * rather than deltas.
 *
 * @since 13.30.1
 * @return array<int,array<string,mixed>>
 */
function snt_dwx_traffic_signals() {
	if ( ! function_exists( 'snt_dashboard_measurement_data' ) || ! function_exists( 'sn_dash_signals_from_measurement' ) ) {
		return array();
	}
	$out = array();
	foreach ( sn_dash_signals_from_measurement( snt_dashboard_measurement_data() ) as $sig ) {
		if ( 0 === stripos( (string) ( $sig['label'] ?? '' ), 'Views' ) ) {
			$out[] = $sig;
		}
	}
	return $out;
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
