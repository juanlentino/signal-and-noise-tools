<?php
/**
 * Regression test (#1227): snt_ai_extract_inline_img_context() must slice
 * its context window in CHARACTERS, not bytes — the sliced text ships to
 * the AI provider as context, and a byte-based cut can (a) split a
 * multibyte character at the raw-window edges before stripping, and
 * (b) mis-locate the window entirely when multibyte prose precedes the
 * image src, since strpos() returns a byte offset but $window is a
 * character count.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

// Only require the base file for its SNT_AI_ALT_BASE_RULES constant.
require __DIR__ . '/../inc/ai-alt-text-suggest.php';
require __DIR__ . '/../inc/ai-alt-inline-suggest.php';

echo "Group: #1227 — inline-img context window is mb_-safe\n";

// A multibyte-heavy paragraph precedes the <img>. strpos() (byte offset)
// used to feed byte offsets straight into a byte-based substr() window,
// which is wrong once the preceding text is multibyte: the window would
// be mis-sized or mis-positioned relative to the actual character count.
$prefix  = str_repeat( 'Ω', 100 ); // 100 chars, 200 bytes — the byte/char gap a mis-derived offset would trip on.
$content = '<p>' . $prefix . ' the caption right before the image <img src="https://example.test/a.png"> and text after.</p>';
$ctx     = snt_ai_extract_inline_img_context( $content, 'https://example.test/a.png', 300 );
ok( '' !== $ctx, 'context is not empty with multibyte chars preceding the image' );
ok( 1 === preg_match( '//u', $ctx ), 'the extracted context is valid UTF-8 (no split multibyte tail)' );
ok( false !== strpos( $ctx, 'caption right before the image' ), 'the context includes prose near the image, not a mis-positioned window (a byte-offset-as-char-offset bug would shift the window)' );
ok( false !== strpos( $ctx, 'text after' ), 'the context also includes prose after the image' );

// A long multibyte stripped context must be truncated to $window CHARACTERS.
$long_mb   = str_repeat( 'Ω', 1000 );
$content2  = '<p>' . $long_mb . ' <img src="https://example.test/b.png"></p>';
$ctx2      = snt_ai_extract_inline_img_context( $content2, 'https://example.test/b.png', 50 );
ok( mb_strlen( $ctx2 ) <= 50, 'the final truncation respects the CHARACTER window, not a byte count' );
ok( 1 === preg_match( '//u', $ctx2 ), 'the final truncated context is valid UTF-8' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
