<?php
/**
 * Tests: every MCP write-door call that reaches core must hand it SLASHED data.
 *
 * WHY THIS EXISTS. Reported live on page 1490, 2026-09-10. Two block_replace
 * calls sent correct block markup and the DB stored it mangled:
 *
 *   sent:   ISRC identifies a <em>recording</em>; ...
 *   stored: ISRC identifies a u003cemu003erecordingu003c/emu003e; ...
 *
 *   sent:   sn-steps__list sn-steps__list--plain
 *   stored: sn-steps__list sn-steps__listu002du002dplain
 *
 * The rendered page then showed literal "u003cem" text. WP's slashing contract
 * is asymmetric: wp_update_post()/wp_insert_post()/update_post_meta() EXPECT
 * slashed input and wp_unslash() it internally. The write door handed them raw
 * serialize_block() output, so every literal backslash was eaten -- a block
 * delimiter attribute carrying <, or a className carrying a BEM
 * --modifier.
 *
 * THE DRY-RUN DIFF IS NOT AN ORACLE for this. It reports the payload, which is
 * correct; the loss happens inside core, after validation. These assertions
 * read back what would be STORED.
 *
 * THE OTHER REASON IT HID: most sn-apply paths accept a $write_callback and the
 * suites use it, which bypasses wp_update_post() entirely. A test driving the
 * callback can never see this bug. The round-trip below drives core's own
 * unslashing on purpose.
 *
 * @since plugin v13.109.6
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "write-door slash contract (plugin v13.109.6)\n\n";

/* ── core's two halves, faithfully ──────────────────────────────────────── */
function wd_slash( $v )   { return is_array( $v ) ? array_map( 'wd_slash', $v )   : ( is_string( $v ) ? addslashes( $v )   : $v ); }
function wd_unslash( $v ) { return is_array( $v ) ? array_map( 'wd_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }

/** What the DB ends up holding: core unslashes whatever the caller passed. */
function wd_store_raw( $content )     { return wd_unslash( $content ); }
function wd_store_slashed( $content ) { return wd_unslash( wd_slash( $content ) ); }

/* ── the exact payloads from the live report ────────────────────────────── */
/**
 * A literal backslash, built from its code point.
 *
 * DELIBERATE, not decoration: the first draft of this file wrote these fixtures
 * as plain literals and the backslashes were stripped somewhere between the
 * editor and disk -- the exact failure this suite exists to catch, one layer up.
 * A fixture for a backslash bug must not depend on any transport preserving a
 * backslash. chr(92) cannot be eaten.
 */
function wd_u( $hex ) { return chr( 92 ) . 'u' . $hex; }

$LT = wd_u( '003c' );          // an escaped "<" as it appears in block markup
$GT = wd_u( '003e' );          // an escaped ">"
$HY = wd_u( '002d' );          // an escaped "-"
$em_attr   = 'ISRC identifies a ' . $LT . 'em' . $GT . 'recording' . $LT . '/em' . $GT . '; ISWC identifies the underlying composition.';
$bem_class = '{"className":"sn-steps__list sn-steps__list' . $HY . $HY . 'plain"}';
$delimiter = '<!-- wp:signal-noise/sidenote {"body":"a ' . $LT . 'em' . $GT . 'term' . $LT . '/em' . $GT . '"} /-->';

// The fixtures really do carry backslashes -- assert it, or every assertion
// below could pass vacuously on backslash-free strings.
ok( false !== strpos( $em_attr, chr( 92 ) . 'u003c' ), 'FIXTURE GUARD: the em payload carries a real literal backslash' );
ok( 4 === substr_count( $em_attr, chr( 92 ) ), '...four of them, one per escape' );
ok( 2 === substr_count( $bem_class, chr( 92 ) ), 'FIXTURE GUARD: the BEM className carries two' );

echo "Group: the defect, reproduced -- an UNSLASHED write loses the backslashes\n";
ok( $em_attr !== wd_store_raw( $em_attr ), 'an unslashed write does NOT round-trip the <em> payload' );
ok( false !== strpos( wd_store_raw( $em_attr ), 'u003cem' ), '...it stores the reported corruption, literal "u003cem"' );
ok( false === strpos( wd_store_raw( $em_attr ), '<em' ), '...with the backslash gone' );
ok( false !== strpos( wd_store_raw( $bem_class ), 'sn-steps__listu002du002dplain' ), 'and the BEM className corrupts exactly as reported' );

echo "\nGroup: the fix -- a SLASHED write round-trips byte for byte\n";
foreach ( array( 'em attribute' => $em_attr, 'BEM className' => $bem_class, 'block delimiter' => $delimiter ) as $label => $payload ) {
	ok( $payload === wd_store_slashed( $payload ), "stored content is byte-identical to what was sent: $label" );
}
// Byte equality, not just "looks right": assert on length and hash too, because
// a substring check would pass on a payload that lost a backslash elsewhere.
ok( strlen( $em_attr ) === strlen( wd_store_slashed( $em_attr ) ), '...same byte LENGTH' );
ok( hash( 'sha256', $em_attr ) === hash( 'sha256', wd_store_slashed( $em_attr ) ), '...same sha256' );

// Content with no backslashes must be unaffected either way -- otherwise the
// fix would be indistinguishable from "slash everything and hope".
$plain = '<!-- wp:paragraph --><p>Ordinary prose, no escapes.</p><!-- /wp:paragraph -->';
ok( $plain === wd_store_raw( $plain ) && $plain === wd_store_slashed( $plain ), 'backslash-free content is untouched by either path' );

/* ── the census: every write site, derived from SOURCE ──────────────────── */
echo "\nGroup: every write-door call that reaches core hands it slashed data\n";
$files = array_merge( glob( __DIR__ . '/../inc/sn-apply/*.php' ), array( __DIR__ . '/../inc/abilities-update-post-surfaces.php' ) );
$total = 0; $unslashed = array();
foreach ( $files as $f ) {
	// Comment-stripped: a docblock naming wp_update_post() is not a call site,
	// and a comment between the call and its argument would hide a wp_slash.
	$src = (string) file_get_contents( $f );
	$src = preg_replace( '#/\*.*?\*/#s', '', $src );
	$src = preg_replace( '#(?m)^\s*//.*$#', '', $src );
	if ( preg_match_all( '/\b(wp_update_post|wp_insert_post|update_post_meta)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $i => $hit ) {
			$total++;
			// The call's OWN argument list, by balanced parens -- NOT a fixed
			// window. A 300-char window was tried first and silently passed a
			// real regression: removing wp_slash from the _sn_meta_description
			// write still "found" one, belonging to the NEXT statement. A
			// proximity window reports the neighbourhood, not the call.
			$open = strpos( $src, '(', $hit[1] );
			$depth = 0; $end = $open;
			for ( $j = $open, $n = strlen( $src ); $j < $n; $j++ ) {
				if ( '(' === $src[ $j ] ) { $depth++; }
				elseif ( ')' === $src[ $j ] ) { $depth--; if ( 0 === $depth ) { $end = $j; break; } }
			}
			$seg = substr( $src, $open, $end - $open + 1 );
			if ( false === strpos( $seg, 'wp_slash' ) ) {
				$unslashed[] = basename( $f ) . ' :: ' . $m[1][ $i ][0];
			}
		}
	}
}
// Guard the guard: a rotted regex finding nothing would pass the loop vacuously.
ok( $total >= 14, "the census FINDS the write sites -- a zero-match sweep would pass over nothing ($total found)" );
ok( array() === $unslashed, 'every write site slashes: ' . ( $unslashed ? implode( '; ', $unslashed ) : 'all ' . $total ) );

/* ── NEGATIVE CONTROLS ──────────────────────────────────────────────────── */
echo "\nGroup: negative controls\n";
$broken = '<?php wp_update_post( array( "ID" => 1, "post_content" => $c ), true );';
ok( 1 === preg_match( '/\bwp_update_post\s*\(/', $broken ) && false === strpos( $broken, 'wp_slash' ),
	'NEGATIVE CONTROL: the census recognises an unslashed wp_update_post as a site and reports it' );
$fixed = '<?php wp_update_post( wp_slash( array( "ID" => 1, "post_content" => $c ) ), true );';
ok( false !== strpos( $fixed, 'wp_slash' ), '...and accepts the slashed form' );
// A comment mentioning the function must NOT count as a call site.
$commented = "<?php\n// wp_update_post() is called elsewhere\n/* see wp_insert_post() */\n";
$stripped  = preg_replace( '#(?m)^\s*//.*$#', '', preg_replace( '#/\*.*?\*/#s', '', $commented ) );
ok( 0 === preg_match_all( '/\b(wp_update_post|wp_insert_post)\s*\(/', $stripped, $x ),
	'...and a comment naming the function is not counted as a call site' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
