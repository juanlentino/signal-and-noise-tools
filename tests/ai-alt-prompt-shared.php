<?php
/**
 * Regression guard for the v6.39.4 alt-text prompt DRY refactor.
 *
 * ai-alt-suggest (attachment) and ai-alt-inline-suggest (inline <img>) are one
 * capability split by image source; their system instructions shared a
 * byte-identical rules suffix that was copy-pasted in two files. The refactor
 * extracts SNT_AI_ALT_BASE_RULES (defined in the primary ai-alt-text-suggest.php,
 * which now loads first) and composes both constants from it. This test pins the
 * EXACT system-instruction strings AND asserts the rules live in one place.
 *
 * v6.48.0: alt-text went vision (the actual image is sent to a multimodal model).
 * The leading task framing was rewritten ("describe the ATTACHED image, text as
 * supplement"); the shared SNT_AI_ALT_BASE_RULES suffix is unchanged. The pinned
 * strings below were updated to the new wording — a deliberate change, so this
 * test still guards against UNINTENDED drift from the v6.48.0 baseline.
 *
 * Run: php tests/ai-alt-prompt-shared.php
 * @since plugin v6.39.4
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function add_action( $tag, $cb, $p = 10, $a = 1 ) {}

// Production load order: the primary (which owns the shared base) loads first.
require_once __DIR__ . '/../inc/ai-alt-text-suggest.php';
require_once __DIR__ . '/../inc/ai-alt-inline-suggest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// The KNOWN-GOOD strings as of the v6.48.0 vision rewrite (alt-text now sends the
// actual image to a multimodal model). The shared SNT_AI_ALT_BASE_RULES suffix
// (no-preamble / no-empty / ALT_INSUFFICIENT_CONTEXT marker / output-only) is
// UNCHANGED — only the leading task framing moved from "describe an image" /
// "describe by URL + context" to "describe the ATTACHED image, text as supplement".
$expect_attach = 'Generate descriptive alt text for the attached image. Output 80-125 characters. Describe what is visible in the image factually, not the page it appears on; use any provided text context only to disambiguate names or specifics you cannot see. No "image of" / "picture of" / "photo of" preamble. No alt="" (empty) suggestions — if there is not enough context for a useful description, output only the literal marker: ALT_INSUFFICIENT_CONTEXT. Output ONLY the alt text or the marker — no quotes, no preamble, no markdown.';
$expect_inline = 'Generate descriptive alt text for an inline image in a post body. Output 80-125 characters. Describe what is visible in the attached image when one is present; otherwise describe it factually from the surrounding paragraph context + the URL filename. No "image of" / "picture of" / "photo of" preamble. No alt="" (empty) suggestions — if there is not enough context for a useful description, output only the literal marker: ALT_INSUFFICIENT_CONTEXT. Output ONLY the alt text or the marker — no quotes, no preamble, no markdown.';

echo "Alt-text prompt pins (v6.48.0 vision wording)\n\n";

echo "Group: full system instructions match the pinned v6.48.0 vision strings\n";
ok( SNT_AI_ALT_SUGGEST_SYSTEM === $expect_attach, 'attachment alt prompt is byte-identical to the v6.48.0 vision pin' );
ok( SNT_AI_ALT_INLINE_SUGGEST_SYSTEM === $expect_inline, 'inline alt prompt is byte-identical to the v6.48.0 vision pin' );

echo "\nGroup: the shared rules now live in ONE place\n";
$base = defined( 'SNT_AI_ALT_BASE_RULES' ) ? SNT_AI_ALT_BASE_RULES : '';
ok( '' !== $base, 'SNT_AI_ALT_BASE_RULES is defined (single source of truth for the common rules)' );
ok( false !== strpos( $base, 'ALT_INSUFFICIENT_CONTEXT' ) && false !== strpos( $base, 'no preamble, no markdown' ), 'base carries the shared no-preamble / no-empty / output-only rules' );
ok( '' !== $base && false !== strpos( SNT_AI_ALT_SUGGEST_SYSTEM, $base ), 'attachment prompt composes from the shared base' );
ok( '' !== $base && false !== strpos( SNT_AI_ALT_INLINE_SUGGEST_SYSTEM, $base ), 'inline prompt composes from the shared base' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
