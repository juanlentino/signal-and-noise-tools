<?php
/**
 * The /services split (inc/services-hub-migration.php), the slug gate that
 * keeps /services/music/ from counting as /music/, and the moved copy.
 *
 * Run: php tests/services-hub-migration.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

const SN_LANG_PAIR_META = '_sn_en_counterpart';

class SH_Error { public function get_error_code() { return 'insert_failed'; } }
function is_wp_error( $v ) { return $v instanceof SH_Error; }

$GLOBALS['opts']  = array();
$GLOBALS['pages'] = array();
$GLOBALS['cap']   = true;
$GLOBALS['next']  = 100;
$GLOBALS['fail_insert'] = false;
$GLOBALS['saved_form']  = null;
$GLOBALS['q']     = null;

function add_action( $h, $c ) {}
function wp_slash( $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function add_option( $k, $v = '', $d = '', $a = null ) { if ( isset( $GLOBALS['opts'][ $k ] ) ) { return false; } $GLOBALS['opts'][ $k ] = $v; return true; }
function current_user_can( $c ) { return $GLOBALS['cap']; }
function get_current_user_id() { return 1; }
function get_page_by_path( $p ) { return $GLOBALS['pages'][ $p ] ?? null; }
function update_post_meta( $id, $k, $v ) { foreach ( $GLOBALS['pages'] as $p ) { if ( $p->ID === $id ) { $p->meta[ $k ] = $v; } } }
function wp_insert_post( $a, $e = false ) {
	if ( $GLOBALS['fail_insert'] ) { return new SH_Error(); }
	$parent = null;
	foreach ( $GLOBALS['pages'] as $path => $p ) { if ( $p->ID === $a['post_parent'] ) { $parent = $path; } }
	$id = $GLOBALS['next']++;
	$GLOBALS['pages'][ $parent . '/' . $a['post_name'] ] = (object) ( $a + array( 'ID' => $id, 'meta' => $a['meta_input'] ) );
	return $id;
}
function wp_update_post( $a, $e = false ) {
	foreach ( $GLOBALS['pages'] as $p ) { if ( $p->ID === $a['ID'] ) { foreach ( $a as $k => $v ) { $p->$k = $v; } } }
	return $a['ID'];
}
function get_post( $id ) { return 2717 === $id ? (object) array( 'ID' => 2717 ) : null; }

require_once __DIR__ . '/../inc/services-hub-migration.php';

$live = array(
	'services'  => __DIR__ . '/fixtures/services-live-2026-11-06.html',
	'servicios' => __DIR__ . '/fixtures/servicios-live-2026-11-06.html',
);
function sh_reset( $live ) {
	$GLOBALS['opts']  = array();
	$GLOBALS['pages'] = array();
	foreach ( $live as $path => $file ) {
		$GLOBALS['pages'][ $path ] = (object) array( 'ID' => 'services' === $path ? 395 : 3642, 'post_content' => (string) file_get_contents( $file ), 'post_excerpt' => '', 'meta' => array() );
	}
}

echo "The frozen hashes describe the captured bodies:\n";
foreach ( sn_services_hub_targets() as $path => $t ) {
	ok( md5( (string) file_get_contents( $live[ $path ] ) ) === $t['hash'], "$path: the fixture hashes to the frozen value" );
}

echo "\nA clean run:\n";
sh_reset( $live );
sn_migrate_services_hub();
$p = $GLOBALS['pages'];
ok( isset( $p['services/music'], $p['services/consulting'], $p['servicios/musica'], $p['servicios/consultoria'] ), 'all four children exist' );
ok( 'music' === $p['services/music']->post_name && 395 === $p['services/music']->post_parent, '/services/music/ is a child of /services/' );
ok( 'publish' === $p['services/music']->post_status, 'the children are published' );
foreach ( sn_services_hub_children() as $path => $spec ) {
	ok( 'page-services' === $p[ $path ]->meta['_wp_page_template'], "$path uses the Services template (the template meta outranks page-music.html)" );
	$len = mb_strlen( $spec['description'] );
	ok( $len >= 120 && $len <= 160, "$path: description is $len characters" );
}
ok( $p['servicios/musica']->meta[ SN_LANG_PAIR_META ] === $p['services/music']->ID, 'the Spanish music page pairs with the English one' );
ok( $p['servicios/consultoria']->meta[ SN_LANG_PAIR_META ] === $p['services/consulting']->ID, 'the Spanish consulting page pairs with the English one' );
ok( ! isset( $p['services/music']->meta[ SN_LANG_PAIR_META ] ), 'an English page carries no pair row' );
ok( false !== strpos( $p['services']->post_content, 'href="/services/consulting/"' ) && false !== strpos( $p['servicios']->post_content, 'href="/servicios/musica/"' ), 'both hubs open their doors' );
ok( '' !== $p['services']->meta['_sn_meta_description'] && false === strpos( $p['services']->post_excerpt, 'artist development' ), 'the hub description is replaced' );
$r = $GLOBALS['opts'][ SN_SERVICES_HUB_OPT ];
ok( 'replaced' === $r['services'] && 'replaced' === $r['servicios'] && 'created' === $r['services/music'], 'the result is kept in the flag' );
ok( 'forms plugin not active' === $r['form'], 'no forms plugin is reported, not fatal' );
ok( ! isset( $GLOBALS['opts'][ SN_SERVICES_HUB_LOCK ] ), 'the lock is released' );

echo "\nIt runs once:\n";
$before = $GLOBALS['next'];
sn_migrate_services_hub();
ok( $before === $GLOBALS['next'], 'a second run inserts nothing' );

echo "\nNothing is overwritten:\n";
sh_reset( $live );
$GLOBALS['pages']['services']->post_content .= "\nowner edit";
$GLOBALS['pages']['services/music'] = (object) array( 'ID' => 9, 'post_content' => 'mine', 'meta' => array() );
sn_migrate_services_hub();
$r = $GLOBALS['opts'][ SN_SERVICES_HUB_OPT ];
ok( 'edited, left alone' === $r['services'] && false !== strpos( $GLOBALS['pages']['services']->post_content, 'owner edit' ), 'an edited hub is left alone' );
ok( 'kept' === $r['services/music'] && 'mine' === $GLOBALS['pages']['services/music']->post_content, 'an existing child is kept' );
ok( 'replaced' === $r['servicios'], 'the other hub still moves' );

echo "\nA door never opens on nothing:\n";
sh_reset( $live );
$GLOBALS['fail_insert'] = true;
$before = $GLOBALS['pages']['services']->post_content;
sn_migrate_services_hub();
$GLOBALS['fail_insert'] = false;
ok( $before === $GLOBALS['pages']['services']->post_content, 'a failed child leaves the hub as it was' );
ok( false === get_option( SN_SERVICES_HUB_OPT ), 'and the flag is withheld, so the next admin load retries' );
sn_migrate_services_hub();
ok( 'replaced' === ( $GLOBALS['opts'][ SN_SERVICES_HUB_OPT ]['services'] ?? '' ), 'the retry finishes' );

echo "\nWho can run it:\n";
sh_reset( $live );
$GLOBALS['cap'] = false;
sn_migrate_services_hub();
$GLOBALS['cap'] = true;
ok( ! isset( $GLOBALS['pages']['services/music'] ), 'only an administrator runs it' );
sh_reset( $live );
$GLOBALS['opts'][ SN_SERVICES_HUB_LOCK ] = time();
sn_migrate_services_hub();
ok( ! isset( $GLOBALS['pages']['services/music'] ), 'a fresh lock stops a second writer' );

echo "\nThe form topic:\n";
$schema = array( 'fields' => array(
	array( 'id' => 'name', 'type' => 'name' ),
	array( 'id' => 'intent', 'type' => 'select', 'prefill' => '', 'choices' => array(
		array( 'label' => 'Remote music work', 'value' => 'Music', 'selected' => false ),
		array( 'label' => 'Recruiter', 'value' => 'Role', 'selected' => false ),
	) ),
) );
$new = sn_services_hub_topic_schema( $schema );
ok( array( 'Music', 'Consulting', 'Role' ) === array_column( $new['fields'][1]['choices'], 'value' ), 'the topic lands right after the music one' );
ok( 'Business consulting' === $new['fields'][1]['choices'][1]['label'], 'its label is the briefed one' );
ok( 'query:topic' === $new['fields'][1]['prefill'], '?topic= preselects it' );
ok( $new === sn_services_hub_topic_schema( $new ), 'applying it twice changes nothing' );
$other = array( 'fields' => array( array( 'id' => 'name' ) ) );
ok( $other === sn_services_hub_topic_schema( $other ), 'a form without the field is left alone' );

if ( true ) { // Declared here, not hoisted: the clean run above has no forms plugin.
	function alltfo_get_form_schema( $f ) { return $GLOBALS['form']; }
	function alltfo_save_form_schema( $id, $s ) { $GLOBALS['saved_form'] = array( $id, $s ); return $s; }
}
$GLOBALS['form'] = $schema;
ok( 'added' === sn_services_hub_add_topic() && 2717 === $GLOBALS['saved_form'][0], 'the live form is saved through the plugin' );
$GLOBALS['form'] = $new; $GLOBALS['saved_form'] = null;
ok( 'unchanged' === sn_services_hub_add_topic() && null === $GLOBALS['saved_form'], 'and not saved again' );

echo "\nThe moved copy, word for word:\n";
$seed = static fn( $n ) => (string) file_get_contents( __DIR__ . '/../inc/seed-content/' . $n );
$text = static fn( $h ) => array_map( static fn( $m ) => trim( strip_tags( $m ) ), preg_match_all( '#<(?:p|h2)\b[^>]*>.*?</(?:p|h2)>#s', $h, $m ) ? $m[0] : array() );
foreach ( array(
	array( 'services', 'services-music.html', array( 'Hiring rather', 'OPERATIONS &amp; AI STRATEGY', 'I help studios', 'Services · 06', 'Business &amp; Strategy', 'How we work', '№ 05', '№ 06', '04 / 06', '02 / 06' ) ),
	array( 'servicios', 'servicios-musica.html', array( '¿Buscás', 'OPERACIONES Y ESTRATEGIA DE IA', 'Ayudo a estudios', 'Servicios · 06', 'Negocio y estrategia', 'Cómo trabajamos', '№ 05', '№ 06', '04 / 06', '02 / 06' ) ),
) as $set ) {
	$new_text = $text( $seed( $set[1] ) );
	$missing  = array();
	foreach ( $text( (string) file_get_contents( $live[ $set[0] ] ) ) as $line ) {
		$dropped = false;
		foreach ( $set[2] as $needle ) { $dropped = $dropped || 0 === strpos( $line, $needle ); }
		if ( ! $dropped && ! in_array( $line, $new_text, true ) ) { $missing[] = $line; }
	}
	ok( array() === $missing, $set[1] . ': every other line moved unchanged' . ( $missing ? ': ' . $missing[0] : '' ) );
	ok( false !== strpos( $seed( $set[1] ), 'id":2253' ) && false === strpos( $seed( $set[1] ), 'id":1425' ), $set[1] . ': the development card stays, the operations card goes' );
	ok( 5 === preg_match_all( '#class="sn-catalog-number"#', $seed( $set[1] ), $m ), $set[1] . ': five offerings' );
}
ok( false !== strpos( $seed( 'services-music.html' ), 'Mixing, mastering, songwriting and strategy travel over the wire' ), 'the remote line drops AI as briefed' );
ok( false !== strpos( $seed( 'servicios-musica.html' ), 'Mezcla, masterización, composición y estrategia viajan por cable' ), 'en español también' );

echo "\nEvery seed follows the house rules:\n";
foreach ( array( 'services-hub', 'servicios-hub', 'services-music', 'servicios-musica', 'services-consulting', 'servicios-consultoria' ) as $name ) {
	$h = $seed( "$name.html" );
	ok( 1 === substr_count( $h, '<h1' ), "$name: one H1" );
	ok( (bool) preg_match( '#<h1[^>]*>[^<]*\.</h1>#', $h ), "$name: the headline ends with a period" );
	ok( false === strpos( $h, '—' ), "$name: no em dash" );
	ok( ! preg_match_all( '#<h[3-6]#', $h ), "$name: headings stay in order, H1 then H2" );
	ok( substr_count( $h, '<!-- wp:' ) === substr_count( $h, '<!-- /wp:' ) + substr_count( $h, '/-->' ), "$name: every block closes" );
}
foreach ( array( 'services-consulting', 'servicios-consultoria' ) as $name ) {
	ok( false !== strpos( $seed( "$name.html" ), 'href="/contact/?topic=Consulting"' ), "$name: the call to action preselects the topic" );
}
ok( false !== strpos( $seed( 'services-consulting.html' ), 'resume/?lane=operations' ), 'consulting keeps the hire line' );
ok( false !== strpos( $seed( 'servicios-consultoria.html' ), 'juanlentino.com/cv/' ), 'and so does its Spanish page' );

echo "\nThe slug gate (inc/bilingual-head.php):\n";
function is_page( $s ) { return 'music' === $s && isset( $GLOBALS['q'] ) && 'music' === $GLOBALS['q']->post_name; }
function is_singular( $t ) { return true; }
function get_queried_object() { return $GLOBALS['q']; }
function get_post_field( $f, $id ) { return 5 === $id ? 'music' : 'services'; }
function sn_lang_en_counterpart( $id ) { return $GLOBALS['q']->en ?? 0; }
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
require_once __DIR__ . '/../inc/bilingual-head.php';
$GLOBALS['q'] = (object) array( 'ID' => 5, 'post_name' => 'music', 'post_parent' => 0 );
ok( true === sn_lang_is_page( 'music' ), '/music/ is the music page' );
$GLOBALS['q'] = (object) array( 'ID' => 6, 'post_name' => 'musica', 'post_parent' => 0, 'en' => 5 );
ok( true === sn_lang_is_page( 'music' ), '/musica/ is too' );
$GLOBALS['q'] = (object) array( 'ID' => 17, 'post_name' => 'music', 'post_parent' => 395 );
ok( false === sn_lang_is_page( 'music' ), '/services/music/ is not' );
$GLOBALS['q'] = (object) array( 'ID' => 19, 'post_name' => 'musica', 'post_parent' => 3642, 'en' => 5 );
ok( false === sn_lang_is_page( 'music' ), '/servicios/musica/ is not' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
