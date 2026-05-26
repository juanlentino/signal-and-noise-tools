<?php
/**
 * Tests login module self-heal (v4.2.0).
 *
 * The original sentinel-only flush check at init priority 99 can
 * false-positive — sentinel says "already flushed for backend" but the
 * persisted rewrite_rules option doesn't actually contain ^backend/?$.
 * v4.2.0's verify-before-trust check catches this and re-flushes.
 */

define( 'ABSPATH', '/' );

// In-memory option store.
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

// Track flush calls.
$GLOBALS['__flush_count'] = 0;
function flush_rewrite_rules( $hard = true ) {
    $GLOBALS['__flush_count']++;
    // Simulate WP's behavior: rebuild rules option from registered
    // in-memory rules. For the test, we pre-set the rules option
    // before calling the handler.
}

// Mock WP filter/action registry — capture callbacks for direct invocation.
$GLOBALS['__actions'] = array();
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['__actions'][ $hook ][] = array( 'cb' => $callback, 'priority' => $priority );
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_rewrite_rule( $regex, $query, $position = 'top' ) {
    // No-op for the priority-99 test — we control rewrite_rules directly.
}

// Mock sn_setting() to return the slug from option.
function sn_setting( $path, $default = null ) {
    $opts = get_option( 'sn_settings', array() );
    if ( $path === 'login.slug' ) {
        return $opts['login']['slug'] ?? $default;
    }
    return $default;
}

// Set initial state — slug = 'backend'
$GLOBALS['__options']['sn_settings'] = array( 'login' => array( 'slug' => 'backend' ) );

// Pre-flight guard: stub is_plugin_active and ABSPATH bits the file expects.
function is_plugin_active( $slug ) { return false; }
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', '/tmp/wp-plugins' ); }

// Load the module under test.
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

function findInitPriorityNinetyNine() {
    foreach ( $GLOBALS['__actions']['init'] ?? array() as $action ) {
        if ( $action['priority'] === 99 ) {
            return $action['cb'];
        }
    }
    return null;
}

// === Test: desync scenario triggers self-heal flush ===

// Sentinel SAYS "already flushed for backend"
$GLOBALS['__options']['sn_login_rewrites_flushed'] = 'backend';
// But rewrite_rules option DOES NOT contain our pattern
$GLOBALS['__options']['rewrite_rules'] = array(
    'unrelated-rule' => 'index.php',
);
$GLOBALS['__flush_count'] = 0;

$init_99 = findInitPriorityNinetyNine();
assertEq( true, is_callable( $init_99 ), 'init priority 99 handler registered' );

if ( is_callable( $init_99 ) ) {
    $init_99();
}

assertEq( 1, $GLOBALS['__flush_count'], 'self-heal triggered flush despite matching sentinel' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
