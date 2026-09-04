<?php
/**
 * Static grep test for U-05 + U-11 (v4.2.0):
 * No PHP file in inc/ should emit inline `style="display:inline-block"`
 * or `style="width:`. Use the .sn-fieldset-actions--inline and
 * .sn-input--filter CSS modifiers instead.
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

require_once __DIR__ . '/lib/inc-population.php'; // #987: inc/ is walked, not top-level-globbed.
$files = snt_test_inc_files();

$pass = 0;
$fail = 0;

$banned = array(
    'style="display:inline-block'   => 'use class="sn-fieldset-actions--inline"',
    'style="display: inline-block'  => 'use class="sn-fieldset-actions--inline"',
    'style="width: 320px'           => 'use class="sn-input--filter"',
    'style="width:320px'            => 'use class="sn-input--filter"',
);

foreach ( $files as $file ) {
    $contents = file_get_contents( $file );
    foreach ( $banned as $needle => $hint ) {
        if ( false !== strpos( $contents, $needle ) ) {
            $fail++;
            echo "FAIL: $file contains banned inline style $needle — $hint\n";
        }
    }
}

if ( 0 === $fail ) {
    $pass++;
    // Report the POPULATION, not just the verdict. This guard used to print
    // "no banned inline styles in inc/*.php" over the top level alone, which
    // read as a statement about inc/ and was a statement about 428 of its 514
    // files (#987). A verdict whose scope is invisible cannot be audited.
    echo 'PASS: no banned inline styles in ' . count( $files ) . ' PHP files under inc/ ('
        . count( snt_test_inc_packages() ) . " packages walked)\n";
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
