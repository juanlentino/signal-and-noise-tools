<?php
/**
 * Tests for inc/ai-official-generators.php: the official excerpt and meta
 * description generators run, with S&N's storage and voice attached.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['f'] = array(); $GLOBALS['opt'] = array();
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['f'][ $h ][] = array( $cb, $a ); return true; }
function apply_filters( $h, $v, ...$rest ) { foreach ( $GLOBALS['f'][ $h ] ?? array() as $c ) { $v = $c[0]( ...array_slice( array_merge( array( $v ), $rest ), 0, max( 1, $c[1] ) ) ); } return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
const SNT_AI_EXCERPT_SYSTEM   = 'Excerpt voice. No em dashes. Output ONLY the excerpt text. No quotes.';
const SNT_AI_META_DESC_SYSTEM = 'Description voice. 140-160 characters. Output ONLY the description text. No markdown.';
require __DIR__ . '/../inc/ai-official-generators.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$p = apply_filters( 'wpai_meta_description_seo_plugins', array( 'yoast-seo' => array( 'file' => 'y', 'meta_key' => '_y' ) ) );
ok( '_sn_meta_description' === $p['signal-noise']['meta_key'] && isset( $p['yoast-seo'] ), 'S&N joins the SEO plugins with its own field; the others stay' );
ok( 'signal-noise' === apply_filters( 'pre_transient_wpai_active_seo_plugin', false ), 'the active SEO plugin reads as S&N, so the AI plugin prints no description tag and writes to S&N\'s field' );

$v = apply_filters( 'wpai_system_instruction', 'OFFICIAL. Return JSON.', 'ai/meta-description', array() );
ok( 0 === strpos( $v, 'OFFICIAL. Return JSON.' ) && false !== strpos( $v, 'Description voice. 140-160 characters.' ), 'meta description: the house rules are appended, the official instruction stays first and whole' );
ok( false === strpos( $v, 'Output ONLY' ), 'S&N\'s own answer-shape sentence is left out: the official ability sets the shape' );
$e = apply_filters( 'wpai_system_instruction', 'OFFICIAL', 'ai/excerpt-generation', array() );
ok( false !== strpos( $e, 'Excerpt voice. No em dashes.' ) && false === strpos( $e, 'Description voice' ), 'excerpt: its own rules, not the description\'s' );
ok( 'OFFICIAL' === apply_filters( 'wpai_system_instruction', 'OFFICIAL', 'ai/summarization', array() ), 'any other ability is untouched' );

ok( false === snt_ai_official_feature_on( 'excerpt-generation' ), 'AI plugin absent: the official feature is not on, so S&N\'s own button stays' );
eval( 'namespace WordPress\AI; function get_min_content_length() { return 0; }' );
ok( false === snt_ai_official_feature_on( 'excerpt-generation' ), 'AI plugin present, box unticked: not on' );
$GLOBALS['opt']['wpai_feature_excerpt-generation_enabled'] = '1';
ok( true === snt_ai_official_feature_on( 'excerpt-generation' ), 'ticked: on, and S&N\'s button stands down' );
add_filter( 'wpai_feature_excerpt-generation_enabled', static fn() => false );
ok( false === snt_ai_official_feature_on( 'excerpt-generation' ), 'ticked but filtered off: not on (the same test the AI plugin applies)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
