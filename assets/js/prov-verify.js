/**
 * Signal & Noise — Notes provenance verifier (/verify), v9.73.0.
 *
 * Vanilla ES, zero dependencies, self-hosted. Every endpoint is read from the
 * page root's data attributes (assets/css/prov-verify.css's sibling PHP,
 * inc/provenance-verify.php) — nothing here is hardcoded, so the same file
 * works unmodified on any environment (production, staging, a fork).
 *
 * Trust model: verification runs entirely in the reader's own browser. Three
 * independent origins triangulate: this site (credential, did document, key
 * mirror), the git ledger repo's raw-content mirror (per-Note records + its
 * own keys/ copy), and a public mempool explorer (independent Bitcoin anchor
 * confirmation). The key mirror this site itself serves is same-origin, so
 * it only proves internal consistency; the ledger's copy is the independent
 * key check.
 *
 * Four checks, each: pending -> PASS / FAIL / UNREACHABLE (never conflated —
 * a network timeout is not a cryptographic failure, and is reported as such):
 *   1. Signature   — Ed25519 verify over the signed payload.
 *   2. Content hash — SHA-256 of that same payload.
 *   3. Live match   — informational only; a mismatch is never a FAIL, since
 *                      a newer chain version may legitimately exist.
 *   4. Anchor       — the Bitcoin confirmation, cross-attested by the ledger.
 *
 * @since 9.73.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	/** Per-fetch budget: a slow/blocked origin degrades to UNREACHABLE, never a silent hang. */
	var FETCH_TIMEOUT_MS = 8000;

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

	var ICON = {
		pending:     '…',
		PASS:        '✓',
		FAIL:        '✕',
		UNREACHABLE: '⚠',
		NOTE:        'ℹ'
	};

	var root = document.querySelector( '.sn-verify' );
	if ( ! root ) {
		return;
	}

	var config = {
		credentialBase: root.getAttribute( 'data-credential-base' ) || '',
		didUrl:         root.getAttribute( 'data-did-url' ) || '',
		keysUrl:        root.getAttribute( 'data-keys-url' ) || '',
		ledgerBase:     root.getAttribute( 'data-ledger-base' ) || '',
		mempoolBase:    root.getAttribute( 'data-mempool-base' ) || '',
		note:           root.getAttribute( 'data-note' ) || '',
		version:        parseInt( root.getAttribute( 'data-version' ) || '0', 10 ) || 0
	};

	var announceEl = root.querySelector( '[data-role="announce"]' );
	var statusLine = root.querySelector( '[data-role="status-line"]' );
	var factsEl    = root.querySelector( '[data-role="facts"]' );
	var form       = root.querySelector( '[data-role="paste-form"]' );
	var input      = document.getElementById( 'sn-verify-input' );

	/**
	 * Screen-reader + visible progress line, one polite region for the whole
	 * run. Announcements are COALESCED: four checks settle near-simultaneously,
	 * and each write to a live region cancels the previous read, so rapid
	 * successive calls collapse into one joined message after a quiet moment —
	 * the reader hears every verdict, not just the last write.
	 */
	var announceQueue = [];
	var announceTimer = null;
	function announce( text ) {
		if ( ! announceEl ) {
			return;
		}
		announceQueue.push( text );
		if ( announceTimer ) {
			window.clearTimeout( announceTimer );
		}
		announceTimer = window.setTimeout( function () {
			announceEl.textContent = announceQueue.join( ' ' );
			announceQueue = [];
			announceTimer = null;
		}, 400 );
	}

	function setStatusLine( text ) {
		if ( statusLine ) {
			statusLine.textContent = text;
		}
	}

	/**
	 * Paint one of the four checks. `state` is one of STATE.*; `detail` is the
	 * plain-language sentence shown under it (never color alone — the state
	 * word and an icon both change).
	 */
	function setCheck( key, state, detail ) {
		var li = root.querySelector( '.sn-verify-check[data-check="' + key + '"]' );
		if ( ! li ) {
			return;
		}
		li.setAttribute( 'data-state', state );
		var stateEl = li.querySelector( '[data-role="state"]' );
		if ( stateEl ) {
			stateEl.textContent = ( ICON[ state ] || '' ) + ' ' + state;
		}
		var detailEl = li.querySelector( '[data-role="detail"]' );
		if ( detailEl && undefined !== detail ) {
			detailEl.textContent = detail;
		}
		// Initial pending states are visual scaffolding, not verdicts — announcing
		// them just pads the coalesced screen-reader message with noise.
		if ( STATE.PENDING !== state ) {
			announce( detail || state );
		}
	}

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
	 * fetch() with an AbortController timeout so a stalled/blocked origin
	 * (e.g. an un-updated CSP not yet allowing the ledger + mempool hosts)
	 * degrades to an explicit UNREACHABLE outcome instead of hanging forever.
	 * Resolves { ok, status, json } — never rejects; the caller reads `ok`.
	 */
	function fetchJSON( url ) {
		if ( ! url ) {
			return Promise.resolve( { ok: false, status: 0, json: null, timedOut: false } );
		}
		var controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
		var timer = controller ? setTimeout( function () { controller.abort(); }, FETCH_TIMEOUT_MS ) : null;
		return fetch( url, { signal: controller ? controller.signal : undefined, credentials: 'omit' } )
			.then( function ( res ) {
				if ( timer ) {
					clearTimeout( timer );
				}
				if ( ! res.ok ) {
					return { ok: false, status: res.status, json: null, timedOut: false };
				}
				return res.json().then(
					function ( json ) { return { ok: true, status: res.status, json: json, timedOut: false }; },
					function () { return { ok: false, status: res.status, json: null, timedOut: false }; }
				);
			} )
			.catch( function ( err ) {
				if ( timer ) {
					clearTimeout( timer );
				}
				var timedOut = !! ( controller && controller.signal && controller.signal.aborted ) || 'AbortError' === ( err && err.name );
				return { ok: false, status: 0, json: null, timedOut: timedOut };
			} );
	}

	/** Feature-detect Ed25519 in SubtleCrypto. Never throws; resolves a bool. */
	function ed25519Supported() {
		if ( ! window.crypto || ! window.crypto.subtle || ! window.crypto.subtle.importKey ) {
			return Promise.resolve( false );
		}
		var probeKey = { kty: 'OKP', crv: 'Ed25519', x: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' };
		return window.crypto.subtle
			.importKey( 'jwk', probeKey, { name: 'Ed25519' }, false, [ 'verify' ] )
			.then( function () { return true; }, function () { return false; } );
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

	/**
	 * Resolve a pasted value to { uid, version }. A bare id is used as-is
	 * (already same-origin by construction — it only ever addresses this
	 * site's own credential endpoint). A pasted link is only followed when it
	 * resolves, relative to the current page, to THIS SAME origin — a foreign
	 * link is rejected outright rather than fetched, so paste mode can never
	 * be turned into a fetch-anything-you-paste probe of another host.
	 */
	function resolvePasted( value ) {
		var raw = String( value || '' ).trim();
		if ( ! raw ) {
			return Promise.resolve( null );
		}
		var uuidShape = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
		if ( uuidShape.test( raw ) ) {
			return Promise.resolve( { uid: raw.toLowerCase(), version: 0 } );
		}
		var url;
		try {
			url = new URL( raw, location.href );
		} catch ( e ) {
			return Promise.resolve( null );
		}
		if ( url.origin !== location.origin ) {
			setStatusLine( 'Only links to this site are supported here — paste the note id instead, or open the note and use its own Verify link.' );
			return Promise.resolve( null );
		}
		var twinUrl = url.href.replace( /[#?].*$/, '' ).replace( /\/?$/, '' ) + '.json';
		return fetchJSON( twinUrl ).then( function ( res ) {
			if ( ! res.ok || ! res.json ) {
				return null;
			}
			// The exact key the theme's content twin uses for its provenance
			// ref isn't pinned across repos, so probe the plausible shapes.
			var j = res.json;
			var uid =
				( j.provenance && j.provenance.note_uid ) ||
				j.note_uid ||
				( j.provenance && j.provenance.uid ) ||
				'';
			return uid ? { uid: String( uid ).toLowerCase(), version: 0 } : null;
		} );
	}

	/** Signature check: cross-check the key across all three origins, then verify. */
	function checkSignature( cred, didDoc, siteKeys, ledgerKeys ) {
		return ed25519Supported().then( function ( supported ) {
			if ( ! supported ) {
				setCheck( 'signature', STATE.NOTE, 'This browser does not support Ed25519 verification — showing the credential\'s facts and links below instead of a pass/fail verdict.' );
				return null; // caller renders the fallback facts panel.
			}
			var vm = didDoc && didDoc.verificationMethod && didDoc.verificationMethod[ 0 ];
			var jwk = vm && vm.publicKeyJwk;
			if ( ! jwk || ! jwk.x ) {
				setCheck( 'signature', STATE.FAIL, 'No public key is published at the did document — nothing to verify against.' );
				return false;
			}
			var didKeyBytes = base64urlToBytes( jwk.x );

			var siteKeyB64   = siteKeys && siteKeys.keys && siteKeys.keys[ 0 ] && siteKeys.keys[ 0 ].public_key_base64;
			var ledgerKeyB64 = ledgerKeys && ledgerKeys.keys && ledgerKeys.keys[ 0 ] && ledgerKeys.keys[ 0 ].public_key_base64;
			if ( siteKeyB64 && ! bytesEqual( didKeyBytes, base64ToBytes( siteKeyB64 ) ) ) {
				setCheck( 'signature', STATE.FAIL, 'Key mismatch: the did document and this site\'s own key mirror disagree.' );
				return false;
			}
			if ( ledgerKeyB64 && ! bytesEqual( didKeyBytes, base64ToBytes( ledgerKeyB64 ) ) ) {
				setCheck( 'signature', STATE.FAIL, 'Key mismatch: the did document and the independent ledger copy of the key disagree.' );
				return false;
			}

			var proof = cred.proof || {};
			var payloadBytes = base64ToBytes( proof.signedPayloadB64 );
			var sigBytes      = base64ToBytes( proof.proofValue );

			return window.crypto.subtle
				.importKey( 'jwk', jwk, { name: 'Ed25519' }, false, [ 'verify' ] )
				.then( function ( key ) {
					return window.crypto.subtle.verify( 'Ed25519', key, sigBytes, payloadBytes );
				} )
				.then( function ( valid ) {
					setCheck(
						'signature',
						valid ? STATE.PASS : STATE.FAIL,
						valid
							? 'The Ed25519 signature matches the published key.'
							: 'The signature does not match the published key — this credential cannot be trusted as-is.'
					);
					return valid;
				} )
				.catch( function () {
					setCheck( 'signature', STATE.FAIL, 'The signature could not be verified.' );
					return false;
				} );
		} );
	}

	/** Content-hash check: SHA-256 of the signed payload bytes vs the credential's claim. */
	function checkContentHash( cred ) {
		if ( ! window.crypto || ! window.crypto.subtle || ! window.crypto.subtle.digest ) {
			setCheck( 'content-hash', STATE.UNREACHABLE, 'This browser has no SHA-256 support to run this check with.' );
			return Promise.resolve( false );
		}
		var proof = cred.proof || {};
		var payloadBytes = base64ToBytes( proof.signedPayloadB64 );
		var evidence = ( cred.evidence && cred.evidence[ 0 ] ) || {};
		var claimed = String( evidence.contentHash || '' ).replace( /^sha256:/, '' ).toLowerCase();

		return window.crypto.subtle.digest( 'SHA-256', payloadBytes ).then( function ( digest ) {
			var actual = bytesToHex( new Uint8Array( digest ) );
			var match = !! claimed && actual === claimed;
			setCheck(
				'content-hash',
				match ? STATE.PASS : STATE.FAIL,
				match
					? 'The signed content hashes to exactly the value this credential claims.'
					: 'The signed payload does not hash to the value this credential claims.'
			);
			return match;
		} );
	}

	/**
	 * Live-match check: never a FAIL. A mismatch just means the Note has
	 * moved on since this version was signed — a newer, also-signed version
	 * may already exist, so this is informational, not a failure of the
	 * credential in hand.
	 */
	function checkLiveMatch( cred ) {
		var subject = cred.credentialSubject || {};
		var url = String( subject.url || '' );
		if ( ! url ) {
			setCheck( 'live-match', STATE.UNREACHABLE, 'This credential does not carry a live URL to compare against.' );
			return Promise.resolve();
		}
		var twinUrl = url.replace( /\/?$/, '' ) + '.json';
		return fetchJSON( twinUrl ).then( function ( res ) {
			if ( ! res.ok ) {
				setCheck( 'live-match', STATE.UNREACHABLE, 'Could not reach the live version of this note to compare.' );
				return;
			}
			var evidence = ( cred.evidence && cred.evidence[ 0 ] ) || {};
			var signedContent = '';
			try {
				var payload = JSON.parse( atob( String( ( cred.proof && cred.proof.signedPayloadB64 ) || '' ) ) );
				signedContent = String( payload.content || '' );
			} catch ( e ) {
				signedContent = '';
			}
			// The theme's .json twin schema carries content_text / content_html —
		// there is NO bare `content` field (reading one made this check report
		// "edited since signing" on every Note, always; caught live 2026-07-21).
		var liveRaw = ( res.json && ( res.json.content_text || res.json.content || '' ) ) || '';
			var liveNormalized = roughNormalize( liveRaw );
			var matches = !! signedContent && liveNormalized === roughNormalize( signedContent );
			setCheck(
				'live-match',
				STATE.NOTE,
				matches
					? 'This matches the currently published content.'
					: 'Content edited since signing — this credential proves version ' + ( evidence.version || '?' ) + ' as of ' + ( cred.validFrom || 'its signing date' ) + '. A newer signed version may already exist.'
			);
		} );
	}

	/** Anchor check: the Bitcoin confirmation, cross-attested against the independent ledger record. */
	function checkAnchor( cred, uid, version ) {
		var evidence = ( cred.evidence && cred.evidence[ 0 ] ) || {};
		var anchor = evidence.anchor || {};
		if ( 'pending' === anchor.status ) {
			setCheck( 'anchor', STATE.NOTE, 'Awaiting Bitcoin confirmation — not a failure, just not yet on-chain.' );
			return Promise.resolve();
		}
		if ( 'confirmed' !== anchor.status || ( ! anchor.txid && ! anchor.block ) ) {
			setCheck( 'anchor', STATE.FAIL, 'This credential does not carry a confirmed Bitcoin anchor.' );
			return Promise.resolve();
		}
		if ( ! anchor.txid ) {
			// The NORMAL shape for most Notes: OTS block-anchored, with no
			// aggregation transaction id extracted. There is no tx to look up on
			// the mempool explorer — a missing cross-check, never a contradiction
			// (the same principle as the ledger schema guard below). The ledger
			// record can still cross-attest the content hash, so do that instead.
			var ledgerOnlyUrl = config.ledgerBase.replace( /\/?$/, '' ) + '/notes/' + encodeURIComponent( uid ) + '/v' + encodeURIComponent( version || evidence.version || 0 ) + '.json';
			return fetchJSON( ledgerOnlyUrl ).then( function ( ledgerRes ) {
				var blockNote = 'Block-anchored via OpenTimestamps at block ' + anchor.block + '; no aggregation transaction id was extracted, so an independent mempool cross-check is not possible for this proof.';
				var rec2      = ( ledgerRes.ok && ledgerRes.json ) || {};
				var recHash2  = String( rec2.content_hash || '' ).replace( /^sha256:/, '' ).toLowerCase();
				var credHash2 = String( evidence.contentHash || '' ).replace( /^sha256:/, '' ).toLowerCase();
				if ( ! ledgerRes.ok ) {
					setCheck( 'anchor', STATE.NOTE, blockNote + ' The independent ledger record could not be reached to cross-attest the content hash.' );
					return;
				}
				if ( recHash2 && credHash2 && recHash2 !== credHash2 ) {
					setCheck( 'anchor', STATE.FAIL, 'The independent ledger record contradicts this credential\'s content hash.' );
					return;
				}
				// The ledger may carry the aggregation txid the credential's own
				// chain data predates. When it does AND its hash attests this
				// content, complete the triangle: mempool must confirm the
				// ledger-supplied tx at the very block this credential claims.
				var ledgerTxid = String( ( rec2.ots && rec2.ots.bitcoin_txid ) || '' ).toLowerCase();
				if ( ledgerTxid && recHash2 && recHash2 === credHash2 ) {
					var ledgerTxUrl = config.mempoolBase.replace( /\/?$/, '' ) + '/tx/' + encodeURIComponent( ledgerTxid ) + '/status';
					return fetchJSON( ledgerTxUrl ).then( function ( txRes2 ) {
						if ( ! txRes2.ok ) {
							setCheck( 'anchor', STATE.NOTE, blockNote + ' The independent ledger record attests the same content hash (its transaction could not be cross-checked on the mempool explorer).' );
							return;
						}
						var confirmed2 = !! ( txRes2.json && txRes2.json.confirmed );
						var blockOk2 = confirmed2 && txRes2.json.block_height === anchor.block;
						if ( blockOk2 ) {
							setCheck( 'anchor', STATE.PASS, 'The ledger record supplies the aggregation transaction; Bitcoin confirms it at block ' + anchor.block + ', and the ledger attests the same content hash (an attestation, not a cryptographic inclusion proof).' );
							return;
						}
						setCheck( 'anchor', STATE.FAIL, 'The ledger-supplied transaction does not confirm at the block this credential claims.' );
					} );
				}
				setCheck( 'anchor', STATE.NOTE, blockNote + ( recHash2 && recHash2 === credHash2 ? ' The independent ledger record attests the same content hash.' : '' ) );
			} );
		}

		var txStatusUrl = config.mempoolBase.replace( /\/?$/, '' ) + '/tx/' + encodeURIComponent( anchor.txid ) + '/status';
		var ledgerUrl = config.ledgerBase.replace( /\/?$/, '' ) + '/notes/' + encodeURIComponent( uid ) + '/v' + encodeURIComponent( version || evidence.version || 0 ) + '.json';

		return Promise.all( [ fetchJSON( txStatusUrl ), fetchJSON( ledgerUrl ) ] ).then( function ( results ) {
			var txRes = results[ 0 ];
			var ledgerRes = results[ 1 ];

			if ( ! txRes.ok ) {
				setCheck( 'anchor', STATE.UNREACHABLE, 'Could not reach the mempool explorer to confirm this anchor.' );
				return;
			}
			var confirmed = !! ( txRes.json && txRes.json.confirmed );
			var blockOk = ! anchor.block || ( txRes.json && txRes.json.block_height === anchor.block );
			if ( ! confirmed || ! blockOk ) {
				setCheck( 'anchor', STATE.FAIL, 'The Bitcoin transaction does not confirm the block this credential claims.' );
				return;
			}
			if ( ! ledgerRes.ok ) {
				setCheck( 'anchor', STATE.UNREACHABLE, 'Bitcoin confirms the anchor, but the independent ledger record could not be reached to cross-attest it.' );
				return;
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
				setCheck( 'anchor', STATE.UNREACHABLE, 'Bitcoin confirms the anchor, but the ledger record carries no comparable fields to cross-attest it (schema mismatch).' );
				return;
			}
			// A PRESENT field that disagrees is a contradiction; an ABSENT field
			// is a gap. Only contradictions may FAIL.
			if ( ( recTxid && recTxid !== anchorTxid ) || ( recHash && credHash && recHash !== credHash ) ) {
				setCheck( 'anchor', STATE.FAIL, 'Bitcoin confirms a transaction, but the independent ledger record does not tie it to this same content.' );
				return;
			}
			var ledgerTiesIt = recTxid === anchorTxid && '' !== recHash && recHash === credHash;
			setCheck(
				'anchor',
				ledgerTiesIt ? STATE.PASS : STATE.NOTE,
				ledgerTiesIt
					? 'Confirmed on Bitcoin and attested by the independent ledger record (this is an attestation, not a cryptographic inclusion proof).'
					: 'Confirmed on Bitcoin; the ledger record partially attests it (one field is absent from the record) with no contradiction found.'
			);
		} );
	}

	/** Unsupported-crypto fallback: the credential's own facts + links, no fake verdicts. */
	function renderFallbackFacts( cred ) {
		if ( ! factsEl ) {
			return;
		}
		var evidence = ( cred.evidence && cred.evidence[ 0 ] ) || {};
		var anchor = evidence.anchor || {};
		factsEl.hidden = false;
		factsEl.textContent = '';

		var lines = [
			'Issuer: ' + ( cred.issuer || 'unknown' ),
			'Signed: ' + ( cred.validFrom || 'unknown date' ),
			'Content hash: ' + ( evidence.contentHash || 'unknown' ),
			'Anchor status: ' + ( anchor.status || 'unknown' )
		];
		lines.forEach( function ( line ) {
			var p = document.createElement( 'p' );
			p.textContent = line;
			factsEl.appendChild( p );
		} );
		if ( anchor.explorer ) {
			var a = document.createElement( 'a' );
			a.href = anchor.explorer;
			a.rel = 'nofollow noopener';
			a.target = '_blank';
			a.textContent = 'View the anchor on the public Bitcoin ledger';
			factsEl.appendChild( a );
		}
	}

	function resetChecks() {
		[ 'signature', 'content-hash', 'live-match', 'anchor' ].forEach( function ( key ) {
			setCheck( key, STATE.PENDING, '' );
		} );
		if ( factsEl ) {
			factsEl.hidden = true;
			factsEl.textContent = '';
		}
	}

	/** Orchestrate the whole run for a resolved { uid, version }. */
	function runVerification( uid, version ) {
		resetChecks();
		setStatusLine( 'Fetching the credential…' );

		var credUrl = config.credentialBase.replace( /\/?$/, '' ) + '/' + encodeURIComponent( uid ) + ( version ? '?v=' + encodeURIComponent( version ) : '' );

		fetchJSON( credUrl ).then( function ( credRes ) {
			if ( ! credRes.ok ) {
				setStatusLine( 404 === credRes.status ? 'No public credential exists for this Note.' : 'Could not reach this site\'s credential endpoint.' );
				return null;
			}
			var cred = credRes.json;
			setStatusLine( 'Checking…' );

			var ledgerKeysUrl = config.ledgerBase.replace( /\/?$/, '' ) + '/keys/provenance-keys.json';
			return Promise.all( [ fetchJSON( config.didUrl ), fetchJSON( config.keysUrl ), fetchJSON( ledgerKeysUrl ) ] ).then( function ( results ) {
				var didRes = results[ 0 ];
				var siteKeysRes = results[ 1 ];
				var ledgerKeysRes = results[ 2 ];

				if ( ! didRes.ok ) {
					setCheck( 'signature', STATE.UNREACHABLE, 'Could not reach this site\'s did document.' );
				}
				if ( ! ledgerKeysRes.ok ) {
					announce( 'Could not reach the independent ledger key copy — signature verification continues without that cross-check.' );
				}

				var signatureDone = didRes.ok
					? checkSignature( cred, didRes.json, siteKeysRes.json, ledgerKeysRes.json ).then( function ( ok ) {
							// null = Ed25519 unsupported, false = crypto FAIL: both
							// promise the facts/links panel, so both must render it.
							if ( ! ok ) {
								renderFallbackFacts( cred );
							}
					  } )
					: Promise.resolve();

				var effectiveVersion = version || ( ( cred.evidence && cred.evidence[ 0 ] && cred.evidence[ 0 ].version ) || 0 );

				return Promise.all( [ signatureDone, checkContentHash( cred ), checkLiveMatch( cred ), checkAnchor( cred, uid, effectiveVersion ) ] );
			} );
		} ).then( function () {
			setStatusLine( 'Done.' );
		} ).catch( function () {
			setStatusLine( 'Something went wrong while running these checks.' );
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( evt ) {
			evt.preventDefault();
			var value = input ? input.value : '';
			resolvePasted( value ).then( function ( resolved ) {
				if ( resolved ) {
					runVerification( resolved.uid, resolved.version );
				} else if ( value.trim() ) {
					setStatusLine( 'That does not look like a note id or a link on this site.' );
				}
			} );
		} );
	}

	if ( config.note ) {
		if ( input ) {
			input.value = config.note;
		}
		runVerification( config.note, config.version );
	}
} )();
