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
foreach ( array( 'ai-alt-apply', 'ai-drift-apply', 'ai-orphan-apply', 'ai-link-apply' ) as $a ) {
	$x = ann( 'signal-noise/' . $a );
	ok( false === ( $x['idempotent'] ?? null ) && true === ( $x['destructive'] ?? null ), "$a: destructive true + idempotent false" );
}

echo "\nGroup: insights schema declares signal_summary (impl returns it)\n";
ok( false === ( ann( 'signal-noise/run-insights-scan' )['idempotent'] ?? null ), 'run-insights-scan: idempotent false (force re-runs a generative scan)' );
ok( array_key_exists( 'signal_summary', out_props( 'signal-noise/run-insights-scan' ) ), 'run-insights-scan output_schema declares signal_summary' );
ok( array_key_exists( 'signal_summary', out_props( 'signal-noise/get-insights' ) ), 'get-insights output_schema declares signal_summary' );

echo "\nGroup: v7.4.0 unlinked-mention abilities\n";
$ls = $GLOBALS['__ab']['signal-noise/ai-link-suggest'] ?? null;
ok( is_array( $ls ), 'ai-link-suggest: registered' );
ok( ( $ls['input_schema']['required'] ?? array() ) === array( 'post_id', 'target_id' ), 'ai-link-suggest: requires post_id + target_id' );
ok( 'snt_ability_perm_edit_post' === ( $ls['permission_callback'] ?? '' ), 'ai-link-suggest: per-post edit permission' );
ok( true === ( $ls['meta']['annotations']['idempotent'] ?? null ), 'ai-link-suggest: idempotent (read-only, cached verdict)' );
ok( isset( $ls['output_schema']['properties']['can_apply'] ), 'ai-link-suggest: output schema declares can_apply (v8.1.1 additive)' );
$la = $GLOBALS['__ab']['signal-noise/ai-link-apply'] ?? null;
ok( is_array( $la ), 'ai-link-apply: registered' );
ok( ( $la['input_schema']['required'] ?? array() ) === array( 'post_id', 'anchor', 'context_snippet', 'fingerprint', 'target_url' ), 'ai-link-apply: full splice contract required' );

echo "\nGroup: v8.1.0 semantic-pair suggest ability\n";
$ps = $GLOBALS['__ab']['signal-noise/ai-pair-suggest'] ?? null;
ok( is_array( $ps ), 'ai-pair-suggest: registered' );
ok( ( $ps['input_schema']['required'] ?? array() ) === array( 'post_id', 'target_id' ), 'ai-pair-suggest: requires post_id + target_id' );
ok( 'snt_ability_perm_edit_post' === ( $ps['permission_callback'] ?? '' ), 'ai-pair-suggest: per-post edit permission' );
ok( true === ( $ps['meta']['annotations']['idempotent'] ?? null ), 'ai-pair-suggest: idempotent (cached verdict, no write)' );
ok( isset( $ps['output_schema']['properties']['can_apply'] ), 'ai-pair-suggest: output schema declares can_apply' );
ok( 'snt_ability_ai_pair_suggest' === ( $ps['execute_callback'] ?? '' ), 'ai-pair-suggest: execute callback wired' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
