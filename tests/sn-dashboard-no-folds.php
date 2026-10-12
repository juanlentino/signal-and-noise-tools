<?php
/**
 * Folds on S&N Home are earned, one by one (owner, 2026-10-11: no collapsible
 * sections; 2026-10-12: "The collapsible options should be thought. Some
 * places earn it, other don't.").
 *
 * A source-level guard over every painter of the dashboard app
 * (apps/sn-dashboard/**.php) and the North star form it paints
 * (inc/north-star-settings.php): no raw `os-disclosure`, `<details` or
 * `sn-disclosure` in CODE, and every `snt_kit_fold(` call is in $earned below
 * with the reason it earns the fold (inc/openstation-kit-fold.php has the
 * three tests: reference or setup, a summary that answers, open when it needs
 * you). Comments are stripped first (PHP tokenizer), so a docblock may still
 * name the fold it replaced.
 *
 * Run: php tests/sn-dashboard-no-folds.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0; $fail = 0;

// file => the reason the fold is earned. One call per file listed.
$earned = array(
	'apps/sn-dashboard/parts/leaves/ai-mcp-connect-steps.php' => 'Connect a client: setup, done once per client; the summary names the clients; open while the user has no Application Password',
);
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
$folds     = array();
foreach ( $files as $path ) {
	$code = '';
	foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	$n = substr_count( $code, 'snt_kit_fold(' );
	if ( $n > 0 ) {
		$folds[ str_replace( $root . '/', '', $path ) ] = $n;
	}
	foreach ( array( 'os-disclosure', '<details', 'sn-disclosure' ) as $marker ) {
		$n = substr_count( strtolower( $code ), $marker );
		if ( $n > 0 ) {
			$offenders[] = str_replace( $root . '/', '', $path ) . " ($marker x$n)";
		}
	}
}
ok( array() === $offenders, 'no raw fold markup anywhere on S&N Home (a fold goes through snt_kit_fold): ' . ( $offenders ? implode( ', ', $offenders ) : 'none' ) );
$unlisted = array_diff_key( $folds, $earned );
ok( array() === $unlisted, 'every fold on S&N Home is listed with the reason it is earned: ' . ( $unlisted ? implode( ', ', array_keys( $unlisted ) ) : 'none unlisted' ) );
$stale = array_diff_key( $earned, $folds );
ok( array() === $stale && array() === array_filter( $folds, static fn( $n ) => 1 !== $n ), 'each listed fold is painted, once: ' . json_encode( $folds ) );

// The helper's own contract: no summary, no fold; open only when asked.
define( 'ABSPATH', $root . '/' );
require_once $root . '/inc/openstation-kit.php';
require_once $root . '/inc/openstation-kit-display.php';
$closed = snt_kit_fold( 'Setup', 'Two steps, both done', '<p>x</p>' );
ok( 0 === strpos( $closed, '<os-disclosure heading="Setup" hint="Two steps, both done"' ) && 0 === preg_match( '/\sopen[\s=>]/', $closed ), 'a fold paints its summary and starts closed' );
ok( false !== strpos( $closed, '<os-stack gap="12"><p>x</p></os-stack></os-disclosure>' ), 'a fold stacks its content with the gap an open section gives it' );
ok( 1 === preg_match( '/<os-disclosure[^>]*\sopen[\s=>]/', snt_kit_fold( 'Setup', 'One step left', '<p>x</p>', true ) ), 'a fold that needs the reader paints open' );
ok( false === strpos( snt_kit_fold( 'Setup', ' ', '<p>x</p>' ), 'os-disclosure' ), 'a fold with no summary paints its content open, as a section: closing may hide detail, never the only statement of a fact' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
