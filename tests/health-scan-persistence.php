<?php
/**
 * Persistence regression for the Health scan store (inc/health-checks.php).
 *
 * v6.47.2: the scan result moved from a transient to a DURABLE option so it
 * survives the object-cache flush a caching plugin (e.g. Breeze/Redis on
 * Cloudways) fires on a plugin update. Before this the scan reset on every
 * plugin update and the owner had to re-run it. These assertions pin: the writer
 * persists to an autoload=no option; the reader reads it back; and crucially the
 * scan SURVIVES a full transient/object-cache flush (the reported bug).
 *
 * @since plugin v6.47.2
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

// ── Separate in-memory stores so flushing one cannot affect the other (this is
// the whole point: an object-cache flush wipes transients but NOT options). ──
$GLOBALS['__opts']          = array();
$GLOBALS['__transients']    = array();
$GLOBALS['__last_autoload'] = 'UNSET';

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__opts'][ $key ]  = $value;
	$GLOBALS['__last_autoload'] = $autoload; // capture the 3rd arg for the autoload=no assertion
	return true;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['__transients'][ $key ] = $value; return true; }
function add_action() {}
// v12.23.0: sn_health_store_scan() fires sn_health_scan_stored so the rolling
// history (inc/health-scan-history.php) can hang off a seam rather than being
// wired into the writer. This suite drives the real store function, so it needs
// the seam stubbed — a no-op here, because what is asserted below is the
// PERSISTENCE contract, not what listens to it.
function do_action( $hook, ...$args ) {}
function home_url( $path = '/' ) { return 'https://example.test' . $path; }

require_once __DIR__ . '/../inc/health-checks.php';

$pass = 0; $fail = 0;
function hp_true( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Health scan persistence — durable option, survives an object-cache flush\n\n";

$fake = array(
	'scanned_at' => 1782000000,
	'elapsed_ms' => 42,
	'checks'     => array( 'missing_alt' => array( 'count' => 3 ) ),
);

// 1. Nothing stored yet → null.
$GLOBALS['__opts'] = array();
hp_true( null === sn_health_last_scan(), 'sn_health_last_scan() returns null when nothing is stored' );

// 2. The writer persists to a DURABLE option (autoload=no), NOT a transient.
hp_true( function_exists( 'sn_health_store_scan' ), 'sn_health_store_scan() exists (extracted, durable writer)' );
if ( function_exists( 'sn_health_store_scan' ) ) {
	$GLOBALS['__opts'] = array();
	$GLOBALS['__transients'] = array();
	$GLOBALS['__last_autoload'] = 'UNSET';
	sn_health_store_scan( $fake );
	hp_true( ( $GLOBALS['__opts'][ SN_HEALTH_CACHE_KEY ] ?? null ) === $fake, 'store writes the result to the SN_HEALTH_CACHE_KEY option' );
	hp_true( false === $GLOBALS['__last_autoload'], 'store writes with autoload=false (kept out of the per-request alloptions load)' );
	hp_true( empty( $GLOBALS['__transients'] ), 'store does NOT write a transient (transients are flush-volatile)' );
}

// 3. The reader reads the option back.
$GLOBALS['__opts'] = array( SN_HEALTH_CACHE_KEY => $fake );
hp_true( sn_health_last_scan() === $fake, 'sn_health_last_scan() reads the result back from the durable option' );

// 4. THE REGRESSION: a plugin update flushes the object cache, clearing ALL
// transients. The durable option (and thus the scan) must remain. Pre-fix the
// reader read get_transient(), so post-flush it returned null — the reset bug.
$GLOBALS['__opts']       = array( SN_HEALTH_CACHE_KEY => $fake ); // durable: survives the flush
$GLOBALS['__transients'] = array();                              // every transient wiped by the flush
hp_true( sn_health_last_scan() === $fake, 'scan SURVIVES a transient/object-cache flush (the update-reset bug)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
