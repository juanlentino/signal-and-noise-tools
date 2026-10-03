<?php
/**
 * Standalone test: the AI model catalog (20.10.0).
 * Run: php tests/ai-model-catalog.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['set'] = array();
function sn_setting( $k, $d = null ) { return $GLOBALS['set'][ $k ] ?? $d; }
function sn_setting_update( $k, $v ) { $GLOBALS['set'][ $k ] = $v; return true; }
function __( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function is_wp_error( $t ) { return false; }
require __DIR__ . '/../inc/ai-model-catalog.php';
$src = static fn( $f ) => (string) file_get_contents( __DIR__ . '/../' . $f );

echo "The lists\n";
$prose = sn_ai_models( 'prose' ); $vision = sn_ai_models( 'vision' );
ok( array_key_first( $prose ) === sn_ai_default_model() && 'claude-sonnet-5-5' === sn_ai_default_model() && isset( $prose['claude-opus-5-5'], $prose['claude-fable-5-1'], $prose['claude-haiku-4-5'] ), 'prose: the current generation, the default first' );
ok( isset( $prose['claude-sonnet-5'], $prose['claude-opus-4-8'] ), 'prose: the ids the old list offered are still there, so a stored choice still shows as selected' );
ok( array_key_first( $vision ) === sn_ai_default_vision_model() && isset( $vision['gemini-3.5-flash-lite'], $vision['gemini-3.8-flash'], $vision['gemini-2.5-flash-lite'], $vision['gemini-2.5-flash'], $vision['gemini-2.5-pro'] ), 'vision: Gemini 3 first, the 2.5 ids kept and labeled' );

echo "\nOne default, not eight\n";
ok( false !== strpos( $src( 'inc/ai-bootstrap.php' ), "define( 'SN_AI_DEFAULT_MODEL',  '" . sn_ai_default_model() . "' );" ), 'SN_AI_DEFAULT_MODEL equals the catalog default' );
ok( false !== strpos( $src( 'inc/settings.php' ), "'ai_model'               => '" . sn_ai_default_model() . "'," ), 'the stored-settings default equals it too' );
$stray = array();
require_once __DIR__ . '/lib/inc-population.php';
foreach ( array_merge( snt_test_inc_files(), glob( __DIR__ . '/../apps/*/parts/leaves/*.php' ) ) as $f ) {
	if ( 1 === preg_match( "/sn_setting\(\s*'theme\.ai_(alt_)?model',\s*'/", (string) file_get_contents( $f ) ) ) { $stray[] = basename( $f ); }
}
ok( array() === $stray, 'no file reads theme.ai_model or theme.ai_alt_model with a literal fallback' . ( $stray ? ': ' . implode( ', ', $stray ) : '' ) );

echo "\nA typed id\n";
ok( sn_ai_model_id_ok( 'claude-opus-6' ) && sn_ai_model_id_ok( 'gemini-4.0-flash' ) && ! sn_ai_model_id_ok( 'Claude Opus' ) && ! sn_ai_model_id_ok( 'x' ) && ! sn_ai_model_id_ok( '<script>' ) && ! sn_ai_model_id_ok( str_repeat( 'a', 65 ) ) && ! sn_ai_model_id_ok( '-leading' ) && ! sn_ai_model_id_ok( array() ), 'the shape: lowercase, digits, dot, hyphen, 3 to 64' );
ok( true === sn_ai_models_add_extra( 'prose', 'claude-opus-6' ) && isset( sn_ai_models( 'prose' )['claude-opus-6'] ) && ! isset( sn_ai_models( 'vision' )['claude-opus-6'] ), 'a typed prose id is remembered and offered by the prose picker only' );
ok( false === sn_ai_models_add_extra( 'prose', 'Bad Id!' ) && array( 'claude-opus-6' ) === sn_ai_models_extra( 'prose' ), 'a malformed id is refused and stores nothing' );
ok( true === sn_ai_models_add_extra( 'prose', 'claude-sonnet-5-5' ) && array( 'claude-opus-6' ) === sn_ai_models_extra( 'prose' ), 'a built-in id is accepted and not stored twice' );
for ( $i = 0; $i < 25; $i++ ) { sn_ai_models_add_extra( 'vision', "gemini-test-$i" ); }
ok( SN_AI_MODELS_EXTRA_MAX === count( sn_ai_models_extra( 'vision' ) ) && 'gemini-test-24' === sn_ai_models_extra( 'vision' )[0], 'the remembered list is capped, newest kept' );
$GLOBALS['set']['theme.ai_models_extra'] = array( 'ok-id', '<b>', 7 );
ok( array( 'ok-id' ) === sn_ai_models_extra( 'prose' ), 'a hand-edited option cannot put a malformed id in the picker' );

echo "\nThe save\n";
$GLOBALS['set'] = array();
require __DIR__ . '/../inc/admin-post-actions/theme-ai.php';
sn_handle_ai_settings_save( array( 'theme_ai_model' => 'claude-haiku-4-5', 'theme_ai_model_other' => ' Claude-Opus-6 ', 'theme_ai_alt_model' => 'gemini-3.8-flash', 'theme_ai_alt_model_other' => '' ) );
ok( 'claude-opus-6' === $GLOBALS['set']['theme.ai_model'] && 'gemini-3.8-flash' === $GLOBALS['set']['theme.ai_alt_model'], 'a typed id wins over the select (trimmed, lowercased); an empty one leaves the select in charge' );
sn_handle_ai_settings_save( array( 'theme_ai_model' => 'not-on-the-list', 'theme_ai_model_other' => 'bad id', 'theme_ai_alt_model' => 'gemini-3.8-flash' ) );
ok( 'claude-opus-6' === $GLOBALS['set']['theme.ai_model'], 'a malformed typed id and an off-list select keep the current model' );

echo "\nThe forms and the prices\n";
foreach ( array( 'inc/admin-forms/ai-settings.php', 'apps/sn-dashboard/parts/leaves/ai-models-budget.php' ) as $f ) {
	ok( false !== strpos( $src( $f ), 'theme_ai_model_other' ) && false !== strpos( $src( $f ), 'theme_ai_alt_model_other' ), "$f carries both typed-id fields (the classic screen and its native twin)" );
}
function apply_filters( $h, $v ) { return $v; }
require __DIR__ . '/../inc/ai-bootstrap/pricing.php';
$rates = snt_ai_model_pricing();
$unpriced = array_diff( array_merge( array_keys( sn_ai_models_builtin( 'prose' ) ), array_keys( sn_ai_models_builtin( 'vision' ) ) ), array_keys( $rates ) );
ok( array( 'gemini-2.5-pro' ) === array_values( $unpriced ), 'every built-in model has a price except Gemini 2.5 Pro, which never had one (tiered by prompt size)' . ( $unpriced ? ': ' . implode( ', ', $unpriced ) : '' ) );
ok( 2.0 === (float) $rates['claude-sonnet-5']['in'] && 10.0 === (float) $rates['claude-sonnet-5']['out'] && 4.0 === (float) $rates['claude-opus-5-5']['in'], 'Sonnet 5 at its list $2/$10 (was $3/$15); Opus 5.5 at $4/$20' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
