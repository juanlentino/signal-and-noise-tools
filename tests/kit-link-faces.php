<?php
/**
 * A link reads as a link in its sentence; an address keeps the code face.
 *
 * Owner, 2026-10-12: "Fix the link style too". `.snt-link` was monospace 12px
 * everywhere, so "Developer filter seams" and "Verification docket" painted
 * as code, and door links took the admin accent (pure red here, 4.4:1 on the
 * window, failing AA for text). snt_kit_link() now picks the face from the
 * text, and every link, door or anchor, is the app's link pink.
 *
 * Run: php tests/kit-link-faces.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require_once __DIR__ . '/lib/os-leaf-harness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

foreach ( array( 'Developer filter seams →', 'Open the PDF', 'Edit', 'RSL license', 'The Abilities catalog (this repo’s docs)', 'and/or', 'AI/ML', 'C#' ) as $words ) {
	ok( ! snt_kit_link_is_address( $words ) && false !== strpos( snt_kit_link( $words, 'https://other.example/x' ), 'class="snt-link"' ), "words read as prose: $words" );
}
foreach ( array( '/resume', 'https://juanlentino.com/notes/x', 'ai-provider-for-anthropic#33', '/about/uses', 'example.com', 'news.example.org/story' ) as $address ) {
	ok( snt_kit_link_is_address( $address ) && false !== strpos( snt_kit_link( $address, 'https://other.example/x' ), 'class="snt-link snt-link--url"' ), "an address keeps the code face: $address" );
}

$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' );
preg_match( '/\n\.snt-link \{([^}]*)\}/', $css, $base );
preg_match( '/\n\.snt-link--url \{([^}]*)\}/', $css, $url );
ok( isset( $base[1] ) && false === strpos( $base[1], 'font-family' ) && false === strpos( $base[1], 'font-size' ) && false !== strpos( $base[1], 'text-decoration: underline' ) && false !== strpos( $base[1], 'color: var( --os-ui-accent-strong' ), 'a prose link takes the sentence\'s font and size, underlined, in the link pink (the AA claim: without it a link falls to the browser blue)' );
ok( isset( $url[1] ) && false !== strpos( $url[1], '--os-ui-font-mono' ) && false !== strpos( $url[1], 'font-size: 12px' ) && false === strpos( $url[1], 'text-decoration' ), 'an address link keeps the monospace face at 12px and stays underlined, as a door link is' );
ok( 1 === preg_match( '/\na\.snt-link \{\s*font-weight: 500;/', $css ), 'an anchor link matches a door link\'s weight (500, set in os-button\'s shadow)' );
$row = snt_kit_list( array( array( 'label' => '/resume', 'href' => 'https://other.example/x' ) ) );
ok( false !== strpos( $row, 'class="snt-list__label"' ) && false === strpos( $row, 'snt-link' ), 'a list row whose label is an address still swaps to the list label class (no snt-link class left behind)' );
ok( 1 === preg_match( '/\.snt-app os-button\[variant="link"\] \{\s*--os-ui-button-fg: var\( --os-ui-accent-strong/', $css ), 'door and go links take the link pink, not the admin accent (red here, 4.4:1, fails AA)' );
$dash = (string) file_get_contents( dirname( __DIR__ ) . '/apps/sn-dashboard/sn-dashboard.css' );
foreach ( array( '.snt-home__view-all {', '.snt-home__attention-action {' ) as $sel ) {
	$at = strpos( $dash, $sel );
	$rule = false !== $at ? substr( $dash, $at, (int) strpos( $dash, '}', $at ) - $at ) : '';
	ok( '' !== $rule && false !== strpos( $rule, 'color: var( --os-ui-accent-strong' ), "a text link on Home takes the link pink, not the failing admin accent: $sel" );
}
ok( false === strpos( $dash, '.snt-link {' ), 'no leaf overrides the link face any more (Provenance and MCP Clients each had)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
