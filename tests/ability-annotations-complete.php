<?php
/**
 * Every ability registration states all three annotations, and its MCP meta.
 *
 * The rw guard reads `meta.annotations.readonly` with
 * `! empty( $decl['readonly'] )` (inc/mcp/mcp-rw-guard.php), so an OMITTED
 * key and a deliberate `false` are the same byte to the guard and different
 * facts to a reader. The Abilities API treats a missing annotation as
 * "behaviour unknown", which is a worse signal than either value: the absence
 * is a bug, not a default. This suite is the guard that says so.
 *
 * It reads the SOURCE rather than a live registry on purpose. Bootstrapping
 * every ability file standalone means loading their dependencies; a file that
 * failed to load would take its registrations out of the walk and the pin
 * would go green over the blocks it never saw. Parsing the files names each
 * one by path.
 *
 * Run: php tests/ability-annotations-complete.php
 * @since plugin 17.6.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

/**
 * Every `'annotations' => array( ... )` in $src, with its balanced inner text.
 *
 * @param string $src
 * @return array<int,array{pos:int,inner:string}>
 */
function snt_ann_blocks( $src ) {
	$out = array();
	$off = 0;
	while ( false !== ( $p = strpos( $src, "'annotations'", $off ) ) ) {
		$ap = strpos( $src, 'array(', $p );
		if ( false === $ap ) { $off = $p + 13; continue; }
		$i = $ap + 6; $depth = 1; $len = strlen( $src );
		while ( $i < $len && $depth > 0 ) {
			if ( '(' === $src[ $i ] ) { ++$depth; } elseif ( ')' === $src[ $i ] ) { --$depth; }
			++$i;
		}
		$out[] = array( 'pos' => $p, 'inner' => substr( $src, $ap + 6, $i - 1 - ( $ap + 6 ) ) );
		$off = $i;
	}
	return $out;
}

$files = array();
foreach ( array( '/inc/*.php', '/inc/*/*.php' ) as $g ) {
	foreach ( glob( $root . $g ) as $f ) {
		if ( false !== strpos( file_get_contents( $f ), 'wp_register_ability(' ) ) { $files[] = $f; }
	}
}
sort( $files );

echo "Ability annotations: all three keys on every registration\n\n";
ok( count( $files ) > 40, 'the walk found the ability files (' . count( $files ) . ')' );

echo "\nGroup A: every registration carries an annotations block\n";
$regs = 0; $blocks = 0;
foreach ( $files as $f ) {
	$src = file_get_contents( $f );
	// Registrations that pass a literal slug; a loop over a table registers
	// many slugs from ONE block, so this is a floor, not an equality.
	$regs   += preg_match_all( "/wp_register_ability\(\s*'[^']+'/", $src );
	$blocks += count( snt_ann_blocks( $src ) );
}
ok( $blocks >= $regs, "annotation blocks ($blocks) cover every literal registration ($regs)" );

echo "\nGroup B: no block omits readonly, destructive or idempotent\n";
$short = array();
foreach ( $files as $f ) {
	$src = file_get_contents( $f );
	foreach ( snt_ann_blocks( $src ) as $b ) {
		$missing = array();
		foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $k ) {
			if ( ! preg_match( "/'" . $k . "'\s*=>\s*(?:true|false)/", $b['inner'] ) ) { $missing[] = $k; }
		}
		if ( $missing ) {
			$short[] = str_replace( $root . '/', '', $f ) . ':' . ( substr_count( substr( $src, 0, $b['pos'] ), "\n" ) + 1 )
				. ' omits ' . implode( ', ', $missing );
		}
	}
}
ok( array() === $short, 'all ' . $blocks . ' annotation blocks state all three keys'
	. ( $short ? " " . count( $short ) . " do not:\n    " . implode( "\n    ", $short ) : '' ) );

echo "\nGroup C: meta.mcp is set, and public matches the read door\n";
// The read-door allowlist is the source of truth for what is public through a
// door; meta.mcp.public restates it per ability so the bundled MCP adapter
// reaches the same set when the hand-maintained list retires.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; } // phpcs:ignore
}
require_once $root . '/inc/mcp/mcp-capabilities.php';
$allow = array_flip( sn_mcp_allowlist() );

/**
 * The `'meta' => array(...)` (or shared meta variable) enclosing an
 * annotations block, read as the 400 bytes before it.
 *
 * @param string $src
 * @param int    $pos
 * @return string
 */
function snt_meta_ctx( $src, $pos ) { return substr( $src, max( 0, $pos - 400 ), min( 400, $pos ) ); }

$no_mcp = array();
$public_blocks = 0;
foreach ( $files as $f ) {
	$src = file_get_contents( $f );
	foreach ( snt_ann_blocks( $src ) as $b ) {
		if ( ! preg_match( "/'mcp'\s*=>\s*array\(\s*'public'\s*=>\s*(true|false),\s*'type'\s*=>\s*'(tool|resource|prompt)'/", snt_meta_ctx( $src, $b['pos'] ), $m ) ) {
			$no_mcp[] = str_replace( $root . '/', '', $f ) . ':' . ( substr_count( substr( $src, 0, $b['pos'] ), "\n" ) + 1 );
			continue;
		}
		if ( 'true' === $m[1] ) { ++$public_blocks; }
	}
}
ok( array() === $no_mcp, 'every block has meta.mcp.public + meta.mcp.type'
	. ( $no_mcp ? " missing on:\n    " . implode( "\n    ", $no_mcp ) : '' ) );
ok( $public_blocks > 0 && $public_blocks < $blocks, "meta.mcp.public is true on $public_blocks of $blocks blocks (not all, not none)" );

echo "\nGroup D: the representative read and the representative write\n";
// sn-status: a read door tool, PURE-READ by construction.
$sn_status = file_get_contents( $root . '/inc/abilities-sn-status.php' );
ok( (bool) preg_match( "/'mcp'\s*=>\s*array\(\s*'public'\s*=>\s*true,\s*'type'\s*=>\s*'tool'\s*\)/", $sn_status ), 'sn-status (read door): meta.mcp = public true, type tool' );
ok( (bool) preg_match( "/'readonly'\s*=>\s*true,\s*'destructive'\s*=>\s*false,\s*'idempotent'\s*=>\s*true/", $sn_status ), 'sn-status: readonly true, destructive false, idempotent true' );
ok( isset( $allow['signal-noise/sn-status'] ), 'sn-status is on the read-door allowlist, which is what public true restates' );

// sn-apply: the rw door's consolidated write. Never public through the read door.
$sn_apply = file_get_contents( $root . '/inc/abilities-sn-apply.php' );
ok( (bool) preg_match( "/'mcp'\s*=>\s*array\(\s*'public'\s*=>\s*false,\s*'type'\s*=>\s*'tool'\s*\)/", $sn_apply ), 'sn-apply (rw door): meta.mcp = public false, type tool' );
ok( (bool) preg_match( "/'readonly'\s*=>\s*false,\s*'destructive'\s*=>\s*true,\s*'idempotent'\s*=>\s*true/", $sn_apply ), 'sn-apply: readonly false, destructive true, idempotent true' );
ok( ! isset( $allow['signal-noise/sn-apply'] ), 'sn-apply is NOT on the read-door allowlist' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
