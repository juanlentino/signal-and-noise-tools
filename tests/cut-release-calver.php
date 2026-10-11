<?php
/**
 * tools/cut-release.sh numbers by date (owner 2026-10-11): YYYY.M.N, N the cut's
 * place in its month. Runs the real script, --dry-run, in a scratch repository
 * with the date pinned through SN_CUT_YEAR / SN_CUT_MONTH.
 */

$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

$src = dirname( __DIR__ );

/**
 * Dry-run the script against a plugin at $current, on $year/$month.
 *
 * @return array{0:int,1:string} Exit code and combined output.
 */
function cut( $current, $year, $month, $level = 'release' ) {
	global $src;
	$dir = sys_get_temp_dir() . '/snt-cut-' . bin2hex( random_bytes( 4 ) );
	mkdir( "$dir/tools/lib", 0777, true );
	mkdir( "$dir/docs/changelog", 0777, true );
	copy( "$src/tools/cut-release.sh", "$dir/tools/cut-release.sh" );
	copy( "$src/tools/lib/consolidate-changelog-sections.awk", "$dir/tools/lib/consolidate-changelog-sections.awk" );
	file_put_contents( "$dir/signal-and-noise-tools.php", "<?php\n/**\n * Plugin Name: test\n * Version: $current\n */\n" );
	file_put_contents( "$dir/CHANGELOG.md", "# Changelog\n\n## [Unreleased]\n\n### Fixed\n- a bullet\n" );
	file_put_contents( "$dir/docs/changelog/v13.md", "# Archive\n\n## [1.0.0] - 2026-01-01 - old\n" );
	$q   = static fn( $s ) => escapeshellarg( (string) $s );
	$cmd = 'cd ' . $q( $dir ) . ' && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -qm init'
		. ' && SN_CUT_YEAR=' . $q( $year ) . ' SN_CUT_MONTH=' . $q( $month )
		. ' bash tools/cut-release.sh ' . $q( $level ) . ' headline --dry-run 2>&1';
	exec( $cmd, $out, $code );
	exec( 'rm -rf ' . $q( $dir ) );
	return array( $code, implode( "\n", $out ) );
}

$next = static function ( $current, $year, $month, $level = 'release' ) {
	list( $code, $out ) = cut( $current, $year, $month, $level );
	return 0 === $code && preg_match( '/next\s*:\s*(\S+)/', $out, $m ) ? $m[1] : 'refused: ' . $out;
};

echo "cut-release: versions by date\n";
ok( '2026.10.1' === $next( '24.0.0', 2026, 10 ), 'the first date cut after the WordPress shape: 24.0.0 -> 2026.10.1' );
ok( '2026.10.2' === $next( '2026.10.1', 2026, 10 ), 'the same month counts up: 2026.10.1 -> 2026.10.2' );
ok( '2026.10.10' === $next( '2026.10.9', 2026, 10 ), 'N is a count, not a digit: 2026.10.9 -> 2026.10.10' );
ok( '2026.11.1' === $next( '2026.10.5', 2026, 11 ), 'a new month starts at 1: 2026.10.5 -> 2026.11.1' );
ok( '2027.1.1' === $next( '2026.12.3', 2027, 1 ), 'a new year: 2026.12.3 -> 2027.1.1, no leading zero' );
ok( '2026.11.1' === $next( '2026.10.1', 2026, 11, 'fix' ), '`fix` cuts the same date number as `release` (the old fix would have been 2026.10.2)' );
list( $code, $out ) = cut( '2026.10.1', 2026, 10, 'major' );
ok( 0 !== $code && false !== strpos( $out, 'first number is the year' ), '`major` is refused, and says why' );
list( $code, $out ) = cut( '2027.1.1', 2026, 12 );
ok( 0 !== $code && false !== strpos( $out, 'refusing to go down' ), 'a clock behind the installed version never cuts a lower number' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
