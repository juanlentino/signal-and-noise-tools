/**
 * Signal & Noise — Notes provenance verifier, PURE decision core.
 *
 * Extracted VERBATIM from assets/js/prov-verify.js (v9.79.2) so the five
 * same-day hotfixes' decision paths (9.73.1 → 9.75.1) are executable under
 * Node fixtures (tests/js/prov-verify-core.test.mjs) instead of only
 * string-grepped. Everything here is side-effect-free: text normalization,
 * payload-vs-twin comparison, ledger record interpretation, anchor-state
 * derivation, and verdict assembly. No DOM, no fetch, no WebCrypto — the
 * page file (assets/js/prov-verify.js) keeps all orchestration and rendering
 * and consumes this file's single global.
 *
 * Classic script, NOT an ES module: an IIFE assigning window.SNProvVerifyCore
 * (guarded so environments without window don't throw) plus a CommonJS export
 * guard so Node can require() the same file unmodified.
 *
 * A "verdict" is { state, detail }: `state` is one of STATE.*, `detail` the
 * plain-language sentence the page renders under the check. Functions that
 * may either settle or ask the caller to fetch more return { verdict } or a
 * follow-up instruction — the caller branches on which key is present.
 *
 * @since 9.79.2
 */
( function ( factory ) {
	'use strict';
	var api = factory();
	if ( typeof window !== 'undefined' ) {
		window.SNProvVerifyCore = api;
	}
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
} )( function () {
	'use strict';

	/** The four check states. PASS/FAIL/UNREACHABLE are rendered as both text
	 *  and an icon (never color-only); NOTE is the informational, non-failing
	 *  outcome used by the live-match check and a still-pending anchor. */
	var STATE = {
		PENDING:     'pending',
		PASS:        'PASS',
		FAIL:        'FAIL',
		UNREACHABLE: 'UNREACHABLE',
		NOTE:        'NOTE'
	};

	/** The uid shape both the paste form and the ?note= prefill accept. */
	var UUID_SHAPE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

	/** Standard base64 (NOT base64url) -> bytes. proof.signedPayloadB64 and
	 *  proof.proofValue are both standard base64 (see inc/provenance-credential.php:
	 *  base64_encode($canonical) for the payload; the signature travels the
	 *  same base64 convention end to end — the worker's X-SN-Ed25519 header and
	 *  the stored commit['signature'] are both base64, never base64url or hex). */
	function base64ToBytes( b64 ) {
		var bin = atob( String( b64 || '' ) );
		var bytes = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			bytes[ i ] = bin.charCodeAt( i );
		}
		return bytes;
	}

	/** base64url (JWK 'x') -> bytes: restore the standard alphabet + padding. */
	function base64urlToBytes( b64url ) {
		var s = String( b64url || '' ).replace( /-/g, '+' ).replace( /_/g, '/' );
		while ( s.length % 4 ) {
			s += '=';
		}
		return base64ToBytes( s );
	}

	function bytesToHex( bytes ) {
		var out = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			out += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
		}
		return out;
	}

	function bytesEqual( a, b ) {
		if ( ! a || ! b || a.length !== b.length ) {
			return false;
		}
		for ( var i = 0; i < a.length; i++ ) {
			if ( a[ i ] !== b[ i ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Strip a Note's live text down to the same comparison surface the signed
	 * payload's `content` field carries: markup removed, entities decoded,
	 * whitespace collapsed. This is a best-effort, browser-side approximation
	 * of the plugin's sn-normalize-v1 algorithm (inc/provenance-core.php) —
	 * good enough to say "unchanged" vs "edited"; it is NOT re-derived as the
	 * cryptographic input (the signature check verifies the signed bytes
	 * exactly as fetched, never this approximation). Regex-only — the raw
	 * text is never assigned into the DOM, so nothing here is ever parsed or
	 * executed as markup.
	 */
	function roughNormalize( raw ) {
		var s = String( raw || '' );
		s = s.replace( /<!--\s*\/?wp:[\s\S]*?-->/g, '' );
		s = s.replace( /<[^>]*>/g, '' );
		s = s
			.replace( /&#(\d+);/g, function ( m, dec ) { return String.fromCodePoint( parseInt( dec, 10 ) ); } )
			.replace( /&#x([0-9a-f]+);/gi, function ( m, hex ) { return String.fromCodePoint( parseInt( hex, 16 ) ); } )
			.replace( /&nbsp;/g, ' ' )
			.replace( /&quot;/g, '"' )
			.replace( /&#39;|&apos;/g, "'" )
			.replace( /&lt;/g, '<' )
			.replace( /&gt;/g, '>' )
			.replace( /&amp;/g, '&' ); // decode &amp; last so it never re-triggers the entities above.
		if ( 'function' === typeof s.normalize ) {
			s = s.normalize( 'NFC' );
		}
		s = s.replace( /\r\n?/g, '\n' );
		s = s
			.split( '\n' )
			.map( function ( line ) { return line.replace( /[ \t ]+/g, ' ' ).trim(); } )
			.join( '\n' );
		// Final collapse: ALL whitespace including newlines. The canonical
		// payload keeps its paragraph structure (35 lines) while the twin's
		// content_text is the document flattened to ONE line — line-preserving
		// comparison can never match them. "Same words in the same order" is
		// the honest match semantic; paragraph breaks carry no provenance.
		s = s.replace( /\s+/g, ' ' ).trim();
		return s;
	}

	/** The status line for a failed credential fetch. Specific copy — the
	 *  only context a dead run leaves besides the four UNREACHABLE stamps. */
	function credentialFailureStatus( status ) {
		return 404 === status ? 'No public credential exists for this Note.' : 'Could not reach this site\'s credential endpoint.';
	}

	/** v9.75.1: only a run that actually ran may write the closing "Done." —
	 *  the 'failed' sentinel keeps the specific error status line on screen. */
	function shouldWriteDone( outcome ) {
		return 'failed' !== outcome;
	}

	/**
	 * Signature key cross-check across all three origins: the did document's
	 * JWK against this site's own key mirror AND the independent ledger copy.
	 * Returns { jwk } when every published copy agrees (the caller verifies
	 * with it), or { verdict } with the FAIL naming which pair disagrees.
	 */
	function deriveKeyAgreement( didDoc, siteKeys, ledgerKeys ) {
		var vm = didDoc && didDoc.verificationMethod && didDoc.verificationMethod[ 0 ];
		var jwk = vm && vm.publicKeyJwk;
		if ( ! jwk || ! jwk.x ) {
			return { verdict: { state: STATE.FAIL, detail: 'No public key is published at the did document — nothing to verify against.' } };
		}
		// Every decode below is wrapped: a corrupted published key must be a
		// DISTINCT key-corrupt verdict, never an uncaught atob throw that the
		// caller can only render as generic UNREACHABLE noise.
		var didKeyBytes;
		try {
			didKeyBytes = base64urlToBytes( jwk.x );
		} catch ( e ) {
			return { verdict: { state: STATE.FAIL, detail: 'Key corrupt: the did document\'s published key cannot be decoded.' } };
		}

		var siteKeyB64   = siteKeys && siteKeys.keys && siteKeys.keys[ 0 ] && siteKeys.keys[ 0 ].public_key_base64;
		var ledgerKeyB64 = ledgerKeys && ledgerKeys.keys && ledgerKeys.keys[ 0 ] && ledgerKeys.keys[ 0 ].public_key_base64;
		if ( siteKeyB64 ) {
			var siteKeyBytes;
			try {
				siteKeyBytes = base64ToBytes( siteKeyB64 );
			} catch ( e2 ) {
				return { verdict: { state: STATE.FAIL, detail: 'Key corrupt: this site\'s own key mirror copy cannot be decoded.' } };
			}
			if ( ! bytesEqual( didKeyBytes, siteKeyBytes ) ) {
				return { verdict: { state: STATE.FAIL, detail: 'Key mismatch: the did document and this site\'s own key mirror disagree.' } };
			}
		}
		if ( ledgerKeyB64 ) {
			var ledgerKeyBytes;
			try {
				ledgerKeyBytes = base64ToBytes( ledgerKeyB64 );
			} catch ( e3 ) {
				return { verdict: { state: STATE.FAIL, detail: 'Key corrupt: the independent ledger copy of the key cannot be decoded.' } };
			}
			if ( ! bytesEqual( didKeyBytes, ledgerKeyBytes ) ) {
				return { verdict: { state: STATE.FAIL, detail: 'Key mismatch: the did document and the independent ledger copy of the key disagree.' } };
			}
		}
		return { jwk: jwk };
	}

	/**
	 * Decode the proof's payload + signature bytes for the signature check.
	 * Malformed base64 must be a verdict, not an uncaught throw that strands
	 * the docket mid-run — this page's whole job is judging possibly-bad data.
	 */
	function decodeProofBytes( cred ) {
		var proof = ( cred && cred.proof ) || {};
		try {
			return {
				payloadBytes: base64ToBytes( proof.signedPayloadB64 ),
				sigBytes:     base64ToBytes( proof.proofValue )
			};
		} catch ( e ) {
			return { malformed: true, verdict: { state: STATE.FAIL, detail: 'This credential is malformed and cannot be decoded.' } };
		}
	}

	/**
	 * Decode ONLY the signed payload bytes for the content-hash check. A
	 * synchronous throw here would abort the whole Promise.all argument
	 * list in runVerification (checks 03 and 04 never even start) — decode
	 * failure is a FAIL verdict for THIS check, nothing more. Kept separate
	 * from decodeProofBytes: a corrupt proofValue must not fail the
	 * content-hash check, whose input is the payload alone.
	 */
	function decodeSignedPayloadBytes( cred ) {
		var proof = ( cred && cred.proof ) || {};
		try {
			return { payloadBytes: base64ToBytes( proof.signedPayloadB64 ) };
		} catch ( e ) {
			return { malformed: true, verdict: { state: STATE.FAIL, detail: 'This credential is malformed and cannot be decoded.' } };
		}
	}

	function deriveSignatureVerdict( valid ) {
		return {
			state:  valid ? STATE.PASS : STATE.FAIL,
			detail: valid
				? 'The Ed25519 signature matches the published key.'
				: 'The signature does not match the published key — this credential cannot be trusted as-is.'
		};
	}

	/** The credential's claimed content hash, 'sha256:' prefix stripped, lowercased. */
	function claimedContentHash( cred ) {
		var evidence = ( cred && cred.evidence && cred.evidence[ 0 ] ) || {};
		return String( evidence.contentHash || '' ).replace( /^sha256:/, '' ).toLowerCase();
	}

	function deriveContentHashVerdict( actualHex, claimed ) {
		var match = !! claimed && actualHex === claimed;
		return {
			state:  match ? STATE.PASS : STATE.FAIL,
			detail: match
				? 'The signed content hashes to exactly the value this credential claims.'
				: 'The signed payload does not hash to the value this credential claims.'
		};
	}

	/** The live twin URL for the credential's subject, or '' when the
	 *  credential carries no live URL to compare against. */
	function liveMatchTwinUrl( cred ) {
		var subject = ( cred && cred.credentialSubject ) || {};
		var url = String( subject.url || '' );
		if ( ! url ) {
			return '';
		}
		return url.replace( /\/?$/, '' ) + '.json';
	}

	/**
	 * Live-match verdict: never a FAIL. A mismatch just means the Note has
	 * moved on since this version was signed — a newer, also-signed version
	 * may already exist, so this is informational, not a failure of the
	 * credential in hand.
	 */
	function deriveLiveMatchVerdict( cred, twinJson ) {
		var evidence = ( cred.evidence && cred.evidence[ 0 ] ) || {};
		var signedContent = '';
		var decodeFailed = false;
		try {
			var payload = JSON.parse( atob( String( ( cred.proof && cred.proof.signedPayloadB64 ) || '' ) ) );
			signedContent = String( payload.content || '' );
		} catch ( e ) {
			decodeFailed = true;
		}
		if ( decodeFailed || ! signedContent ) {
			// An undecodable payload is NOT evidence of an edit — asserting
			// "edited since signing" here would claim something never established.
			return { state: STATE.NOTE, detail: 'The signed payload could not be decoded for comparison — no edit claim either way.' };
		}
		// The theme's .json twin schema carries content_text / content_html —
		// there is NO bare `content` field (reading one made this check report
		// "edited since signing" on every Note, always; caught live 2026-07-21).
		var liveRaw = ( twinJson && ( twinJson.content_text || twinJson.content || '' ) ) || '';
		var liveNormalized = roughNormalize( liveRaw );
		var matches = liveNormalized === roughNormalize( signedContent );
		// A full match is the good outcome this check exists to report — stamp
		// it PASS. Only the mismatch stays NOTE (honest post-edit dating, still
		// never a FAIL: a newer signed version may already exist).
		return {
			state:  matches ? STATE.PASS : STATE.NOTE,
			detail: matches
				? 'This matches the currently published content.'
				: 'Content edited since signing — this credential proves version ' + ( evidence.version || '?' ) + ' as of ' + ( cred.validFrom || 'its signing date' ) + '. A newer signed version may already exist.'
		};
	}

	/**
	 * Anchor plan: settle the fetch-free verdicts (pending, unconfirmed), or
	 * tell the caller which fetch path to walk. 'block-only' is the NORMAL
	 * shape for most Notes: OTS block-anchored, with no aggregation
	 * transaction id extracted.
	 */
	function deriveAnchorPlan( cred ) {
		var evidence = ( cred && cred.evidence && cred.evidence[ 0 ] ) || {};
		var anchor = evidence.anchor || {};
		if ( 'pending' === anchor.status ) {
			return { verdict: { state: STATE.NOTE, detail: 'Awaiting Bitcoin confirmation — not a failure, just not yet on-chain.' } };
		}
		if ( 'confirmed' !== anchor.status || ( ! anchor.txid && ! anchor.block ) ) {
			return { verdict: { state: STATE.FAIL, detail: 'This credential does not carry a confirmed Bitcoin anchor.' } };
		}
		return { anchor: anchor, evidence: evidence, mode: anchor.txid ? 'txid' : 'block-only' };
	}

	/** Real ledger record path: notes/<uid>/v<n>.json (never a flat file). */
	function ledgerRecordUrl( ledgerBase, uid, version, evidence ) {
		return String( ledgerBase || '' ).replace( /\/?$/, '' ) + '/notes/' + encodeURIComponent( uid ) + '/v' + encodeURIComponent( version || ( evidence && evidence.version ) || 0 ) + '.json';
	}

	/** The ledger's key copy lives at keys/provenance-keys.json — NOT
	 *  keys/keys.json (the v9.73.1 live catch). */
	function ledgerKeysUrl( ledgerBase ) {
		return String( ledgerBase || '' ).replace( /\/?$/, '' ) + '/keys/provenance-keys.json';
	}

	function mempoolTxStatusUrl( mempoolBase, txid ) {
		return String( mempoolBase || '' ).replace( /\/?$/, '' ) + '/tx/' + encodeURIComponent( txid ) + '/status';
	}

	/**
	 * Block-only (no-txid) anchor: interpret the ledger record. There is no
	 * tx to look up on the mempool explorer — a missing cross-check, never a
	 * contradiction (the same principle as the ledger schema guard in
	 * deriveTxAnchor). The ledger record can still cross-attest the content
	 * hash, so do that instead. Returns { verdict }, or { followTxid,
	 * blockNote } when the ledger supplies the aggregation transaction id the
	 * credential's own chain data predates AND its hash attests this content
	 * — the caller then completes the triangle via deriveLedgerTxAnchor.
	 */
	function deriveBlockOnlyAnchor( anchor, evidence, ledgerRes ) {
		var blockNote = 'Block-anchored via OpenTimestamps at block ' + anchor.block + '; no aggregation transaction id was extracted, so an independent mempool cross-check is not possible for this proof.';
		var rec2      = ( ledgerRes.ok && ledgerRes.json ) || {};
		var recHash2  = String( rec2.content_hash || '' ).replace( /^sha256:/, '' ).toLowerCase();
		var credHash2 = String( evidence.contentHash || '' ).replace( /^sha256:/, '' ).toLowerCase();
		if ( ! ledgerRes.ok ) {
			return { verdict: { state: STATE.NOTE, detail: blockNote + ' The independent ledger record could not be reached to cross-attest the content hash.' } };
		}
		if ( recHash2 && credHash2 && recHash2 !== credHash2 ) {
			return { verdict: { state: STATE.FAIL, detail: 'The independent ledger record contradicts this credential\'s content hash.' } };
		}
		// The ledger may carry the aggregation txid the credential's own
		// chain data predates. When it does AND its hash attests this
		// content, complete the triangle: mempool must confirm the
		// ledger-supplied tx at the very block this credential claims.
		var ledgerTxid = String( ( rec2.ots && rec2.ots.bitcoin_txid ) || '' ).toLowerCase();
		if ( ledgerTxid && recHash2 && recHash2 === credHash2 ) {
			return { followTxid: ledgerTxid, blockNote: blockNote };
		}
		return { verdict: { state: STATE.NOTE, detail: blockNote + ( recHash2 && recHash2 === credHash2 ? ' The independent ledger record attests the same content hash.' : '' ) } };
	}

	/** Triangle completion: the ledger-supplied tx must confirm at the very
	 *  block this credential claims before the anchor may PASS. */
	function deriveLedgerTxAnchor( anchor, blockNote, txRes2 ) {
		if ( ! txRes2.ok ) {
			return { state: STATE.NOTE, detail: blockNote + ' The independent ledger record attests the same content hash (its transaction could not be cross-checked on the mempool explorer).' };
		}
		var confirmed2 = !! ( txRes2.json && txRes2.json.confirmed );
		var blockOk2 = confirmed2 && txRes2.json.block_height === anchor.block;
		if ( blockOk2 ) {
			return { state: STATE.PASS, detail: 'The ledger record supplies the aggregation transaction; Bitcoin confirms it at block ' + anchor.block + ', and the ledger attests the same content hash (an attestation, not a cryptographic inclusion proof).' };
		}
		return { state: STATE.FAIL, detail: 'The ledger-supplied transaction does not confirm at the block this credential claims.' };
	}

	/** Txid-carrying anchor: mempool confirmation + ledger cross-attestation. */
	function deriveTxAnchor( anchor, evidence, txRes, ledgerRes ) {
		if ( ! txRes.ok ) {
			return { state: STATE.UNREACHABLE, detail: 'Could not reach the mempool explorer to confirm this anchor.' };
		}
		var confirmed = !! ( txRes.json && txRes.json.confirmed );
		var blockOk = ! anchor.block || ( txRes.json && txRes.json.block_height === anchor.block );
		if ( ! confirmed || ! blockOk ) {
			return { state: STATE.FAIL, detail: 'The Bitcoin transaction does not confirm the block this credential claims.' };
		}
		if ( ! ledgerRes.ok ) {
			return { state: STATE.UNREACHABLE, detail: 'Bitcoin confirms the anchor, but the independent ledger record could not be reached to cross-attest it.' };
		}
		// Real ledger record shape (notes/<uid>/v<n>.json): content_hash at the
		// top level, the txid nested under ots. Keep the legacy top-level txid
		// as a fallback so a future flattening doesn't break the check.
		var rec        = ledgerRes.json || {};
		var anchorTxid = String( anchor.txid || '' ).toLowerCase();
		var recTxid    = String( ( rec.ots && rec.ots.bitcoin_txid ) || rec.bitcoin_txid || '' ).toLowerCase();
		var recHash    = String( rec.content_hash || '' ).replace( /^sha256:/, '' ).toLowerCase();
		var credHash   = String( evidence.contentHash || '' ).replace( /^sha256:/, '' ).toLowerCase();
		if ( ! recTxid && ! recHash ) {
			// A record that carries no comparable fields is a schema mismatch,
			// not a contradiction — never render it as FAIL.
			return { state: STATE.UNREACHABLE, detail: 'Bitcoin confirms the anchor, but the ledger record carries no comparable fields to cross-attest it (schema mismatch).' };
		}
		// A PRESENT field that disagrees is a contradiction; an ABSENT field
		// is a gap. Only contradictions may FAIL.
		if ( ( recTxid && recTxid !== anchorTxid ) || ( recHash && credHash && recHash !== credHash ) ) {
			return { state: STATE.FAIL, detail: 'Bitcoin confirms a transaction, but the independent ledger record does not tie it to this same content.' };
		}
		var ledgerTiesIt = recTxid === anchorTxid && '' !== recHash && recHash === credHash;
		return {
			state:  ledgerTiesIt ? STATE.PASS : STATE.NOTE,
			detail: ledgerTiesIt
				? 'Confirmed on Bitcoin and attested by the independent ledger record (this is an attestation, not a cryptographic inclusion proof).'
				: 'Confirmed on Bitcoin; the ledger record partially attests it (one field is absent from the record) with no contradiction found.'
		};
	}

	/** A pasted same-origin page URL's twin address: fragment/query stripped,
	 *  trailing slash normalized, '.json' appended. */
	function pastedTwinUrl( href ) {
		return String( href || '' ).replace( /[#?].*$/, '' ).replace( /\/?$/, '' ) + '.json';
	}

	/**
	 * Resolve a fetched twin to { uid, version }, or null when the twin
	 * carries no note id. The exact key the theme's content twin uses for its
	 * provenance ref isn't pinned across repos, so probe the plausible shapes.
	 */
	function resolveTwinRef( twinJson, baseHref ) {
		var j = twinJson || {};
		var uid =
			( j.provenance && j.provenance.note_uid ) ||
			j.note_uid ||
			( j.provenance && j.provenance.uid ) ||
			'';
		var version = 0;
		if ( ! uid && j.provenance && j.provenance.verify_url ) {
			// The one provenance field every deployed twin DOES carry is its own
			// verify_url ("/verify?note=<uid>&v=<n>") — read the uid back out of
			// it, so pasting a Note URL works against twins that predate the
			// theme emitting note_uid directly.
			try {
				var vu = new URL( j.provenance.verify_url, baseHref );
				uid = vu.searchParams.get( 'note' ) || '';
				version = parseInt( vu.searchParams.get( 'v' ) || '0', 10 ) || 0;
			} catch ( e ) {
				uid = '';
			}
		}
		if ( ! uid ) {
			return null;
		}
		return { uid: String( uid ).toLowerCase(), version: version };
	}

	/** Above this many words per side the LCS table is not worth building —
	 *  the caller renders a "too large to diff" note instead. */
	var DIFF_MAX_WORDS = 4000;

	/**
	 * Word-level diff of two content_text payloads (the /verify version-compare
	 * docket). PURE: split both sides on whitespace, LCS over the word arrays,
	 * emit runs of { op: 'same'|'del'|'add', text } with consecutive same-op
	 * words joined by single spaces. Returns null when either side exceeds
	 * DIFF_MAX_WORDS (quadratic table — refuse honestly rather than hang the
	 * page). The output is DATA ONLY — the UI asset builds DOM via
	 * createElement/textContent, never markup from these strings.
	 */
	function diffWords( aText, bText ) {
		var a = String( aText || '' ).split( /\s+/ ).filter( function ( w ) { return '' !== w; } );
		var b = String( bText || '' ).split( /\s+/ ).filter( function ( w ) { return '' !== w; } );
		if ( a.length > DIFF_MAX_WORDS || b.length > DIFF_MAX_WORDS ) {
			return null;
		}
		// LCS length table (single allocation, (a+1)x(b+1)).
		var w = b.length + 1;
		var table = new Array( ( a.length + 1 ) * w );
		var i, j;
		for ( j = 0; j <= b.length; j++ ) {
			table[ j ] = 0;
		}
		for ( i = 1; i <= a.length; i++ ) {
			table[ i * w ] = 0;
			for ( j = 1; j <= b.length; j++ ) {
				table[ i * w + j ] = a[ i - 1 ] === b[ j - 1 ]
					? table[ ( i - 1 ) * w + ( j - 1 ) ] + 1
					: Math.max( table[ ( i - 1 ) * w + j ], table[ i * w + ( j - 1 ) ] );
			}
		}
		// Backtrack into reversed op list.
		var ops = [];
		i = a.length;
		j = b.length;
		while ( i > 0 && j > 0 ) {
			if ( a[ i - 1 ] === b[ j - 1 ] ) {
				ops.push( { op: 'same', text: a[ i - 1 ] } );
				i--; j--;
			} else if ( table[ ( i - 1 ) * w + j ] > table[ i * w + ( j - 1 ) ] ) {
				// Strict >: on a tie take the add branch first, so a plain
				// substitution reads del-then-add after the final reverse.
				ops.push( { op: 'del', text: a[ i - 1 ] } );
				i--;
			} else {
				ops.push( { op: 'add', text: b[ j - 1 ] } );
				j--;
			}
		}
		while ( i > 0 ) { ops.push( { op: 'del', text: a[ i - 1 ] } ); i--; }
		while ( j > 0 ) { ops.push( { op: 'add', text: b[ j - 1 ] } ); j--; }
		ops.reverse();
		// Merge consecutive same-op words into runs.
		var runs = [];
		ops.forEach( function ( o ) {
			var last = runs[ runs.length - 1 ];
			if ( last && last.op === o.op ) {
				last.text += ' ' + o.text;
			} else {
				runs.push( { op: o.op, text: o.text } );
			}
		} );
		return runs;
	}

	/** The one raw URL sink in an otherwise textContent-only renderer:
	 *  http(s) only, so a poisoned credential can't plant a javascript: link. */
	function isSafeExplorerUrl( url ) {
		return /^https?:\/\//i.test( String( url ) );
	}

	return {
		STATE:                    STATE,
		UUID_SHAPE:               UUID_SHAPE,
		base64ToBytes:            base64ToBytes,
		base64urlToBytes:         base64urlToBytes,
		bytesToHex:               bytesToHex,
		bytesEqual:               bytesEqual,
		roughNormalize:           roughNormalize,
		credentialFailureStatus:  credentialFailureStatus,
		shouldWriteDone:          shouldWriteDone,
		deriveKeyAgreement:       deriveKeyAgreement,
		decodeProofBytes:         decodeProofBytes,
		decodeSignedPayloadBytes: decodeSignedPayloadBytes,
		deriveSignatureVerdict:   deriveSignatureVerdict,
		claimedContentHash:       claimedContentHash,
		deriveContentHashVerdict: deriveContentHashVerdict,
		liveMatchTwinUrl:         liveMatchTwinUrl,
		deriveLiveMatchVerdict:   deriveLiveMatchVerdict,
		deriveAnchorPlan:         deriveAnchorPlan,
		ledgerRecordUrl:          ledgerRecordUrl,
		ledgerKeysUrl:            ledgerKeysUrl,
		mempoolTxStatusUrl:       mempoolTxStatusUrl,
		deriveBlockOnlyAnchor:    deriveBlockOnlyAnchor,
		deriveLedgerTxAnchor:     deriveLedgerTxAnchor,
		deriveTxAnchor:           deriveTxAnchor,
		DIFF_MAX_WORDS:           DIFF_MAX_WORDS,
		diffWords:                diffWords,
		pastedTwinUrl:            pastedTwinUrl,
		resolveTwinRef:           resolveTwinRef,
		isSafeExplorerUrl:        isSafeExplorerUrl
	};
} );
