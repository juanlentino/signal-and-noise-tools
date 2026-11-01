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
 * @param array<string,mixed>|null $get The view params for the past-slot grid (null reads $_GET).
 * @return string Escaped HTML.
 */
function sn_analytics_live_admin_html( $get = null ) {
	$list = static function ( $hook, $heading, $sub, $empty ) {
		return '<div class="sn-live-admin__col">'
			. '<h3 class="sn-live-admin__h">' . esc_html( $heading ) . '</h3>'
			. '<p class="sn-live-admin__sub">' . esc_html( $sub ) . '</p>'
			. '<ol class="sn-live-admin__list" ' . $hook . ' data-empty="' . esc_attr( $empty ) . '"><li class="sn-live-admin__empty">' . esc_html__( 'not read', 'signal-and-noise-tools' ) . '</li></ol>'
			. '</div>';
	};
	// Two columns that wrap: the live hour beside the 24-hour grid, which is
	// only ~290px wide, so it never takes a row of its own on a wide panel.
	return '<div class="sn-live-admin">'
		. '<div class="sn-live-admin__now">'
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
		// How far each page's readers have scrolled (inc/analytics-live-scroll.php), one line per row.
		/* translators: %s: a number of readers. */
		. $list( 'data-sn-live-pages data-scroll-none="' . esc_attr__( 'not scrolled yet', 'signal-and-noise-tools' ) . '" data-scroll-quarter="' . esc_attr__( '%s past a quarter', 'signal-and-noise-tools' ) . '" data-scroll-half="' . esc_attr__( '%s past halfway', 'signal-and-noise-tools' ) . '" data-scroll-end="' . esc_attr__( '%s at the end', 'signal-and-noise-tools' ) . '"', __( 'Being read now', 'signal-and-noise-tools' ), __( 'human readers, published pages', 'signal-and-noise-tools' ), __( 'Nobody on a page right now.', 'signal-and-noise-tools' ) )
		. $list( 'data-sn-live-sources', __( 'Arrived from', 'signal-and-noise-tools' ), __( 'new pageviews, last 5 minutes', 'signal-and-noise-tools' ), __( 'No arrivals in the last 5 minutes.', 'signal-and-noise-tools' ) )
		// One line per reader (inc/analytics-live-readers.php): where, on what, from where, and how far into the visit.
		/* translators: 1: minutes since the visit began, 2: pages read in the visit. */
		. $list( 'data-sn-live-readers data-visit="' . esc_attr__( '%1$s min, %2$s pages', 'signal-and-noise-tools' ) . '"'
			/* translators: %s: minutes since the visit began. */
			. ' data-visit-one="' . esc_attr__( '%s min, 1 page', 'signal-and-noise-tools' ) . '"'
			/* translators: %s: where the visit came in from, e.g. LinkedIn. */
			. ' data-from="' . esc_attr__( 'from %s', 'signal-and-noise-tools' ) . '"', __( 'Readers now', 'signal-and-noise-tools' ), __( 'human readers, last 5 minutes: place, device, entry source', 'signal-and-noise-tools' ), __( 'Nobody reading right now.', 'signal-and-noise-tools' ) )
		. '</div>'
		/* translators: %s: the time of the last live read, e.g. 16:42. */
		. '<p class="sn-live-admin__meta" data-sn-live-meta data-updated="' . esc_attr__( 'live · updated %s', 'signal-and-noise-tools' ) . '" data-unknown="' . esc_attr__( 'live · not read yet', 'signal-and-noise-tools' ) . '">' . esc_html__( 'live', 'signal-and-noise-tools' ) . '</p>'
		. '</div>'
		// The stored 5-minute slots of the last day, each a link (inc/analytics-live-slots-admin.php).
		. ( function_exists( 'sn_analytics_live_slots_html' ) ? sn_analytics_live_slots_html( $get ) : '' )
		. '</div>';
}
