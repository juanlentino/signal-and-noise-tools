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
	ok( v2.detail.startsWith( 'Content edited since signing — this credential proves version 1 as of ' ),
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

console.log( `\nResult: ${pass} passed, ${fail} failed.` );
process.exit( fail > 0 ? 1 : 0 );
