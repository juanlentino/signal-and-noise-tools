<?php
/**
 * Signal & Noise Tools: the live strip at the top of [sn_public_stats].
 *
 * /stats is edge-cached HTML, so this paints only the shell: both figures
 * read "—" until assets/live-now.js fills them from the public live route
 * (inc/analytics-live.php). A number baked into a page the edge keeps for
 * hours would be wrong for most of that time. Without JavaScript the strip
 * says the live figures need it; a figure that cannot be read stays "—",
 * never 0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strip's markup. PURE.
 *
 * @return string
 */
function sn_public_stats_live_html() {
	$fig = static function ( $key, $label, $sub, $extra = '' ) {
		$attrs = '';
		foreach ( sn_analytics_live_attrs( $key, 'human' ) as $k => $v ) {
			$attrs .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return '<div class="sn-public-stats__live-fig">'
			. '<span class="sn-public-stats__live-n"' . $attrs . '>—</span>'
			. '<span class="sn-public-stats__live-l">' . esc_html( $label ) . '</span>'
			. '<span class="sn-public-stats__sub">' . esc_html( $sub ) . '</span>'
			. $extra
			. '</div>';
	};
	// The last hour under Reading now: twelve 5-minute bars, drawn by
	// live-now.js; the note under them says the same thing in words.
	$hour = '<svg class="sn-public-stats__live-hour" data-sn-live-hour viewBox="0 0 240 34" preserveAspectRatio="none" role="img" aria-labelledby="sn-public-stats-live-hour-note"></svg>'
		. '<span class="sn-public-stats__live-ax" aria-hidden="true"><span>' . esc_html__( 'an hour ago', 'signal-and-noise-tools' ) . '</span><span>' . esc_html__( 'now', 'signal-and-noise-tools' ) . '</span></span>'
		. '<span class="sn-public-stats__sub" id="sn-public-stats-live-hour-note" data-sn-live-hour-note>' . esc_html__( 'Readers per 5 minutes over the last hour.', 'signal-and-noise-tools' ) . '</span>';
	return '<section class="sn-public-stats__live" aria-labelledby="sn-public-stats-live-h">'
		. '<h2 id="sn-public-stats-live-h" class="sn-public-stats__live-h">' . esc_html__( 'Live', 'signal-and-noise-tools' ) . '</h2>'
		. '<div class="sn-public-stats__live-row">'
		. $fig( 'now', __( 'Reading now', 'signal-and-noise-tools' ), __( 'readers active in the last 5 minutes', 'signal-and-noise-tools' ), $hour )
		. $fig( 'today', __( 'Views today', 'signal-and-noise-tools' ), __( 'human pageviews since midnight, site time', 'signal-and-noise-tools' ) )
		. '</div>'
		// Being read now: filled by live-now.js; only published public pages.
		. '<div class="sn-public-stats__live-pages">'
		. '<h3 id="sn-public-stats-live-pages-h">' . esc_html__( 'Being read now', 'signal-and-noise-tools' ) . '</h3>'
		. '<ol class="sn-public-stats__top" aria-labelledby="sn-public-stats-live-pages-h" data-sn-live-pages><li class="sn-public-stats__live-empty">—</li></ol>'
		. '</div>'
		. '<p class="sn-public-stats__live-meta" data-sn-live-meta>' . esc_html__( 'The live figures need JavaScript.', 'signal-and-noise-tools' ) . '</p>'
		. '</section>';
}

/**
 * The updater, on the page that carries the shortcode: public route, 60 s.
 *
 * @return void
 */
function sn_public_stats_live_enqueue() {
	wp_enqueue_script( 'sn-live-now' );
	wp_localize_script( 'sn-live-now', 'snLiveNow', array(
		'url'      => rest_url( 'signal-noise/v1/live' ),
		'interval' => 60,
		/* translators: %s: the time the live figures were last read, e.g. 14:03. */
		'updated'  => __( 'Updated %s', 'signal-and-noise-tools' ),
		'unknown'  => __( 'Not measured right now.', 'signal-and-noise-tools' ),
		'nobody'   => __( 'Nobody on a page right now.', 'signal-and-noise-tools' ),
		'readers'  => __( 'readers', 'signal-and-noise-tools' ),
		'reader'   => __( 'reader', 'signal-and-noise-tools' ),
		/* translators: 1: most readers in one 5-minute slot, 2: when, e.g. "10 minutes ago". */
		'hourPeak' => __( 'Peak %1$s, %2$s. Readers per 5 minutes over the last hour.', 'signal-and-noise-tools' ),
		/* translators: %s: minutes. */
		'agoMin'   => __( '%s minutes ago', 'signal-and-noise-tools' ),
		'agoNow'   => __( 'in the last 5 minutes', 'signal-and-noise-tools' ),
		'hourNone' => __( 'No readers in the last hour.', 'signal-and-noise-tools' ),
	) );
}
