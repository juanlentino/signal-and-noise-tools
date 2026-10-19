<?php
/**
 * /verify's document semantics: the site's language, a skip link like the
 * theme's, and a tab bar that is a tablist, not a navigation landmark. The
 * shell is a standalone document (no theme chrome), so it carries all three
 * itself. Source-level, like tests/provenance-verify-page.php.
 *
 * Run: php tests/provenance-verify-a11y.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$src = (string) file_get_contents( __DIR__ . '/../inc/provenance-verify.php' );
$css = (string) file_get_contents( __DIR__ . '/../assets/css/prov-verify.css' );
$html = substr( $src, (int) strpos( $src, '<!DOCTYPE html>' ) );
echo "/verify: language, skip link, tab semantics\n";

ok( false === strpos( $html, '<html lang="en">' ) && false !== strpos( $html, 'language_attributes()' ), 'the language comes from the site (en-US there), not a hard-coded "en"' );
ok( 1 === preg_match( '/<html <\?php[^>]*?\'lang="en-US"\'/', $html ), 'and falls back to en-US where WordPress is not loaded' );
ok( 1 === preg_match( '/<body>\s*(?:<\?php.*?\?>\s*)?<a class="sn-verify-skip" href="#sn-verify-main">Skip to content<\/a>/s', $html ), 'the first thing in the body is a skip link to the main region' );
ok( false !== strpos( $html, '<main id="sn-verify-main" tabindex="-1">' ), 'the main region takes focus when the link is followed' );
ok( 1 === preg_match( '/\.sn-verify-skip\{[^}]*position:absolute[^}]*top:-100%/', $css ) && 1 === preg_match( '/\.sn-verify-skip:focus\{[^}]*top:\.5rem[^}]*outline:2px solid var\(--blood\)/', $css ), 'styled like the theme\'s: off screen until focused, then a panel at the top with the blood outline' );
ok( false === strpos( $html, '<nav' ), 'no navigation landmark: the tab bar moves between panels of this page, it navigates nowhere' );
ok( 1 === preg_match( '/<div class="sn-verify-nav">\s*<div class="sn-verify-nav-list" data-role="tablist" role="tablist" aria-label="Verification sections">/', $html ), 'the tablist itself carries the name the nav had; the classes, so the look, are unchanged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
