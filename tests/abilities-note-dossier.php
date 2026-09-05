<?php
/**
 * Standalone test: inc/abilities-note-dossier.php — the registration
 * contract (GET, edit_post, the window enum) and the execute callback's
 * envelope, with the composer stubbed. The file is a DOOR: it must load and
 * register with the builders absent, and answer a clean error when they are.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $t, $d = null ) { return $t; }
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
$GLOBALS['__registered'] = array();
function wp_register_ability( $name, $args ) { $GLOBALS['__registered'][ $name ] = $args; return (object) array( 'name' => $name ); }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

require __DIR__ . '/../inc/abilities-note-dossier.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "abilities -- note-dossier\n\n";

foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() as $cb ) { call_user_func( $cb ); }
$a = $GLOBALS['__registered']['signal-noise/note-dossier'] ?? null;
ok( is_array( $a ), 'registers signal-noise/note-dossier on wp_abilities_api_init' );
ok( 'content' === $a['category'], 'category: content (one of the six registered)' );
ok( 'snt_ability_perm_edit_post' === $a['permission_callback'], 'gated on edit_post for the note itself' );
ok( 'snt_ability_note_dossier' === $a['execute_callback'], 'execute callback is the named function' );
ok( true === $a['meta']['show_in_rest'] && true === $a['meta']['annotations']['readonly'] && true === $a['meta']['annotations']['idempotent'] && true === $a['meta']['annotations']['open_world_hint'], 'REST-reachable, readonly (=> GET), idempotent, and honest that it reads the public ledger over HTTP' );
ok( 'object' === $a['input_schema']['type'] && array( 'post_id' ) === $a['input_schema']['required'] && 'integer' === $a['input_schema']['properties']['post_id']['type'] && 1 === $a['input_schema']['properties']['post_id']['minimum'], 'input: an object requiring post_id (integer >= 1)' );
ok( array( 7, 30, 90 ) === $a['input_schema']['properties']['days']['enum'] && 30 === $a['input_schema']['properties']['days']['default'] && false === $a['input_schema']['additionalProperties'], 'days: 7 | 30 | 90, default 30, nothing else accepted' );
ok( 'object' === $a['output_schema']['type'] && isset( $a['output_schema']['properties']['blocks'], $a['output_schema']['properties']['fetched_at'], $a['output_schema']['properties']['is_public'] ), 'output: the envelope names blocks, is_public and fetched_at' );
ok( ! isset( $a['meta']['public'] ) && ! isset( $a['meta']['mcp'] ), 'no MCP Adapter opt-in keys' );
ok( ! preg_match( "/'(public|mcp)'\\s*=>/", (string) file_get_contents( __DIR__ . '/../inc/abilities-note-dossier.php' ) ), 'the file never spells the adapter opt-in keys: tests/mcp-connect-render.php greps the SOURCE for them, which is why the envelope key is is_public' );

echo "\nexecute: the door with the builders absent\n";
$r = snt_ability_note_dossier( array( 'post_id' => '7', 'days' => '30' ) );
ok( is_wp_error( $r ) && 'snt_note_dossier_unavailable' === $r->get_error_code() && 500 === $r->data['status'], 'builders not loaded: a 500 that says so, never a crash' );

echo "\nexecute: with the composer\n";
// Declared CONDITIONALLY: PHP early-binds unconditional top-level declarations,
// which would make the 500 assertion above impossible (function_exists would
// already be true on line one). Inside a block they bind when execution arrives.
if ( ! function_exists( 'sn_note_dossier_days' ) ) {
	function sn_note_dossier_days( $raw ) { $d = (int) $raw; return in_array( $d, array( 7, 30, 90 ), true ) ? $d : 30; }
	function sn_note_dossier_compose( $id, $days ) { $GLOBALS['__compose_args'] = array( $id, $days ); return 7 === (int) $id ? array( 'ok' => true, 'post_id' => 7, 'days' => $days, 'is_public' => true, 'blocks' => array(), 'fetched_at' => 1 ) : null; }
}
$r = snt_ability_note_dossier( array( 'post_id' => '7', 'days' => '90' ) );
ok( is_array( $r ) && true === $r['ok'] && array( 7, 90 ) === $GLOBALS['__compose_args'], 'GET input arrives as strings; post_id and days are cast before the composer sees them' );
$r = snt_ability_note_dossier( array( 'post_id' => 7, 'days' => 14 ) );
ok( array( 7, 30 ) === $GLOBALS['__compose_args'], 'a window outside the enum falls to 30 in PHP too (the schema is the first gate, not the only one)' );
$r = snt_ability_note_dossier( array( 'post_id' => 999 ) );
ok( is_wp_error( $r ) && 'snt_note_dossier_not_found' === $r->get_error_code() && 404 === $r->data['status'], 'not a note: 404' );
$r = snt_ability_note_dossier( null );
ok( is_wp_error( $r ) && 404 === $r->data['status'], 'null input (an input-less GET) is a 404, not a warning' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
