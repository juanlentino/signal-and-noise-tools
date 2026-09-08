<?php
/**
 * OpenStation presentation for the shared Analytics report primitives.
 *
 * @package SignalNoiseTools
 */
namespace SignalNoise\OpenStationHost\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Render resolved KPI values with native stats, preserving comparison metadata.
 *
 * @param array $cards Shared metric cards.
 * @param array $opts Comparison and caption options.
 * @return string Escaped native HTML.
 */
function native_stats( array $cards, array $opts ) {
	$html = '<div class="snt-native-stats">';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) || ! isset( $card['l'], $card['n'] ) ) {
			continue;
		}
		$caption = '';
		$note = '';
		if ( ! empty( $card['live'] ) ) {
			$caption = __( 'live', 'signal-and-noise-tools' );
		} elseif ( ! empty( $card['delta'] ) ) {
			ob_start();
			\snt_an_delta_badge( $card['delta'], array( 'variant' => 'kpi', 'basis_label' => (string) ( $opts['basis_label'] ?? '' ), 'sentiment' => (string) ( $card['sentiment'] ?? 'up_good' ) ) );
			$note = (string) ob_get_clean();
		} elseif ( isset( $card['sub'] ) && '' !== (string) $card['sub'] ) {
			$caption = (string) $card['sub'];
		} elseif ( 'omit' !== ( $opts['empty_slot'] ?? 'no-change' ) ) {
			$caption = __( 'no change', 'signal-and-noise-tools' );
		}
		$html .= '<div class="snt-native-stat">' . \snt_kit_stat( (string) $card['n'], (string) $card['l'], $caption );
		$html .= '' !== $note ? '<div class="snt-native-stat-note">' . $note . '</div>' : '';
		$html .= '</div>';
	}
	return $html . '</div>';
}

/**
 * Scope native presentation to one capture. Classic requests retain their HTML.
 * The report code still owns queries, metric units, ordering and chart geometry.
 *
 * @param array    $get Resolved query.
 * @param callable $paint Report producer.
 * @return string Native report markup.
 */
function native_capture( array $get, callable $paint ) {
	// Partial standalone hosts can exercise dispatch without loading the UI kit.
	if ( ! function_exists( 'snt_kit_section' ) || ! function_exists( 'add_filter' ) ) {
		return capture( $get, $paint );
	}
	$panels = array();
	$surface = static function ( $html, $piece, $data ) use ( &$panels ) {
		if ( 'panel-open' === $piece ) {
			$args = $data['args'];
			$fold = ! empty( $args['collapsible'] );
			$panels[] = $fold;
			$html = '<os-section class="snt-native-panel" heading="' . esc_attr( (string) $data['title'] ) . '"><div class="snt-native-panel-body">';
			if ( ! empty( $args['header_meta'] ) ) {
				$html .= '<div class="snt-native-panel-meta">' . wp_kses_post( (string) $args['header_meta'] ) . '</div>';
			}
			if ( $fold ) {
				$html .= '<details class="snt-native-disclosure"' . ( empty( $args['collapsed'] ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Details', 'signal-and-noise-tools' ) . '</summary>';
			}
			return $html;
		}
		if ( 'panel-close' === $piece ) {
			return ( array_pop( $panels ) ? '</details>' : '' ) . '</div></os-section>';
		}
		if ( 'signal' === $piece ) {
			$signal = $data['signal'];
			$kind = (string) ( $signal['kind'] ?? '' );
			$tier = ucfirst( (string) ( $signal['tier'] ?? 'predictive' ) );
			$status = 'forecast_withheld' === $kind ? __( 'Forecast unavailable', 'signal-and-noise-tools' ) : $tier;
			$confidence = (string) ( $signal['confidence'] ?? '' );
			$direction = (string) ( $signal['direction'] ?? '' );
			$direction_label = 'up' === $direction ? __( 'rising', 'signal-and-noise-tools' ) : ( 'down' === $direction ? __( 'falling', 'signal-and-noise-tools' ) : '' );
			return '<span class="sn-an-signal sn-an-signal--' . esc_attr( $kind ) . '">'
				. '<span class="snt-signal-status">' . esc_html( $status ) . '</span>'
				. '<span class="snt-signal-explanation">' . esc_html( (string) ( $signal['plain_label'] ?? '' ) ) . '</span>'
				. '<span class="snt-signal-meta">' . esc_html( implode( ' · ', array_filter( array(
					'forecast_withheld' === $kind ? $tier : '',
					$direction_label,
					/* translators: %s: statistical confidence level, including none. */
					'' !== $confidence ? sprintf( __( 'Confidence: %s', 'signal-and-noise-tools' ), $confidence ) : '',
				) ) ) ) . '</span></span>';
		}
		if ( 'kpis' === $piece ) {
			return native_stats( (array) $data['cards'], (array) $data['opts'] );
		}
		if ( 'stat' === $piece ) {
			return \snt_kit_stat( (string) $data['value'], (string) $data['label'], '', '', array( 'class' => 'snt-native-percentile' ) );
		}
		if ( 'clamp-open' === $piece ) {
			return '<div class="snt-native-clamp" data-visible="' . esc_attr( (int) $data['visible'] ) . '">';
		}
		if ( 'clamp-close' === $piece ) {
			$html = '';
			if ( (int) $data['total'] > (int) $data['visible'] ) {
				/* translators: %d: total report rows. */
				$label = sprintf( __( 'View all %d', 'signal-and-noise-tools' ), (int) $data['total'] );
				$html = '<os-button class="snt-native-viewall" variant="secondary" aria-expanded="false" data-more="' . esc_attr( $label ) . '" data-less="' . esc_attr__( 'Show fewer', 'signal-and-noise-tools' ) . '">' . esc_html( $label ) . '</os-button>';
			}
			return $html . '</div>';
		}
		if ( 'gate' === $piece ) {
			$body = '<p>' . esc_html( (string) $data['message'] ) . '</p>';
			if ( '' !== $data['cta_label'] && '' !== $data['cta_url'] ) {
				$body .= '<a class="snt-native-link" href="' . esc_url( $data['cta_url'] ) . '">' . esc_html( $data['cta_label'] ) . '</a>';
			}
			return \snt_kit_section( (string) $data['title'], $body, '', array( 'class' => 'snt-native-panel' ) );
		}
		return $html;
	};
	add_filter( 'snt_analytics_surface', $surface, 10, 3 );
	try {
		$html = capture( $get, $paint );
		// The lazy Uptime table is populated after capture. Give its existing
		// mount a keyboard-scrollable region, without touching classic/widget
		// renderers or replacing their asynchronous data/controls.
		return str_replace(
			'data-sn-uptime-lazy-detail>',
			'data-sn-uptime-lazy-detail tabindex="0" role="region" aria-label="' . esc_attr__( 'Uptime monitor details', 'signal-and-noise-tools' ) . '">',
			$html
		);
	} finally {
		remove_filter( 'snt_analytics_surface', $surface, 10 );
	}
}

/**
 * Preserve escaped semantic cells as inert source for native os-table renderers.
 * This runs after navigation rewriting, so links retain their native actions.
 *
 * @param string $html Escaped report output.
 * @return string Report with native table hosts.
 */
function native_tables( $html ) {
	return (string) preg_replace_callback(
		'~<table\b[^>]*>.*?</table>~s',
		static function ( $match ) {
			return '<snt-analytics-table><template>' . $match[0] . '</template></snt-analytics-table>';
		},
		$html
	);
}
