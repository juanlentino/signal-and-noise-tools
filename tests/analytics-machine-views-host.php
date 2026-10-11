<?php
/**
 * Tests: the machine views as S&N Analytics' native window REALLY paints them.
 *
 * 2026-10-11: release 2026.10.1 shipped Machine readers and Reader evidence as
 * white boxes with invisible text. canonical_piece() (apps/sn-analytics/parts/
 * canonical.php) sends every view/* key through the classic renderer and the
 * native capture, so the native painters never ran; the classic markup used a
 * settings fieldset (`.sn-fieldset`) and core notices, which the dark window
 * never maps. Every test then called the painters directly. This one goes
 * through canonical_piece(), the production path, and fails on any classic
 * light-surface class in what the native window receives.
 *
 * Run: php tests/analytics-machine-views-host.php
 */
namespace SignalNoise\OpenStationHost\Analytics {
	function capture( array $get, ?callable $paint = null ) {
		ob_start();
		$paint();
		return (string) ob_get_clean();
	}
	function page_slug() { return 'sn-analytics'; }
}
namespace {
	require_once __DIR__ . '/lib/os-leaf-harness.php';
	if ( ! function_exists( 'remove_filter' ) ) { function remove_filter( $hook, $cb, $p = 10 ) { foreach ( $GLOBALS['__filters'][ $hook ][ $p ] ?? array() as $i => $f ) { if ( $f === $cb ) { unset( $GLOBALS['__filters'][ $hook ][ $p ][ $i ] ); } } return true; } }
	if ( ! function_exists( 'remove_query_arg' ) ) { function remove_query_arg( $k, $u = '' ) { return 'admin.php?page=sn-analytics&sn_view=machine-readers'; } }
	if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $k, $v = '', $u = '' ) { return 'admin.php?page=sn-analytics&' . ( is_array( $k ) ? http_build_query( $k ) : $k . '=' . $v ); } }
	function snt_os_host_keep_forms( $html, ...$a ) { return $html; }
	function snt_os_host_rewrite( $html, ...$a ) { return $html; }
	function snt_os_analytics_keep_actions() { return array(); }
	function sn_rss_tracker_window_stats_multi( $w ) { return array( 'windows' => array( 7 => array( 'total' => 40, 'uniques' => 5 ), 30 => array( 'total' => 210, 'uniques' => 9 ) ) ); }
	function snt_ml_reader_anomalies() { return array( 'ok' => true, 'state' => 'ok', 'co_movement' => array( array( 'network' => 'Google LLC', 'day' => '2026-10-10', 'direction' => 'up', 'families' => array( 'amazon-ai', 'anthropic', 'bytedance' ) ) ) ); }
	$day = static fn( $n ) => gmdate( 'Y-m-d', time() - $n * 86400 );
	$r   = static fn( $family, $purpose, $agent, $hits, $net, $vb = '', $extra = array() ) => array_merge( array( 'family' => $family, 'surface' => 'html', 'day' => $day( 2 ), 'hits' => $hits, 'purpose' => $purpose, 'agent' => $agent, 'vendor' => explode( '-', $agent )[0], 'taxonomy_version' => '1.4.0', 'first_party' => false, 'verified_bot' => $vb, 'network' => $net, 'signed_agent' => 'unsigned', 'markdown_requested' => false ), $extra );
	$rows = array(
		$r( 'anthropic', 'train', 'anthropic-claudebot', 25, 'Anthropic, PBC', 'AI Crawler', array( 'signed_agent' => 'valid' ) ),
		$r( 'anthropic', 'train', 'anthropic-claudebot', 798, 'Google LLC', '', array( 'day' => $day( 1 ) ) ),
		$r( 'openai', 'train', 'openai-gptbot', 309, 'Google LLC' ),
		$r( 'google-ai', 'train', 'google-extended', 170, 'Google LLC' ),
		$r( 'other-bot', 'search', 'anthropic-searchbot', 570, 'Anthropic, PBC', 'Search Engine Crawler', array( 'day' => $day( 3 ) ) ),
		$r( 'other-bot', 'unknown', '', 3537, 'Google LLC', '', array( 'day' => $day( 4 ) ) ),
		$r( 'unclassified-machine', 'ops', 'self-ledger', 11868, 'Microsoft Corporation', '', array( 'first_party' => true, 'vendor' => 'signal-and-noise', 'day' => $day( 5 ) ) ),
	);
	function snt_mr_fetch( $days = 30, $view = 'aggregate' ) {
		$f = array(
			'aggregate'       => array( 'ok' => true, 'truncated' => false, 'rows' => $GLOBALS['__rows'], 'webmcp' => array( 'calls' => 9 ) ),
			'rights'          => array( 'ok' => true, 'rows' => array( array( 'observed_at' => '2026-10-09T10:00:00.511Z', 'path' => '/license.xml', 'user_agent' => 'GPTBot/1.0', 'vendor' => 'openai', 'purpose' => 'train', 'family' => 'openai', 'hits' => 1 ) ) ),
			'unknown-network' => array( 'ok' => true, 'detail' => 'network', 'rows' => array( array( 'user_agent' => 'Mozilla/5.0 compatible GrokBot/1.0 +https //x.ai/', 'hits' => 310, 'verified_bot' => '', 'network' => 'Google LLC' ) ) ),
		);
		return $f[ $view ] ?? array( 'ok' => false, 'rows' => array() );
	}
	$GLOBALS['__rows'] = $rows;
	foreach ( array( 'machine-readers-taxonomy', 'machine-readers-render', 'machine-readers-insights', 'rights-evidence-compose', 'machine-readers-basis', 'machine-readers-basis-fold', 'machine-readers-ledger', 'mr-series', 'ml-reader-anomalies-split', 'machine-readers-readers-model', 'machine-readers-evidence-model', 'evidence-kit', 'evidence-paint-surface', 'evidence-paint-readers', 'evidence-paint-actors', 'evidence-paint-anomalies', 'evidence-paint-reference', 'evidence-paint-rights', 'evidence-paint-charts', 'evidence-paint-tiles', 'analytics-machine-views', 'analytics-view-machine-readers', 'analytics-view-reader-evidence', 'analytics-panels', 'analytics-view-body' ) as $f ) {
		require_once SNT_PATH . "inc/$f.php";
	}
	require_once SNT_PATH . 'apps/sn-analytics/parts/canonical.php';

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }
	$ctx = static fn( $view ) => array( 'view' => $view, 'from' => $day( 6 ), 'to' => $day( 0 ), 'class' => 'human', 'granularity' => 'day', 'range' => '7', 'compare' => 'off', 'get' => array() );
	// Classes the dark native window never maps: a settings fieldset, core
	// notices, the classic tile row and chip, the classic two-up.
	$leaks = array( 'sn-fieldset', 'class="notice', 'sn-mr-kpi-row', 'sn-mr-chip', 'sn-2up', 'snt-ev-band' );

	foreach ( array( 'machine-readers' => 'Machine readers', 'reader-evidence' => 'Reader evidence' ) as $view => $name ) {
		echo "\nGroup: $name through canonical_piece(), the path production paints\n";
		$html = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'view/' . $view, $ctx( $view ) )['html'];
		ok( substr_count( $html, '<os-section class="snt-native-panel"' ) >= 4, "$name: its boxes are native panels (" . substr_count( $html, '<os-section class="snt-native-panel"' ) . ')' );
		ok( false !== strpos( $html, '<div class="snt-native-stats">' ) && false !== strpos( $html, '<os-stat' ), "$name: its tiles are os-stat tiles in the native stats row" );
		foreach ( $leaks as $leak ) {
			ok( false === strpos( $html, $leak ), "$name: no `$leak` reaches the dark window" );
		}
		ok( false === strpos( $html, '<details' ) && false === strpos( $html, 'os-disclosure' ), "$name: no collapsible section" );
	}

	echo "\nGroup: what each view says\n";
	$mr = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'view/machine-readers', $ctx( 'machine-readers' ) )['html'];
	ok( false !== strpos( $mr, 'Machine reads per day (UTC)' ) && false !== strpos( $mr, 'sn-spark' ), 'Machine readers: the reads-per-day chart, the Overview chart' );
	ok( false !== strpos( $mr, 'AI training, claimed against proof' ), 'Machine readers: the finding has its own panel' );
	ok( false !== strpos( $mr, 'AI training, counted both ways' ) && false !== strpos( $mr, 'ai_training_by_purpose' ), 'Machine readers: counted both ways as two tiles, the field names as captions' );
	ok( false !== strpos( $mr, '2026-10-09T10:00:00.511Z' ), 'Machine readers: the rights log keeps a millisecond stamp whole (#1227)' );
	$re = \SignalNoise\OpenStationHost\Analytics\canonical_piece( 'view/reader-evidence', $ctx( 'reader-evidence' ) )['html'];
	ok( false !== strpos( $re, 'Verified share by purpose' ) && false !== strpos( $re, 'Verified share by family' ), 'Reader evidence: the verified share by purpose and by family' );
	ok( 1 === preg_match( '/class="sn-mr-bar"[^>]*aria-hidden="true"/', $re ), 'Reader evidence: the bars are aria-hidden; their counts are text' );
	ok( false !== strpos( $re, '<strong>25</strong> of 1,132 verified' ), 'Reader evidence: the train row reads 25 of 1,132 verified (Google-Extended left out)' );
	ok( false !== strpos( $re, 'Control tokens' ) && false !== strpos( $re, 'Google-Extended' ), 'Reader evidence: the control-token tile and panel name Google-Extended' );
	ok( false !== strpos( $re, 'sn-mr-verdict' ) && false !== strpos( $re, 'Google LLC' ), 'Reader evidence: co-movement is a dark-mapped callout naming the network' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
