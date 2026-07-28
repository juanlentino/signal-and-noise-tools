<?php
/**
 * Tests: docs/MACHINE-READERS.md stays true to the code (Session 3, docs lane).
 *
 * A reference doc that has drifted from the thing it documents is worse than no
 * doc, and this one mirrors two fixed allowlists plus three real endpoints. So
 * the enums are read from their source of truth (inc/machine-readers-api.php)
 * and asserted against the prose, and the endpoints are pinned by name: extend
 * an enum without extending the doc and this suite goes red.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// The source of truth for both enums and the contract minimum. Pure
// definitions, no WordPress calls at load, so a bare require is safe here.
require __DIR__ . '/../inc/machine-readers-api.php';

$path = __DIR__ . '/../docs/MACHINE-READERS.md';
$doc  = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

echo "Group: the doc exists and is a reference\n";
ok( '' !== $doc, 'docs/MACHINE-READERS.md exists and is readable' );
ok( strlen( $doc ) > 3000, 'substantive, not a stub' );
ok( 0 === strpos( $doc, '# ' ), 'opens with an h1, like docs/REST-HARDENING.md' );
ok( false !== strpos( $doc, 'inc/machine-readers-api.php' ), 'points at the implementation' );
ok( false !== strpos( $doc, 'src/machine-readers.mjs' ), 'points at the edge half too' );

echo "\nGroup: real endpoints only (no invented routes)\n";
$endpoints = array(
	'/_sn/rights-signals/version',
	'/_sn/rights-signals/crawler-list-status',
	'/_sn/rights-signals/machine-readers',
);
foreach ( $endpoints as $endpoint ) {
	ok( false !== strpos( $doc, $endpoint ), "names the real endpoint $endpoint" );
}
ok( false === strpos( $doc, '/_sn/machine-readers' ), 'no invented bare sensor path' );
ok( false === strpos( $doc, '/wp-json/sn-mr' ), 'no invented REST namespace' );

echo "\nGroup: the family enum mirrors inc/machine-readers-api.php\n";
$families = snt_mr_valid_families();
ok( 18 === count( $families ), 'the source of truth still declares 18 families' );
$missing = array();
foreach ( $families as $family ) {
	if ( false === strpos( $doc, '`' . $family . '`' ) ) { $missing[] = $family; }
}
ok( empty( $missing ), 'every family is named as code in the doc (missing: ' . ( $missing ? implode( ', ', $missing ) : 'none' ) . ')' );
ok( false !== strpos( $doc, '18 famil' ), 'the doc states the family count' );

echo "\nGroup: the surface enum mirrors inc/machine-readers-api.php\n";
$surfaces = snt_mr_valid_surfaces();
ok( 10 === count( $surfaces ), 'the source of truth still declares 10 surface classes' );
$missing = array();
foreach ( $surfaces as $surface ) {
	if ( false === strpos( $doc, '`' . $surface . '`' ) ) { $missing[] = $surface; }
}
ok( empty( $missing ), 'every surface class is named as code in the doc (missing: ' . ( $missing ? implode( ', ', $missing ) : 'none' ) . ')' );
ok( false !== strpos( $doc, '10 surface' ), 'the doc states the surface count' );
ok( false !== strpos( $doc, 'extend BOTH' ) || false !== stripos( $doc, 'extend both' ), 'the mirror rule is stated, not implied' );

echo "\nGroup: the privacy posture is on the page, in full\n";
ok( false !== stripos( $doc, 'aggregate-only' ), 'aggregate-only writes' );
ok( false !== stripos( $doc, 'raw User-Agent' ), 'the raw User-Agent is discussed by name' );
ok( false !== stripos( $doc, 'never stored' ) || false !== stripos( $doc, 'never leaves' ), 'and stated as never stored' );
ok( false !== stripos( $doc, 'never summed' ), 'sensor counts are never summed with beacon counts' );
ok( false !== stripos( $doc, 'never recorded' ), 'humans are never recorded by the sensor' );
ok( false !== stripos( $doc, 'cookieless' ), 'the standing cookieless principle is named' );
ok( false !== strpos( $doc, 'other-bot' ), 'the unknown-value fallback is documented' );

echo "\nGroup: deploy + secret requirements\n";
foreach ( array( 'SN_MR_READ_TOKEN', 'SN_MR_SQL_TOKEN', 'CF_ACCOUNT_ID', 'SN_MR_WORKER_URL', 'sn_machine_readers' ) as $name ) {
	ok( false !== strpos( $doc, $name ), "names $name" );
}
ok( false !== strpos( $doc, 'wrangler secret put' ), 'says how the worker secrets are set' );
ok( false !== strpos( $doc, 'SN_MR_SENSOR_MIN' ), 'names the contract-minimum constant' );
ok( false !== strpos( $doc, (string) SN_MR_SENSOR_MIN ), 'and states its current value (' . SN_MR_SENSOR_MIN . ')' );

echo "\nGroup: end-to-end verification, with runnable commands\n";
ok( false !== strpos( $doc, '## How to verify' ), 'has a How to verify section' );
// Shell line continuations are the readable way to write a long curl, so join
// them before matching: the assertion is about the command, not its wrapping.
$joined = (string) preg_replace( '/\\\\\n\s*/', ' ', $doc );
foreach ( $endpoints as $endpoint ) {
	ok( 1 === preg_match( '#curl[^\n]*' . preg_quote( $endpoint, '#' ) . '#', $joined ), "a curl command hits $endpoint" );
}
ok( false !== strpos( $doc, 'Authorization: Bearer' ), 'the authenticated read shows its header' );
ok( false !== strpos( $doc, '401' ), 'the anonymous 401 is named as the proof the gate is on' );

echo "\nGroup: the smoke-test boundary is stated, not silently skipped\n";
ok( false !== strpos( $doc, 'smoke-test.yml' ), 'names the workflow file that owns the hourly probe' );
ok( false !== stripos( $doc, 'theme repo' ), 'and says which repo it lives in' );

echo "\nGroup: house style\n";
ok( false === strpos( $doc, "\xE2\x80\x94" ), 'no em-dashes in the prose' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
