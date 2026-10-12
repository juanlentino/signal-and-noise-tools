<?php
/**
 * No collapsible sections on S&N Home (owner rule, 2026-10-11).
 *
 * A source-level guard over every painter of the dashboard app
 * (apps/sn-dashboard/**.php) and the North star form it paints
 * (inc/north-star-settings.php): no `os-disclosure`, no `<details`, no
 * `sn-disclosure` in CODE. Comments are stripped first (PHP tokenizer), so a
 * docblock may still name the fold it replaced. Each leaf suite pins its own
 * paint fold-free (snt_leaf_folds()); this pins the leaves no suite paints in
 * every state, and every leaf added later.
 *
 * Run: php tests/sn-dashboard-no-folds.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root  = dirname( __DIR__ );
$files = array( $root . '/inc/north-star-settings.php' );
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/apps/sn-dashboard', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );
ok( count( $files ) > 60, 'the guard reads the whole app: ' . count( $files ) . ' files' );

$offenders = array();
foreach ( $files as $path ) {
	$code = '';
	foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	foreach ( array( 'os-disclosure', '<details', 'sn-disclosure' ) as $marker ) {
		$n = substr_count( strtolower( $code ), $marker );
		if ( $n > 0 ) {
			$offenders[] = str_replace( $root . '/', '', $path ) . " ($marker x$n)";
		}
	}
}
ok( array() === $offenders, 'no fold is painted anywhere on S&N Home: ' . ( $offenders ? implode( ', ', $offenders ) : 'none' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
