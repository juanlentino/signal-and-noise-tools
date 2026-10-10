<?php
/**
 * Guard: the daily series on the Machine Readers summary (series: "day").
 * Without `series` the response is byte-identical to the fixture captured
 * BEFORE the series existed; with it, `daily` is oldest first, zero-filled,
 * its edge days partial, and its fields sum to the window figures.
 *
 * Run: php tests/machine-readers-daily.php
 * Regenerate the default-response fixture (only before a deliberate change):
 *   GEN=1 php tests/machine-readers-daily.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function add_action( $t, $cb ) { $GLOBALS['__acts'][ $t ][] = $cb; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; }

// The sensor read: the only seam. Same signature as the real one.
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) { return 'totals' === $view ? $GLOBALS['__mr_totals'] : $GLOBALS['__mr']; }
function snt_mr_sensor_info() { return array( 'version' => '1.29.0' ); }
function snt_mr_crawler_list_status() { return array( 'last_check_ok' => '2026-10-10', 'last_check_drift' => '' ); }
foreach ( array( 'machine-readers-taxonomy', 'machine-readers-render', 'machine-readers-summary', 'machine-readers-daily', 'abilities-machine-readers' ) as $f ) {
	if ( is_file( __DIR__ . "/../inc/$f.php" ) ) { require __DIR__ . "/../inc/$f.php"; }
}
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$row = static fn( $day, $family, $surface, $purpose, $hits, $fp = false, $signed = 'valid' ) => array(
	'family' => $family, 'surface' => $surface, 'day' => $day, 'hits' => $hits, 'vendor' => $family, 'agent' => $family . '-bot',
	'purpose' => $purpose, 'taxonomy_version' => '1.0.0', 'training_corpus_source' => false, 'first_party' => $fp, 'signed_agent' => $signed,
);
$d = static fn( $ago ) => gmdate( 'Y-m-d', time() - $ago * 86400 );
// Three scenarios the default response must keep exactly: exact totals with
// the taxonomy, the aggregate fallback, and a sensor that never answered.
$scenarios = array(
	'exact' => array(
		array( 'ok' => true, 'rows' => array( $row( '2026-10-03', 'openai', 'rights', 'train', 15 ), $row( '2026-10-03', 'search', 'html', 'search', 25 ), $row( '2026-10-05', 'anthropic', 'robots', 'retrieval', 7 ), $row( '2026-10-05', 'uptime', 'html', 'ops', 9, true ) ), 'truncated' => false ),
		array( 'ok' => true, 'rows' => array( array( 'day' => '2026-10-03', 'hits' => 40 ), array( 'day' => '2026-10-05', 'hits' => 16 ) ) ),
	),
	'fallback' => array(
		array( 'ok' => true, 'rows' => array( $row( '2026-10-04', 'openai', 'html', 'train', 3 ) ), 'truncated' => true ),
		array( 'ok' => false, 'rows' => array(), 'error' => 'http_500' ),
	),
	'unconfigured' => array(
		array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ),
		array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ),
	),
);
$run = static function ( $name, $input ) use ( $scenarios ) {
	list( $GLOBALS['__mr'], $GLOBALS['__mr_totals'] ) = $scenarios[ $name ];
	return snt_ability_get_machine_readers_summary( $input );
};
$fixture = __DIR__ . '/fixtures/mr-summary-default.json';
$now     = array();
foreach ( array_keys( $scenarios ) as $name ) {
	$now[ $name ] = json_encode( $run( $name, array( 'days' => 7 ) ) );
}
if ( getenv( 'GEN' ) ) {
	file_put_contents( $fixture, json_encode( $now, JSON_PRETTY_PRINT ) . "\n" );
	echo "wrote $fixture\n";
	exit( 0 );
}

echo "\nWithout series, the response is the one captured before the series existed\n";
$before = json_decode( (string) file_get_contents( $fixture ), true );
foreach ( array_keys( $scenarios ) as $name ) {
	ok( ( $before[ $name ] ?? null ) === $now[ $name ], "byte-identical: $name" );
}

echo "\nseries refuses anything but \"day\"\n";
foreach ( array( 'week', 'DAY', '', 1, true, array( 'day' ) ) as $bad ) {
	$r = $run( 'exact', array( 'days' => 7, 'series' => $bad ) );
	ok( is_wp_error( $r ) && 'ability_invalid_input' === $r->code, 'refused: ' . json_encode( $bad ) );
}
ok( array( 'day' ) === ( $GLOBALS['__ab']['signal-noise/get-machine-readers-summary']['input_schema']['properties']['series']['enum'] ?? null ), 'the input schema declares series as enum ["day"]' );

echo "\nThe pure series: oldest first, zero-filled, edges partial, sums hold\n";
$fixed = gmmktime( 15, 0, 0, 10, 10, 2026 ); // 2026-10-10 15:00 UTC
$rows  = $scenarios['exact'][0]['rows'];
$tot   = $scenarios['exact'][1]['rows'];
$s     = snt_mr_summary_daily( $rows, $tot, 7, $fixed );
$days  = array_column( $s, 'day' );
ok( '2026-10-03' === $days[0] && '2026-10-10' === end( $days ) && 8 === count( $days ), 'a 7-day rolling window touches 8 UTC dates, oldest first' );
ok( true === ( $s[0]['partial'] ?? null ) && true === ( $s[7]['partial'] ?? null ) && ! array_key_exists( 'partial', $s[3] ), 'the first and last dates carry partial: true; full days carry no partial key' );
$z = $s[1]; // 2026-10-04: no rows at all
ok( 0 === $z['total'] && 0 === $z['ai_training'] && 0 === $z['first_party'] && array() === $z['purposes'] && array() === $z['ai_surfaces'], 'a no-data day appears as zeros and empty lists' );
ok( 40 === $s[0]['total'] && 16 === $s[2]['total'], 'per-day total comes from the exact totals view' );

$blank = snt_mr_summary_daily( array( $row( '', 'openai', 'html', 'train', 4 ) ), null, 7, $fixed );
ok( 8 === count( $blank ) && '2026-10-03' === $blank[0]['day'] && 4 === $blank[0]['ai_training'] && true === $blank[0]['partial'], 'a row with a blanked day joins the first date: no empty date, the sum holds' );

echo "\nThrough the ability: daily fields sum to the window figures\n";
foreach ( array( 'exact', 'fallback' ) as $name ) {
	$out = $run( $name, array( 'days' => 7, 'series' => 'day' ) );
	$sum = static fn( $k ) => array_sum( array_column( $out['daily'], $k ) );
	ok( $sum( 'total' ) === $out['total'] && $sum( 'ai_training' ) === $out['ai_training'], "$name: total and ai_training sum" );
	if ( null !== $out['first_party'] ) {
		ok( $sum( 'first_party' ) === $out['first_party'], "$name: first_party sums" );
	}
	$fold = static function ( $lists, $key ) {
		$acc = array();
		foreach ( $lists as $l ) { foreach ( (array) $l as $e ) { $acc[ $e[ $key ] ] = ( $acc[ $e[ $key ] ] ?? 0 ) + $e['hits']; } }
		ksort( $acc );
		return $acc;
	};
	$win = static function ( $l, $key ) { $a = array_column( (array) $l, 'hits', $key ); ksort( $a ); return $a; };
	ok( $fold( array_column( $out['daily'], 'purposes' ), 'purpose' ) === $win( $out['purposes'], 'purpose' ), "$name: purposes sum per purpose" );
	ok( $fold( array_column( $out['daily'], 'ai_surfaces' ), 'surface' ) === $win( $out['ai_surfaces'], 'surface' ), "$name: ai_surfaces sum per surface" );
	ok( $out['total_exact'] === $out['daily_total_exact'], "$name: the series says whether its totals are exact (daily_total_exact = $name)" );
	ok( ! array_key_exists( 'ai_training_status', $out ), "$name: no ai_training_status (the sensor records no response status)" );
}
$off = $run( 'unconfigured', array( 'days' => 7, 'series' => 'day' ) );
ok( false === $off['ok'] && ! array_key_exists( 'daily', $off ), 'a sensor that never answered returns no series, not a row of zeros' );

echo "\nThe remote twin: same callback, same schema, door-checked\n";
$set = (string) file_get_contents( __DIR__ . '/../inc/abilities-remote-set.php' );
ok( 1 === preg_match( "/'signal-noise\/remote-machine-readers-summary'.*?'permission_callback'\s*=>\s*'([a-z_]+)'/s", $set, $m ) && false !== strpos( $set, "function {$m[1]}" ), 'the twin keeps its own remote door permission (door off: the bridge 404s, the standard error)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
