<?php
/**
 * Regression tests (#1228) for inc/ai-markdown-strip.php:
 *
 *  - snt_ai_strip_markdown(): the single-'*' italic rule must not eat a
 *    digit-flanked multiplication expression ("5*3*2" -> "532").
 *  - snt_ai_untrusted_display(): the control-character strip claims C0/C1,
 *    but only ever matched C0 — a C1 control character (U+0080-U+009F)
 *    survived untouched.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/ai-markdown-strip.php';

echo "Group: #1228 — single-* italics vs multiplication\n";
ok( '5*3*2 equals thirty' === snt_ai_strip_markdown( '5*3*2 equals thirty' ), 'a digit-flanked multiplication expression is left alone' );
ok( 'This is emphasis' === snt_ai_strip_markdown( 'This is *emphasis*' ), 'genuine italics (not digit-flanked) are still stripped' );
ok( 'value is 5' === snt_ai_strip_markdown( 'value is *5*' ), 'a single digit wrapped in asterisks (no OUTER digit flank) is still stripped' );

echo "\nGroup: #1228 — C1 control characters are actually removed\n";
$c1 = "before" . "\xC2\x85" . "after"; // U+0085 (NEL), a real C1 control, UTF-8 encoded.
$out = snt_ai_untrusted_display( $c1 );
ok( 'beforeafter' === $out, 'a C1 control character (U+0085) is removed, matching the "C0/C1" docblock claim' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
