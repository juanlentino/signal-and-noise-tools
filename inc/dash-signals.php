<?php
/**
 * Signal & Noise — the signals row.
 *
 * CONTEXT OVER ISOLATION (Few). A number on its own cannot be judged: 103 views
 * is good or bad only against last week, and $0.61 of AI spend means nothing
 * without last month. Every signal therefore renders a comparison slot, and a
 * signal that has no comparison SAYS so rather than quietly collapsing to a
 * bare number — a missing comparison is a gap in the instrument, not a tidier
 * card.
 *
 * @package SignalNoiseTools
 * @since 11.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the signals row.
 *
 * @since 11.30.0
 * @param array<int,array<string,mixed>> $signals label, value, compare, dir, measured, sub.
 * @return void
 */
function sn_dash_render_signals( array $signals ) {
	if ( empty( $signals ) ) {
		return;
	}
	echo '<div class="sn-scr__signals">';
	foreach ( $signals as $sig ) {
		if ( ! is_array( $sig ) ) {
			continue;
		}
		$classes = array( 'sn-sig' );
		// array_key_exists, not a falsy test: a measured 0 is a value, and the
		// citations signal is 0 on a true and interesting day.
		if ( array_key_exists( 'measured', $sig ) && false === $sig['measured'] ) {
			$classes[] = 'sn-sig--unmeasured';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<span class="sn-sig__k">' . esc_html( (string) ( $sig['label'] ?? '' ) ) . '</span>';
		echo '<span class="sn-sig__n">' . esc_html( (string) ( $sig['value'] ?? '' ) );
		if ( '' !== (string) ( $sig['sub'] ?? '' ) ) {
			echo '<i class="sn-sig__sub">' . esc_html( (string) $sig['sub'] ) . '</i>';
		}
		echo '</span>';

		$compare = (string) ( $sig['compare'] ?? '' );
		$dir     = (string) ( $sig['dir'] ?? '' );
		$dclass  = ( 'up' === $dir || 'down' === $dir ) ? ' sn-sig__compare--' . $dir : '';
		echo '<span class="sn-sig__compare' . esc_attr( $dclass ) . '">';
		echo esc_html( '' !== $compare ? $compare : __( 'no prior period', 'signal-and-noise-tools' ) );
		echo '</span>';
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Build the five signals from the measurement payload.
 *
 * A pure transform. Two honesty rules run through it, and both are pinned in
 * tests/dash-signals.php:
 *
 *   ABSENT IS NOT ZERO. A key that was never measured yields measured=false and
 *   an em dash; a key measured AT zero yields a real 0. "Nobody has cited you
 *   yet" is a finding, and dimming it to "—" would delete it.
 *
 *   NEVER INVENT A BASELINE. Where no prior period exists the comparison is the
 *   empty string and the direction is empty, so nothing gets coloured on
 *   evidence that does not exist. The renderer prints "no prior period" — which
 *   is a fact about the instrument, not a gap to be tidied away.
 *
 * @since 11.30.0
 * @param array<string,mixed> $data From snt_dashboard_measurement_data().
 * @return array<int,array<string,mixed>>
 */
function sn_dash_signals_from_measurement( array $data ) {
	// The Search Console window is whatever the last sync used — 28 days by
	// default, ending a few days back because Google has not finished counting
	// the most recent ones. Labelling that "7d" would overstate a week by a
	// month's worth and read as entirely plausible, so the label is DERIVED.
	$days         = isset( $data['search_clicks_days'] ) ? (int) $data['search_clicks_days'] : 0;
	$clicks_label = $days > 0
		/* translators: %d days in the Search Console window */
		? sprintf( __( 'Clicks · %dd', 'signal-and-noise-tools' ), $days )
		: __( 'Clicks', 'signal-and-noise-tools' );

	$spec = array(
		array( 'key' => 'views_7d', 'label' => __( 'Views · 7d', 'signal-and-noise-tools' ) ),
		array( 'key' => 'search_clicks', 'label' => $clicks_label ),
		array( 'key' => 'ai_spend_30d', 'label' => __( 'AI spend · 30d', 'signal-and-noise-tools' ), 'money' => true ),
		array( 'key' => 'anchored', 'label' => __( 'Anchored', 'signal-and-noise-tools' ) ),
		array( 'key' => 'citations', 'label' => __( 'Citations', 'signal-and-noise-tools' ) ),
	);

	$out = array();
	foreach ( $spec as $f ) {
		// array_key_exists + an explicit null test: isset() would fold a
		// measured null and an absent key into the same answer, and only one
		// of those is a transport problem.
		$measured = array_key_exists( $f['key'], $data ) && null !== $data[ $f['key'] ];
		$raw      = $measured ? $data[ $f['key'] ] : null;

		if ( ! $measured ) {
			$value = '—';
		} elseif ( ! empty( $f['money'] ) ) {
			$value = '$' . number_format_i18n( (float) $raw, 2 );
		} else {
			$value = number_format_i18n( (int) $raw );
		}

		$compare = '';
		$dir     = '';
		if ( 'views_7d' === $f['key'] && $measured && array_key_exists( 'views_delta', $data ) && null !== $data['views_delta'] ) {
			$d   = (int) $data['views_delta'];
			$dir = $d > 0 ? 'up' : ( $d < 0 ? 'down' : '' );
			$compare = sprintf(
				/* translators: %s signed change against the prior seven days */
				__( '%s on prior 7d', 'signal-and-noise-tools' ),
				( $d > 0 ? '+' : ( $d < 0 ? '−' : '' ) ) . number_format_i18n( abs( $d ) )
			);
		}

		$out[] = array(
			'label'    => $f['label'],
			'value'    => $value,
			'measured' => $measured,
			'compare'  => $compare,
			'dir'      => $dir,
		);
	}

	return $out;
}
