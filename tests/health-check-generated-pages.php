<?php
/**
 * Tests: generated-page-body drift check (19th health check).
 *
 * The engines are pure builders and the tests pin what they BUILD. Nothing
 * pinned what is actually STORED on the page — which is where all three
 * failures happened, each caught only by an owner screenshot:
 *
 *   v10.33.1 — the /resume body was wrapped in wp:html, so WordPress enqueued
 *              none of the columns/file/separator block styles and the page
 *              lost its layout on the live site.
 *   v10.33.2 — an unchanged save skipped the sync, stranding the fix.
 *   v10.33.3 — a band shipped at the wrong width.
 *
 * Run: php tests/health-check-generated-pages.php
 * @since plugin v10.44.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

require __DIR__ . '/../inc/health-check-generated-pages.php';

/**
 * Bodies in the shape the engines actually emit today.
 *
 * NOTE the deliberate asymmetry, which is the whole point of this check:
 * /now and /uses ARE wp:html by design (sn_now_build_body / sn_uses_dossier_html
 * wrap a raw <div>), while /resume must be REAL BLOCK MARKUP — a wp:html
 * /resume is precisely the v10.33.1 regression.
 */
function gp_good() {
	return array(
		'resume' => '<!-- wp:group {"layout":{"type":"constrained","contentSize":"1320px"}} --><div class="wp-block-group">'
			. '<p class="sn-catalog-eyebrow">Dossier</p>'
			. '<!-- wp:columns {"className":"sn-resume-hero-split"} --><div class="wp-block-columns sn-resume-hero-split"></div><!-- /wp:columns -->'
			. '</div><!-- /wp:group -->',
		'now'    => '<!-- wp:html -->' . "\n" . '<div class="sn-now-page"><header class="sn-now-hero"></header></div>' . "\n" . '<!-- /wp:html -->',
		'uses'   => '<!-- wp:html -->' . "\n" . '<div class="sn-uses-page"><header class="sn-uses-hero"></header></div>' . "\n" . '<!-- /wp:html -->',
	);
}

echo "Group: a healthy stored body set passes every page\n";
$v = snt_generated_pages_evaluate( gp_good() );
ok( is_array( $v ) && 3 === count( $v ), 'three pages come back' );
foreach ( array( 'resume', 'now', 'uses' ) as $page ) {
	ok( true === ( $v[ $page ]['ok'] ?? false ), "page '$page' ok on a healthy body" );
}

echo "\nGroup: the v10.33.1 regression (a wp:html /resume) is caught\n";
$bad = gp_good();
$bad['resume'] = '<!-- wp:html -->' . "\n" . '<div class="sn-resume-page"><div class="sn-resume-hero-split"></div></div>' . "\n" . '<!-- /wp:html -->';
$v = snt_generated_pages_evaluate( $bad );
ok( false === ( $v['resume']['ok'] ?? true ), 'a wp:html /resume body FAILS (no core block CSS would load)' );
ok( true === ( $v['now']['ok'] ?? false ), 'and the wp:html /now body still passes — wp:html is correct THERE' );

echo "\nGroup: a lost hero is caught on every page\n";
foreach ( array( 'resume' => 'sn-resume-hero-split', 'now' => 'sn-now-hero', 'uses' => 'sn-uses-hero' ) as $page => $marker ) {
	$bad = gp_good();
	$bad[ $page ] = str_replace( $marker, 'gone', $bad[ $page ] );
	$v = snt_generated_pages_evaluate( $bad );
	ok( false === ( $v[ $page ]['ok'] ?? true ), "a missing '$marker' fails '$page'" );
}

echo "\nGroup: the v10.33.3 width outlier is caught\n";
$bad = gp_good();
$bad['resume'] = str_replace( '"contentSize":"1320px"', '"contentSize":"1400px"', $bad['resume'] );
$v = snt_generated_pages_evaluate( $bad );
ok( false === ( $v['resume']['ok'] ?? true ), 'a band at the superseded 1400px width fails /resume' );

echo "\nGroup: an empty or missing body is a failure, never a silent pass\n";
$bad = gp_good(); $bad['now'] = '';
$v = snt_generated_pages_evaluate( $bad );
ok( false === ( $v['now']['ok'] ?? true ), 'an empty stored body fails' );

$v = snt_generated_pages_evaluate( array() );
ok( 3 === count( $v ) && false === ( $v['resume']['ok'] ?? true ), 'a page absent from the set fails rather than disappearing' );

echo "\nGroup: verdicts carry a human-readable reason\n";
$bad = gp_good(); $bad['uses'] = str_replace( 'sn-uses-hero', 'gone', $bad['uses'] );
$v = snt_generated_pages_evaluate( $bad );
ok( ! empty( $v['uses']['detail'] ) && is_string( $v['uses']['detail'] ), 'a failing page explains itself' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
