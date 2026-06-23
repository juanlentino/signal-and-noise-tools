<?php
/**
 * Contract accuracy of the NON-AI abilities' Abilities-API metadata (v6.39.2
 * audit fixes). The agent run-path derives the required HTTP verb from the
 * readonly/destructive/idempotent annotations (abilities-api
 * validate_request_method: readonly => GET, destructive+idempotent => DELETE,
 * else POST), so a pure read that omits `readonly:true` is forced to POST and
 * 405s on the semantically-correct GET. These assertions pin the corrected
 * contract:
 *   - F1: the five pure-read audit/analytics abilities declare readonly:true.
 *   - F2: block-migrations-suggest (does not write) declares readonly:true.
 *   - F3: pattern-adoption-dismiss gates per-resource edit_post (parity with
 *         its REST twin + the block-migrations-dismiss sibling), not blanket
 *         manage_options.
 * Plus regression guards that the destructive abilities keep their (already
 * correct) annotations.
 *
 * Run: php tests/non-ai-abilities-contract.php
 * @since plugin v6.39.2
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__acts'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $tag ][] = $cb; }
$GLOBALS['__ab'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; }

require_once __DIR__ . '/../inc/abilities-audit.php';
require_once __DIR__ . '/../inc/abilities-analytics.php';
require_once __DIR__ . '/../inc/abilities-block-migrations.php';
require_once __DIR__ . '/../inc/abilities-pattern-adoption.php';
require_once __DIR__ . '/../inc/abilities-content.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function ann( $slug ) { return $GLOBALS['__ab'][ $slug ]['meta']['annotations'] ?? array(); }
function perm( $slug ) { return $GLOBALS['__ab'][ $slug ]['permission_callback'] ?? null; }

echo "Non-AI abilities contract accuracy\n\n";

echo "Group F1: pure-read audit/analytics abilities declare readonly:true (=> GET)\n";
foreach ( array(
	'signal-noise/get-audit-summary',
	'signal-noise/get-audit-counters',
	'signal-noise/get-audit-login-successes',
	'signal-noise/get-analytics-events',
	'signal-noise/get-analytics-summary',
) as $slug ) {
	$a = ann( $slug );
	ok( true === ( $a['readonly'] ?? null ) && true === ( $a['idempotent'] ?? null ), "$slug: readonly true + idempotent true" );
}

echo "\nGroup F2: block-migrations-suggest (does not write) declares readonly:true\n";
$s = ann( 'signal-noise/block-migrations-suggest' );
ok( true === ( $s['readonly'] ?? null ) && true === ( $s['idempotent'] ?? null ), 'block-migrations-suggest: readonly true + idempotent true' );

echo "\nGroup F3: pattern-adoption-dismiss gates per-resource edit_post (parity)\n";
ok( 'snt_ability_perm_edit_post' === perm( 'signal-noise/pattern-adoption-dismiss' ), 'pattern-adoption-dismiss: permission_callback => snt_ability_perm_edit_post' );
ok( 'snt_ability_perm_edit_post' === perm( 'signal-noise/block-migrations-dismiss' ), 'block-migrations-dismiss: permission_callback => snt_ability_perm_edit_post (unchanged sibling)' );

echo "\nGroup: regression — destructive abilities keep correct annotations\n";
foreach ( array(
	'signal-noise/run-audit-prune' => false,
	'signal-noise/merge-tags'      => false,
	'signal-noise/prune-unused-tags' => false,
	'signal-noise/block-migrations-apply' => false,
) as $slug => $idem ) {
	$x = ann( $slug );
	ok( true === ( $x['destructive'] ?? null ) && $idem === ( $x['idempotent'] ?? null ), "$slug: destructive true + idempotent " . var_export( $idem, true ) );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
