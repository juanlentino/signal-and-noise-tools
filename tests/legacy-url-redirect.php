<?php
/**
 * Smoke test for sn_admin_maybe_redirect_legacy() — guards against
 * removal of the redirect from sn_admin_pages() legacy URLs to the
 * canonical sn_admin_top_tabs() URLs. v4.2.0 (D-02 audit closure).
 *
 * The redirect handler itself was added in v3.8.0; this test just
 * pins down the contract.
 */

define( 'ABSPATH', '/' );

// Minimal WP stubs.
$GLOBALS['__redirects'] = array();
function admin_url( $path = '' ) {
    return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function sanitize_text_field( $s ) {
    return is_string( $s ) ? trim( strip_tags( $s ) ) : '';
}
function wp_unslash( $v ) {
    return is_string( $v ) ? stripslashes( $v ) : $v;
}

$path = __DIR__ . '/../inc/admin-page.php';
$src  = file_get_contents( $path );

$pass = 0;
$fail = 0;

function assertContains( $needle, $haystack, $label ) {
    global $pass, $fail;
    if ( false !== strpos( $haystack, $needle ) ) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label — needle " . var_export( $needle, true ) . " not found\n";
    }
}

// === Test 1: redirect handler exists and is named correctly ===
assertContains( 'function sn_admin_maybe_redirect_legacy', $src, 'redirect handler function exists' );

// === Test 2: handler reads ?page= legacy URL ===
assertContains( "\$_GET['page']", $src, 'handler reads ?page= query var' );

// === Test 3: handler builds the canonical destination ===
assertContains( "admin_url( 'admin.php?page=sn-theme-options&tab=' .", $src, 'handler builds canonical admin URL' );

// === Test 4: redirect map contains the at-risk D-02 slugs ===
assertContains( "'login'", $src, 'redirect map covers login slug' );
assertContains( "'rss'", $src, 'redirect map covers rss slug' );

// === Test 5: @deprecated docblock on sn_admin_pages ===
if ( preg_match( '/@deprecated\s+4\.2\.0/', $src ) ) {
    $pass++;
    echo "PASS: @deprecated 4.2.0 docblock present in file\n";
} else {
    $fail++;
    echo "FAIL: no @deprecated 4.2.0 docblock found\n";
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
