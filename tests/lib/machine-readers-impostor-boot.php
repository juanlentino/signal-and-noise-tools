<?php
/**
 * Shared bootstrap for the impostor suites: WordPress stubs, a fake snt_mr_fetch()
 * and the real row normalizer. Required by tests/machine-readers-impostor-reasons.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}
function __( $s, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function add_action( $tag, $cb, $p = 10, $a = 1 ) {}
function wp_register_ability( $slug, $args ) {}

$GLOBALS['__mr'] = array();
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) {
	return $GLOBALS['__mr'][ $view ] ?? array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' );
}
function snt_mr_sensor_info() { return null; }
function snt_mr_crawler_list_status() { return null; }

require __DIR__ . '/../../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../../inc/rights-evidence-compose.php';
require __DIR__ . '/../../inc/machine-readers-render.php';
require __DIR__ . '/../../inc/machine-readers-basis.php';
require __DIR__ . '/../../inc/machine-readers-basis-fold.php';
require __DIR__ . '/../../inc/machine-readers-summary.php';
require __DIR__ . '/../../inc/machine-readers-daily.php';
require __DIR__ . '/../../inc/machine-readers-ledger.php';
require __DIR__ . '/../../inc/machine-readers-readers-model.php';
require __DIR__ . '/../../inc/machine-readers-evidence-model.php';
require __DIR__ . '/../../inc/analytics-lanes-machine.php';

// The real field normalizer, as tests/machine-readers-basis.php uses it.
function snt_mr_normalize_rows( $data ) {
	$out = array();
	foreach ( $data as $r ) {
		$out[] = array_merge(
			array( 'family' => (string) ( $r['family'] ?? 'other-bot' ), 'surface' => (string) ( $r['surface'] ?? 'html' ), 'day' => (string) ( $r['day'] ?? '' ), 'hits' => (int) ( $r['hits'] ?? 0 ) ),
			snt_mr_normalize_taxonomy_fields( $r )
		);
	}
	return $out;
}
