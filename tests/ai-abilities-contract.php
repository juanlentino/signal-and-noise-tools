<?php
/**
 * Contract accuracy of the AI abilities' Abilities-API metadata (v6.40.0 audit
 * fixes). The agent surface must not lie: generative + mutating calls are NOT
 * idempotent, the dead `concise` input is gone, and the insights schema declares
 * the signal_summary key the impl returns.
 *
 * Run: php tests/ai-abilities-contract.php
 * @since plugin v6.40.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__acts'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $tag ][] = $cb; }
$GLOBALS['__ab'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; }

require_once __DIR__ . '/../inc/abilities-ai-post-editor.php';
require_once __DIR__ . '/../inc/abilities-ai-health.php';
require_once __DIR__ . '/../inc/abilities-insights.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function ann( $slug ) { return $GLOBALS['__ab'][ $slug ]['meta']['annotations'] ?? array(); }
function in_props( $slug ) { return $GLOBALS['__ab'][ $slug ]['input_schema']['properties'] ?? array(); }
function out_props( $slug ) { return $GLOBALS['__ab'][ $slug ]['output_schema']['properties'] ?? array(); }

echo "AI abilities contract accuracy\n\n";

echo "Group: generative writers are NOT idempotent\n";
ok( false === ( ann( 'signal-noise/ai-generate-meta-description' )['idempotent'] ?? null ), 'meta-description: idempotent => false (generative + writes meta)' );
ok( false === ( ann( 'signal-noise/ai-generate-og-card-title' )['idempotent'] ?? null ), 'og-card-title: idempotent => false (generative + writes meta + PNG)' );
$ex = ann( 'signal-noise/ai-generate-excerpt' );
ok( false === ( $ex['idempotent'] ?? null ) && true === ( $ex['readonly'] ?? null ), 'excerpt: readonly true + idempotent false (returns-only, generative)' );

echo "\nGroup: dead `concise` contract dropped from og-card-title\n";
ok( ! array_key_exists( 'concise', in_props( 'signal-noise/ai-generate-og-card-title' ) ), 'og-card-title input no longer declares the ignored `concise` field' );
ok( array_key_exists( 'concise', in_props( 'signal-noise/ai-generate-meta-description' ) ), 'meta-description KEEPS concise (its impl honors it)' );

echo "\nGroup: fingerprint-gated applies are NOT idempotent (409 on replay)\n";
foreach ( array( 'ai-alt-apply', 'ai-drift-apply', 'ai-orphan-apply' ) as $a ) {
	$x = ann( 'signal-noise/' . $a );
	ok( false === ( $x['idempotent'] ?? null ) && true === ( $x['destructive'] ?? null ), "$a: destructive true + idempotent false" );
}

echo "\nGroup: insights schema declares signal_summary (impl returns it)\n";
ok( false === ( ann( 'signal-noise/run-insights-scan' )['idempotent'] ?? null ), 'run-insights-scan: idempotent false (force re-runs a generative scan)' );
ok( array_key_exists( 'signal_summary', out_props( 'signal-noise/run-insights-scan' ) ), 'run-insights-scan output_schema declares signal_summary' );
ok( array_key_exists( 'signal_summary', out_props( 'signal-noise/get-insights' ) ), 'get-insights output_schema declares signal_summary' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
