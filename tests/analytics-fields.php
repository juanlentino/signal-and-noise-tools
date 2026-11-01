<?php
/**
 * Tests: the analytics fields read (inc/analytics-fields.php). Owner brief
 * "MCP full analytics access", 2026-11-01: before querying, a caller can see
 * every dimension and metric the reads accept, with its unit and meaning. The
 * registry is pinned to what the query enforces, so the two cannot drift.
 *
 * Run: php tests/analytics-fields.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['abilities'] = array();
function add_action( $h, $cb = null ) { if ( 'wp_abilities_api_init' === $h ) { $GLOBALS['init'][] = $cb; } }
function add_filter() {}
function wp_register_ability( $slug, $def ) { $GLOBALS['abilities'][ $slug ] = $def; }
$GLOBALS['allowed'] = array();
function sn_remote_analytics_allows( $slug ) { return in_array( $slug, $GLOBALS['allowed'], true ); }
class WP_Error { public function __construct( ...$a ) {} }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$root = dirname( __DIR__ );
// The real constants, read out of their files: a class or range added there must show here.
$const = static function ( $file, $name ) use ( $root ) {
	preg_match( '/const ' . $name . '\s*=\s*(array\([^)]*\));/', (string) file_get_contents( "$root/inc/$file" ), $m );
	return eval( 'return ' . $m[1] . ';' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only: the plugin's own constant literal.
};
define( 'SN_ANALYTICS_CLASSES', $const( 'analytics-rollup.php', 'SN_ANALYTICS_CLASSES' ) );
define( 'SN_ANALYTICS_RANGES', $const( 'analytics-admin.php', 'SN_ANALYTICS_RANGES' ) );
require $root . '/inc/analytics-fields.php';
require $root . '/inc/analytics-rows.php';
require $root . '/inc/abilities-analytics-rows.php';
foreach ( $GLOBALS['init'] as $cb ) { $cb(); }

$f = snt_analytics_fields();

echo "\nGroup: the registry is what the query enforces\n";
ok( SNT_AROWS_DIMENSIONS === array_column( $f['dimensions'], 'name' ), 'the dimensions are exactly the ones analytics_rows accepts, in its order' );
$row_props = array_keys( snt_analytics_rows_output_schema()['properties']['rows']['items']['properties'] );
ok( array_values( array_diff( $row_props, SNT_AROWS_DIMENSIONS ) ) === array_column( $f['metrics'], 'name' ), 'the metrics are exactly the figures each row carries' );
ok( SN_ANALYTICS_CLASSES === array_column( $f['classes'], 'name' ), 'the classes are the ones every read accepts' );
ok( array( '7', '14', '30', '90', '365', 'all' ) === $f['windows']['ranges'], 'the ranges a read accepts (from SN_ANALYTICS_RANGES, which the validator reads)' );
ok( false !== strpos( $f['classes'][2]['meaning'], 'path, day and link_code' ) && false !== strpos( $f['classes'][2]['meaning'], 'referrer, country and device rows leave it out' ), 'bot says where the over-cap rule lands: bot by page, nowhere by referrer, country or device' );

echo "\nGroup: every entry says what it is\n";
foreach ( array( 'dimensions', 'metrics' ) as $kind ) {
	foreach ( $f[ $kind ] as $e ) {
		ok( '' !== trim( $e['meaning'] ) && '' !== trim( $e['unit'] ) && array() !== $e['sources'], "$kind {$e['name']}: a meaning, a unit and a store" );
		ok( array() === array_diff( $e['sources'], array_column( $f['sources'], 'name' ) ), "$kind {$e['name']}: its stores are named in sources" );
	}
}
ok( false === strpos( json_encode( $f, JSON_UNESCAPED_UNICODE ), '—' ), 'no em dash anywhere' );
$names = array_column( $f['not_available'], 'name' );
ok( in_array( 'returning across days', $names, true ) && in_array( 'who a visitor is', $names, true ), 'what is not measured is said: no cross-day identity, no visitor identity' );

echo "\nGroup: the answer fits its schema\n";
$schema = snt_analytics_fields_output_schema();
ok( array_keys( $schema['properties'] ) === array_keys( $f ), 'every top-level key is in the schema, in order' );
foreach ( array( 'dimensions', 'metrics', 'classes', 'sources', 'reads', 'not_available' ) as $k ) {
	$want = array_keys( $schema['properties'][ $k ]['items']['properties'] );
	ok( array() === array_filter( $f[ $k ], static fn( $e ) => array_keys( $e ) !== $want ), "$k: every entry has exactly the schema's fields" );
}

echo "\nGroup: both doors\n";
ok( isset( $GLOBALS['abilities']['signal-noise/analytics-fields'], $GLOBALS['abilities']['signal-noise/remote-analytics-fields'] ), 'a local read and its remote twin' );
$local  = $GLOBALS['abilities']['signal-noise/analytics-fields'];
$remote = $GLOBALS['abilities']['signal-noise/remote-analytics-fields'];
ok( $local['output_schema'] === $remote['output_schema'] && $local['execute_callback'] === $remote['execute_callback'], 'the twin answers the same thing, by construction' );
ok( 'snt_ability_perm_manage_options' === $local['permission_callback'], 'local: the owner only' );
ok( false === snt_ability_perm_remote_analytics_fields(), 'remote: refused while the door does not allow it (switch off, or a caller without the capability)' );
$GLOBALS['allowed'] = array( 'signal-noise/remote-analytics-rows' );
ok( false === snt_ability_perm_remote_analytics_fields(), 'remote: another slug allowed is not this one' );
$GLOBALS['allowed'] = array( 'signal-noise/remote-analytics-fields' );
ok( true === snt_ability_perm_remote_analytics_fields(), 'remote: allowed only for its own slug' );
ok( $f === snt_ability_analytics_fields( array( 'anything' => 1 ) ), 'the read takes no arguments' );
$guard = (string) file_get_contents( $root . '/inc/mcp/mcp-remote-guard.php' );
ok( false !== strpos( $guard, "\t\t'signal-noise/remote-analytics-fields',\n" ) && false !== strpos( $guard, "'analytics_fields'     => \$out( true," ), 'the twin is on the remote list, with its verdict (tests/mcp-remote-guard.php pins the exact list)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
