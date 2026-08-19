<?php
/**
 * Signal & Noise — the shared verdict.
 *
 * ONE answer to "is anything wrong?", consumed by BOTH the index.php widget
 * and the full Dashboard screen. Two surfaces deriving this independently is
 * how you get a green widget sitting above a red screen.
 *
 * ZERO-COST BY CONSTRUCTION. This is a pure function over cards the caller
 * already built. It performs no query, no remote call and no scan — which is
 * what lets the index.php widget use it, since that screen renders on every
 * single admin login (the discipline established in v8.2.0's uptime widget).
 *
 * @package SignalNoiseTools
 * @since 11.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive the verdict from the same cards the rail renders.
 *
 * @since 11.30.0
 * @param array<int,array<string,mixed>> $cards Glance cards.
 * @return array{state:string,headline:string,exceptions:array<int,array<string,string>>}
 */
function sn_dash_verdict( array $cards ) {
	$exceptions = array();
	$worst      = 'ok';

	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';

		// v11.16.0: cold is not broken. A probe that has not reported yet
		// carries a warn pill so its own card reads honestly, but it is not a
		// fault and must not raise the page's verdict. Same predicate the rail
		// and the zone state use, so all three agree by construction.
		if ( ! sn_admin_card_wants_attention( $card ) ) {
			continue;
		}
		if ( 'ok' !== $kind && 'err' !== $kind && 'warn' !== $kind ) {
			continue;
		}
		if ( 'ok' === $kind ) {
			continue;
		}

		$exceptions[] = array(
			'label'  => (string) ( $card['label'] ?? '' ),
			'detail' => (string) ( $card['value'] ?? '' ),
			'kind'   => $kind,
		);
		if ( 'err' === $kind ) {
			$worst = 'err';
		} elseif ( 'ok' === $worst ) {
			$worst = 'warn';
		}
	}

	// Nothing to check is not the same fact as everything checked out, and only
	// one of them is reassuring. An empty card list means the callers upstream
	// produced nothing — which is a transport problem, not a healthy site.
	if ( empty( $cards ) ) {
		return array(
			'state'      => 'unknown',
			'headline'   => __( 'Nothing reported in.', 'signal-and-noise-tools' ),
			'exceptions' => array(),
		);
	}

	// THE COUNT IS THE LIST'S OWN LENGTH. v11.16.2 shipped a tally derived
	// separately from the rows it introduced and read 21/21 while a check was
	// failing. There is exactly one array here, and the headline counts it.
	$n = count( $exceptions );
	if ( 0 === $n ) {
		return array(
			'state'      => 'ok',
			'headline'   => __( 'Everything is holding.', 'signal-and-noise-tools' ),
			'exceptions' => array(),
		);
	}

	return array(
		'state'      => $worst,
		/* translators: %d things needing attention */
		'headline'   => sprintf( _n( '%d thing needs attention.', '%d things need attention.', $n, 'signal-and-noise-tools' ), $n ),
		'exceptions' => $exceptions,
	);
}
