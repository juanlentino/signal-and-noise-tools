/**
 * Node fixture harness for the /verify decision core
 * (assets/js/prov-verify-core.js) — the pure logic five same-day hotfixes
 * (9.73.1 → 9.75.1) all landed in, previously pinned only by string greps.
 *
 * Run: node tests/js/prov-verify-core.test.mjs
 *
 * NO network at runtime. Fixtures live under tests/js/fixtures/ and mirror
 * shapes verified against live data (2026-07-21/22):
 *   - the ledger stores records at notes/<uid>/v<n>.json with the Bitcoin
 *     txid under the ots.* subtree (bitcoin_txid; most records carry only
 *     bitcoin_block — the block-only norm), and keys at
 *     keys/provenance-keys.json;
 *   - the published .json twin uses content_text (NOT `content`), flattened
 *     to ONE line, while the Note payload keeps paragraphs and newlines;
 *   - did.json carries the key as base64url JWK x while both key mirrors
 *     carry standard base64 — same live bytes, two encodings.
 *
 * Each group names the hotfix whose repaired behavior it would have caught.
 * The PHP sweep wrapper (tests/provenance-verify-core.php) shells out to
 * this file and relays the summary line.
 */
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const require = createRequire( import.meta.url );
const core = require( join( here, '..', '..', 'assets', 'js', 'prov-verify-core.js' ) );
const fx = ( name ) => JSON.parse( readFileSync( join( here, 'fixtures', name ), 'utf8' ) );

let pass = 0;
let fail = 0;
function ok( cond, msg ) {
	if ( cond ) { pass++; console.log( `  PASS: ${msg}` ); }
	else { fail++; console.log( `  FAIL: ${msg}` ); }
}
function eq( expected, actual, msg ) {
	if ( expected === actual ) { pass++; console.log( `  PASS: ${msg}` ); }
	else {
		fail++;
		console.log( `  FAIL: ${msg}\n    Expected: ${JSON.stringify( expected )}\n    Actual:   ${JSON.stringify( actual )}` );
	}
}

const credTxid    = fx( 'cred-confirmed-txid.json' );
const credBlock   = fx( 'cred-block-only.json' );
const credPending = fx( 'cred-pending.json' );
const credBad     = fx( 'cred-malformed-proof.json' );
const TXID  = credTxid.evidence[ 0 ].anchor.txid;
const BLOCK = credTxid.evidence[ 0 ].anchor.block;

console.log( 'prov-verify-core suite (Node fixture harness)\n' );

// ─── Group 1: roughNormalize — 9.74.2 (whitespace collapse) ─────────────
console.log( 'Group 1: roughNormalize (9.74.2 — collapse ALL whitespace runs)' );
{
	const payload = JSON.parse( atob( credBlock.proof.signedPayloadB64 ) );
	const twin = fx( 'twin-match.json' );
	ok( payload.content.includes( '\n\n' ), 'precondition: the payload keeps paragraph structure (real newlines)' );
	ok( ! twin.content_text.includes( '\n' ), 'precondition: the twin content_text is ONE flattened line' );
	eq( core.roughNormalize( twin.content_text ), core.roughNormalize( payload.content ),
		'paragraphed payload and flattened twin normalize EQUAL (the 9.74.2 repair — line-preserving comparison could never match)' );
	eq( 'a b c', core.roughNormalize( 'a \t b\n\n\nc' ), 'tabs, runs of spaces, and blank lines all collapse to single spaces' );
	eq( 'a b', core.roughNormalize( 'a\r\nb' ), 'CRLF is handled like a newline' );
	eq( 'x y', core.roughNormalize( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->\n<p>y</p>' ),
		'block comments and markup strip away (real content_html keeps newlines between blocks)' );
	eq( '"q" \'a\' <b> & é', core.roughNormalize( '&quot;q&quot; &#39;a&#39; &lt;b&gt; &amp; &#233;' ),
		'the entity set decodes (quotes, apostrophe, angle brackets, ampersand, numeric)' );
	eq( '&lt;', core.roughNormalize( '&amp;lt;' ), '&amp; decodes LAST so it never re-triggers the other entities' );
	eq( 'é', core.roughNormalize( 'é' ), 'NFC normalization applies (combining accent composes)' );
}

// ─── Group 2: live match — 9.74.1 (content_text) + 9.75.0 (PASS stamp,
//     no-edit-claim on decode failure) ──────────────────────────────────
console.log( '\nGroup 2: deriveLiveMatchVerdict (9.74.1 twin schema; 9.75.0 PASS stamp + honest decode failure)' );
{
	const twinMatch = fx( 'twin-match.json' );
	ok( ! ( 'content' in twinMatch ), 'precondition: the twin fixture has NO bare `content` key (the field 9.74.1 stopped reading)' );
	const v1 = core.deriveLiveMatchVerdict( credBlock, twinMatch );
	eq( core.STATE.PASS, v1.state, 'an unedited Note stamps PASS (9.75.0 — was an unexplained NOTE; pre-9.74.1 it was "edited" on every Note)' );
	eq( 'This matches the currently published content.', v1.detail, 'match detail sentence' );

	const v2 = core.deriveLiveMatchVerdict( credBlock, fx( 'twin-edited.json' ) );
	eq( core.STATE.NOTE, v2.state, 'an edited Note is NOTE — informational, never a FAIL' );
	ok( v2.detail.startsWith( 'Content edited since signing. This credential proves version 1 as of ' ),
		'edit detail names the proven version and signing date' );

	const v3 = core.deriveLiveMatchVerdict( credBad, twinMatch );
	eq( core.STATE.NOTE, v3.state, 'an undecodable payload is NOTE, not FAIL' );
	ok( v3.detail.includes( 'no edit claim either way' ),
		'decode failure claims NO edit either way (9.75.0 — pre-fix asserted "edited since signing", never established)' );
	ok( ! v3.detail.includes( 'edited since signing' ), 'decode failure never masquerades as an edit' );

	const credNoContent = JSON.parse( JSON.stringify( credBlock ) );
	credNoContent.proof.signedPayloadB64 = Buffer.from( '{"title":"no content field"}' ).toString( 'base64' );
	eq( core.STATE.NOTE, core.deriveLiveMatchVerdict( credNoContent, twinMatch ).state,
		'a payload without content also renders the no-claim NOTE' );

	eq( 'https://juanlentino.com/notes/fixture-docket-witness.json', core.liveMatchTwinUrl( credBlock ),
		'twin URL derives from the subject url, trailing slash normalized, .json appended' );
	eq( '', core.liveMatchTwinUrl( { credentialSubject: {} } ), 'a credential without a live URL yields no twin URL' );
}

// ─── Group 2b: UTF-8 — v10.66.1 (a false "edited since signing" claim, live) ─
console.log( '\nGroup 2b: non-ASCII content survives base64 decoding (10.66.1)' );
{
	// THE LIVE DEFECT. atob() returns a BINARY string — one character per BYTE —
	// so a UTF-8 multibyte character arrives as its separate bytes. Confirmed in
	// production 2026-08-08: /verify?note=045d4cec-bf8c-4fdc-8b5f-30145d3ed639
	// ("Start here", whose text contains № U+2116) reported
	// "Content edited since signing" about a Note that had NOT been edited, and
	// degraded the overall verdict to "corroboration is incomplete".
	//
	// A false edit claim on the page whose entire purpose is trustworthiness is
	// the worst failure this module can have, so it is pinned at the decoder AND
	// at the verdict.
	const text = 'the numbered spine, № 1.00 and № 2.00 — signed, not edited.';
	const credUtf8 = JSON.parse( JSON.stringify( credBlock ) );
	credUtf8.proof.signedPayloadB64 = Buffer.from( JSON.stringify( { content: text } ), 'utf8' ).toString( 'base64' );

	const v = core.deriveLiveMatchVerdict( credUtf8, { content_text: text } );
	eq( core.STATE.PASS, v.state, 'a Note carrying non-ASCII still MATCHES its unedited twin' );
	ok( ! v.detail.includes( 'edited since signing' ), 'never claims an edit that did not happen' );

	// The decoder itself, directly.
	eq( text, core.base64ToUtf8( Buffer.from( text, 'utf8' ).toString( 'base64' ) ),
		'base64ToUtf8 returns CHARACTERS, not bytes' );
	eq( '№', core.base64ToUtf8( Buffer.from( '№', 'utf8' ).toString( 'base64' ) ),
		'a 3-byte character decodes to ONE character' );

	// GUARD, and the whole reason this is a separate function: the BYTES path
	// must stay byte-exact. base64ToBytes feeds Ed25519 signature verification
	// and SHA-256 hashing — "fixing" it to decode text would break check 01 and
	// check 02 on the page that exists to run them.
	eq( 3, core.base64ToBytes( Buffer.from( '№', 'utf8' ).toString( 'base64' ) ).length,
		'base64ToBytes still returns 3 BYTES for №, unchanged (signature/hash depend on it)' );
}

// ─── Group 3: proof decode — 9.75.0 (malformed base64 = verdict, not throw) ──
console.log( '\nGroup 3: decodeProofBytes / decodeSignedPayloadBytes (9.75.0 — corrupt base64 stranded the docket)' );
{
	let threw = false;
	let d;
	try { d = core.decodeProofBytes( credBad ); } catch ( e ) { threw = true; }
	ok( ! threw, 'decodeProofBytes does NOT throw on corrupt base64 (pre-fix: atob threw, checks 03/04 never started)' );
	ok( d && d.malformed === true, 'corrupt proof reports malformed' );
	eq( core.STATE.FAIL, d.verdict.state, 'malformed proof is a FAIL verdict' );
	eq( 'This credential is malformed and cannot be decoded.', d.verdict.detail, 'malformed detail sentence' );

	const good = core.decodeProofBytes( credTxid );
	ok( good.payloadBytes.length > 0 && good.sigBytes.length === 64, 'a well-formed proof decodes to payload + 64-byte signature' );

	// The two decode sites are separate on purpose: a corrupt SIGNATURE must
	// not fail the content-hash check, whose input is the payload alone.
	const credBadSig = JSON.parse( JSON.stringify( credTxid ) );
	credBadSig.proof.proofValue = '%%%corrupt%%%';
	ok( ! core.decodeSignedPayloadBytes( credBadSig ).malformed,
		'content-hash decode ignores a corrupt proofValue (payload-only input)' );
	ok( core.decodeProofBytes( credBadSig ).malformed === true,
		'signature decode does flag the corrupt proofValue' );
	ok( core.decodeSignedPayloadBytes( credBad ).malformed === true,
		'content-hash decode flags a corrupt payload with the same FAIL verdict' );
}

// ─── Group 4: content hash claim + verdict ──────────────────────────────
console.log( '\nGroup 4: claimedContentHash + deriveContentHashVerdict (fixture is internally consistent, hashed offline)' );
{
	const claimed = core.claimedContentHash( credTxid );
	ok( /^[0-9a-f]{64}$/.test( claimed ), 'claimed hash strips the sha256: prefix and lowercases' );
	const actual = createHash( 'sha256' ).update( Buffer.from( core.decodeSignedPayloadBytes( credTxid ).payloadBytes ) ).digest( 'hex' );
	eq( claimed, actual, 'the fixture credential really hashes to its own claim (node:crypto, no network)' );
	eq( core.STATE.PASS, core.deriveContentHashVerdict( actual, claimed ).state, 'matching hash is PASS' );
	eq( core.STATE.FAIL, core.deriveContentHashVerdict( actual.replace( /^./, '0' ) === actual ? 'f' + actual.slice( 1 ) : actual.replace( /^./, '0' ), claimed ).state,
		'a tampered hash is FAIL' );
	eq( core.STATE.FAIL, core.deriveContentHashVerdict( actual, '' ).state, 'an absent claim can never PASS' );
}

// ─── Group 5: key agreement (live key, two real encodings) ──────────────
console.log( '\nGroup 5: deriveKeyAgreement (did base64url x vs mirrors\' standard base64 — the live pair)' );
{
	const did = fx( 'did.json' );
	const keys = fx( 'keys.json' );
	const mism = fx( 'keys-mismatch.json' );
	const a1 = core.deriveKeyAgreement( did, keys, keys );
	ok( ! a1.verdict && a1.jwk && a1.jwk.x === did.verificationMethod[ 0 ].publicKeyJwk.x,
		'all three origins agree: base64url JWK x equals the mirrors\' standard base64 bytes' );
	const a2 = core.deriveKeyAgreement( did, mism, keys );
	eq( core.STATE.FAIL, a2.verdict.state, 'a site-mirror mismatch is FAIL' );
	ok( a2.verdict.detail.includes( 'this site' ), 'site mismatch names the site mirror' );
	const a3 = core.deriveKeyAgreement( did, keys, mism );
	eq( core.STATE.FAIL, a3.verdict.state, 'a ledger-copy mismatch is FAIL' );
	ok( a3.verdict.detail.includes( 'independent ledger copy' ), 'ledger mismatch names the ledger copy' );
	const a4 = core.deriveKeyAgreement( {}, keys, keys );
	eq( core.STATE.FAIL, a4.verdict.state, 'a did document without a key is FAIL' );
	ok( a4.verdict.detail.includes( 'No public key is published' ), 'missing-key detail sentence' );
	const missing = core.deriveKeyAgreement( did, null, null );
	ok( ! missing.verdict, 'ABSENT mirrors are a gap, not a contradiction — agreement proceeds on the did key alone' );
	const sig = core.deriveSignatureVerdict( true );
	eq( core.STATE.PASS, sig.state, 'a valid signature is PASS' );
	eq( core.STATE.FAIL, core.deriveSignatureVerdict( false ).state, 'an invalid signature is FAIL' );
}

console.log( '\nGroup 5b: v2 key documents — the ACTIVE key is chosen by status, not by position (v10.77.0)' );
{
	// Before v10.77.0 the gate read keys[ 0 ]. That was correct only while the
	// array held exactly one key. The v2 schema adds RETIRED keys with validity
	// windows (so anchors signed under an old key keep verifying), and the
	// moment history sorts ahead of the active key, position-based selection
	// compares the did document against a RETIRED key and reports a key
	// mismatch on a completely healthy site. This fixture puts history first on
	// purpose — it is the arrangement that would have broken it.
	const did = fx( 'did.json' );
	const v1  = fx( 'keys.json' );
	const v2  = fx( 'keys-v2-history-first.json' );

	eq( 'retired', v2.keys[ 0 ].status,
		'the fixture really does put a RETIRED key at index 0 (otherwise this group proves nothing)' );

	const a1 = core.deriveKeyAgreement( did, v2, v2 );
	ok( ! a1.verdict,
		'a v2 document with history AHEAD of the active key still AGREES (pre-fix: keys[0] was retired → FAIL)' );

	const a2 = core.deriveKeyAgreement( did, v2, v1 );
	ok( ! a2.verdict,
		'a v2 site mirror agrees with a v1 ledger copy — the two schemas interoperate during rollout' );

	// Backwards compatibility: a document with NO status field anywhere must
	// still resolve through the index-0 fallback exactly as it always did.
	const legacy = JSON.parse( JSON.stringify( v1 ) );
	delete legacy.keys[ 0 ].status;
	const a3 = core.deriveKeyAgreement( did, legacy, legacy );
	ok( ! a3.verdict,
		'a statusless (pre-v2) document still verifies via the index-0 fallback' );

	// And a genuine mismatch must still FAIL — the fix must not have turned the
	// gate into something that always finds an agreeable key somewhere.
	const wrong = JSON.parse( JSON.stringify( v2 ) );
	wrong.keys[ 1 ].public_key_base64 = fx( 'keys-mismatch.json' ).keys[ 0 ].public_key_base64;
	const a4 = core.deriveKeyAgreement( did, wrong, v2 );
	eq( core.STATE.FAIL, a4.verdict.state,
		'a wrong ACTIVE key still FAILs — selection by status did not become "find any key that agrees"' );
}

console.log( '\nGroup 5c: the key is the one the RECORD names, not the one that happens to be active' );
{
	// A credential is signed by exactly ONE key and says which: proof.pubkey_id
	// (the same identity the Worker already writes onto every ledger record).
	// Resolving "the ACTIVE key" instead verifies every historical Note against
	// TODAY's key, so the first rotation reports correctly-signed Notes as
	// unverifiable — the failure the v2 schema's retired keys and validity
	// windows exist to prevent, and which nothing consumed until now.
	const did     = fx( 'did.json' );
	const v2      = fx( 'keys-v2-history-first.json' );
	const retired = v2.keys[ 0 ];
	const b64url  = ( b64 ) => Buffer.from( b64, 'base64' ).toString( 'base64url' );

	eq( 'retired', retired.status, 'fixture check: index 0 really is the RETIRED key' );

	const a1 = core.deriveKeyAgreement( did, v2, v2, retired.id );
	ok( ! a1.verdict, 'a credential naming the retired key still AGREES after rotation' );
	eq( b64url( retired.public_key_base64 ), a1.jwk && a1.jwk.x,
		'and resolves to the RETIRED key bytes — not the active key the did document publishes' );

	// A named key nobody publishes is a FAIL. The tempting fallback — "no entry,
	// use the active key" — is the original bug wearing a different hat: it
	// verifies the record against a key that did not sign it and reports PASS.
	const a2 = core.deriveKeyAgreement( did, v2, v2, 'sn-ed25519-2099-12' );
	ok( a2.verdict, 'a record naming an UNPUBLISHED key does not quietly fall back to the active key' );
	eq( core.STATE.FAIL, a2.verdict && a2.verdict.state, 'and it is a FAIL' );

	// The two mirrors are independent origins; disagreeing about the SAME key id
	// is a contradiction, not a gap, so it must not resolve to either one.
	const forked = JSON.parse( JSON.stringify( v2 ) );
	forked.keys[ 0 ].public_key_base64 = fx( 'keys-mismatch.json' ).keys[ 0 ].public_key_base64;
	const a3 = core.deriveKeyAgreement( did, forked, v2, retired.id );
	eq( core.STATE.FAIL, a3.verdict && a3.verdict.state,
		'the site mirror and the ledger publishing DIFFERENT bytes for one key id is a FAIL' );
}

console.log( '\nGroup 5d: deriveRetraction — absence is an ANSWER, an outage is not' );
{
	const UID = '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f42';
	const body = ( over ) => ( { ok: true, status: 200, json: Object.assign( {
		payload: { kind: 'retraction', note_uid: UID, version: 1, retracted_at: '2026-08-15', what_was_wrong: 'x' },
		content_hash: 'abc', signature: 'sig', pubkey_id: 'sn-ed25519-2026-07'
	}, over || {} ) } );

	// 404 is the NORMAL case and a REAL answer: nothing has been retracted. It
	// must be distinguishable from "we could not find out".
	const none = core.deriveRetraction( { ok: false, status: 404, json: null }, UID, 1 );
	ok( null === none.retraction && ! none.unknown, 'a 404 means NOT retracted — a real answer, not a gap' );

	// A network failure is NOT evidence of absence. Reading it as "no retraction"
	// is how a withdrawn record renders as authentic to anyone whose fetch fails.
	const blip = core.deriveRetraction( { ok: false, status: 0, json: null }, UID, 1 );
	ok( blip.unknown, 'an unreachable retraction path is UNKNOWN, never "not retracted"' );
	ok( null === blip.retraction, 'and it never yields a retraction to act on' );

	// A retraction served at our path but naming a DIFFERENT record must not
	// suppress this one — otherwise one stray file silences an unrelated Note.
	const alien = core.deriveRetraction( body( { payload: { kind: 'retraction', note_uid: 'ffffffff-0000-4000-8000-000000000000', version: 1 } } ), UID, 1 );
	ok( null === alien.retraction, 'a retraction naming another record is not honoured' );
	ok( alien.mismatched, 'and the mismatch is reported rather than swallowed' );

	// Version matters: a retraction of v1 says nothing about v2.
	ok( null === core.deriveRetraction( body(), UID, 2 ).retraction, 'a retraction of v1 does not retract v2' );

	// The happy path: names this exact record.
	// The path is kind-INDEPENDENT: a retraction lives in one directory whatever
	// the subject was, because the record it withdraws is named inside its own
	// payload. That keeps the reader's lookup a single deterministic URL.
	eq( 'https://raw.example/main/retractions/' + UID + '/v2.json',
		core.retractionUrl( 'https://raw.example/main/', UID, 2 ),
		'retraction URL mirrors the record path under one retractions/ root' );
	eq( 'https://raw.example/main/retractions/' + UID + '/v2.json',
		core.retractionUrl( 'https://raw.example/main', UID, 2 ),
		'and a base without a trailing slash resolves identically' );

	const hit = core.deriveRetraction( body(), UID, 1 );
	ok( hit.retraction && '2026-08-15' === hit.retraction.retracted_at, 'a retraction naming THIS record is returned for signature verification' );
	ok( ! hit.unknown && ! hit.mismatched, 'and carries no gap or mismatch flag' );
}

console.log( '\nGroup 5e: retractionOutcome — a retraction we cannot verify is UNKNOWN, never clean' );
{
	const R = { retracted_at: '2026-08-15', what_was_wrong: 'x' };

	// Verified: it withdraws the record.
	eq( R, core.retractionOutcome( { retraction: R }, true ).retraction, 'a verified retraction is honoured' );
	ok( ! core.retractionOutcome( { retraction: R }, true ).unknown, 'and carries no uncertainty' );

	// Present but NOT verified. Discarding this silently was the first design and
	// it is wrong in the same direction as everything else here: it converts an
	// attacker-supplied file into a clean bill of health. It cannot be honoured
	// either — an unverified retraction would let anyone silence any record — so
	// the honest state is "could not determine".
	const bad = core.retractionOutcome( { retraction: R }, false );
	ok( null === bad.retraction, 'an unverified retraction is never honoured' );
	ok( bad.unknown, 'and never silently ignored either — it is UNKNOWN' );

	// Could not even attempt verification (no WebCrypto, malformed bytes, key
	// unresolvable): same answer.
	ok( core.retractionOutcome( { retraction: R }, null ).unknown, 'an unattemptable check is UNKNOWN' );

	// 404: a real answer. This is the common case and must stay clean.
	const none = core.retractionOutcome( { retraction: null, unknown: false }, null );
	ok( null === none.retraction && ! none.unknown, 'a confirmed absence stays clean' );

	// An unreachable lookup or a retraction naming another record: both leave us
	// unable to say whether THIS record was withdrawn.
	ok( core.retractionOutcome( { retraction: null, unknown: true }, null ).unknown, 'an unreachable lookup is UNKNOWN' );
	ok( core.retractionOutcome( { retraction: null, mismatched: true }, null ).unknown, 'a mismatched retraction leaves the status UNKNOWN' );
}

console.log( '\nGroup 5f: retractionRows — the reasons a reader is owed' );
{
	const full = {
		kind: 'retraction', retracted_at: '2026-08-15',
		claimed: 'the content hash of the published Note',
		what_was_wrong: 'the hash was computed over pre-normalization bytes',
		root_cause: 'the signer read the raw content before the normalizer ran',
		what_changed: 'the signer now hashes normalized bytes, pinned by a test',
		correct_value_at_retraction: { content_hash: 'abc123', caveat: 'Do not treat this as current.' }
	};
	const rows = core.retractionRows( full );
	const labels = rows.map( function ( r ) { return r[ 0 ]; } );

	// What was wrong leads. A retraction whose reason is buried under metadata
	// answers the question nobody asked first.
	ok( /wrong/i.test( labels[ 0 ] ), 'the reason leads the panel' );
	ok( labels.some( function ( l ) { return /root cause/i.test( l ); } ), 'root cause is shown' );
	ok( labels.some( function ( l ) { return /changed/i.test( l ); } ), 'what changed is shown' );

	// A stated correct value must never appear without its staleness caveat —
	// published bare it becomes the next wrong number someone compares against.
	const correct = rows.filter( function ( r ) { return /correct/i.test( r[ 0 ] ); } );
	eq( 1, correct.length, 'the corrected value is shown once' );
	ok( /Do not treat this as current/.test( correct[ 0 ][ 1 ] ), 'and always carries its caveat' );

	// Absent fields produce NO row. An empty "Root cause:" reads as though we
	// looked and found nothing, when in fact nothing was said.
	const sparse = core.retractionRows( { what_was_wrong: 'x', retracted_at: '2026-08-15' } );
	ok( ! sparse.some( function ( r ) { return '' === String( r[ 1 ] ).trim(); } ), 'no empty rows' );
	ok( ! sparse.some( function ( r ) { return /root cause/i.test( r[ 0 ] ); } ), 'an unstated root cause is omitted, not blanked' );

	// A correct value with no caveat from the producer still gets one here: the
	// panel is the last place that can stop a bare number going out.
	const nocaveat = core.retractionRows( { what_was_wrong: 'x', correct_value_at_retraction: { pcr0: 'aef4' } } );
	const cv = nocaveat.filter( function ( r ) { return /correct/i.test( r[ 0 ] ); } );
	ok( cv.length === 1 && /not.*current|will change/i.test( cv[ 0 ][ 1 ] ), 'a caveat-less correct value is never rendered bare' );

	ok( core.retractionRows( null ).length === 0, 'no retraction, no rows' );
}

// ─── Group 6: anchor plan (BOTH anchor shapes + the block-only norm) ────
console.log( '\nGroup 6: deriveAnchorPlan (pending attestation; confirmed with txid; block-only — 9.73.2)' );
{
	const p1 = core.deriveAnchorPlan( credPending );
	eq( core.STATE.NOTE, p1.verdict.state, 'a pending anchor is NOTE, never a failure' );
	ok( p1.verdict.detail.includes( 'Awaiting Bitcoin confirmation' ), 'pending detail sentence' );

	const p2 = core.deriveAnchorPlan( credTxid );
	eq( 'txid', p2.mode, 'a confirmed txid-carrying anchor walks the mempool + ledger path' );

	const p3 = core.deriveAnchorPlan( credBlock );
	eq( 'block-only', p3.mode,
		'a confirmed block-only anchor (the live norm, 20+ of 25 records) gets its own path — 9.73.2: pre-fix this shape FAILed' );
	ok( ! p3.verdict, 'block-only is NOT settled as a verdict up front' );

	const credNoAnchor = JSON.parse( JSON.stringify( credTxid ) );
	credNoAnchor.evidence[ 0 ].anchor = {};
	eq( core.STATE.FAIL, core.deriveAnchorPlan( credNoAnchor ).verdict.state, 'no anchor at all is FAIL' );
	ok( core.deriveAnchorPlan( { } ).verdict.state === core.STATE.FAIL, 'a credential without evidence settles FAIL, no throw' );
}

// ─── Group 7: ledger URLs — 9.73.1 (the ledger's REAL layout) ───────────
console.log( '\nGroup 7: ledger/mempool URLs (9.73.1 — keys/provenance-keys.json, notes/<uid>/v<n>.json)' );
{
	const base = 'https://raw.example/ledger/main/';
	const keysUrl = core.ledgerKeysUrl( base );
	eq( 'https://raw.example/ledger/main/keys/provenance-keys.json', keysUrl,
		'the ledger key copy is keys/provenance-keys.json (9.73.1 — the pre-fix keys/keys.json does not exist)' );
	ok( ! keysUrl.includes( 'keys/keys.json' ), 'never the pre-fix keys/keys.json path' );
	eq( 'https://raw.example/ledger/main/notes/' + credTxid.evidence[ 0 ].anchor.txid.slice( 0, 0 ) + '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f42/v1.json',
		core.ledgerRecordUrl( base, '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f42', 0, credTxid.evidence[ 0 ] ),
		'records live at notes/<uid>/v<n>.json; version falls back to the evidence version' );
	eq( 'https://raw.example/ledger/main/notes/abc/v3.json', core.ledgerRecordUrl( base, 'abc', 3, { version: 1 } ),
		'an explicit version wins over the evidence fallback' );
	eq( 'https://raw.example/ledger/main/notes/abc/v0.json', core.ledgerRecordUrl( base, 'abc', 0, {} ),
		'no version anywhere resolves to v0' );
	eq( 'https://mempool.example/api/tx/' + TXID + '/status', core.mempoolTxStatusUrl( 'https://mempool.example/api/', TXID ),
		'mempool status URL is /tx/<txid>/status' );
}

// ─── Group 8: block-only anchor interpretation — 9.73.2 + the 9.74.1 triangle ──
console.log( '\nGroup 8: deriveBlockOnlyAnchor (9.73.2 — honest NOTE, per-field FAIL; 9.74.1 — ledger-supplied txid triangle)' );
{
	const anchor = credBlock.evidence[ 0 ].anchor;
	const evidence = credBlock.evidence[ 0 ];
	const recBlockOnly = { ok: true, status: 200, json: fx( 'ledger-record-block-only.json' ) };
	const o1 = core.deriveBlockOnlyAnchor( anchor, evidence, recBlockOnly );
	eq( core.STATE.NOTE, o1.verdict.state,
		'a hash-attested block-only proof is NOTE, never FAIL (9.73.2 — pre-fix FAILed 4 of 5 live Notes)' );
	ok( o1.verdict.detail.includes( 'Block-anchored via OpenTimestamps at block ' + BLOCK ), 'detail names the OTS block' );
	ok( o1.verdict.detail.includes( 'attests the same content hash' ), 'detail carries the ledger hash cross-attest' );

	const o2 = core.deriveBlockOnlyAnchor( anchor, evidence, { ok: false, status: 0, json: null } );
	eq( core.STATE.NOTE, o2.verdict.state, 'an unreachable ledger stays an honest NOTE' );
	ok( o2.verdict.detail.includes( 'could not be reached to cross-attest' ), 'unreachable detail names the gap' );

	const contradicted = JSON.parse( JSON.stringify( recBlockOnly.json ) );
	contradicted.content_hash = 'f'.repeat( 64 );
	eq( core.STATE.FAIL, core.deriveBlockOnlyAnchor( anchor, evidence, { ok: true, status: 200, json: contradicted } ).verdict.state,
		'a PRESENT hash that contradicts is the one FAIL (contradiction, not gap)' );

	const upper = JSON.parse( JSON.stringify( recBlockOnly.json ) );
	upper.content_hash = upper.content_hash.toUpperCase();
	ok( core.deriveBlockOnlyAnchor( anchor, evidence, { ok: true, status: 200, json: upper } ).verdict.detail.includes( 'attests the same content hash' ),
		'hash comparison is case-normalized (9.73.2)' );

	const recTx = { ok: true, status: 200, json: fx( 'ledger-record-txid.json' ) };
	const o3 = core.deriveBlockOnlyAnchor( anchor, evidence, recTx );
	eq( TXID, o3.followTxid,
		'a ledger record carrying ots.bitcoin_txid + an attesting hash asks the caller to complete the triangle (9.74.1)' );
	ok( ! o3.verdict, 'the triangle case settles later, not here' );

	// Triangle completion.
	const t1 = core.deriveLedgerTxAnchor( anchor, o3.blockNote, { ok: true, status: 200, json: { confirmed: true, block_height: BLOCK } } );
	eq( core.STATE.PASS, t1.state, 'mempool confirming the ledger tx at the claimed block completes the triangle: PASS' );
	ok( t1.detail.includes( 'The ledger record supplies the aggregation transaction' ), 'triangle detail sentence' );
	eq( core.STATE.FAIL, core.deriveLedgerTxAnchor( anchor, o3.blockNote, { ok: true, status: 200, json: { confirmed: true, block_height: BLOCK - 7 } } ).state,
		'a block mismatch on the ledger-supplied tx is a contradiction: FAIL' );
	eq( core.STATE.FAIL, core.deriveLedgerTxAnchor( anchor, o3.blockNote, { ok: true, status: 200, json: { confirmed: false } } ).state,
		'an unconfirmed ledger-supplied tx cannot PASS' );
	const t2 = core.deriveLedgerTxAnchor( anchor, o3.blockNote, { ok: false, status: 0, json: null } );
	eq( core.STATE.NOTE, t2.state, 'an unreachable explorer stays an honest NOTE' );
	ok( t2.detail.includes( 'could not be cross-checked on the mempool explorer' ), 'unreachable-explorer detail' );
}

// ─── Group 9: txid anchor — 9.73.1 (ots.* nesting, schema mismatch) +
//     9.73.2 (per-field contradiction, case normalization) ───────────────
console.log( '\nGroup 9: deriveTxAnchor (9.73.1 — ots.bitcoin_txid nesting + schema-mismatch UNREACHABLE; 9.73.2 — per-field FAIL)' );
{
	const anchor = credTxid.evidence[ 0 ].anchor;
	const evidence = credTxid.evidence[ 0 ];
	const txOk = { ok: true, status: 200, json: { confirmed: true, block_height: BLOCK } };
	const recTx = { ok: true, status: 200, json: fx( 'ledger-record-txid.json' ) };

	const v1 = core.deriveTxAnchor( anchor, evidence, txOk, recTx );
	eq( core.STATE.PASS, v1.state,
		'the REAL record shape (txid under ots.*) fully ties the anchor: PASS (9.73.1 — pre-fix read top-level bitcoin_txid and found nothing)' );
	ok( v1.detail.includes( 'attested by the independent ledger record' ), 'full-tie detail sentence' );

	eq( core.STATE.PASS, core.deriveTxAnchor( anchor, evidence, txOk, { ok: true, status: 200, json: fx( 'ledger-record-legacy-flat.json' ) } ).state,
		'the legacy top-level bitcoin_txid fallback still ties (future flattening safe)' );

	const v2 = core.deriveTxAnchor( anchor, evidence, txOk, { ok: true, status: 200, json: fx( 'ledger-record-alien.json' ) } );
	eq( core.STATE.UNREACHABLE, v2.state,
		'a record with NO comparable fields is a schema mismatch: UNREACHABLE, never FAIL (9.73.1)' );
	ok( v2.detail.includes( 'schema mismatch' ), 'schema-mismatch detail names itself' );

	const wrongTx = JSON.parse( JSON.stringify( recTx.json ) );
	wrongTx.ots.bitcoin_txid = 'a'.repeat( 64 );
	eq( core.STATE.FAIL, core.deriveTxAnchor( anchor, evidence, txOk, { ok: true, status: 200, json: wrongTx } ).state,
		'a PRESENT txid that disagrees is a contradiction: FAIL' );

	const hashOnly = JSON.parse( JSON.stringify( recTx.json ) );
	delete hashOnly.ots.bitcoin_txid;
	const v3 = core.deriveTxAnchor( anchor, evidence, txOk, { ok: true, status: 200, json: hashOnly } );
	eq( core.STATE.NOTE, v3.state, 'an ABSENT txid with an attesting hash is partial attestation: NOTE, not FAIL (9.73.2 per-field)' );
	ok( v3.detail.includes( 'partially attests' ), 'partial-attestation detail sentence' );

	const upperTx = JSON.parse( JSON.stringify( recTx.json ) );
	upperTx.ots.bitcoin_txid = upperTx.ots.bitcoin_txid.toUpperCase();
	upperTx.content_hash = upperTx.content_hash.toUpperCase();
	eq( core.STATE.PASS, core.deriveTxAnchor( anchor, evidence, txOk, { ok: true, status: 200, json: upperTx } ).state,
		'txid + hash comparisons are case-normalized (9.73.2)' );

	eq( core.STATE.UNREACHABLE, core.deriveTxAnchor( anchor, evidence, { ok: false, status: 0, json: null }, recTx ).state,
		'an unreachable mempool explorer is UNREACHABLE, not a crypto failure' );
	eq( core.STATE.FAIL, core.deriveTxAnchor( anchor, evidence, { ok: true, status: 200, json: { confirmed: false } }, recTx ).state,
		'an unconfirmed transaction is FAIL' );
	eq( core.STATE.FAIL, core.deriveTxAnchor( anchor, evidence, { ok: true, status: 200, json: { confirmed: true, block_height: BLOCK + 1 } }, recTx ).state,
		'a confirmed tx at the WRONG block is FAIL' );
	eq( core.STATE.UNREACHABLE, core.deriveTxAnchor( anchor, evidence, txOk, { ok: false, status: 0, json: null } ).state,
		'Bitcoin confirms but the ledger is unreachable: UNREACHABLE cross-attest gap' );

	const anchorNoBlock = { ...anchor, block: 0 };
	eq( core.STATE.PASS, core.deriveTxAnchor( anchorNoBlock, evidence, { ok: true, status: 200, json: { confirmed: true, block_height: BLOCK } }, recTx ).state,
		'an anchor without a block claim accepts any confirmed block (no claim, no contradiction)' );
}

// ─── Group 10: paste-a-URL resolution — 9.75.0 ──────────────────────────
console.log( '\nGroup 10: resolveTwinRef + pastedTwinUrl (9.75.0 — paste-a-URL actually resolves)' );
{
	const base = 'https://juanlentino.com/verify';
	const r1 = core.resolveTwinRef( fx( 'twin-match.json' ), base );
	eq( '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f42', r1.uid, 'the twin\'s provenance.note_uid resolves directly (theme v10.45.0 emits it)' );
	eq( 0, r1.version, 'no version in the direct ref' );

	const r2 = core.resolveTwinRef( fx( 'twin-verify-url-only.json' ), base );
	eq( '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f42', r2.uid, 'an older twin without note_uid resolves via its verify_url query string' );
	eq( 2, r2.version, 'the verify_url &v= carries through' );

	eq( null, core.resolveTwinRef( fx( 'twin-no-ref.json' ), base ), 'a twin with no provenance ref resolves to null (specific copy, not a generic error)' );
	eq( null, core.resolveTwinRef( { provenance: { verify_url: 'https://' } }, base ), 'a malformed verify_url resolves to null, no throw' );

	const rUpper = core.resolveTwinRef( { provenance: { note_uid: '3F7C2A10-9D4E-4B6F-8A21-5E0C9B7D1F42' } }, base );
	eq( '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f42', rUpper.uid, 'uids lowercase on the way out' );

	eq( 'https://juanlentino.com/notes/a-note.json', core.pastedTwinUrl( 'https://juanlentino.com/notes/a-note/?utm=x#frag' ),
		'a pasted URL\'s twin address strips query + fragment and normalizes the trailing slash' );
	ok( core.UUID_SHAPE.test( '3F7C2A10-9D4E-4B6F-8A21-5E0C9B7D1F42' ), 'the uid shape accepts uppercase' );
	ok( ! core.UUID_SHAPE.test( '3f7c2a10-9d4e-4b6f-8a21-5e0c9b7d1f4' ), 'one hex digit short is not a uid' );
}

// ─── Group 11: run finish + status copy — 9.75.1 ────────────────────────
console.log( '\nGroup 11: shouldWriteDone + credentialFailureStatus (9.75.1 — the no-credential line survives the finish)' );
{
	eq( false, core.shouldWriteDone( 'failed' ), 'the failed sentinel suppresses the closing "Done." (9.75.1 — pre-fix it overwrote the specific error line)' );
	eq( true, core.shouldWriteDone( [ undefined, true, undefined, undefined ] ), 'a run that ran writes Done. (Promise.all result)' );
	eq( true, core.shouldWriteDone( undefined ), 'an undefined outcome writes Done.' );
	eq( 'No public credential exists for this Note.', core.credentialFailureStatus( 404 ), 'a 404 names the missing credential' );
	eq( 'Could not reach this site\'s credential endpoint.', core.credentialFailureStatus( 0 ), 'a network failure names the unreachable endpoint' );
	eq( 'Could not reach this site\'s credential endpoint.', core.credentialFailureStatus( 500 ), 'a server error is an unreachable endpoint, not a missing credential' );
}

// ─── Group 12: explorer link scheme guard — 9.75.0 ──────────────────────
console.log( '\nGroup 12: isSafeExplorerUrl (9.75.0 — no javascript: links from a poisoned credential)' );
{
	ok( core.isSafeExplorerUrl( 'https://mempool.space/tx/abc' ), 'https passes' );
	ok( core.isSafeExplorerUrl( 'HTTPS://MEMPOOL.SPACE/TX/ABC' ), 'scheme check is case-insensitive' );
	ok( core.isSafeExplorerUrl( 'http://example.com/x' ), 'http passes' );
	ok( ! core.isSafeExplorerUrl( 'javascript:alert(1)' ), 'javascript: is rejected' );
	// eslint-disable-next-line no-script-url
	ok( ! core.isSafeExplorerUrl( ' javascript:alert(1)' ), 'a leading space does not smuggle a scheme past the anchor' );
	ok( ! core.isSafeExplorerUrl( 'data:text/html,x' ), 'data: is rejected' );
}

// ─── Group 13: corrupted ledger/site keys — 9.81.0 (distinct KEY-CORRUPT
//     verdict, never an uncaught atob throw → generic UNREACHABLE noise) ──
console.log( '\nGroup 13: deriveKeyAgreement corrupt-key decodes (9.81.0 — a corrupt key is a verdict, not a throw)' );
{
	const did = fx( 'did.json' );
	const keys = fx( 'keys.json' );
	const corrupt = fx( 'keys-corrupt.json' );

	let threw = false;
	let r;
	try { r = core.deriveKeyAgreement( did, keys, corrupt ); } catch ( e ) { threw = true; }
	ok( ! threw, 'a corrupt LEDGER key never throws out of deriveKeyAgreement (pre-fix: bare atob threw)' );
	eq( core.STATE.FAIL, r.verdict.state, 'a corrupt ledger key settles as a verdict' );
	ok( r.verdict.detail.includes( 'Key corrupt' ) && r.verdict.detail.includes( 'ledger' ),
		'the verdict is the DISTINCT key-corrupt outcome naming the ledger copy (not generic unreachable noise)' );

	const r2 = core.deriveKeyAgreement( did, corrupt, keys );
	eq( core.STATE.FAIL, r2.verdict.state, 'a corrupt SITE-mirror key settles as a verdict too' );
	ok( r2.verdict.detail.includes( 'Key corrupt' ) && r2.verdict.detail.includes( 'site' ),
		'the site-mirror corrupt verdict names the site mirror' );

	const didCorrupt = JSON.parse( JSON.stringify( did ) );
	didCorrupt.verificationMethod[ 0 ].publicKeyJwk.x = '%%%not-base64url%%%';
	let threw3 = false;
	let r3;
	try { r3 = core.deriveKeyAgreement( didCorrupt, keys, keys ); } catch ( e ) { threw3 = true; }
	ok( ! threw3 && r3.verdict.state === core.STATE.FAIL && r3.verdict.detail.includes( 'did document' ),
		'a corrupt did-document key is its own key-corrupt FAIL, no throw' );

	const clean = core.deriveKeyAgreement( did, keys, keys );
	ok( ! clean.verdict && !! clean.jwk, 'well-formed keys still agree after the hardening (no behavior change on the happy path)' );
}

// ─── Group 14: diffWords — 9.81.0 version compare (pure word-level LCS) ──
console.log( '\nGroup 14: diffWords (9.81.0 — the /verify version-compare docket\'s pure diff)' );
{
	const same = core.diffWords( 'a b c', 'a b c' );
	eq( 1, same.length, 'identical texts collapse to one run' );
	eq( 'same', same[ 0 ].op, 'that run is same-op' );
	eq( 'a b c', same[ 0 ].text, 'consecutive same-op words join with single spaces' );

	const edit = core.diffWords( 'the quick brown fox', 'the slow brown fox jumps' );
	eq( JSON.stringify( [
		{ op: 'same', text: 'the' },
		{ op: 'del', text: 'quick' },
		{ op: 'add', text: 'slow' },
		{ op: 'same', text: 'brown fox' },
		{ op: 'add', text: 'jumps' }
	] ), JSON.stringify( edit ), 'a substitution + a tail addition diff word-by-word' );

	eq( 'add', core.diffWords( '', 'new words' )[ 0 ].op, 'an empty A side is all additions' );
	eq( 'del', core.diffWords( 'old words', '' )[ 0 ].op, 'an empty B side is all deletions' );
	eq( 0, core.diffWords( '', '' ).length, 'two empty sides diff to zero runs' );
	eq( 1, core.diffWords( 'a  b\n\nc', 'a b c' ).length, 'whitespace runs never fabricate a diff (split on all whitespace)' );

	// Fixture-backed: the real twin-match vs twin-edited content_text pair.
	const a = fx( 'twin-match.json' ).content_text;
	const b = fx( 'twin-edited.json' ).content_text;
	const runs = core.diffWords( a, b );
	ok( runs.some( ( r ) => 'del' === r.op ) || runs.some( ( r ) => 'add' === r.op ),
		'the edited twin fixture produces at least one changed run against the matching one' );
	ok( runs.filter( ( r ) => 'same' === r.op ).length > 0, 'unchanged words survive as same-op runs' );
	const reassembledB = runs.filter( ( r ) => 'del' !== r.op ).map( ( r ) => r.text ).join( ' ' );
	eq( b.split( /\s+/ ).filter( Boolean ).join( ' ' ), reassembledB,
		'dropping deletions reassembles side B exactly (the diff loses no words)' );
	const reassembledA = runs.filter( ( r ) => 'add' !== r.op ).map( ( r ) => r.text ).join( ' ' );
	eq( a.split( /\s+/ ).filter( Boolean ).join( ' ' ), reassembledA,
		'dropping additions reassembles side A exactly' );

	const big = new Array( core.DIFF_MAX_WORDS + 1 ).fill( 'w' ).join( ' ' );
	eq( null, core.diffWords( big, 'a' ), 'an over-cap side returns null (refuse honestly, never hang the page)' );
}


// ── Proof walk (v9.87.0): attestation → inclusion proof, honestly sourced ──
{
	const cred = { evidence: [ { anchor: { status: 'confirmed', txid: 'a1b2', block: 901000 }, contentHash: 'sha256:ABCD' } ] };
	const rec  = { content_hash: 'sha256:abcd', leaf_hash: 'leafbeef', ots: { bitcoin_txid: 'a1b2', bitcoin_block: 901000 } };
	const tx   = { confirmed: true, block_height: 901000 };
	const walk = core.deriveProofWalk( cred, rec, tx, 'https://mempool.space' );
	eq( 4, walk.length, 'proof walk: four steps' );
	eq( 'abcd', walk[0].value, 'content hash normalized (sha256: stripped, lowercased)' );
	ok( /site.*ledger|agree/i.test( walk[0].source ), 'content hash source states site+ledger agreement' );
	eq( 'leafbeef', walk[1].value, 'ledger leaf hash surfaces' );
	eq( 'a1b2', walk[2].value, 'txid surfaces' );
	eq( 'https://mempool.space/tx/a1b2', walk[2].href, 'tx links to the fixed-base explorer' );
	eq( 901000, walk[3].value, 'block height surfaces' );
	ok( /chain/i.test( walk[3].source ), 'confirmed tx marks the block step chain-attested' );
}
{
	// The block-only norm: most records carry no txid — never fabricate one.
	const cred = { evidence: [ { anchor: { status: 'confirmed', block: 900123 }, contentHash: 'sha256:abcd' } ] };
	const rec  = { content_hash: 'sha256:abcd', ots: { bitcoin_block: 900123 } };
	const walk = core.deriveProofWalk( cred, rec, null, 'https://mempool.space' );
	ok( /not extracted|block-only/i.test( walk[2].value ), 'block-only proof says so instead of faking a txid' );
	ok( ! walk[2].href, 'no explorer link without a txid' );
	ok( /not recorded/i.test( walk[1].value ), 'missing leaf hash is "not recorded", never blank' );
	eq( 900123, walk[3].value, 'block still surfaces from the ledger/credential' );
}
{
	// Hash disagreement is surfaced, never averaged away.
	const cred = { evidence: [ { anchor: { status: 'confirmed', txid: 't' }, contentHash: 'sha256:aaaa' } ] };
	const rec  = { content_hash: 'sha256:bbbb', ots: {} };
	const walk = core.deriveProofWalk( cred, rec, null, 'https://mempool.space' );
	ok( /disagree|mismatch/i.test( walk[0].source ), 'site-vs-ledger hash mismatch is flagged in the source label' );
}

{
	// v9.88.0 regression: the credential's REAL key is camelCase contentHash
	// (inc/provenance-credential.php). The v9.87.0 fixtures invented snake_case
	// and certified a shape production never emits — step 01 rendered blank.
	const cred = { evidence: [ { anchor: { status: 'confirmed', txid: 'x' }, contentHash: 'sha256:DEAD' } ] };
	const walk = core.deriveProofWalk( cred, { content_hash: 'sha256:dead' }, null, 'https://mempool.space' );
	eq( 'dead', walk[0].value, 'credential contentHash (camelCase) is read' );
	ok( /agree/i.test( walk[0].source ), 'credential and ledger agreement is detectable with the real key' );
}

// --- v10.49.0: the overall verdict the band states ------------------------
// The page-level answer is DERIVED from the four rows, never stored beside
// them. What these pin is the asymmetry that makes the answer honest: the
// signature and content hash can DISPROVE a credential, while live-match and
// anchor can only corroborate one — so a missing anchor must never read the
// same as a broken signature.
{
	const S = core.STATE;
	const all = ( v ) => ( { 'signature': v, 'content-hash': v, 'live-match': v, 'anchor': v } );

	eq( 'running', core.deriveOverallVerdict( all( S.PENDING ) ).level, 'all pending is a running verdict' );
	eq( 'running', core.deriveOverallVerdict( { ...all( S.PASS ), anchor: S.PENDING } ).level, 'one unsettled check keeps the whole verdict running' );
	eq( 'running', core.deriveOverallVerdict( {} ).level, 'an empty state map is running, not a pass' );

	const passing = core.deriveOverallVerdict( all( S.PASS ) );
	eq( 'pass', passing.level, 'four PASS is an unqualified pass' );
	eq( 'Authentic', passing.word, 'a full pass reads "Authentic"' );
	eq( 0, passing.caveats.length, 'a full pass carries no caveats' );

	// The live case this owner's own notes hit: 3 PASS + a block-only anchor.
	const qualified = core.deriveOverallVerdict( { ...all( S.PASS ), anchor: S.NOTE } );
	eq( 'qualified', qualified.level, 'core checks passing with a NOTE anchor is qualified, not failed' );
	eq( 'Authentic', qualified.word, 'incomplete corroboration does not retract the word Authentic' );
	ok( /Bitcoin anchor/.test( qualified.line ), 'the qualified line names the check that fell short' );
	eq( 1, qualified.caveats.length, 'exactly the non-passing check is a caveat' );

	// A broken signature is a different KIND of answer from a missing one.
	const failed = core.deriveOverallVerdict( { ...all( S.PASS ), 'signature': S.FAIL } );
	eq( 'fail', failed.level, 'a FAIL signature fails the whole verdict' );
	eq( 'Not authentic', failed.word, 'a core FAIL reads "Not authentic"' );
	eq( 'fail', core.deriveOverallVerdict( { ...all( S.PASS ), 'content-hash': S.FAIL } ).level, 'a FAIL content hash fails the whole verdict too' );

	// ── Retraction dominates ────────────────────────────────────────────────
	// A retraction says the PUBLISHER withdrew this record: it asserted
	// something false. Every cryptographic check can still pass — the bytes are
	// intact and the signature is genuine; what is wrong is what they SAY. So a
	// retraction is not a fifth check to be averaged in with the others. It
	// dominates, or the docket would tell a reader "Authentic" about a record
	// its own publisher has disavowed.
	const retracted = core.deriveOverallVerdict( all( S.PASS ), { retraction: { retracted_at: '2026-08-15', what_was_wrong: 'the anchored hash was computed over the wrong bytes' } } );
	eq( 'retracted', retracted.level, 'a retraction overrides an otherwise fully passing docket' );
	ok( ! /Authentic/.test( retracted.word + ' ' + retracted.line ),
		'and the reader is never told "Authentic" about a withdrawn record' );
	ok( /withdrawn|retracted/i.test( retracted.line ), 'the line says the record was withdrawn, in words' );

	// It dominates a FAILING docket too: "retracted" is the more informative
	// answer, and it is the publisher's own statement rather than an inference.
	eq( 'retracted', core.deriveOverallVerdict( { ...all( S.PASS ), 'signature': S.FAIL }, { retraction: { retracted_at: '2026-08-15' } } ).level,
		'a retraction also dominates a failing check' );

	// Absent is the NORMAL case and must change nothing.
	eq( 'pass', core.deriveOverallVerdict( all( S.PASS ), null ).level,
		'no retraction leaves the verdict exactly as it was' );

	// ── Unknown withdrawal status ───────────────────────────────────────────
	// If we could not find out whether this record was withdrawn, saying nothing
	// is the one thing we must not do: an attacker who can block ONE fetch would
	// then hide a retraction behind a clean "Authentic". Fail toward uncertainty,
	// never toward confidence.
	const unsure = core.deriveOverallVerdict( all( S.PASS ), { retraction: null, unknown: true } );
	ok( 'pass' !== unsure.level, 'an unconfirmable withdrawal status is never a clean pass' );
	eq( 'qualified', unsure.level, 'it qualifies the verdict rather than failing it — a missing answer, not a failed one' );
	ok( /withdraw/i.test( unsure.line ), 'and the line tells the reader WHAT could not be confirmed' );

	// It must not masquerade as a retraction either: "we could not check" is not
	// "this was withdrawn".
	ok( 'retracted' !== unsure.level, 'not knowing is never reported as retracted' );

	// A verified retraction still dominates even when the status is also murky.
	eq( 'retracted', core.deriveOverallVerdict( all( S.PASS ), { retraction: { retracted_at: '2026-08-15' }, unknown: true } ).level,
		'a verified retraction outranks the uncertainty' );

	eq( 'pass', core.deriveOverallVerdict( all( S.PASS ), { retraction: null, unknown: false } ).level,
		'a confirmed NOT-retracted (404) leaves the verdict clean' );

	const unproven = core.deriveOverallVerdict( { ...all( S.PASS ), 'signature': S.UNREACHABLE } );
	eq( 'unproven', unproven.level, 'an unrunnable core check is unproven, never a fail' );
	eq( 'Not proven', unproven.word, 'a missing answer is worded as missing' );
	ok( /not a failed one|missing answer/i.test( unproven.line ), 'the unproven line says so in words, not colour alone' );

	// A non-core check can never escalate past qualified, however it lands.
	eq( 'qualified', core.deriveOverallVerdict( { ...all( S.PASS ), 'live-match': S.UNREACHABLE } ).level, 'an unreachable live-match only qualifies the verdict' );
	eq( 'qualified', core.deriveOverallVerdict( { ...all( S.PASS ), 'live-match': S.FAIL } ).level, 'even a FAIL live-match cannot make a signed, intact Note inauthentic' );

	// Caveat prose stays readable as the list grows.
	const two = core.deriveOverallVerdict( { ...all( S.PASS ), 'live-match': S.NOTE, anchor: S.NOTE } );
	ok( / and /.test( two.line ), 'two caveats join with "and"' );
	const three = core.deriveOverallVerdict( { 'signature': S.PASS, 'content-hash': S.PASS, 'live-match': S.NOTE, anchor: S.UNREACHABLE } );
	eq( 2, three.caveats.length, 'caveats count only the non-passing checks' );
	ok( ! /undefined/.test( three.line ), 'no undefined leaks into the reader-facing line' );
}

console.log( `\nResult: ${pass} passed, ${fail} failed.` );
process.exit( fail > 0 ? 1 : 0 );
