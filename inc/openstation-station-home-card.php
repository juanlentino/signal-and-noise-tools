<?php
/**
 * Signal & Noise — the S&N Analytics card on OpenStation's Station Home.
 *
 * WHY THIS EXISTS. v12.10.0 moved the Analytics screen off the WordPress
 * Dashboard menu onto its own top-level menu, which fixed its reachability
 * inside the OpenStation shell (Station Home's URL matcher claims
 * `index.php` by pathname alone — upstream WordPress/openstation#650) but
 * took it off the shell's launch surface at the same time. Station Home is a
 * personal launch surface, and metrics that are not on it are not at hand.
 *
 * Cards are STRUCTURED DATA, never plugin HTML: Station Home owns the layout,
 * escaping and responsive behaviour, and takes a value/detail/url/tone from us.
 * That is why this file emits no markup at all.
 *
 * IT MUST NEVER MATTER WHETHER OPENSTATION IS INSTALLED. Registration goes
 * through snt_os_register_station_home_card(), which returns null when the
 * function is absent, and the callback is only ever invoked by the shell.
 *
 * COST. Upstream is explicit that opening Station Home must not trigger
 * expensive work — its own snapshot reads CACHED update data rather than
 * initiating an update check. The callback below therefore makes exactly the
 * call the SN Dashboard glance card already makes on every admin page render,
 * and returns null the moment analytics is unconfigured, so an unconfigured
 * site does no work and shows no card.
 *
 * @package SignalNoiseTools
 * @since 12.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Card id. Namespaced so it cannot collide with another plugin's card. */
const SNT_STATION_HOME_CARD_ID = 'signal-and-noise-analytics';

/**
 * Build the card payload: 7-day human views, linking to the Analytics screen.
 *
 * Returns null (which Station Home treats as "omit this card") rather than a
 * zero when analytics is unconfigured or the accessors are absent. A card
 * reading "0" on a site that has never measured anything is a false statement,
 * not an empty state — the same unknown-is-not-zero rule the health surfaces
 * hold to.
 *
 * @return array|null
 */
function snt_station_home_analytics_card_data() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return null;
	}
	if ( ! function_exists( 'sn_analytics_period_deltas' ) || ! function_exists( 'snt_analytics_page_url' ) ) {
		return null;
	}

	$to     = current_time( 'Y-m-d' );
	$from   = gmdate( 'Y-m-d', strtotime( $to . ' -6 days' ) );
	$deltas = sn_analytics_period_deltas( $from, $to, 'human' );
	if ( ! is_array( $deltas ) || ! isset( $deltas['views']['current'] ) ) {
		return null;
	}

	return array(
		'value'        => number_format_i18n( (int) $deltas['views']['current'] ),
		'detail'       => __( 'Human views, last 7 days', 'signal-and-noise-tools' ),
		'url'          => snt_analytics_page_url(),
		'action_label' => __( 'Open S&N Analytics', 'signal-and-noise-tools' ),
		'tone'         => 'neutral',
	);
}

/**
 * Register the card on init, per upstream's documented contract.
 *
 * `default_enabled` is TRUE here, against upstream's example, and deliberately:
 * this is the site owner's own toolkit on their own site, and the whole reason
 * the screen moved was that its metrics were not at hand. Each user can still
 * opt out from "From your plugins → Customize"; the flag chooses the INITIAL
 * state, not the permanent one.
 */
function snt_station_home_register_card() {
	snt_os_register_station_home_card(
		SNT_STATION_HOME_CARD_ID,
		array(
			'label'           => __( 'S&N Analytics', 'signal-and-noise-tools' ),
			'description'     => __( 'First-party traffic for the last seven days.', 'signal-and-noise-tools' ),
			'provider'        => __( 'Signal & Noise Tools', 'signal-and-noise-tools' ),
			'icon'            => 'dashicons-chart-area',
			'default_enabled' => true,
			'capabilities'    => array( 'manage_options' ),
			'callback'        => 'snt_station_home_analytics_card_data',
		)
	);
}
add_action( 'init', 'snt_station_home_register_card' );
