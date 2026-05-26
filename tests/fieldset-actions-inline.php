<?php
/**
 * Static grep test for U-05 + U-11 (v4.2.0):
 * No PHP file in inc/ should emit inline `style="display:inline-block"`
 * or `style="width:`. Use the .sn-fieldset-actions--inline and
 * .sn-input--filter CSS modifiers instead.
 */

$inc_dir = __DIR__ . '/../inc';
$files   = glob( $inc_dir . '/*.php' );

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
    echo "PASS: no banned inline styles in inc/*.php\n";
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
