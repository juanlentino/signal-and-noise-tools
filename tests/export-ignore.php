<?php
/**
 * Export-ignore contract (v9.42.0 S2 §7): the tag archive IS the shipped plugin,
 * so the dev tree must never ride it. Archives HEAD and asserts runtime files
 * present, dev files absent. Skips clean (0/0) outside a git checkout — CI runs
 * this from the repo, live installs never see it (it export-ignores itself).
 * The git invocation is a FIXED command string (no variable interpolation beyond
 * the escapeshellarg'd repo root) run via proc_open — CLI test context only.
 * Run: php tests/export-ignore.php
 * @since plugin v9.42.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$root = dirname( __DIR__ );
$cmd  = 'cd ' . escapeshellarg( $root ) . ' && git archive --format=tar HEAD 2>/dev/null | tar -t 2>/dev/null';
$pipes = array();
$proc  = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
$raw   = is_resource( $proc ) ? (string) stream_get_contents( $pipes[1] ) : '';
if ( is_resource( $proc ) ) {
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
}
$out = array_values( array_filter( explode( "\n", $raw ) ) );
if ( empty( $out ) ) {
	echo "export-ignore suite - no git checkout available, skipping clean\n";
	echo "\nResult: 0 passed, 0 failed.\n";
	exit( 0 );
}

$list = array_flip( $out );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
$has_prefix = function ( $prefix ) use ( $out ) {
	foreach ( $out as $f ) { if ( 0 === strpos( $f, $prefix ) ) { return true; } }
	return false;
};

echo "export-ignore suite - the tag archive ships runtime only\n";
ok( isset( $list['signal-and-noise-tools.php'] ), 'plugin bootstrap present' );
ok( $has_prefix( 'inc/' ), 'inc/ present' );
ok( $has_prefix( 'assets/' ), 'assets/ present' );
ok( ! $has_prefix( 'tests/' ), 'tests/ absent (3.1 MB of dev suites never ship)' );
ok( ! $has_prefix( '.github/' ), '.github/ absent' );
ok( ! $has_prefix( 'docs/' ), 'docs/ absent' );
ok( ! isset( $list['CHANGELOG.md'] ), 'CHANGELOG.md absent (the repo is the release record)' );
ok( ! isset( $list['composer.json'] ) && ! isset( $list['composer.lock'] ), 'composer files absent (no runtime autoload)' );
ok( ! isset( $list['phpstan.neon'] ) && ! isset( $list['phpstan-baseline.neon'] ) && ! isset( $list['phpstan-bootstrap.php'] ), 'phpstan configs absent' );
ok( ! isset( $list['phpcs.xml.dist'] ) && ! isset( $list['.gitattributes'] ), 'phpcs config + .gitattributes absent' );
$dv = 'lib/pdf/vendor/dompdf/dompdf/lib/fonts/';
ok( isset( $list[ $dv . 'DejaVuSans.ttf' ] ) && isset( $list[ $dv . 'DejaVuSans.ufm' ] ), 'regular DejaVu Sans ships: the resume PDF draws its U+25C6 bullet with it' );
ok( ! isset( $list[ $dv . 'DejaVuSans-Bold.ttf' ] ) && ! isset( $list[ $dv . 'DejaVuSans-Oblique.ttf' ] ) && ! isset( $list[ $dv . 'DejaVuSans-BoldOblique.ttf' ] ), 'the unused DejaVu Bold/Oblique/BoldOblique faces do not ship (~2.6 MB)' );
ok( ! isset( $list[ $dv . 'DejaVuSans-Bold.ufm' ] ) && ! isset( $list[ $dv . 'DejaVuSans-BoldOblique.ufm' ] ), 'nor their metrics files' );
ok( isset( $list['lib/pdf/fonts/Lato-Regular.ttf'] ) && isset( $list['lib/pdf/fonts/Lato-BoldItalic.ttf'] ), 'the Lato faces the resume actually uses still ship' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
