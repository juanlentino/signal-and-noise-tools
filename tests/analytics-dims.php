<?php
/**
 * Tests for inc/analytics-dims.php — the referrer/country/device breakdown layer.
 * Run: php tests/analytics-dims.php
 * @since plugin v5.0.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
// Constants the dims module reuses from sibling modules (not loaded here).
define( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS', 7 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

$GLOBALS['__ad_options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__ad_options'] ) ? $GLOBALS['__ad_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__ad_options'][ $k ] = $v; return true; }

$GLOBALS['__ad_dbdelta_calls'] = array();
function dbDelta( $sql ) { $GLOBALS['__ad_dbdelta_calls'][] = $sql; return array(); }

// AE read-client seam (analytics-api.php not loaded here).
$GLOBALS['__ad_query_return']  = null;
$GLOBALS['__ad_query_calls']   = array();
$GLOBALS['__ad_config_present'] = true;
function sn_analytics_config() { return $GLOBALS['__ad_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
function sn_analytics_query( $sql ) { $GLOBALS['__ad_query_calls'][] = $sql; return $GLOBALS['__ad_query_return']; }

class AD_Stub_wpdb {
    public $prefix = 'wp_';
    public $queries = array();
    public $rows = array();
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
            $a = $args[ $i ] ?? ''; ++$i;
            switch ( $m[0] ) {
                case '%d': return (string) (int) $a;
                case '%f': return (string) (float) $a;
                default:   return "'" . addslashes( (string) $a ) . "'";
            }
        }, $query );
    }
    public function query( $sql ) { $this->queries[] = $sql; return empty( $GLOBALS['__ad_query_fail'] ) ? 1 : false; }
    public function get_results( $sql, $output = ARRAY_A ) {
        $this->queries[] = $sql;
        if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
        $rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
        foreach ( array( 'dim', 'class' ) as $f ) {
            if ( preg_match( "/{$f} = '([^']*)'/", $sql, $mm ) ) {
                $val  = $mm[1];
                $rows = array_values( array_filter( $rows, function ( $r ) use ( $f, $val ) { return (string) ( $r[ $f ] ?? '' ) === $val; } ) );
            }
        }
        // GROUP BY value → aggregate per value.
        if ( stripos( $sql, 'GROUP BY value' ) !== false ) {
            $agg = array();
            foreach ( $rows as $r ) {
                $v = (string) $r['value'];
                if ( ! isset( $agg[ $v ] ) ) { $agg[ $v ] = array( 'value' => $v, 'views' => 0, 'visits' => 0 ); }
                $agg[ $v ]['views']  += (int) $r['views'];
                $agg[ $v ]['visits'] += (int) $r['visits'];
            }
            usort( $agg, function ( $a, $b ) { return (int) $b['views'] - (int) $a['views']; } );
            return array_values( $agg );
        }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new AD_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-dims.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }
function ad_reset() {
    $GLOBALS['__ad_options'] = array();
    $GLOBALS['__ad_dbdelta_calls'] = array();
    $GLOBALS['__ad_query_return'] = null;
    $GLOBALS['__ad_query_calls'] = array();
    $GLOBALS['__ad_config_present'] = true;
    $GLOBALS['__ad_query_fail'] = false;
    $GLOBALS['wpdb'] = new AD_Stub_wpdb();
}

echo "Analytics dims (referrer/country/device) layer\n\n";

echo "Group: schema SQL\n";
ad_reset();
$schema = sn_analytics_dims_schema_sql();
ok( is_string( $schema ) && strpos( $schema, 'wp_sn_analytics_dims' ) !== false, 'schema: targets the prefixed dims table' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: dbDelta two-space PRIMARY KEY form' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*,\s*dim\s*,\s*value\s*,\s*class\s*\)/', $schema ) === 1, 'schema: UNIQUE KEY (day, dim, value, class)' );
ok( strpos( $schema, 'VARCHAR(160)' ) !== false, 'schema: value is VARCHAR(160) so the key fits 767 bytes' );
ok( strpos( $schema, 'utf8mb4' ) !== false, 'schema: includes the charset collate' );
foreach ( array( 'day', 'dim', 'value', 'class', 'views', 'visits' ) as $col ) {
    ok( preg_match( '/\b' . $col . '\b/', $schema ) === 1, "schema: declares the $col column" );
}

echo "\nGroup: maybe_install\n";
ad_reset();
sn_analytics_dims_maybe_install();
ok( count( $GLOBALS['__ad_dbdelta_calls'] ) === 1, 'maybe_install: missing version runs dbDelta' );
ok( get_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT ) === SN_ANALYTICS_DIMS_DB_VERSION, 'maybe_install: stamps the version option' );
ad_reset();
update_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT, SN_ANALYTICS_DIMS_DB_VERSION );
sn_analytics_dims_maybe_install();
ok( count( $GLOBALS['__ad_dbdelta_calls'] ) === 0, 'maybe_install: current version → no dbDelta' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
