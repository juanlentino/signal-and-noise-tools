<?php
/**
 * Plugin Check's direct-access rule, mirrored: the standalone-aware guard
 * must sit within a file's first fifty lines.
 *
 * WordPress/plugin-check `Direct_File_Access_Check` (1.9.0) accepts a file two
 * ways. Its AST path wants `if ( ! defined( 'ABSPATH' ) ) { exit; }` with a
 * BARE exit as a direct statement of the if, or `defined( 'ABSPATH' ) || exit;`
 * at top level. Its regex fallback is more lenient about the shape -- an
 * `if ( ! defined( 'ABSPATH' ) ) {` is enough -- but it reads ONLY THE FIRST
 * FIFTY LINES of the file. The app files use the standalone-aware form
 * (`defined( 'OPENSTATION_STANDALONE' ) || exit;` inside the if), which the
 * AST path refuses, so they live or die by the regex window. On 2026-09-06 a
 * longer header docblock pushed parts/attention.php's guard to line 63 and
 * the PR's Plugin Check went red on a file that WAS guarded.
 *
 * This suite reads every PHP file under apps/ and inc/ that guards with the
 * standalone form and fails when the guard is past line 50, so the window is
 * found here, before the push. Files whose guard is the AST-simple form pass
 * the AST path at any line and are not held to the window.
 *
 * Run: php tests/direct-access-guard-window.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $msg\n";
	} else {
		$fail++;
		echo "FAIL: $msg\n";
	}
}

/** Plugin Check's window, from Direct_File_Access_Check::has_direct_access_protection_regex(). */
const PLUGIN_CHECK_GUARD_WINDOW = 50;

/**
 * The line (1-based) of the first `if ( ! defined( 'ABSPATH' ) )`, or 0.
 *
 * @param string $contents File contents.
 * @return int
 */
function guard_line( $contents ) {
	$lines = explode( "\n", $contents );
	foreach ( $lines as $i => $line ) {
		if ( preg_match( "/if\\s*\\(\\s*!\\s*defined\\s*\\(\\s*['\"]ABSPATH['\"]\\s*\\)\\s*\\)/", $line ) ) {
			return $i + 1;
		}
	}
	return 0;
}

/**
 * Whether a file uses the standalone-aware guard, the form only the regex path accepts.
 *
 * @param string $contents File contents.
 * @return bool
 */
function uses_standalone_guard( $contents ) {
	return false !== strpos( $contents, "defined( 'OPENSTATION_STANDALONE' ) || exit;" );
}

/**
 * Does Plugin Check's regex window see the guard?
 *
 * @param string $contents File contents.
 * @return bool
 */
function guard_within_window( $contents ) {
	$line = guard_line( $contents );
	return $line > 0 && $line <= PLUGIN_CHECK_GUARD_WINDOW;
}

echo "direct-access-guard-window -- Plugin Check reads fifty lines\n\nGroup 1: the rule itself, on synthetic files\n";
$short = "<?php\n/**\n * Header.\n */\n\nnamespace X;\n\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefined( 'OPENSTATION_STANDALONE' ) || exit;\n}\n";
$long  = "<?php\n/**\n" . str_repeat( " * a line of header prose\n", 55 ) . " */\n\nnamespace X;\n\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefined( 'OPENSTATION_STANDALONE' ) || exit;\n}\n";
ok( 8 === guard_line( $short ) && guard_within_window( $short ), 'a guard on line 8 is inside the window' );
ok( 62 === guard_line( $long ) && ! guard_within_window( $long ), 'a guard on line 62 is outside it -- the shape that went red on the PR' );
ok( 0 === guard_line( "<?php\necho 1;\n" ) && ! guard_within_window( "<?php\necho 1;\n" ), 'no guard at all is outside it' );
ok( uses_standalone_guard( $short ) && ! uses_standalone_guard( "<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n" ), 'the standalone form is told apart from the AST-simple form, which is not held to the window' );

echo "\nGroup 2: every standalone-guarded file under apps/ and inc/\n";
$root  = dirname( __DIR__ );
$files = array();
foreach ( array( 'apps', 'inc' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = (string) $file;
		// Another session's worktree under .claude/ is not this tree.
		if ( 'php' !== pathinfo( $path, PATHINFO_EXTENSION ) || false !== strpos( $path, '/.claude/' ) ) {
			continue;
		}
		$files[] = $path;
	}
}
sort( $files );
$held = 0;
$late = array();
foreach ( $files as $path ) {
	$contents = (string) file_get_contents( $path );
	if ( ! uses_standalone_guard( $contents ) ) {
		continue;
	}
	$held++;
	if ( ! guard_within_window( $contents ) ) {
		$late[] = substr( $path, strlen( $root ) + 1 ) . ':' . guard_line( $contents );
	}
}
ok( $held > 0, sprintf( '%d files use the standalone-aware guard and are held to the window', $held ) );
ok( array() === $late, 'every one of them guards within the first fifty lines' . ( $late ? ' (late: ' . implode( ', ', $late ) . ')' : '' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
