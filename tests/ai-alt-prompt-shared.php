<?php
/**
 * Regression guard for the v6.39.4 alt-text prompt DRY refactor.
 *
 * ai-alt-suggest (attachment) and ai-alt-inline-suggest (inline <img>) are one
 * capability split by image source; their system instructions shared a
 * byte-identical rules suffix that was copy-pasted in two files. The refactor
 * extracts SNT_AI_ALT_BASE_RULES (defined in the primary ai-alt-text-suggest.php,
 * which now loads first) and composes both constants from it. This test pins the
 * EXACT pre-refactor strings so the change is proven behavior-preserving (zero
 * prompt drift) AND asserts the rules now live in one place.
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

// The KNOWN-GOOD strings as shipped through v6.39.3 (source of truth for the
// regression — the refactor must reproduce these byte-for-byte).
$expect_attach = 'Generate descriptive alt text for an image. Output 80-125 characters. Describe the image factually, not the page it appears on. No "image of" / "picture of" / "photo of" preamble. No alt="" (empty) suggestions — if there is not enough context for a useful description, output only the literal marker: ALT_INSUFFICIENT_CONTEXT. Output ONLY the alt text or the marker — no quotes, no preamble, no markdown.';
$expect_inline = 'Generate descriptive alt text for an image referenced by URL in a post body. Output 80-125 characters. Describe the image factually based on the surrounding paragraph context + the URL filename. No "image of" / "picture of" / "photo of" preamble. No alt="" (empty) suggestions — if there is not enough context for a useful description, output only the literal marker: ALT_INSUFFICIENT_CONTEXT. Output ONLY the alt text or the marker — no quotes, no preamble, no markdown.';

echo "Alt-text prompt DRY refactor (behavior-preserving)\n\n";

echo "Group: full system instructions are unchanged (no prompt drift)\n";
ok( SNT_AI_ALT_SUGGEST_SYSTEM === $expect_attach, 'attachment alt prompt is byte-identical to v6.39.3' );
ok( SNT_AI_ALT_INLINE_SUGGEST_SYSTEM === $expect_inline, 'inline alt prompt is byte-identical to v6.39.3' );

echo "\nGroup: the shared rules now live in ONE place\n";
$base = defined( 'SNT_AI_ALT_BASE_RULES' ) ? SNT_AI_ALT_BASE_RULES : '';
ok( '' !== $base, 'SNT_AI_ALT_BASE_RULES is defined (single source of truth for the common rules)' );
ok( false !== strpos( $base, 'ALT_INSUFFICIENT_CONTEXT' ) && false !== strpos( $base, 'no preamble, no markdown' ), 'base carries the shared no-preamble / no-empty / output-only rules' );
ok( '' !== $base && false !== strpos( SNT_AI_ALT_SUGGEST_SYSTEM, $base ), 'attachment prompt composes from the shared base' );
ok( '' !== $base && false !== strpos( SNT_AI_ALT_INLINE_SUGGEST_SYSTEM, $base ), 'inline prompt composes from the shared base' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
