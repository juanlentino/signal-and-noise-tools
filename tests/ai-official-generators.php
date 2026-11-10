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
function add_action( $h, $cb, $p = 10, $a = 1 ) { return add_filter( $h, $cb, $p, $a ); }
function do_action( $h, ...$args ) { foreach ( $GLOBALS['f'][ $h ] ?? array() as $c ) { $c[0]( ...array_slice( $args, 0, $c[1] ) ); } }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
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
eval( 'namespace WordPress\AI; function get_min_content_length( $id = "", $d = 250 ) { return $d; }' );
ok( false === snt_ai_official_feature_on( 'excerpt-generation' ), 'AI plugin present, box unticked: not on' );
$GLOBALS['opt']['wpai_feature_excerpt-generation_enabled'] = '1';
ok( true === snt_ai_official_feature_on( 'excerpt-generation' ), 'ticked: on, and S&N\'s button stands down' );
add_filter( 'wpai_feature_excerpt-generation_enabled', static fn() => false );
ok( false === snt_ai_official_feature_on( 'excerpt-generation' ), 'ticked but filtered off: not on (the same test the AI plugin applies)' );

// The official button is greyed under its content floor: S&N's stays there.
$GLOBALS['f']['wpai_feature_excerpt-generation_enabled'] = array();
ok( false === snt_ai_official_generator_takes( 'excerpt-generation', '' ) && false === snt_ai_official_generator_takes( 'excerpt-generation', '<p>short</p>' ), 'an empty or short post: the official button cannot write it, so S&N\'s button stays' );
ok( true === snt_ai_official_generator_takes( 'excerpt-generation', '<p>' . str_repeat( 'word ', 80 ) . '</p>' ), 'a post over the floor: the official button takes it' );
add_filter( 'wpai_features_enabled', static fn() => false );
ok( false === snt_ai_official_generator_takes( 'excerpt-generation', str_repeat( 'word ', 80 ) ), 'all AI plugin features switched off by filter: S&N\'s button stays' );

// The focus keyword reaches the official prompt, for the post the ability runs on.
$GLOBALS['meta'] = array( 7 => array( '_sn_focus_keyword' => " music <b>provenance</b>\n records " ), 8 => array() );
ok( 'P' === apply_filters( 'wpai_meta_description_prompt', 'P', 'c', 't' ), 'no ability has run: the prompt is untouched' );
do_action( 'wp_before_execute_ability', 'ai/meta-description', array( 'post_id' => 7 ), null );
ok( "P\n\nFOCUS KEYWORD (must appear verbatim): music provenance records" === apply_filters( 'wpai_meta_description_prompt', 'P', 'c', 't' ), 'the post\'s stored focus keyword is appended to the official prompt, as one clean line' );
do_action( 'wp_before_execute_ability', 'ai/excerpt-generation', array( 'post_id' => 8 ), null );
ok( false !== strpos( apply_filters( 'wpai_meta_description_prompt', 'P', 'c', 't' ), 'music provenance records' ), 'another ability running does not change which post the description is for' );
do_action( 'wp_before_execute_ability', 'ai/meta-description', array( 'post_id' => 8 ), null );
ok( 'P' === apply_filters( 'wpai_meta_description_prompt', 'P', 'c', 't' ), 'a post with no focus keyword: the prompt is untouched (the title\'s topic noun rule applies)' );
do_action( 'wp_before_execute_ability', 'ai/meta-description', array( 'post_id' => 7 ), null );
do_action( 'wp_before_execute_ability', 'ai/meta-description', array( 'content' => 'pasted text' ), null );
ok( 'P' === apply_filters( 'wpai_meta_description_prompt', 'P', 'c', 't' ), 'a run with no post (content only) never inherits the previous post\'s keyword' );
do_action( 'wp_before_execute_ability', 'ai/meta-description', (object) array( 'post_id' => '7' ), null );
ok( false !== strpos( apply_filters( 'wpai_meta_description_prompt', 'P', 'c', 't' ), 'music provenance records' ), 'an object input with a string post id is read the same way' );
ok( 120 === mb_strlen( substr( snt_ai_official_keyword_line( str_repeat( 'k', 400 ) ), strlen( 'FOCUS KEYWORD (must appear verbatim): ' ) ) ), 'an overlong keyword is cut at 120 characters' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
