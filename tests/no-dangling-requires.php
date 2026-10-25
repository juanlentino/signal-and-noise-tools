<?php
/**
 * Every require/include of a plugin file names a file that exists.
 *
 * 2026-10-25 defect hunt: inc/admin-post-actions/analytics.php required
 * __DIR__ . '/analytics-export.php' after the handlers moved one directory
 * down (2026-08-23), so every S&N Analytics export died on a missing file for
 * two months. The handler test only checked that the action was registered.
 * This walks the source for the three path shapes the plugin uses and
 * resolves each against the file that holds it.
 *
 * Run: php tests/no-dangling-requires.php
 */

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$files = array( $root . '/signal-and-noise-tools.php' );
foreach ( array( 'inc', 'apps' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( 'php' === $f->getExtension() ) {
			$files[] = $f->getPathname();
		}
	}
}

$re = '/\b(?:require|include)(?:_once)?\s*\(?\s*(__DIR__|dirname\(\s*__DIR__\s*(?:,\s*(\d+)\s*)?\)|SNT_PATH)\s*\.\s*([\'"])([^\'"$]+)\3/';
$checked = 0;
$missing = array();
foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );
	if ( ! preg_match_all( $re, $src, $m, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $m as $hit ) {
		// dirname( __DIR__, N ) is N levels above the file's own directory.
		$base = '__DIR__' === $hit[1] ? dirname( $file ) : ( 'SNT_PATH' === $hit[1] ? $root . '/' : dirname( $file, 1 + max( 1, (int) $hit[2] ) ) );
		$path = rtrim( $base, '/' ) . '/' . ltrim( $hit[4], '/' );
		++$checked;
		if ( ! file_exists( $path ) ) {
			$missing[] = substr( $file, strlen( $root ) + 1 ) . ' -> ' . $hit[1] . " . '" . $hit[4] . "'";
		}
	}
}

ok( $checked > 100, "the walk found the plugin's requires ($checked)" );
ok( array() === $missing, 'every required plugin file exists' . ( $missing ? ': ' . implode( '; ', $missing ) : '' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
