<?php
/**
 * Tests: the plugin's REST route population is named, and its two public
 * routes are the two it means to have (enforcement audit 2026-09-11, Phase 3).
 *
 * Every register_rest_route() call carries a permission_callback (core only
 * WARNS when one is missing, it does not refuse), and exactly three of them are
 * public by design: the W3C webmention receiver (a public inbox is the
 * protocol), the verifiable-credential fetch (a VC exists to be verified by
 * anyone), and the bridge (which authenticates in its handler). A fourth
 * `__return_true` has to be argued onto the list below — it cannot arrive by
 * accident.
 *
 * The audit's first count said 22; the grep that produced it counted a
 * function DEFINITION and a function_exists() guard as registrations. The
 * parser here reads the call itself: 19.
 *
 * Source-derived, so a new route is counted the moment it is written. The
 * bridge route is registered conditionally and is included: its
 * `__return_true` is deliberate (authentication happens in the handler, in
 * one ordered place — see inc/mcp/mcp-bridge-route.php) and it is pinned by
 * name so that reasoning is on the record, not inferred from a count.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

$root  = dirname( __DIR__ );
$calls = array(); // "file:line" => array( ns, route, permission )
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
	$src = (string) file_get_contents( $file->getPathname() );
	if ( false === strpos( $src, 'register_rest_route' ) ) { continue; }
	// Each call: from its opening to the next call (or EOF). The permission
	// callback is whatever `permission_callback` names inside that span.
	$chunks = preg_split( '/(?=register_rest_route\s*\()/', $src );
	$offset = 0;
	foreach ( $chunks as $chunk ) {
		$at = $offset; $offset += strlen( $chunk );
		if ( ! preg_match( '/^register_rest_route\s*\(\s*(?:[\'"]([^\'"]+)[\'"]|(\$\w+|SN_[A-Z_]+|\w+\(\)))\s*,\s*(?:[\'"]([^\'"]+)[\'"]|(\$\w+|SN_[A-Z_]+))/', $chunk, $m ) ) { continue; }
		$line = substr_count( $src, "\n", 0, $at ) + 1;
		$perm = preg_match( '/[\'"]permission_callback[\'"]\s*=>\s*(?:[\'"]([a-z_0-9]+)[\'"]|(function\s*\(|static\s+function|fn\s*\())/i', $chunk, $pm )
			? ( isset( $pm[1] ) && '' !== $pm[1] ? $pm[1] : 'closure' )
			: 'MISSING';
		$rel = str_replace( $root . '/', '', $file->getPathname() );
		$calls[ "$rel:$line" ] = array(
			'ns'    => $m[1] !== '' ? $m[1] : $m[2],
			'route' => ( isset( $m[3] ) && $m[3] !== '' ) ? $m[3] : ( $m[4] ?? '?' ),
			'perm'  => $perm,
		);
	}
}
ksort( $calls );

echo "Group: the population is found, and every route names a permission\n";
ok( count( $calls ) >= 15, 'the scan found the route population (' . count( $calls ) . ' registrations; a scan finding nothing would pass every pin below vacuously)' );
$missing = array_keys( array_filter( $calls, static fn( $c ) => 'MISSING' === $c['perm'] ) );
ok( array() === $missing, 'every register_rest_route() carries a permission_callback (core only warns)' . ( $missing ? ' — MISSING at: ' . implode( ', ', $missing ) : '' ) );

echo "\nGroup: the route count is pinned, so a new route is a deliberate edit here\n";
// One line per route, by file:line. Adding a route means adding a line AND
// deciding its permission; the failure message prints what to paste.
$expected_count = 19;
ok( $expected_count === count( $calls ), "exactly $expected_count REST route registrations (found " . count( $calls ) . ')' . ( $expected_count !== count( $calls ) ? "\n        " . implode( "\n        ", array_map( static fn( $k, $c ) => "$k  {$c['ns']}{$c['route']}  [{$c['perm']}]", array_keys( $calls ), $calls ) ) : '' ) );

echo "\nGroup: exactly these routes are public, each for a stated reason\n";
// Keyed by the ROUTE argument as written in source (a constant name where the
// file uses one), so the pin does not depend on resolving namespaces.
$public_expected = array(
	'SN_CIT_REST_ROUTE'                    => 'W3C webmention receiver (inc/citations-endpoint.php): a public inbox is the protocol; the handler can only ever create an unverified row',
	'/credential/(?P<uid>[A-Za-z0-9-]+)'   => 'verifiable credential (inc/provenance-credential.php): exists to be verified by anyone',
	'/bridge'                              => 'Worker->origin bridge (inc/mcp/mcp-bridge-route.php): bearer-checked in the handler, in one ordered place; not even registered unless armed',
);
$public_found = array();
foreach ( $calls as $where => $c ) {
	if ( '__return_true' === $c['perm'] ) { $public_found[ $c['route'] ] = $where; }
}
$unexpected = array_diff_key( $public_found, $public_expected );
$vanished   = array_diff_key( $public_expected, $public_found );
ok( array() === $unexpected, 'no route is public that this suite does not name' . ( $unexpected ? ' — NEW PUBLIC ROUTE: ' . implode( ', ', array_map( static fn( $k, $w ) => "$k at $w", array_keys( $unexpected ), $unexpected ) ) : '' ) );
ok( array() === $vanished, 'every named public route still exists (a removed one needs its line removed here too)' . ( $vanished ? ' — GONE: ' . implode( ', ', array_keys( $vanished ) ) : '' ) );
ok( 3 === count( $public_found ), 'three public routes, no more' );

echo "\nGroup: everything else is gated on a capability, a token, or a signature\n";
$gated = array_filter( $calls, static fn( $c ) => '__return_true' !== $c['perm'] );
$named = array_unique( array_column( $gated, 'perm' ) );
sort( $named );
ok( count( $gated ) === count( $calls ) - 3, count( $gated ) . ' gated routes; permission callbacks in use: ' . implode( ', ', $named ) );

echo "\nGroup: negative control — the parser can tell a closure from a name from nothing\n";
$probe = "register_rest_route( 'x/v1', '/a', array( 'permission_callback' => function () { return true; } ) );\n"
	. "register_rest_route( 'x/v1', '/b', array( 'permission_callback' => 'named_cb' ) );\n"
	. "register_rest_route( 'x/v1', '/c', array( 'callback' => 'h' ) );";
$seen = array();
foreach ( preg_split( '/(?=register_rest_route\s*\()/', $probe ) as $chunk ) {
	if ( ! preg_match( '/^register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $chunk, $m ) ) { continue; }
	$seen[ $m[2] ] = preg_match( '/[\'"]permission_callback[\'"]\s*=>\s*(?:[\'"]([a-z_0-9]+)[\'"]|(function\s*\(|static\s+function|fn\s*\())/i', $chunk, $pm )
		? ( isset( $pm[1] ) && '' !== $pm[1] ? $pm[1] : 'closure' ) : 'MISSING';
}
ok( array( '/a' => 'closure', '/b' => 'named_cb', '/c' => 'MISSING' ) === $seen, 'control: closure / named / MISSING are three different readings' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
