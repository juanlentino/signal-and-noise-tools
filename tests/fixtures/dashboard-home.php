<?php
/**
 * S&N Home, live-shaped: the dashboard data bundle and the north star reading
 * the Home suite paints (tests/os-leaf-dashboard-systems-wall.php), plus the
 * stubs the full paint reaches. NOT a suite (tests/run.sh globs tests/*.php).
 *
 * Shaped like the live site: the three attention checks (Provenance, Health,
 * Cron, in the glance order) and the seven fleet components (Theme, Plugin and
 * the five workers), the reading's real layer keys.
 */

/**
 * The data bundle snt_dashboard_tab_data() returns.
 *
 * @param bool $calm All healthy: no check wants a look, every component reported, no overrides.
 * @return array
 */
function snt_home_fixture( $calm = false ) {
	$url  = static fn( $q ) => 'https://example.test/wp-admin/admin.php?page=sn-theme-options&' . $q;
	$comp = static fn( $label, $v ) => array( 'label' => $label, 'value' => $v, 'measured' => "\u{2014}" !== $v, 'attention' => false );
	return array(
		'verdict'    => array( 'state' => $calm ? 'ok' : 'warn' ),
		'checks'     => array(
			array( 'label' => 'Provenance', 'href' => $url( 'tab=tools&sub=provenance' ), 'value' => '212 confirmed', 'pill' => array( 'kind' => 'ok', 'text' => 'all anchored' ) ),
			$calm
				? array( 'label' => 'Health', 'href' => $url( 'tab=monitoring&sub=health' ), 'value' => '0 findings', 'pill' => array( 'kind' => 'ok', 'text' => 'all clear' ), 'meta_html' => 'scanned 3 hours ago' )
				: array( 'label' => 'Health', 'href' => $url( 'tab=monitoring&sub=health' ), 'value' => '3 findings', 'pill' => array( 'kind' => 'warn', 'text' => 'issues found' ), 'meta_html' => 'scanned 3 hours ago' ),
			array( 'label' => 'Cron', 'href' => $url( 'tab=connections&sub=cron' ), 'value' => '41 events', 'pill' => $calm ? array( 'kind' => 'ok', 'text' => 'healthy' ) : array( 'kind' => 'warn', 'text' => '2 orphans' ) ),
		),
		'components' => array(
			$comp( 'Theme', '8.4.1' ),
			$comp( 'Plugin', '2026.10.1' ),
			$comp( 'Analytics', '3.2.0' ),
			$comp( 'Provenance edge', '1.9.4' ),
			$comp( 'Login guard', '2.1.0' ),
			$comp( 'Remote door', $calm ? '1.4.0' : "\u{2014}" ),
			$comp( 'Rights signals', '1.3.0' ),
		),
		'panels'     => array(
			array( 'title' => 'Top pages', 'group' => 'audience', 'rows' => array( array( 'label' => '/notes/two-kinds-of-provenance/', 'value' => '412' ), array( 'label' => '/', 'value' => '377' ), array( 'label' => '/resume/', 'value' => '96' ) ) ),
			array( 'title' => 'Top sources', 'group' => 'audience', 'rows' => array( array( 'label' => 'linkedin.com', 'value' => '288' ), array( 'label' => 'google.com', 'value' => '201' ), array( 'label' => 'news.ycombinator.com', 'value' => '64' ) ) ),
			array( 'title' => 'Top queries', 'group' => 'audience', 'caption' => 'clicks, 28 days', 'rows' => array( array( 'label' => 'provenance over detection', 'value' => '31' ), array( 'label' => 'juan lentino', 'value' => '27' ) ) ),
		),
		// inc/dash-attention.php's items, as the live site makes them (2026.10.4).
		'attention'  => $calm ? array() : array(
			array( 'label' => 'Health', 'text' => '3 health findings in the last scan.', 'href' => $url( 'tab=monitoring&sub=health' ) ),
			array( 'label' => 'Database overrides', 'text' => '3 theme items are served from the database, not from the theme files.', 'href' => $url( 'tab=dashboard#sn-dash-diagnostics' ) ),
			array( 'label' => 'Cron', 'text' => '2 scheduled cron events have no handler, so nothing runs when they fire.', 'href' => $url( 'tab=connections&sub=cron' ) ),
		),
		'overrides'  => $calm ? array() : array( 'wp_template/home', 'wp_template_part/header', 'wp_navigation/navigation' ),
		'runs'       => array(),
	);
}

/** @return array<string,string> Every north star signal label => the figure it paints. */
function snt_home_signal_figures() {
	return array(
		'Deep readers (2+ pages)'                             => '31',
		'Downloads, outbound clicks'                          => '18',
		'Resume and contact visits'                           => '9',
		'Resume PDF downloads'                                => '4',
		'Feed subscribe clicks'                               => '2',
		'Notes shared'                                        => '3',
		'Signatures checked'                                  => '1',
		'Research links followed'                             => '6',
		'Readers per note'                                    => '14.2 · 28d',
		'DOI downloads'                                       => '23 · 28d',
		'Inquiries'                                           => 'not measured',
		'Notes published'                                     => '2',
		'Feed click-throughs'                                 => '11',
		'Feed opens (a floor)'                                => '64',
		'Feed subscribers (a ceiling)'                        => '120',
		'RSS readers'                                         => '17',
		'Search clicks'                                       => '212 · 28d',
	);
}

function snt_dashboard_tab_data() { return $GLOBALS['__home_data'] ?? snt_home_fixture(); }

function snt_nsm_reading() {
	if ( isset( $GLOBALS['__nsm'] ) ) {
		return $GLOBALS['__nsm'];
	}
	$v = static fn( $n, $w = '7d' ) => array( 'value' => $n, 'window' => $w );
	return array(
		'configured' => true,
		'capped'     => false,
		'value'      => 47,
		'previous'   => 41,
		'prior_avg'  => 39.3,
		'series'     => array( 36, 42, 41, 47 ),
		'trend'      => array( 22, 25, 31, 28, 33, 30, 36, 38, 36, 42, 41, 47 ),
		'layers'     => array(
			'intent' => array(
				'deep_readers' => $v( 31 ), 'actions' => $v( 18 ), 'career_visits' => $v( 9 ), 'resume_downloads' => $v( 4 ),
				'subscribes' => $v( 2 ), 'shares' => $v( 3 ), 'verifies' => $v( 1 ), 'research_links' => $v( 6 ),
			),
			'return' => array(
				'readers_per_note' => $v( 14.2, '28d' ),
				'doi_downloads'    => $v( 23, '28d' ),
				'inquiries'        => array( 'value' => null, 'window' => '', 'pending' => 'The forms plugin is not active.' ),
			),
			'inputs' => array(
				'notes_published' => $v( 2 ), 'feed_clicks' => $v( 11 ), 'feed_opens' => $v( 64 ),
				'feed_subscribers' => $v( 120, 'max_day_7d' ), 'rss_readers' => $v( 17 ), 'search_clicks' => $v( 212, '28d' ),
			),
			'quality' => array( 'likely_automated_share' => array( 'value' => 8.4, 'window' => '7d', 'observe_only' => true ) ),
		),
	);
}

// The pulse and the greeting read these when they exist.
// `visits` is the deprecated ungated count (every visitor-day); Home reads the
// gated `pageview_visits`, the Analytics app's Visits (2026.10.4 audit).
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) {
	return $GLOBALS['__home_deltas'] ?? array(
		'views'           => array( 'current' => 1914, 'previous' => 1702, 'pct' => 12 ),
		'visits'          => array( 'current' => 1270, 'previous' => 1180, 'pct' => 8 ),
		'pageview_visits' => array( 'current' => 812, 'previous' => 744, 'pct' => 9 ),
	);
}
function sn_analytics_config() { return $GLOBALS['__home_analytics'] ?? true; }
function wp_count_posts( $type = 'post' ) { return (object) array( 'publish' => 52, 'draft' => 4, 'pending' => 0, 'future' => 2, 'private' => 1, 'trash' => 9, 'auto-draft' => 3 ); }
function sn_analytics_delta( $cur, $prev ) { return array( 'pct' => $prev > 0 ? (int) round( ( $cur - $prev ) / $prev * 100 ) : null ); }
function snt_dashboard_measurement_data() { return array( 'search_clicks' => 212, 'search_clicks_days' => 28 ); }
function get_bloginfo( $what = '' ) { return 'Signal & Noise'; }
function wp_get_current_user() { return (object) array( 'user_firstname' => 'Juan', 'display_name' => 'Juan Lentino' ); }

/** Continue working: three recent items, found_posts the real total. */
class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public function __construct( array $args ) {
		$p = static fn( $id, $type, $status, $title, $h ) => (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $h * 3600 ) );
		$this->posts       = array( $p( 11, 'post', 'draft', 'Provenance without institutions', 2 ), $p( 12, 'post', 'future', 'Two kinds of provenance', 20 ), $p( 7, 'page', 'publish', 'Resume', 50 ) );
		$this->found_posts = 3;
	}
}
