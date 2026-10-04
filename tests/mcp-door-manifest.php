<?php
/**
 * The three MCP door lists, pinned whole. An ability reaches a door only by
 * being on one of them, so a change to what is exposed has to change
 * tests/fixtures/mcp-door-manifest.json too: a deliberate diff in a file the
 * owner reviews (.github/CODEOWNERS). The lists are filterable; nothing in the
 * plugin may hook those filters, or the pin would be reading half the story.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '0' ); }
function apply_filters( $h, $v ) { return $v; }
function add_action() { return true; }
function add_filter() { return true; }
function get_option( $k, $d = false ) { return $d; }
function __( $t, $d = '' ) { return $t; }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$file = __DIR__ . '/fixtures/mcp-door-manifest.json';
$live = array( 'read' => sn_mcp_allowlist(), 'write' => sn_mcp_rw_allowlist(), 'remote' => sn_mcp_remote_slugs() );
foreach ( $live as &$slugs ) { $slugs = array_values( $slugs ); sort( $slugs ); }
unset( $slugs );
if ( in_array( '--write', $argv, true ) ) {
	file_put_contents( $file, json_encode( $live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}
$pinned = json_decode( (string) file_get_contents( $file ), true );
foreach ( $live as $door => $slugs ) {
	$added   = array_values( array_diff( $slugs, (array) ( $pinned[ $door ] ?? array() ) ) );
	$removed = array_values( array_diff( (array) ( $pinned[ $door ] ?? array() ), $slugs ) );
	ok( array() === $added && array() === $removed, "the $door door exposes exactly its pinned " . count( $slugs ) . ' abilities' . ( $added ? '; ADDED ' . implode( ', ', $added ) : '' ) . ( $removed ? '; REMOVED ' . implode( ', ', $removed ) : '' ) );
	ok( count( $slugs ) === count( array_unique( $slugs ) ) && count( $slugs ) > 0, "the $door list is not empty and names nothing twice" );
}
ok( array() === array_intersect( $live['read'], $live['write'] ), 'no ability is on both the read and the write door' );

// The lists pass through a filter each. Nothing shipped may hook them.
$hooks   = array();
$names_ok = array( 'inc/mcp/mcp-capabilities.php', 'inc/mcp/mcp-read-guard.php', 'inc/mcp/mcp-rw-guard.php', 'inc/mcp/mcp-telemetry-read.php', 'inc/admin-forms/mcp-connect-status.php', 'inc/admin-forms/mcp-connect.php', 'apps/sn-dashboard/parts/leaves/ai-mcp-connect-parts.php' );
$unowned = array();
// The one line the required check reads its paths from.
preg_match( "/^\s*OWNED: '(.+)'\s*$/m", (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/door-owner.yml' ), $m );
$owned = (string) ( $m[1] ?? '' );
ok( '' !== $owned && 1 === preg_match( '#' . $owned . '#', 'inc/mcp/mcp-capabilities.php' ) && 1 === preg_match( '#' . $owned . '#', 'tests/run.sh' ) && 1 === preg_match( '#' . $owned . '#', 'signal-and-noise-tools.php' ) && 0 === preg_match( '#' . $owned . '#', 'docs/VERSIONING.md' ) && 0 === preg_match( '#' . $owned . '#', 'assets/os-app.css' ), 'the door-owner check carries its path list, and the list is a real filter' );
$root  = dirname( __DIR__ );
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$p = $f->getPathname();
	if ( 'php' !== $f->getExtension() || preg_match( '#/(vendor|node_modules|tests|\.git|\.claude)/#', $p ) ) {
		continue;
	}
	$src = (string) file_get_contents( $p );
	$rel = substr( $p, strlen( $root ) + 1 );
	// The filter's NAME as a string, anywhere: a hook can be spelled with
	// whitespace, named arguments or a variable callback, so the call is not
	// what is matched. The files that already name it (they ask whether the
	// list function exists, or apply the filter) are listed; a new one fails.
	if ( ! in_array( $rel, $names_ok, true ) && preg_match( '/[\'"]sn_mcp_(rw_)?allowlist[\'"]/', $src ) ) {
		$hooks[] = $rel;
	}
	if ( preg_match( '/^[^*\/\n]*\bwp_register_ability\s*\(/m', $src ) && ! preg_match( '#' . $owned . '#', $rel ) ) {
		$unowned[] = $rel;
	}
}
ok( array() === $unowned, 'every file that registers an ability is on the door-owner path list' . ( $unowned ? ': ' . implode( ', ', $unowned ) : '' ) );
ok( array() === $hooks, 'no new file names the door-list filters' . ( $hooks ? ': ' . implode( ', ', $hooks ) : '' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
