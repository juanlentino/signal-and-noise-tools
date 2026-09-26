<?php
/**
 * The north star reading: four rolling weeks of raw events, tallied, plus the
 * supporting metrics, cached for an hour so widget polls never re-query the
 * Analytics Engine. Exposed as the read ability `signal-noise/north-star`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build (or return the cached) reading.
 *
 * @param bool $fresh Skip the cache.
 * @return array
 */
function snt_nsm_reading( $fresh = false ) {
	$cached = $fresh ? false : get_transient( SNT_NSM_CACHE_KEY );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$cfg  = snt_nsm_config();
	$now  = time();
	$from = gmdate( 'Y-m-d', $now - ( 7 * SNT_NSM_WEEKS - 1 ) * DAY_IN_SECONDS );
	$raw  = function_exists( 'sn_analytics_fetch_session_events' )
		? sn_analytics_fetch_session_events( $from, gmdate( 'Y-m-d', $now ), 'human' )
		: array( 'visits' => array(), 'capped' => false, 'configured' => false );

	$weeks = array();
	foreach ( snt_nsm_weeks( (array) $raw['visits'], $now ) as $visits ) {
		$weeks[] = snt_nsm_tally( $visits, $cfg );
	}
	$series = array_column( $weeks, 'readers' );
	$prior  = array_slice( $series, 1 );

	$out = array(
		'configured' => (bool) $raw['configured'],
		'capped'     => (bool) $raw['capped'],
		'value'      => (int) $series[0],
		'previous'   => (int) $series[1],
		'prior_avg'  => $prior ? round( array_sum( $prior ) / count( $prior ), 1 ) : 0.0,
		'series'     => array_reverse( $series ), // oldest week first, for a sparkline
		'definition' => array(
			'sections' => $cfg['sections'],
			'scroll'   => $cfg['scroll'],
			'dwell_s'  => (int) ( $cfg['dwell_ms'] / 1000 ),
			'unit'     => 'visitor-day',
		),
		'layers'     => snt_nsm_inputs( $weeks[0], $now, (int) array_sum( $series ) ),
		'as_of'      => gmdate( 'c', $now ),
	);
	set_transient( SNT_NSM_CACHE_KEY, $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * The supporting metrics in three groups: intent (layer 2), return (layer 3,
 * adapted to writing: no sales), and the inputs that feed the star. Each carries its own window; a missing source is
 * null, never zero.
 *
 * @param array $week       This week's tally.
 * @param int   $now        Epoch seconds.
 * @param int   $readers_4w Readers over the four weeks.
 * @return array<string,array<string,array{value:int|float|null,window:string}>>
 */
function snt_nsm_inputs( array $week, $now, $readers_4w ) {
	$rss = function_exists( 'sn_rss_tracker_window_stats_multi' ) ? sn_rss_tracker_window_stats_multi( array( 7 ) ) : null;
	$gsc = function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null;
	$notes = snt_nsm_published_since( $now - 7 * DAY_IN_SECONDS );
	$month = snt_nsm_published_since( $now - 7 * SNT_NSM_WEEKS * DAY_IN_SECONDS );
	$inq   = snt_nsm_inquiries_since( $now - 7 * DAY_IN_SECONDS );
	return array(
		// Layer 2, intent: deliberate acts past a read.
		'intent' => array(
			'deep_readers'   => array( 'value' => (int) $week['deep'], 'window' => '7d' ),
			'actions'        => array( 'value' => (int) $week['intent'], 'window' => '7d' ),
			'career_visits'  => array( 'value' => (int) $week['career'], 'window' => '7d' ),
		),
		// Layer 3, return: what the writing brings back. Not a sale: readers per
		// note is the return on writing time, DOI downloads the scholarly
		// uptake, inquiries the opportunities (inc/north-star-return.php).
		'return' => array(
			'readers_per_note' => array( 'value' => $month > 0 ? round( $readers_4w / $month, 1 ) : null, 'window' => '28d' ),
			'doi_downloads'    => snt_nsm_zenodo_reading( (array) get_option( SNT_NSM_ZENODO_OPT, array() ), gmdate( 'Y-m-d', $now ) ),
			'inquiries'        => null === $inq ? array( 'value' => null, 'window' => '', 'pending' => __( 'The forms plugin is not active.', 'signal-and-noise-tools' ) ) : array( 'value' => $inq, 'window' => '7d' ),
		),
		// What feeds the star.
		'inputs' => array(
			'notes_published' => array( 'value' => $notes, 'window' => '7d' ),
			'rss_readers'     => array( 'value' => isset( $rss['windows'][7]['uniques'] ) ? (int) $rss['windows'][7]['uniques'] : null, 'window' => '7d' ),
			'search_clicks'   => array( 'value' => is_array( $gsc ) ? (int) $gsc['clicks'] : null, 'window' => is_array( $gsc ) ? (int) ( $gsc['days'] ?? 0 ) . 'd' : '' ),
		),
	);
}

/**
 * Posts published since an epoch.
 *
 * @param int $since Epoch seconds.
 * @return int
 */
function snt_nsm_published_since( $since ) {
	return count(
		get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'date_query'     => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ), 'column' => 'post_date_gmt' ) ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		)
	);
}

/**
 * Ability execute: the reading.
 *
 * @return array
 */
function snt_ability_north_star() {
	return snt_nsm_reading();
}

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'signal-noise/north-star',
			array(
				'label'               => __( 'North star', 'signal-and-noise-tools' ),
				'description'         => __( 'Weekly engaged readers: human visitor-days with at least one core page read past the scroll or dwell floor, over four rolling weeks, with the supporting metrics (deep readers, intent actions, resume/contact visits, RSS readers, search clicks, notes published). Cached for an hour.', 'signal-and-noise-tools' ),
				'category'            => 'diagnostics',
				'permission_callback' => 'snt_ability_perm_manage_options',
				'execute_callback'    => 'snt_ability_north_star',
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true, 'type' => 'tool' ),
					'annotations'  => array(
						'readonly'        => true,
						'destructive'     => false,
						'idempotent'      => true,
						'open_world_hint' => false,
					),
				),
			)
		);
	}
);
