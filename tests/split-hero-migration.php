<?php
/**
 * Standalone fixture tests for the v10.36.0 split-hero one-shot migration
 * (inc/split-hero-migration.php).
 *
 * Covers: hash-guarded hero-band swap (replace on exact match, permanent
 * skip on owner-edited or oddly-shaped bodies, retry while a page is
 * absent), seed block-grammar validity, and flag idempotence.
 *
 * Run: php tests/split-hero-migration.php
 * @since plugin v10.36.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
$GLOBALS['__pages']   = array(); // path => WP_Post-ish object
$GLOBALS['__updates'] = array();
function get_page_by_path( $path ) { return $GLOBALS['__pages'][ $path ] ?? null; }
function wp_update_post( $arr ) { $GLOBALS['__updates'][] = $arr; return $arr['ID'] ?? 0; }
function add_action( $hook, $cb ) {}

require_once __DIR__ . '/../inc/split-hero-migration.php';

// ── Seed grammar: every seed decodes + balances (block-recovery guard) ──
echo "\nTest: seed block grammar\n";
foreach ( sn_split_hero_targets() as $path => $t ) {
	$s = file_get_contents( __DIR__ . '/../inc/seed-content/' . $t['seed'] );
	ok( is_string( $s ) && '' !== $s, "$path seed exists" );
	preg_match_all( '/<!-- wp:[a-z-]+ (\{.*?\}) -->/s', $s, $j );
	$badj = 0;
	foreach ( $j[1] as $b ) { if ( null === json_decode( $b, true ) ) { $badj++; } }
	ok( 0 === $badj, "$path seed JSON blobs decode" );
	ok( substr_count( $s, '<div' ) === substr_count( $s, '</div>' ), "$path seed divs balance" );
	$open  = preg_match_all( '/<!-- wp:group[ \n]/', $s, $x );
	$close = substr_count( $s, '<!-- /wp:group -->' );
	ok( $open === $close && substr_count( $s, '"contentSize":"1320px"' ) === $open, "$path seed groups balance at 1320px" );
	ok( 'about' === $path ? true : false !== strpos( $s, 'sn-cms-hero-split' ), "$path seed carries the split composition (About exempt — no side content)" );
}

// ── swap: exact match replaces the band, tail preserved ──
echo "\nTest: sn_split_hero_swap_page\n";
$targets = sn_split_hero_targets();
$band    = '<!-- wp:group {"x":1} -->' . "\n" . '<div class="wp-block-group">OLD HERO</div>' . "\n" . '<!-- /wp:group -->';
$tail    = "\n\n<!-- wp:paragraph -->\n<p>BODY REST</p>\n<!-- /wp:paragraph -->";
$hash    = md5( $band );

$GLOBALS['__pages']['about'] = (object) array( 'ID' => 391, 'post_content' => $band . $tail );
ok( true === sn_split_hero_swap_page( 'about', $hash, $targets['about']['seed'] ), 'matching band → handled' );
ok( 1 === count( $GLOBALS['__updates'] ), 'matching band → one update written' );
$new = (string) $GLOBALS['__updates'][0]['post_content'];
ok( false === strpos( $new, 'OLD HERO' ), 'old band gone' );
ok( false !== strpos( $new, '"contentSize":"1320px"' ), 'seed band in place' );
ok( false !== strpos( $new, 'BODY REST' ), 'body tail preserved verbatim' );

$GLOBALS['__updates'] = array();
$GLOBALS['__pages']['services'] = (object) array( 'ID' => 395, 'post_content' => $band . ' edited' . $tail );
ok( true === sn_split_hero_swap_page( 'services', md5( $band . ' X' ), $targets['services']['seed'] ), 'hash mismatch → permanent skip (handled)' );
$GLOBALS['__pages']['music'] = (object) array( 'ID' => 405, 'post_content' => '<p>no leading group</p>' );
ok( true === sn_split_hero_swap_page( 'music', $hash, $targets['music']['seed'] ), 'unexpected shape → permanent skip (handled)' );
ok( array() === $GLOBALS['__updates'], 'skips write nothing' );
ok( false === sn_split_hero_swap_page( 'contact', $hash, $targets['contact']['seed'] ), 'absent page → retry (false)' );

// ── one-shot: flag withheld while a page is absent; stamped when complete ──
echo "\nTest: sn_migrate_split_hero\n";
$GLOBALS['__resyncs'] = 0;
function sn_resume_sync_page() { $GLOBALS['__resyncs']++; }
function sn_now_sync_page() { $GLOBALS['__resyncs']++; }
function sn_uses_sync_page() { $GLOBALS['__resyncs']++; }

sn_migrate_split_hero(); // contact still absent
ok( 3 === $GLOBALS['__resyncs'], 'engine pages regenerated (resume/now/uses)' );
ok( ! isset( $GLOBALS['__options'][ SN_SPLIT_HERO_MIGR_OPT ] ), 'flag withheld while a page is absent' );

$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => '<p>no leading group</p>' );
sn_migrate_split_hero();
ok( isset( $GLOBALS['__options'][ SN_SPLIT_HERO_MIGR_OPT ] ), 'flag stamped once all pages handled' );
$n = $GLOBALS['__resyncs'];
sn_migrate_split_hero();
ok( $n === $GLOBALS['__resyncs'], 'flag short-circuits re-runs (idempotent)' );

// ── v2 composition repair: contact letterhead + resume regenerate ──
echo "\nTest: sn_migrate_split_hero_v2\n";
$seedDir = __DIR__ . '/../inc/seed-content/';
$heroV1  = trim( file_get_contents( $seedDir . 'split-hero-contact.html' ) );
$proseV1 = trim( file_get_contents( $seedDir . 'split-hero-contact-prose-v1.html' ) );

$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => $heroV1 . "\n\n" . $proseV1 . "\n" );
$GLOBALS['__updates'] = array();
$n = $GLOBALS['__resyncs'];
sn_migrate_split_hero_v2();
ok( 1 === count( $GLOBALS['__updates'] ), 'v1 markup present → one update written' );
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false !== strpos( $new, 'are-vertically-aligned-top sn-cms-hero-split' ), 'contact hero re-swapped top-aligned' );
ok( false !== strpos( $new, 'sn-cms-prose-split' ) && false === strpos( $new, '"contentSize":"760px"' ), 'prose re-banded to the 1320px letterhead grid' );
ok( false === strpos( $new, 'are-vertically-aligned-bottom' ), 'no bottom alignment remains' );
ok( false !== strpos( $new, 'the next page</a> before reaching out' ), 'prose content preserved verbatim' );
ok( $GLOBALS['__resyncs'] === $n + 1, 'resume regenerated once' );
ok( isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V2_OPT ] ), 'v2 flag stamped' );
$u = count( $GLOBALS['__updates'] );
sn_migrate_split_hero_v2();
ok( $u === count( $GLOBALS['__updates'] ) && $GLOBALS['__resyncs'] === $n + 1, 'v2 flag short-circuits re-runs' );

unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V2_OPT ] );
$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => 'owner rewrote everything' );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v2();
ok( array() === $GLOBALS['__updates'], 'owner-edited body → no write, never clobbered' );
ok( isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V2_OPT ] ), 'skip still stamps the flag (permanent, by design)' );

// ── v3: contact centered spine (from either prior state) ──
echo "\nTest: sn_migrate_split_hero_v3\n";
$heroV2  = trim( file_get_contents( $seedDir . 'split-hero-contact-hero-v2.html' ) );
$proseV2 = trim( file_get_contents( $seedDir . 'split-hero-contact-prose-v2.html' ) );

$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => $heroV2 . "\n\n" . $proseV2 . "\n" );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v3();
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false !== strpos( $new, 'has-text-align-center' ) && false !== strpos( $new, '>CONTACT<' ), 'v2 state → centered spine hero' );
ok( false !== strpos( $new, '[sn_availability]' ), 'availability shortcode survives, centered' );
ok( false !== strpos( $new, '"contentSize":"880px"' ) && false === strpos( $new, '"contentSize":"760px"' ) && false !== strpos( $new, 'the next page</a> before reaching out' ), 'prose band centered at 880px, content verbatim' );
ok( false === strpos( $new, 'sn-cms-hero-split' ) && false === strpos( $new, 'sn-cms-prose-split' ), 'no split columns remain on /contact' );

unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V3_OPT ] );
$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => $heroV1 . "\n\n" . $proseV1 . "\n" );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v3();
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false !== strpos( $new, 'has-text-align-center' ) && false !== strpos( $new, '"contentSize":"880px"' ) && false === strpos( $new, '"contentSize":"760px"' ), 'v10.36.0 state (v2 never ran) → centered hero + 880px prose' );

unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V3_OPT ] );
$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => 'owner rewrote everything' );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v3();
ok( array() === $GLOBALS['__updates'] && isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V3_OPT ] ), 'owner-edited body → no write, flag stamped' );

// ── v4: availability line centers via wp:html flex wrapper ──
echo "\nTest: sn_migrate_split_hero_v4\n";
$heroV3  = trim( file_get_contents( $seedDir . 'split-hero-contact-hero-v3.html' ) );
$proseV3 = trim( file_get_contents( $seedDir . 'split-hero-contact-prose-v3.html' ) );

$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => $heroV3 . "\n\n" . $proseV3 . "\n" );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v4();
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false !== strpos( $new, 'display:flex;justify-content:center' ) && false !== strpos( $new, '[sn_availability]' ), 'v3 state → availability in centered flex wrapper' );
ok( false === strpos( $new, '<p class="has-text-align-center">[sn_availability]</p>' ), 'no p-in-p shortcode wrapper remains' );
ok( false !== strpos( $new, '"contentSize":"880px"' ), '880px prose untouched' );

unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V4_OPT ] );
$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => $heroV1 . "\n\n" . $proseV1 . "\n" );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v4();
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false !== strpos( $new, 'display:flex;justify-content:center' ) && false !== strpos( $new, '"contentSize":"880px"' ), 'v10.36.0 state lifts straight to the current spine' );

unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V4_OPT ] );
$GLOBALS['__pages']['contact'] = (object) array( 'ID' => 408, 'post_content' => 'owner rewrote everything' );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v4();
ok( array() === $GLOBALS['__updates'] && isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V4_OPT ] ), 'owner-edited body → no write, flag stamped' );

// ── v5: one frame everywhere ──
echo "\nTest: sn_migrate_split_hero_v5\n";
$svcTail = trim( file_get_contents( $seedDir . 'split-hero-services-tail-v1.html' ) );
$heroV4  = trim( file_get_contents( $seedDir . 'split-hero-contact-hero-v4.html' ) );

$GLOBALS['__pages'] = array(
	'about'    => (object) array( 'ID' => 391, 'post_content' => 'x {"contentSize":"1400px"} y {"contentSize":"1400px"} z' ),
	'services' => (object) array( 'ID' => 395, 'post_content' => 'head {"contentSize":"1400px"} mid ' . $svcTail ),
	'music'    => (object) array( 'ID' => 405, 'post_content' => 'owner rewrote this page' ),
	'contact'  => (object) array( 'ID' => 408, 'post_content' => $heroV4 . "\n\n" . $proseV3 . "\n" ),
);
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v5();
$byId = array();
foreach ( $GLOBALS['__updates'] as $u ) { $byId[ $u['ID'] ] = $u['post_content']; }
ok( isset( $byId[391] ) && false === strpos( $byId[391], '1400px' ) && 2 === substr_count( $byId[391], '"contentSize":"1320px"' ), 'about: every 1400px band → 1320px, content untouched' );
ok( isset( $byId[395] ) && false !== strpos( $byId[395], 'sn-cms-body-rail' ) && false === strpos( $byId[395], '"contentSize":"760px"' ) && false === strpos( $byId[395], '1400px' ), 'services: frame normalized + tail re-banded into the left rail' );
ok( ! isset( $byId[405] ), 'music owner-edited → untouched' );
ok( isset( $byId[408] ) && false === strpos( $byId[408], 'has-text-align-center' ) && false === strpos( $byId[408], '"contentSize":"880px"' ), 'contact: centered spine gone' );
ok( false !== strpos( (string) $byId[408], '[sn_availability]' ) && false !== strpos( (string) $byId[408], 'sn-cms-body-rail' ), 'contact: left-stack hero + prose in the left rail' );
ok( isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V5_OPT ] ), 'v5 flag stamped when all pages present' );
$u = count( $GLOBALS['__updates'] );
sn_migrate_split_hero_v5();
ok( $u === count( $GLOBALS['__updates'] ), 'v5 flag short-circuits re-runs' );

// ── v6: balance polish ──
echo "\nTest: sn_migrate_split_hero_v6\n";
$credV1  = trim( file_get_contents( $seedDir . 'services-credrow-v1.html' ) );
$proseV5 = trim( file_get_contents( $seedDir . 'split-hero-contact-prose-v5.html' ) );

$GLOBALS['__pages'] = array(
	'services' => (object) array( 'ID' => 395, 'post_content' => "head\n" . $credV1 . "\ntail" ),
	'contact'  => (object) array( 'ID' => 408, 'post_content' => "hero\n" . $proseV5 . "\n" ),
);
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v6();
$byId = array();
foreach ( $GLOBALS['__updates'] as $u ) { $byId[ $u['ID'] ] = $u['post_content']; }
ok( isset( $byId[395] ) && false === strpos( $byId[395], 'center' ) && false !== strpos( $byId[395], 'are-vertically-aligned-top sn-credibility-strip' ), 'services strip: no centering at any level, top-aligned' );
ok( isset( $byId[395] ) && false !== strpos( $byId[395], '20+ Years Experience' ) || false !== stripos( (string) ( $byId[395] ?? '' ), 'years experience' ), 'services strip content preserved' );
ok( isset( $byId[408] ) && false !== strpos( $byId[408], 'sn-cms-directory' ) && false === strpos( $byId[408], 'sn-cms-body-rail' ), 'contact prose → two-column directory' );
ok( isset( $byId[408] ) && false !== strpos( $byId[408], 'the next page</a> before reaching out' ) && 7 === substr_count( $byId[408], '<!-- /wp:paragraph -->' ), 'all seven routes preserved verbatim' );
ok( isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V6_OPT ] ), 'v6 flag stamped' );

// ── v7: strip justifies edge-to-edge ──
echo "\nTest: sn_migrate_split_hero_v7\n";
$credV6 = trim( file_get_contents( $seedDir . 'services-credrow-v6.html' ) );
$GLOBALS['__pages'] = array( 'services' => (object) array( 'ID' => 395, 'post_content' => "head\n" . $credV6 . "\ntail" ) );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v7();
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false !== strpos( $new, '"justifyContent":"space-between"' ) && false === strpos( $new, 'wp:columns' ), 'strip → flex space-between group, columns gone' );
ok( 4 === substr_count( $new, '<!-- /wp:paragraph -->' ) && false !== stripos( $new, 'years experience' ), 'all four items preserved verbatim' );
ok( isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V7_OPT ] ), 'v7 flag stamped' );

// ── v9: strip band removed ──
echo "\nTest: sn_migrate_split_hero_v9\n";
$credBand = trim( file_get_contents( $seedDir . 'services-credband-live.html' ) );
$GLOBALS['__pages']['services'] = (object) array( 'ID' => 395, 'post_content' => "hero\n\n" . $credBand . "\n\nrest" );
$GLOBALS['__updates'] = array();
unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V9_OPT ] );
sn_migrate_split_hero_v9();
$new = (string) ( $GLOBALS['__updates'][0]['post_content'] ?? '' );
ok( false === strpos( $new, 'sn-credibility-strip' ) && "hero\n\nrest" === $new, 'strip band removed cleanly, no residue' );
unset( $GLOBALS['__options'][ SN_SPLIT_HERO_V9_OPT ] );
$GLOBALS['__pages']['services'] = (object) array( 'ID' => 395, 'post_content' => 'owner rewrote' );
$GLOBALS['__updates'] = array();
sn_migrate_split_hero_v9();
ok( array() === $GLOBALS['__updates'] && isset( $GLOBALS['__options'][ SN_SPLIT_HERO_V9_OPT ] ), 'owner-edited → skip, flag stamped' );

// ── version-keyed resume regen ──
echo "\nTest: sn_resume_engine_regen\n";
unset( $GLOBALS['__options']['sn_resume_engine_rev'] );
$n = $GLOBALS['__resyncs'];
sn_resume_engine_regen();
sn_resume_engine_regen();
ok( $GLOBALS['__resyncs'] === $n + 1, 'regen fires once per engine rev' );
ok( SN_RESUME_ENGINE_REV === (int) $GLOBALS['__options']['sn_resume_engine_rev'], 'rev recorded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
