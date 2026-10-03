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
function wp_json_encode( $v ) { return json_encode( $v ); }
function add_action() {}
define( 'MINUTE_IN_SECONDS', 60 );
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

echo "\nWhat the providers serve\n";
$meta = static fn( $id, $name, $caps = array( 'text_generation' ), $img = true ) => array( 'id' => $id, 'name' => $name, 'supportedCapabilities' => $caps, 'supportedOptions' => array( array( 'name' => 'input_modalities', 'supportedValues' => $img ? array( array( 'text' ), array( 'text', 'image' ) ) : array( array( 'text' ) ) ) ) );
$r = sn_ai_models_row( $meta( 'claude-opus-6', 'Claude Opus 6' ) );
ok( array( 'id' => 'claude-opus-6', 'name' => 'Claude Opus 6', 'text' => true, 'vision' => true ) === $r, 'a metadata row: text from the capability list, vision from an image input modality' );
ok( false === sn_ai_models_row( $meta( 'x-embed', 'E', array( 'embedding_generation' ), false ) )['text'] && false === sn_ai_models_row( $meta( 'text-only', 'T', array( 'text_generation' ), false ) )['vision'], 'an embedding model is not a text model; a text-only model is not a vision model' );
$rows = array_map( 'sn_ai_models_row', array(
	$meta( 'claude-opus-6', 'Claude Opus 6' ), $meta( 'claude-opus-6-20270101', 'Claude Opus 6' ), $meta( 'claude-sonnet-5-5', 'Claude Sonnet 5.5' ),
	$meta( 'claude-old-20240229', 'Old' ), $meta( 'Bad Id', 'B' ), $meta( 'claude-embed-1', 'E', array( 'embedding_generation' ) ),
) );
$pick = sn_ai_models_pick( $rows, 'prose' );
ok( array( 'claude-opus-6', 'claude-sonnet-5-5', 'claude-old-20240229' ) === array_keys( $pick ) && 'Claude Opus 6 (claude-opus-6)' === $pick['claude-opus-6'], 'prose: text models in the provider\'s order; a dated snapshot goes when its alias is listed and stays when it is the only form; a malformed id and an embedding model never show' );
$g = array_map( 'sn_ai_models_row', array( $meta( 'gemini-4.0-flash', 'Gemini 4 Flash' ), $meta( 'gemini-4.0-flash-tts', 'TTS' ), $meta( 'gemini-4.0-flash-image', 'Img' ), $meta( 'gemini-4.0-pro-preview', 'P' ), $meta( 'gemini-4.0-live', 'L' ), $meta( 'gemini-text', 'T', array( 'text_generation' ), false ) ) );
ok( array( 'gemini-4.0-flash' ) === array_keys( sn_ai_models_pick( $g, 'vision' ) ), 'vision: text models that take an image; speech, image-generation, live and preview variants are not chat models' );
ok( SN_AI_MODELS_MAX === count( sn_ai_models_pick( array_map( static fn( $i ) => sn_ai_models_row( $meta( "claude-m-$i", "M$i" ) ), range( 1, 30 ) ), 'prose' ) ), 'capped' );

echo "\nThe pickers\n";
$now = 1791060000;
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
ok( sn_ai_models( 'prose' ) === sn_ai_models_builtin( 'prose' ) && false !== strpos( sn_ai_models_status_line( $now ), 'the built-in ones' ), 'nothing read yet: the built-in list, and the screen says so' );
$GLOBALS['opt'][ SN_AI_MODELS_OPT ] = array( 'fetched' => time(), 'at' => array( 'anthropic' => time(), 'google' => time() ), 'providers' => array( 'anthropic' => $rows, 'google' => $g ), 'errors' => array() );
$GLOBALS['set']['theme.ai_model'] = 'claude-sonnet-5';
$live = sn_ai_models( 'prose' );
ok( 'claude-opus-6' === array_key_first( $live ) && isset( $live['claude-sonnet-5-5'], $live['claude-sonnet-5'] ) && ! isset( $live['claude-opus-4-8'] ), 'read: the provider\'s list, plus the default and the stored choice so a saved setting still shows; a model the provider no longer lists is gone' );
ok( isset( sn_ai_models( 'vision' )['gemini-4.0-flash'], sn_ai_models( 'vision' )['gemini-3.1-flash-lite'] ) && ! isset( sn_ai_models( 'vision' )['claude-opus-6'] ), 'vision reads Google\'s rows, prose reads Anthropic\'s' );
$GLOBALS['opt'][ SN_AI_MODELS_OPT ]['at']['anthropic'] = time() - SN_AI_MODELS_STALE - 10;
ok( sn_ai_models( 'prose' ) === sn_ai_models_builtin( 'prose' ) && isset( sn_ai_models( 'vision' )['gemini-4.0-flash'] ), 'Anthropic\'s read older than a week is not trusted while Google keeps answering: each provider has its own date' );
$GLOBALS['set']['theme.ai_model'] = 'claude-opus-6';
ok( isset( sn_ai_models( 'prose' )['claude-opus-6'] ) && count( sn_ai_models( 'prose' ) ) === count( sn_ai_models_builtin( 'prose' ) ) + 1, 'a discovered model that was chosen stays in the list when the read goes stale, so it is neither rerouted nor overwritten on the next save' );
$GLOBALS['set']['theme.ai_model'] = 'off-list-tampered';
ok( ! isset( sn_ai_models( 'prose' )['off-list-tampered'] ), 'an id no list ever carried (a hand-edited option) is not offered, so the model filter still rejects it' );
$GLOBALS['set']['theme.ai_model'] = 'claude-sonnet-5';

echo "\nThe daily read\n";
ok( 'the WordPress AI Client is not loaded' === sn_ai_models_ask( 'anthropic' ), 'no AI Client: said, not thrown' );
$GLOBALS['opt'][ SN_AI_MODELS_OPT ] = array( 'fetched' => 5, 'providers' => array( 'anthropic' => $rows ), 'errors' => array() );
$st = sn_ai_models_refresh( $now );
ok( $rows === $st['providers']['anthropic'] && array() === $st['at'] && 5 === $st['fetched'] && 'the WordPress AI Client is not loaded' === $st['errors']['google'], 'a provider that cannot be read keeps its last good rows, the time of the last good read, and records why' );
ok( false !== strpos( sn_ai_models_status_line( $now ), 'anthropic: the WordPress AI Client is not loaded' ), 'and the screen names the reason' );
ok( false !== strpos( $src( 'inc/ai-model-discovery.php' ), "add_action( SN_AI_MODELS_HOOK, 'sn_ai_models_refresh', 10, 0 );" ) && false !== strpos( $src( 'inc/cron-lifecycle.php' ), 'SN_AI_MODELS_HOOK,' ) && false !== strpos( $src( 'inc/cron-dashboard.php' ), "'SN_AI_MODELS_HOOK', 'snt_ai_models_refresh'" ), 'the read is a daily cron, cleared on deactivation and listed on the cron dashboard' );
ok( false === strpos( $src( 'inc/admin-forms/ai-settings.php' ), 'sn_ai_models_ask' ) && false === strpos( $src( 'apps/sn-dashboard/parts/leaves/ai-models-budget.php' ), 'sn_ai_models_refresh' ), 'no settings screen reads a provider while it renders' );

echo "\nThe save\n";
$GLOBALS['set'] = array(); $GLOBALS['opt'][ SN_AI_MODELS_OPT ] = array( 'fetched' => time(), 'at' => array( 'anthropic' => time(), 'google' => time() ), 'providers' => array( 'anthropic' => $rows, 'google' => $g ), 'errors' => array() );
require __DIR__ . '/../inc/admin-post-actions/theme-ai.php';
sn_handle_ai_settings_save( array( 'theme_ai_model' => 'claude-opus-6', 'theme_ai_alt_model' => 'gemini-4.0-flash' ) );
ok( 'claude-opus-6' === $GLOBALS['set']['theme.ai_model'] && 'gemini-4.0-flash' === $GLOBALS['set']['theme.ai_alt_model'], 'a model the provider started serving can be chosen with no release' );
sn_handle_ai_settings_save( array( 'theme_ai_model' => 'not-on-the-list', 'theme_ai_alt_model' => 'gemini-4.0-flash' ) );
ok( 'claude-opus-6' === $GLOBALS['set']['theme.ai_model'], 'an id on neither list keeps the current model' );

echo "\nPrices read from the public list\n";
$GLOBALS['http_body'] = null;
function wp_remote_get( $u, $a = array() ) { $GLOBALS['http_url'] = $u; return null === $GLOBALS['http_body'] ? array( 'code' => 500, 'body' => '' ) : array( 'code' => 200, 'body' => $GLOBALS['http_body'] ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
$row = static fn( $prov, $in, $out, $mode = 'chat' ) => array( 'litellm_provider' => $prov, 'mode' => $mode, 'input_cost_per_token' => $in, 'output_cost_per_token' => $out );
$doc = array(
	'claude-opus-6' => $row( 'anthropic', 3e-6, 15e-6 ), 'claude-sonnet-5-5' => $row( 'anthropic', 2e-6, 1e-5 ), 'claude-haiku-4-5' => $row( 'anthropic', 1e-6, 5e-6 ),
	'gemini/gemini-4.0-flash' => $row( 'gemini', 5e-7, 2e-6 ), 'gemini/gemini-3.1-flash-lite' => $row( 'gemini', 2.5e-7, 1.5e-6 ),
	'gemini-4.0-flash' => $row( 'vertex_ai-language-models', 9e-7, 9e-6 ), 'gpt-9' => $row( 'openai', 1e-6, 2e-6 ), 'claude-embed' => $row( 'anthropic', 1e-7, 1e-7, 'embedding' ),
	'claude-free' => $row( 'anthropic', 0, 0 ), 'claude-typo' => $row( 'anthropic', 1.0, 1.0 ), 'Bad Id' => $row( 'anthropic', 1e-6, 1e-6 ), 'claude-text' => $row( 'anthropic', 'x', 1e-6 ),
);
$p = sn_ai_prices_parse( $doc );
ok( array( 'claude-opus-6', 'claude-sonnet-5-5', 'claude-haiku-4-5', 'gemini-4.0-flash', 'gemini-3.1-flash-lite' ) === array_keys( $p ) && array( 'in' => 3.0, 'out' => 15.0 ) === $p['claude-opus-6'] && array( 'in' => 0.5, 'out' => 2.0 ) === $p['gemini-4.0-flash'], 'kept: Anthropic and Gemini chat rows, per million tokens, the gemini/ prefix dropped; another provider, an embedding row, a zero, a price over the ceiling, a malformed id and a non-number never get in' );
$m = sn_ai_prices_merge( array( 'claude-sonnet-5-5' => array( 'in' => 2.0, 'out' => 10.0 ), 'claude-haiku-4-5' => array( 'in' => 1.0, 'out' => 5.0 ) ), array( 'claude-sonnet-5-5' => array( 'in' => 1.5, 'out' => 10.0 ), 'claude-haiku-4-5' => array( 'in' => 0.1, 'out' => 5.0 ), 'claude-opus-6' => array( 'in' => 3.0, 'out' => 15.0 ) ) );
ok( 1.5 === $m['prices']['claude-sonnet-5-5']['in'] && 1.0 === $m['prices']['claude-haiku-4-5']['in'] && array( 'claude-haiku-4-5' ) === $m['held'] && isset( $m['prices']['claude-opus-6'] ), 'an ordinary change is applied, a new model is added, and a price that moved more than four times is held at the old one and named' );
$GLOBALS['opt'] = array(); $GLOBALS['http_body'] = json_encode( $doc );
$st = sn_ai_prices_refresh( $now, array( 'claude-haiku-4-5' => array( 'in' => 1.0, 'out' => 5.0 ) ) );
ok( SN_AI_PRICES_URL === $GLOBALS['http_url'] && $now === $st['fetched'] && 5 === count( $st['prices'] ) && '' === $st['error'] && 3.0 === sn_ai_prices_read( $now )['claude-opus-6']['in'], 'the daily read stores what the file prices' );
$GLOBALS['http_body'] = json_encode( array( 'claude-opus-6' => $row( 'anthropic', 3e-6, 15e-6 ) ) );
$st2 = sn_ai_prices_refresh( $now + 86400 );
ok( $now === $st2['fetched'] && 5 === count( $st2['prices'] ) && '' !== $st2['error'], 'a read with too few usable rows is not a price list: the stored prices and their date stay, the reason is recorded' );
$GLOBALS['http_body'] = null;
ok( '' !== sn_ai_prices_refresh( $now + 86400 )['error'] && 5 === count( sn_ai_prices_read( $now + 86400 ) ), 'a failed fetch changes nothing' );
ok( array() === sn_ai_prices_read( $now + SN_AI_PRICES_STALE + 10 ) && false !== strpos( sn_ai_prices_status_line( $now + SN_AI_PRICES_STALE + 10 ), 'the built-in table' ) && false !== strpos( sn_ai_prices_status_line( $now ), "LiteLLM's public price list (5 models)" ), 'a read older than two weeks is not used, and the screen says which prices are in force' );

echo "\nThe forms and the prices\n";
foreach ( array( 'inc/admin-forms/ai-settings.php', 'apps/sn-dashboard/parts/leaves/ai-models-budget.php' ) as $f ) {
	ok( false !== strpos( $src( $f ), 'sn_ai_models_status_line()' ) && false === strpos( $src( $f ), '_model_other' ), "$f says where the lists came from, and carries no typed-id field" );
}
function apply_filters( $h, $v ) { return $v; }
require __DIR__ . '/../inc/ai-bootstrap/pricing.php';
$GLOBALS['opt'] = array();
$rates = snt_ai_model_pricing();
$unpriced = array_diff( array_merge( array_keys( sn_ai_models_builtin( 'prose' ) ), array_keys( sn_ai_models_builtin( 'vision' ) ) ), array_keys( $rates ) );
ok( array( 'gemini-2.5-pro' ) === array_values( $unpriced ), 'every built-in model has a price except Gemini 2.5 Pro, which never had one (tiered by prompt size)' . ( $unpriced ? ': ' . implode( ', ', $unpriced ) : '' ) );
ok( 2.0 === (float) $rates['claude-sonnet-5']['in'] && 10.0 === (float) $rates['claude-sonnet-5']['out'] && 4.0 === (float) $rates['claude-opus-5-5']['in'], 'Sonnet 5 at its list $2/$10 (was $3/$15); Opus 5.5 at $4/$20' );

$GLOBALS['opt'][ SN_AI_PRICES_OPT ] = array( 'fetched' => time(), 'prices' => array( 'claude-opus-6' => array( 'in' => 3.0, 'out' => 15.0 ), 'gemini-3.8-flash' => array( 'in' => 0.75, 'out' => 3.75 ) ) );
$rates = snt_ai_model_pricing();
ok( 3.0 === (float) $rates['claude-opus-6']['in'] && 0.75 === (float) $rates['gemini-3.8-flash']['in'] && 2.0 === (float) $rates['claude-sonnet-5']['in'], 'a read price wins over the table and prices a model the table lacks; a model the read lacks keeps its table price' );
ok( false !== strpos( $src( 'inc/ai-model-discovery.php' ), "add_action( SN_AI_MODELS_HOOK, 'sn_ai_prices_cron', 11, 0 );" ), 'the price read rides the same daily cron as the model lists' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
