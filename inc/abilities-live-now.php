<?php
/**
 * Read-door ability: what the site looks like right now.
 *
 * The admin live answer (inc/analytics-live.php) for agents: readers in the
 * last 5 minutes (every class), human views today, the last hour in 5-minute
 * slots, the pages being read, where readers arrived from, one line per current
 * reader (place, device, entry source; inc/analytics-live-readers.php), and the live-surge
 * analytics signal (inc/analytics-live-surge.php). Reads the realtime cache only;
 * never queries Analytics Engine on read. The sn-status `live` section.
 *
 * @package Signal_And_Noise_Tools
 * @since   23.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Execute callback. With no argument the answer is the live payload,
 * unchanged; `hours_back` (1 to 24) adds `slots`, the stored snapshots
 * (inc/analytics-live-slots.php).
 *
 * @param array|null $input { hours_back? }.
 * @return array|WP_Error
 */
function snt_ability_live_now( $input ) {
	if ( ! function_exists( 'sn_analytics_live_payload' ) ) {
		return new WP_Error( 'snt_live_now_unavailable', 'The live analytics module is unavailable.', array( 'status' => 500 ) );
	}
	$out = sn_analytics_live_payload( true );
	if ( is_array( $out ) ) {
		$out['feed_opens'] = function_exists( 'snt_feed_opens_live' ) ? snt_feed_opens_live( (array) get_option( SNT_FEED_OPENS_OPT, array() ), time() ) : null;
	}
	if ( ! is_array( $input ) || ! array_key_exists( 'hours_back', $input ) ) {
		return $out;
	}
	$hours = filter_var( $input['hours_back'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 24 ) ) );
	if ( false === $hours ) {
		return new WP_Error( 'snt_live_now_hours_back', 'hours_back must be a whole number from 1 to 24.', array( 'status' => 400 ) );
	}
	$out['slots'] = function_exists( 'sn_analytics_live_slots' ) ? sn_analytics_live_slots( (int) $hours ) : null;
	return $out;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/live-now', array(
		'label'               => 'What the site looks like right now',
		'description'         => 'The live analytics answer, read from the realtime cache (refreshed at most every 30 s while anyone is watching; never queries Analytics Engine on read). `now`: presumed-human readers (not flagged) active in the last 5 minutes with an engagement signal in that window (visible time, which the beacon sends at 10 s and on leaving, a scroll milestone or a custom event; since 2026-11-14), less `open_tab`, null when not read; `open_tab` (since 2026-11-17): presumed-human visitors with an engagement signal in the window and no page view since their visitor-day began (midnight in New York, where the visitor code rotates), so returning to a tab opened before it; counted apart, never in `now`, `classes.human` or `today`, null when not read (then `now` is not reduced); the hour bars, `slots`, `surge` and `pages` still count them; `arrived`: presumed-human visitors active in the window with a page view and no engagement signal yet, counted apart and never listed in `pages`; `arrived_classes` the same per class; each `hour` slot carries `engaged` (its readers) beside `readers` (every active visitor), and stored `slots` carry `engaged` and `arrived` (null before 2026-11-14); `surge` names its `basis` (readers once their own 4-day baseline exists, visitors until then, with `readers_days` while it learns), and its `readers` is the count on that basis; `classes`: the same count per traffic class (human, suspect, bot; inferred at read time); `today`: presumed-human pageviews since midnight in the site timezone: the exact count from the analytics worker\'s D1 store when `today_exact` is true (analytics worker 2.2.0), else Analytics Engine\'s weighted sum (the sum of its sample interval), an estimate when today was sampled; `today_exact`: whether `today` is that exact count; `today_stored`: the page-view records Analytics Engine kept today, null when not read (and, beside an exact `today`, null unless they were read with it, so an older same-day figure is never compared with the exact count): when `today_exact` is false and it is below `today`, today was sampled at write time and `today` reads "about `today`, from `today_stored` stored"; when `today_exact` is true, `today` is exact and the Sessions read (which lists kept records) can still read lower; `hour`: twelve 5-minute slots, oldest first, the last one current and partial, each { t (unix seconds, slot start), readers }; `pages`: the top five published, indexable notes and pages being read now, { label, url, readers, scroll }, where scroll { reached_25, reached_50, reached_75, reached_100, readers_with_scroll } counts how many of that page\'s current readers passed each scroll milestone in the same 5-minute window (cumulative, from each reader\'s deepest milestone: reached_50 includes those at the end; readers_with_scroll = reached_25; readers minus it have not passed 25% yet), null when the scroll read failed, 0s when it succeeded and nobody has scrolled, absent in a cache written before the field; `sources`: where readers whose pageview landed in the last 5 minutes arrived from, in the dashboard source names, { label, readers }; `readers`: one line per presumed-human reader active in the last 5 minutes, newest first, at most 20, { city, region, country, device, source (the dashboard source name of the visit\'s first page view in the last two hours, "" when none is in them), page and url (the page they are on now, as `pages` labels it; a coded résumé link is "Resume via CODE", anything else (unmatched)), pages (page views in the visit), minutes (since the visit\'s first page view), open_tab (true for a visitor returning to an open tab, counted in `open_tab` and not in `now`), paired (observe only: true when the visit\'s source is (direct) and it overlaps or comes within 60 seconds of a bot- or suspect-class page view, joined by time only; null when that read failed) }, kept only in the realtime cache and never in `slots`, no visitor code, IP address or user agent; `surge`: the live-surge analytics signal (deliberately not an ML pipeline: reader data stays out of the ML family) on the last COMPLETED slot against the same time of day on earlier days (robust z over median and MAD, one slot either side): state learning (under 4 days of history, no verdict), usual, or surge (z >= 3 and at least 3 readers), with readers, usual (the median), ratio (null when the usual is 0), z and days; `fetched`: when the cache was written (null = never). Null for any part means not read, never zero; a quiet site is 0 and [] . Optional `hours_back` (1 to 24) adds `slots`: the stored rolling snapshots of the last N hours, oldest first, one per COMPLETED 5-minute slot, each { t (slot start), readers (human, as in `hour`), classes { human, suspect, bot }, pages (as `pages`, without scroll), sources (as `sources`) }. A slot is stored when a realtime refresh runs after it completes and is kept 24 hours; a slot no refresh saw is absent, never 0. Aggregates only, no withholding floor (live has none). `feed_opens` (since 2026-11-17): feed-reader opens, a floor, read from the site, not the realtime cache: { basis, today (since midnight in the site timezone, like `today`; site days since 2026-11-17), today_day, week (last 7 site days, the same total as rss_stats `feed_opens.total_7d`), notes_7d (top 5 { post_id, title, opens }) }, null when not read. Without `hours_back` the answer is exactly the live answer plus `feed_opens`.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_live_now',
		'input_schema'        => array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array(
				'hours_back' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 24, 'description' => 'Add `slots`: the stored 5-minute snapshots of the last N hours (1 to 24).' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'now'          => array( 'type' => array( 'integer', 'null' ) ),
				'today'        => array( 'type' => array( 'integer', 'null' ) ),
				'today_stored' => array( 'type' => array( 'integer', 'null' ) ),
				'today_exact'  => array( 'type' => 'boolean' ),
				'open_tab'     => array( 'type' => array( 'integer', 'null' ) ),
				'pages'        => array( 'type' => array( 'array', 'null' ) ),
				'hour'         => array( 'type' => array( 'array', 'null' ) ),
				'fetched'      => array( 'type' => array( 'integer', 'null' ) ),
				'surge'        => array( 'type' => array( 'object', 'null' ) ),
				'sources'      => array( 'type' => array( 'array', 'null' ) ),
				'readers'      => array( 'type' => array( 'array', 'null' ) ),
				'classes'      => array( 'type' => 'object' ),
				'arrived'      => array( 'type' => array( 'integer', 'null' ) ),
				'arrived_classes' => array( 'type' => array( 'object', 'null' ) ),
				'slots'        => array( 'type' => array( 'array', 'null' ), 'description' => 'Only with hours_back.' ),
				'feed_opens'   => array( 'type' => array( 'object', 'null' ), 'description' => 'Feed-reader opens (the pixel rss_stats and north-star read), a floor: { basis "floor", today (since midnight in the site timezone), today_day, week (last 7 site days, the rss_stats total_7d), notes_7d [ { post_id, title, opens } ], top 5 }. Read from the site, not the realtime cache; null when the module is absent.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
