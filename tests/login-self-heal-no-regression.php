<?php
/**
 * Confirms the v4.2.0 self-heal does NOT cause a flush when the
 * rewrite_rules option ALREADY contains our pattern. Without this
 * guard, every request would re-flush — DB write storm.
 */

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value ) {
    $GLOBALS['__options'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['__options'][ $name ] );
    return true;
}
$GLOBALS['__flush_count'] = 0;
function flush_rewrite_rules( $hard = true ) {
    $GLOBALS['__flush_count']++;
}
$GLOBALS['__actions'] = array();
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['__actions'][ $hook ][] = array( 'cb' => $callback, 'priority' => $priority );
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_rewrite_rule( $regex, $query, $position = 'top' ) {}
function sn_setting( $path, $default = null ) {
    $opts = get_option( 'sn_settings', array() );
    if ( $path === 'login.slug' ) {
        return $opts['login']['slug'] ?? $default;
    }
    return $default;
}
$GLOBALS['__options']['sn_settings'] = array( 'login' => array( 'slug' => 'backend' ) );
function is_plugin_active( $slug ) { return false; }
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', '/tmp/wp-plugins' ); }

require __DIR__ . '/../inc/login-hide.php';

$pass = 0;
$fail = 0;

function assertEq( $expected, $actual, $label ) {
    global $pass, $fail;
    if ( $expected === $actual ) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label — expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . "\n";
    }
}

// === Test: happy path — sentinel matches AND rule is present → no flush ===

$GLOBALS['__options']['sn_login_rewrites_flushed'] = 'backend';
$GLOBALS['__options']['rewrite_rules'] = array(
    '^backend/?$' => 'wp-login.php',
    'other-rule'  => 'index.php',
);
$GLOBALS['__flush_count'] = 0;

$init_99 = null;
foreach ( $GLOBALS['__actions']['init'] ?? array() as $action ) {
    if ( $action['priority'] === 99 ) {
        $init_99 = $action['cb'];
    }
}

if ( is_callable( $init_99 ) ) {
    $init_99();
}

assertEq( 0, $GLOBALS['__flush_count'], 'no flush when sentinel matches AND rule is present' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
