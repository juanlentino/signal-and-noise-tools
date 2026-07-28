<?php
/**
 * Tests: rights-signals drift probe evaluator (Session 3 lane 3).
 * SCAFFOLD-RED: written against the shells on purpose; lane 3 turns it green.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

require __DIR__ . '/../inc/health-check-rights-signals.php';

function good_responses() {
	return array(
		'tdmrep' => array( 'code' => 200, 'body' => '{"tdm-reservation":1,"tdm-policy":"https://juanlentino.com/tdm-policy/"}' ),
		'rsl'    => array( 'code' => 200, 'body' => '<?xml version="1.0"?><rsl xmlns="https://rslstandard.org/rsl"><content url=""><license><permits type="usage">ai-train</permits></license></content></rsl>' ),
		'robots' => array( 'code' => 200, 'body' => "User-agent: *\nDisallow:\n\nContent-Signal: search=yes, ai-train=no, ai-input=yes\nLicense: https://juanlentino.com/license.xml\n" ),
		'html'   => array( 'code' => 200, 'headers' => array( 'tdm-reservation' => '1', 'tdm-policy' => 'https://juanlentino.com/tdm-policy/' ) ),
		'wpjson' => array( 'code' => 200, 'headers' => array( 'tdm-reservation' => '1', 'tdm-policy' => 'https://juanlentino.com/tdm-policy/' ) ),
	);
}

echo "Group: all-good evaluates all-ok\n";
$v = snt_rights_probe_evaluate( good_responses() );
ok( is_array( $v ) && 5 === count( $v ), 'five named checks come back' );
foreach ( array( 'tdmrep', 'rsl', 'signal', 'license', 'headers' ) as $check ) {
	ok( ( $v[ $check ]['ok'] ?? false ) === true, "check '$check' ok on healthy input" );
}

echo "\nGroup: each failure mode flips ONLY its check\n";
$bad = good_responses(); $bad['tdmrep']['body'] = 'not json';
$v = snt_rights_probe_evaluate( $bad );
ok( ( $v['tdmrep']['ok'] ?? true ) === false && ( $v['rsl']['ok'] ?? false ) === true, 'unparseable tdmrep fails tdmrep only' );

$bad = good_responses(); $bad['robots']['body'] = str_replace( ', ai-input=yes', '', $bad['robots']['body'] );
$v = snt_rights_probe_evaluate( $bad );
ok( ( $v['signal']['ok'] ?? true ) === false, 'missing ai-input=yes fails the signal check (the CF Managed-robots regression class)' );

$bad = good_responses(); $bad['robots']['body'] .= "Content-Signal: search=yes\n";
$v = snt_rights_probe_evaluate( $bad );
ok( ( $v['signal']['ok'] ?? true ) === false, 'a SECOND Content-Signal line fails the signal check (single-line contract)' );

$bad = good_responses(); $bad['robots']['body'] = str_replace( "License: https://juanlentino.com/license.xml\n", '', $bad['robots']['body'] );
$v = snt_rights_probe_evaluate( $bad );
ok( ( $v['license']['ok'] ?? true ) === false, 'missing robots License: line fails the license check' );

$bad = good_responses(); unset( $bad['wpjson']['headers']['tdm-reservation'] );
$v = snt_rights_probe_evaluate( $bad );
ok( ( $v['headers']['ok'] ?? true ) === false, 'TDM header missing on /wp-json fails the headers check' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
