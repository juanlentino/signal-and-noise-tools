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

	// CONTEXT IS WHATEVER LETS YOU JUDGE THE NUMBER — a prior period where one
	// exists, otherwise a denominator or a companion metric. Reading Few's rule
	// as "always a prior period" produced five stacked "no prior period" lines,
	// which is worse than the bare numbers it replaced.
	$views   = isset( $data['views_7d'] ) ? (int) $data['views_7d'] : null;
	$prior   = isset( $data['views_prior'] ) ? (int) $data['views_prior'] : null;
	$delta   = array_key_exists( 'views_delta', $data ) && null !== $data['views_delta'] ? (int) $data['views_delta'] : null;
	$imps    = isset( $data['search_impressions'] ) ? (int) $data['search_impressions'] : null;
	$calls   = isset( $data['ai_calls_30d'] ) ? (int) $data['ai_calls_30d'] : null;
	$anch_t  = isset( $data['anchored_total'] ) ? (int) $data['anchored_total'] : null;
	$capped  = ! empty( $data['search_clicks_capped'] );

	$views_ctx = '';
	$views_dir = '';
	if ( null !== $delta ) {
		$views_dir = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : '' );
		$sign      = $delta > 0 ? '+' : ( $delta < 0 ? '−' : '' );
		$views_ctx = null !== $prior
			/* translators: 1: signed change, 2: prior period total */
			? sprintf( __( '%1$s · %2$s prior 7d', 'signal-and-noise-tools' ), $sign . number_format_i18n( abs( $delta ) ), number_format_i18n( $prior ) )
			/* translators: %s signed change against the prior seven days */
			: sprintf( __( '%s on prior 7d', 'signal-and-noise-tools' ), $sign . number_format_i18n( abs( $delta ) ) );
	}

	$spec = array(
		array( 'key' => 'views_7d', 'label' => __( 'Views · 7d', 'signal-and-noise-tools' ), 'ctx' => $views_ctx, 'dir' => $views_dir ),
		array(
			'key'   => 'search_clicks',
			'label' => $clicks_label,
			// A click count is a RATE question. 5 against 1,240 impressions is
			// legible; 5 alone is not.
			'ctx'   => null !== $imps && $imps > 0
				/* translators: %s impressions in the same window */
				? sprintf( __( 'of %s impressions', 'signal-and-noise-tools' ), number_format_i18n( $imps ) )
				: '',
			'suffix' => $capped ? '+' : '',
		),
		array(
			'key'   => 'ai_spend_30d',
			'label' => __( 'AI spend · 30d', 'signal-and-noise-tools' ),
			'money' => true,
			'ctx'   => null !== $calls && $calls > 0
				/* translators: %s number of AI calls */
				? sprintf( __( 'across %s calls', 'signal-and-noise-tools' ), number_format_i18n( $calls ) )
				: '',
		),
		array(
			'key'   => 'anchored',
			'label' => __( 'Anchored', 'signal-and-noise-tools' ),
			// The denominator IS the context: "33" is a count, "33 of 33" is an
			// answer to "is anything unanchored?".
			'ctx'   => null !== $anch_t && $anch_t > 0
				/* translators: %s total notes */
				? sprintf( __( 'of %s notes', 'signal-and-noise-tools' ), number_format_i18n( $anch_t ) )
				: '',
		),
		array(
			'key'   => 'citations',
			'label' => __( 'Citations', 'signal-and-noise-tools' ),
			'ctx'   => null !== $anch_t && $anch_t > 0
				/* translators: %s total notes */
				? sprintf( __( 'across %s notes', 'signal-and-noise-tools' ), number_format_i18n( $anch_t ) )
				: '',
		),
	);

	$out = array();
	foreach ( $spec as $f ) {
		$measured = array_key_exists( $f['key'], $data ) && null !== $data[ $f['key'] ];
		$raw      = $measured ? $data[ $f['key'] ] : null;

		if ( ! $measured ) {
			$value = '—';
		} elseif ( ! empty( $f['money'] ) ) {
			$value = '$' . number_format_i18n( (float) $raw, 2 );
		} else {
			// A capped window's sum is a FLOOR. Showing it as an exact number
			// is a lie with a decimal point; "5+" is the honest render.
			$value = number_format_i18n( (int) $raw ) . (string) ( $f['suffix'] ?? '' );
		}

		$out[] = array(
			'label'    => $f['label'],
			'value'    => $value,
			'measured' => $measured,
			'compare'  => $measured ? (string) ( $f['ctx'] ?? '' ) : '',
			'dir'      => (string) ( $f['dir'] ?? '' ),
		);
	}

	return $out;
}
