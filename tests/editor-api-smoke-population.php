<?php
/**
 * Tests: tools/editor-api-smoke.php derives its requirements from the WHOLE
 * tree, not the top of inc/ and assets/ (issue #992).
 *
 * WHY A FIXTURE AND NOT A GREP: asserting that the tool's source contains
 * `sn_editor_walk(` would name a FILE for what is a property of its BEHAVIOUR -
 * the trap this repo hit in tests/batch-schedule.php. The tool takes its root
 * as a parameter, so the honest test builds a small tree where the ONLY
 * declaration of a handle and the ONLY use of a symbol live inside packages,
 * and asks whether the tool finds them.
 *
 * The real repo cannot prove this: every handle in inc/ai-bootstrap/ is also
 * contributed by a top-level file, which is exactly why #992 was latent and
 * why a fixture is required to pin it at all.
 *
 * Run: php tests/editor-api-smoke-population.php
 * @since 13.95.3
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Load the tool's functions without running its CLI main.
 *
 * The file ends in argument parsing and exit(), so it cannot be required. Each
 * top-level function is extracted by BALANCED braces instead.
 */
$src = (string) file_get_contents( dirname( __DIR__ ) . '/tools/editor-api-smoke.php' );

/**
 * Extract every TOP-LEVEL function declaration, by balanced braces.
 *
 * A first cut took substr() to the last "\n}\n" in the file. That reaches past
 * the final function into the CLI main - whose if/else also closes at column 0 -
 * so the main ran, hit its own "--wp=DIR is required" guard and exited the test
 * with a message about the TOOL. The failure looked like a tool bug and was an
 * extraction bug. Braces are counted, not guessed.
 */
$toks  = token_get_all( $src );
$funcs = '';
for ( $i = 0, $n = count( $toks ); $i < $n; $i++ ) {
	if ( ! is_array( $toks[ $i ] ) || T_FUNCTION !== $toks[ $i ][0] ) { continue; }
	$depth = 0; $started = false; $buf = '';
	for ( $j = $i; $j < $n; $j++ ) {
		$piece = is_array( $toks[ $j ] ) ? $toks[ $j ][1] : $toks[ $j ];
		$buf  .= $piece;
		if ( '{' === $piece ) { $depth++; $started = true; }
		elseif ( '}' === $piece ) { $depth--; if ( $started && 0 === $depth ) { $i = $j; break; } }
	}
	$funcs .= $buf . "\n";
}
eval( $funcs ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test harness.

ok( function_exists( 'sn_editor_requirements' ), 'the tool\'s requirement deriver loaded' );
ok( function_exists( 'sn_editor_walk' ), 'the tool exposes a tree walker' );

echo "\nGroup 1: the walker reaches any depth\n";
$tmp = sys_get_temp_dir() . '/sn-eas-' . getmypid();
@mkdir( $tmp . '/inc/deep/deeper', 0777, true );
@mkdir( $tmp . '/assets/js/nested', 0777, true );
file_put_contents( $tmp . '/inc/top.php', '<?php // top' );
file_put_contents( $tmp . '/inc/deep/deeper/buried.php', '<?php // buried' );
file_put_contents( $tmp . '/assets/top.js', '// top' );
file_put_contents( $tmp . '/assets/js/nested/buried.js', '// buried' );

$php_files = sn_editor_walk( $tmp, 'inc', 'php' );
$js_files  = sn_editor_walk( $tmp, 'assets', 'js' );
ok( 2 === count( $php_files ), 'inc/ walked to depth 3 (' . count( $php_files ) . ' files)' );
ok( 2 === count( $js_files ), 'assets/ walked to depth 3 (' . count( $js_files ) . ' files)' );
ok( array() === sn_editor_walk( $tmp, 'nope', 'php' ), 'a missing subdirectory is an empty set, not a warning' );

echo "\nGroup 2: a requirement declared ONLY inside a package is discovered\n";
// The handle and the symbol exist nowhere at the top level. Before #992 this
// tree yielded zero of each while the tool reported a clean run.
file_put_contents(
	$tmp . '/inc/deep/deeper/buried.php',
	"<?php\nwp_register_script( 'x', 'x.js', array( 'wp-buried-package', 'wp-i18n' ), '1' );\n"
);
file_put_contents( $tmp . '/assets/js/nested/buried.js', "wp.buriedPkg.BuriedSymbol();\n" );

$req = sn_editor_requirements( $tmp );
$handles = array_keys( (array) ( $req['handles'] ?? array() ) );
$symbols = (array) ( $req['symbols'] ?? array() );

ok( in_array( 'wp-buried-package', $handles, true ),
	'a wp-* handle declared only in inc/deep/deeper/ IS a requirement - got [' . implode( ', ', $handles ) . ']' );
ok( isset( $symbols['buriedPkg']['BuriedSymbol'] ),
	'a wp.<pkg>.<Symbol> used only in assets/js/nested/ IS a requirement' );

// Control: the deriver must not simply accept everything.
ok( ! in_array( 'wp-never-written', $handles, true ),
	'a handle nobody declared is NOT a requirement - the deriver discriminates' );

// Teardown walks rather than globbing. A `glob( $tmp . '/inc/*.php' )` here
// reads identically to the defect tests/inc-population-guard.php exists to
// forbid - and that guard caught this file the first time it ran, correctly by
// its own rule. Teaching it to tell a fixture inc/ from the real one would make
// it guess; not writing the shape is cheaper and leaves the rule absolute.
$rm = static function ( $dir ) use ( &$rm ) {
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) { continue; }
		$path = $dir . '/' . $entry;
		is_dir( $path ) ? $rm( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
};
if ( is_dir( $tmp ) ) { $rm( $tmp ); }

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
