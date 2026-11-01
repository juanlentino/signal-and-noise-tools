<?php
/**
 * Signal & Noise Tools: the analytics fields read (owner brief "MCP full
 * analytics access", 2026-11-01).
 *
 * One registry of what the analytics rows read accepts and returns: every
 * dimension and metric with its unit, a one-line meaning and the store that
 * answers it, plus the classes, the windows and what is not measured at all.
 * The query validator and this read share it, so a caller asking "what can I
 * ask for?" gets the answer the query will actually enforce
 * (tests/analytics-fields.php pins the two together). The local door's
 * analytics_query section keeps its own vocabulary, described with it.
 *
 * `signal-noise/analytics-fields` is the local door's read (sn-metrics section
 * analytics_fields); `signal-noise/remote-analytics-fields` is its remote twin.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The registry. PURE.
 *
 * @return array{dimensions:array,metrics:array,classes:array,windows:array,sources:array,reads:array,not_available:array}
 */
function snt_analytics_fields() {
	$dim = static fn( $name, $meaning, $unit, array $sources ) => array( 'name' => $name, 'meaning' => $meaning, 'unit' => $unit, 'sources' => $sources );
	$met = static fn( $name, $meaning, $unit, array $sources ) => array( 'name' => $name, 'meaning' => $meaning, 'unit' => $unit, 'sources' => $sources );
	return array(
		'dimensions'    => array(
			$dim( 'path', 'The page read. Only the site\'s own content is named; anything else a visitor asked for is (unmatched).', 'site path', array( 'stored' ) ),
			$dim( 'referrer', 'Where the visit came from: a hostname, or (direct), (internal), (unknown); anything that is not a clean hostname is (invalid).', 'hostname', array( 'stored' ) ),
			$dim( 'country', 'The visitor\'s country, from Cloudflare: (unknown) when none was sent; Cloudflare itself reports XX when it cannot tell and T1 for Tor.', 'ISO 3166-1 alpha-2 code, or (unknown)', array( 'stored' ) ),
			$dim( 'device', 'The kind of device, from the browser\'s description: mobile covers phones and tablets (iPad and iPod included); (unknown) when the browser sent none.', 'mobile | desktop | (unknown)', array( 'stored' ) ),
			$dim( 'link_code', 'The code of a coded résumé link (/r/CODE returns CODE), read out of the stored path; a malformed one is (invalid).', 'code', array( 'stored' ) ),
			$dim( 'day', 'The day, in the site\'s time zone.', 'YYYY-MM-DD', array( 'stored' ) ),
		),
		'metrics'       => array(
			$met( 'views', 'Page views, corrected for sampling.', 'page views', array( 'stored' ) ),
			$met( 'pageview_visits', 'Visitor-days that viewed: one visitor on one day, an estimate and not a session count.', 'visitor-days', array( 'stored' ) ),
			$met( 'time_avg_per_view', 'Visible time on the page per view. Stored per page only: null for referrer, country and device, and for a group that holds a day before the rows answer\'s exact_metrics_since.', 'milliseconds', array( 'stored' ) ),
			$met( 'scroll_avg_per_view', 'How far down the page readers got per view: the mean of each view\'s deepest milestone. Stored per page only: null for referrer, country and device, and for a group that holds a day before the rows answer\'s exact_metrics_since.', 'percent, 0 to 100', array( 'stored' ) ),
		),
		'classes'       => array(
			array( 'name' => 'human', 'meaning' => 'Presumed human: not flagged by the user agent, the network, the bot score or the over-cap rule. An inferred class.' ),
			array( 'name' => 'suspect', 'meaning' => 'A datacenter or hosting network that is not a known crawler.' ),
			array( 'name' => 'bot', 'meaning' => 'A known crawler by user agent, or a low Cloudflare bot score (scored only where Bot Management runs). A visitor-day over the 50-page-view cap counts as bot in path, day and link_code rows; referrer, country and device rows leave it out of every class.' ),
		),
		'windows'       => array(
			'ranges' => array_merge( array_map( 'strval', SN_ANALYTICS_RANGES ), array( 'all' ) ), // the list snt_analytics_range_is_valid() reads.
			'note'   => 'A range ends today, in the site\'s time zone; "all" starts at the first stored day.',
		),
		'sources'       => array(
			array( 'name' => 'stored', 'meaning' => 'The daily rollups, one row per day and value.', 'retention' => 'kept forever, from the first stored day', 'grain' => 'day' ),
		),
		'reads'         => array(
			array( 'name' => 'analytics_rows', 'local' => 'sn-metrics section analytics_rows', 'remote' => 'sn_remote_analytics_query', 'arguments' => 'dimensions (one, or one with day), range, class, path, referrer, sort (views | visits | time), limit (1 to 500)' ),
			array( 'name' => 'analytics_fields', 'local' => 'sn-metrics section analytics_fields', 'remote' => 'sn_remote_analytics_fields', 'arguments' => 'none' ),
		),
		'not_available' => array(
			array( 'name' => 'returning across days', 'reason' => 'The daily visitor code rotates at the site\'s midnight and its secret is deleted within 74 hours, so no visitor is recognized on another day.' ),
			array( 'name' => 'who a visitor is', 'reason' => 'No IP address, user agent or visitor code is returned by any analytics read.' ),
		),
	);
}

/** The output schema, shared by both registrations (the remote contract hashes it). PURE. */
function snt_analytics_fields_output_schema() {
	$s    = array( 'type' => 'string' );
	$list = array( 'type' => 'array', 'items' => $s );
	$obj  = static fn( array $props ) => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $props ) );
	return array(
		'type'       => 'object',
		'properties' => array(
			'dimensions'    => $obj( array( 'name' => $s, 'meaning' => $s, 'unit' => $s, 'sources' => $list ) ),
			'metrics'       => $obj( array( 'name' => $s, 'meaning' => $s, 'unit' => $s, 'sources' => $list ) ),
			'classes'       => $obj( array( 'name' => $s, 'meaning' => $s ) ),
			'windows'       => array( 'type' => 'object', 'properties' => array( 'ranges' => $list, 'note' => $s ) ),
			'sources'       => $obj( array( 'name' => $s, 'meaning' => $s, 'retention' => $s, 'grain' => $s ) ),
			'reads'         => $obj( array( 'name' => $s, 'local' => $s, 'remote' => $s, 'arguments' => $s ) ),
			'not_available' => $obj( array( 'name' => $s, 'reason' => $s ) ),
		),
	);
}

/**
 * Execute: the registry, for either door.
 *
 * @param mixed $input Unused (the read takes no arguments).
 * @return array
 */
function snt_ability_analytics_fields( $input = null ) {
	unset( $input );
	return snt_analytics_fields();
}

/** The remote twin's gate: the remote door, for its own slug. */
function snt_ability_perm_remote_analytics_fields() {
	return sn_remote_analytics_allows( 'signal-noise/remote-analytics-fields' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$base = array(
		'category'         => 'analytics',
		'execute_callback' => 'snt_ability_analytics_fields',
		'output_schema'    => snt_analytics_fields_output_schema(),
		'description'      => 'What the analytics rows read (sn-metrics section analytics_rows; the remote analytics query) accepts and returns, before querying: every dimension and metric with its unit, a one-line meaning and the store that answers it (sources), the traffic classes, the windows, the reads that take them, and what is not measured at all. The local analytics_query section keeps its own vocabulary, described with it. Read-only.',
	);
	wp_register_ability( 'signal-noise/analytics-fields', $base + array(
		'label'               => 'Analytics fields',
		// Written out in both registrations for the static readers (tests/abilities-categories.php).
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'permission_callback' => 'snt_ability_perm_manage_options',
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ), // reached locally as sn-metrics{analytics_fields}.
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
	wp_register_ability( 'signal-noise/remote-analytics-fields', $base + array(
		'label'               => 'Analytics fields (remote)',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'permission_callback' => 'snt_ability_perm_remote_analytics_fields',
		'meta'                => array(
			'show_in_rest' => false, // the bridge executes it directly, as every twin.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
