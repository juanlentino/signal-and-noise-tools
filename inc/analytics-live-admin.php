<?php
/**
 * Signal & Noise Tools: the admin Right now block, shared by the classic
 * Overview panel and the native one.
 *
 * Under the two live figures: the last hour as twelve 5-minute bars, then two
 * columns, Being read now and Arrived from (admin only), and the time of the
 * last read. Painted as a shell; assets/live-now.js fills it from the gated
 * live/admin route every 30 s. Every string the updater needs rides on the
 * markup, so the admin handle carries no localize (OpenStation repeats a
 * dependency's l10n per entry). Everything is escaped here, so the builder is
 * declared an escaping function in phpcs.xml.dist.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string Escaped HTML.
 */
function sn_analytics_live_admin_html() {
	$list = static function ( $hook, $heading, $sub, $empty ) {
		return '<div class="sn-live-admin__col">'
			. '<h3 class="sn-live-admin__h">' . esc_html( $heading ) . '</h3>'
			. '<p class="sn-live-admin__sub">' . esc_html( $sub ) . '</p>'
			. '<ol class="sn-live-admin__list" ' . $hook . ' data-empty="' . esc_attr( $empty ) . '"><li class="sn-live-admin__empty">—</li></ol>'
			. '</div>';
	};
	return '<div class="sn-live-admin">'
		. '<div class="sn-live-admin__hour">'
		. '<svg class="sn-live-admin__bars" data-sn-live-hour viewBox="0 0 240 30" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Human readers per 5 minutes over the last hour', 'signal-and-noise-tools' ) . '"></svg>'
		. '<span class="sn-live-admin__ax" aria-hidden="true"><span>' . esc_html__( 'an hour ago', 'signal-and-noise-tools' ) . '</span><span>' . esc_html__( 'now', 'signal-and-noise-tools' ) . '</span></span>'
		. '</div>'
		// The live-surge signal (inc/analytics-live-surge.php), in words.
		/* translators: 1: times the usual, 2: readers now, 3: usual readers. */
		. '<p class="sn-live-admin__surge" data-sn-live-surge data-surge="' . esc_attr__( 'Unusual: about %1$s× the usual for this time of day (%2$s readers, usually %3$s).', 'signal-and-noise-tools' ) . '"'
		/* translators: %s: readers now. */
		. ' data-surge-zero="' . esc_attr__( 'Unusual: %s readers at a time of day that is usually empty.', 'signal-and-noise-tools' ) . '"'
		. ' data-usual="' . esc_attr__( 'Usual for this time of day.', 'signal-and-noise-tools' ) . '"'
		/* translators: 1: days of history so far, 2: days needed. */
		. ' data-learning="' . esc_attr__( 'Learning what is usual: %1$s of %2$s days so far.', 'signal-and-noise-tools' ) . '"></p>'
		. '<div class="sn-live-admin__cols">'
		. $list( 'data-sn-live-pages', __( 'Being read now', 'signal-and-noise-tools' ), __( 'human readers, published pages', 'signal-and-noise-tools' ), __( 'Nobody on a page right now.', 'signal-and-noise-tools' ) )
		. $list( 'data-sn-live-sources', __( 'Arrived from', 'signal-and-noise-tools' ), __( 'new pageviews, last 5 minutes', 'signal-and-noise-tools' ), __( 'No arrivals in the last 5 minutes.', 'signal-and-noise-tools' ) )
		. '</div>'
		/* translators: %s: the time of the last live read, e.g. 16:42. */
		. '<p class="sn-live-admin__meta" data-sn-live-meta data-updated="' . esc_attr__( 'live · updated %s', 'signal-and-noise-tools' ) . '" data-unknown="' . esc_attr__( 'live · not read yet', 'signal-and-noise-tools' ) . '">' . esc_html__( 'live', 'signal-and-noise-tools' ) . '</p>'
		. '</div>';
}
