<?php
/**
 * Every action in the dispatch map resolves to a function that exists.
 *
 * WHY THIS EXISTS. `inc/admin-post-actions.php` was 1,682 lines and was split
 * into 15 per-domain files in v12.21.2 (docs/REFACTOR-admin-post-actions.md). The
 * dispatch map in `inc/admin-post-handler.php` binds action names to function
 * names and does not care which file a function lives in — which is exactly why
 * the split is safe, and exactly why a function that gets DROPPED during the
 * move is invisible until someone clicks that button in wp-admin.
 *
 * A missing handler does not fatal at load. It fatals at click time, in
 * production, on whichever admin action nobody exercised. This suite turns that
 * into a build failure.
 *
 * Written BEFORE the refactor deliberately: a guard added afterwards proves the
 * end state, not the move.
 *
 * Run: php tests/admin-post-handler-map-coverage.php
 *
 * @since 12.21.1
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$root = realpath( __DIR__ . '/..' );
$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

// Read both files as SOURCE rather than loading them: the handlers pull in a
// large dependency tree, and this suite is about names, not behaviour.
$map_src = (string) file_get_contents( "$root/inc/admin-post-handler.php" );

// The map's entries: 'action' => 'sn_handle_fn'.
preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*'(sn_handle_[a-z0-9_]+)'/", $map_src, $m, PREG_SET_ORDER );
ok( count( $m ) > 50, 'dispatch map parsed (' . count( $m ) . ' entries)' );

// Collect every sn_handle_* declaration across inc/, so the guard keeps working
// once the file is split into inc/admin-post-actions/*.php.
$declared = array();
$dir = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/inc" ) );
foreach ( $dir as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	preg_match_all( '/^function\s+(sn_handle_[a-z0-9_]+)\s*\(/m', (string) file_get_contents( $file->getPathname() ), $fm );
	foreach ( (array) $fm[1] as $fn ) {
		$declared[ $fn ][] = str_replace( "$root/", '', $file->getPathname() );
	}
}
ok( count( $declared ) > 0, 'found sn_handle_* declarations (' . count( $declared ) . ')' );

// 1. Every mapped action resolves.
foreach ( $m as $entry ) {
	list( , $action, $fn ) = $entry;
	ok( isset( $declared[ $fn ] ), "action '$action' resolves to $fn()" );
}

// 2. No handler is declared TWICE. A split done by copy rather than cut leaves a
//    duplicate, and PHP fatals with "Cannot redeclare" at load.
foreach ( $declared as $fn => $files ) {
	ok( 1 === count( $files ), "$fn() declared exactly once (in " . implode( ', ', $files ) . ')' );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
