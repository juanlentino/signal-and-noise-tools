<?php
/**
 * Signal & Noise — the Dashboard briefing band.
 *
 * One sentence stating the situation, rendered as FIXED CHROME above the
 * metaboxes: not draggable, not collapsible, not hideable.
 *
 * That is a safety property, not a style choice. Core lets a user collapse a
 * box (`closedpostboxes_{page}`) and hide it outright (`metaboxhidden_{page}`).
 * A user who hides every box must still be told when something needs them, so
 * this band is the backstop and cannot be switched off.
 *
 * The sentence must never overstate. A wrong number is a bug; a sentence that
 * says "everything is holding" over two open findings is a lie, and it reads
 * as judgement rather than data.
 *
 * @package SignalNoiseTools
 * @since 11.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the briefing sentence. Pure.
 *
 * @since 11.29.0
 * @param array<string,mixed> $f Keys: needy, views, views_delta, anchored,
 *                               citations, warming. Absent keys are omitted
 *                               from the sentence rather than defaulted.
 * @return string Plain text; the caller escapes.
 */
function sn_dash_briefing_sentence( array $f ) {
	$needy = isset( $f['needy'] ) ? (int) $f['needy'] : 0;
	$parts = array();

	if ( $needy > 0 ) {
		$open = sprintf(
			/* translators: %d number of checks needing attention */
			_n( '%d check needs attention.', '%d checks need attention.', $needy, 'signal-and-noise-tools' ),
			$needy
		);
	} else {
		$open = __( 'Everything is holding.', 'signal-and-noise-tools' );
	}

	if ( array_key_exists( 'views', $f ) ) {
		$views = sprintf(
			/* translators: %s view count */
			__( '%s views this week', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $f['views'] )
		);
		$delta = isset( $f['views_delta'] ) ? (int) $f['views_delta'] : 0;
		if ( 0 !== $delta ) {
			$views .= $delta > 0
				/* translators: %s absolute change in views */
				? sprintf( __( ', up %s on last', 'signal-and-noise-tools' ), number_format_i18n( $delta ) )
				/* translators: %s absolute change in views */
				: sprintf( __( ', down %s on last', 'signal-and-noise-tools' ), number_format_i18n( abs( $delta ) ) );
		}
		$parts[] = $views;
	}

	if ( array_key_exists( 'anchored', $f ) && (int) $f['anchored'] > 0 ) {
		$parts[] = sprintf(
			/* translators: %s count of anchored notes */
			__( 'all %s notes anchored', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $f['anchored'] )
		);
	}

	if ( array_key_exists( 'citations', $f ) && 0 === (int) $f['citations'] ) {
		$parts[] = __( 'nothing has cited you yet', 'signal-and-noise-tools' );
	}

	if ( isset( $f['warming'] ) && (int) $f['warming'] > 0 ) {
		$parts[] = sprintf(
			/* translators: %d number of workers still warming */
			_n( '%d worker still warming', '%d workers still warming', (int) $f['warming'], 'signal-and-noise-tools' ),
			(int) $f['warming']
		);
	}

	return empty( $parts ) ? $open : $open . ' ' . implode( ', ', $parts ) . '.';
}

/**
 * Render the band.
 *
 * @since 11.29.0
 * @param array<string,mixed> $f See sn_dash_briefing_sentence().
 * @return void
 */
function sn_dash_render_briefing( array $f ) {
	$needy = isset( $f['needy'] ) ? (int) $f['needy'] : 0;
	$state = $needy > 0 ? 'attention' : 'ok';

	echo '<div class="sn-dash-briefing sn-dash-briefing--' . esc_attr( $state ) . '">';
	echo '<p>' . esc_html( sn_dash_briefing_sentence( $f ) ) . '</p>';
	echo '</div>';
}
