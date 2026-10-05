<?php
/**
 * .github/scripts/scan-scope.sh: when the Claude security scan may be skipped.
 * Only docs/Markdown, or a release cut (CHANGELOG plus the header's Version:
 * line and nothing else). Every doubt scans.
 * Run: php tests/ci-scan-scope.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { echo "PASS: $m\n"; $pass++; } else { echo "FAIL: $m\n"; $fail++; } }

function scope( array $rows ) {
	$in  = implode( "\n", array_map( 'json_encode', $rows ) );
	$cmd = 'bash ' . escapeshellarg( __DIR__ . '/../.github/scripts/scan-scope.sh' );
	$p   = proc_open( $cmd, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $io );
	fwrite( $io[0], $in );
	fclose( $io[0] );
	$out = trim( stream_get_contents( $io[1] ) );
	proc_close( $p );
	return $out;
}

if ( '' === trim( (string) shell_exec( 'command -v jq' ) ) ) {
	echo "SKIP: jq not installed\n";
	exit( 0 );
}

$header = array( 'filename' => 'signal-and-noise-tools.php', 'patch' => "@@ -3,7 +3,7 @@\n * Plugin Name: x\n- * Version:     19.1.1\n+ * Version:     19.1.2\n" );
$log    = array( 'filename' => 'CHANGELOG.md', 'patch' => "@@ -1 +1 @@\n+## [19.1.2]" );
$docs   = array( 'filename' => 'docs/changelog/v13.md', 'patch' => '+x' );

ok( 'true' === scope( array( $log, $docs, $header ) ), 'a release cut skips the scan' );
ok( 'true' === scope( array( $log, $docs ) ), 'docs only skips the scan' );
ok( 'false' === scope( array( $log, $header, array( 'filename' => 'inc/x.php', 'patch' => '+y' ) ) ), 'a code file riding a cut is scanned' );
ok( 'false' === scope( array( array( 'filename' => 'signal-and-noise-tools.php', 'patch' => "- * Version:     1.0.0\n+ * Version:     1.0.1\n+require 'x.php';" ) ) ), 'a header edit beside the Version: line is scanned' );
ok( 'true' === scope( array( array( 'filename' => 'signal-and-noise-tools.php', 'patch' => "- * Version:     1.0.0\n+ * Version:     1.0.1\n- * Front-End Change: yes\n+ * Front-End Change: no\n" ), array( 'filename' => 'CHANGELOG.md', 'patch' => '+x' ) ) ), 'Codex on 5b27031: a cut that also writes the Front-End Change header still skips the scan' );
ok( 'true' === scope( array( array( 'filename' => 'signal-and-noise-tools.php', 'patch' => "- * Version:     1.0.0\n+ * Version:     1.0.1\n+ * Front-End Change: yes\n" ) ) ), 'the first cut that adds the header skips it too' );
ok( 'false' === scope( array( array( 'filename' => 'signal-and-noise-tools.php', 'patch' => "+ * Front-End Change: maybe\n" ) ) ), 'any other Front-End Change value is scanned' );
ok( 'false' === scope( array( array( 'filename' => 'signal-and-noise-tools.php' ) ) ), 'a header with no patch (GitHub omits large ones) is scanned' );
ok( 'false' === scope( array( array( 'filename' => 'inc/readme.md.php', 'patch' => '+x' ) ) ), 'a PHP file with .md in its name is scanned' );
ok( 'false' === scope( array() ), 'an empty file list is scanned' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
