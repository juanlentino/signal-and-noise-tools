<?php
/**
 * Tests for snt_word_count() (v10.24.0) — the Unicode-safe word counter that
 * replaces PHP's ASCII-only str_word_count() at all four call sites
 * (reading time, schema.org wordCount, AI prepop gate, AI excerpt length).
 * str_word_count's two failure modes, both pinned here as fixed:
 *   - non-ASCII letters split/vanish ("señal" counted as two words or less);
 *   - standalone numbers count as ZERO words ("2026" is a word to a reader).
 * Run: php tests/word-count.php
 * @since plugin v10.24.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

require __DIR__ . '/../inc/word-count.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( 4 === snt_word_count( 'four plain ascii words' ), 'plain ASCII counts like before' );
ok( 0 === snt_word_count( '' ), 'empty string is zero, no notices' );
ok( 0 === snt_word_count( '   —…!!  ' ), 'punctuation-only is zero' );
ok( 4 === snt_word_count( 'la señal y el' ), 'accented words count as ONE word each (señal is one word)' );
ok( 5 === snt_word_count( 'published in 2026 by Panacea' ), 'standalone numbers COUNT (str_word_count said 4)' );
ok( 2 === snt_word_count( "provenance\u{00A0}ledger" ), 'non-breaking space splits words' );
ok( 1 === snt_word_count( 'BM25' ), 'an alphanumeric token is one word' );
// Interior ' ’ - stay INSIDE the word — str_word_count's semantics, kept
// deliberately so reading times don't inflate ~15% on contraction-heavy prose.
ok( 1 === snt_word_count( "doesn't" ), 'a contraction is ONE word (parity with the old counter)' );
ok( 1 === snt_word_count( 'first-party' ), 'a hyphenated compound is ONE word (parity)' );
ok( 1 === snt_word_count( "reader\u{2019}s" ), 'typographic apostrophe joins too (the theme writes those)' );
ok( 2 === snt_word_count( "- leading dash word" ) - 1, 'a leading dash is NOT part of a word' );
// The HIGH from review: invalid UTF-8 must never silently zero a real post.
$bad = 'alpha beta ' . chr( 0xC3 ) . ' gamma delta';
ok( 4 === snt_word_count( $bad ), 'invalid UTF-8 bytes never zero the count — the malformed byte is dropped, the words survive' );
// The one behavioral guarantee downstream consumers rely on: monotone —
// adding prose never lowers the count.
ok( snt_word_count( 'alpha beta gamma' ) > snt_word_count( 'alpha beta' ), 'monotone in content' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
