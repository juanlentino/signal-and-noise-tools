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
 * Since v9.79.2 every side-effect-free decision (normalization, twin
 * comparison, ledger record interpretation, anchor derivation, verdict
 * assembly) lives in assets/js/prov-verify-core.js — the SNProvVerifyCore
 * global this file consumes, loaded first by inc/provenance-verify.php and
 * exercised under Node fixtures (tests/js/prov-verify-core.test.mjs). This
 * file keeps everything environmental: DOM, fetch, WebCrypto, orchestration.
 *
 * @since 9.73.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var root = document.querySelector( '.sn-verify' );
	if ( ! root ) {
		return;
	}

	/** The pure decision core (assets/js/prov-verify-core.js), loaded before
	 *  this file by the page shell. Without it no verdict can be derived —
	 *  but a bare return here left four perpetual "pending" stamps and zero
	 *  feedback (a cached/blocked core script looked like a hung run). Paint
	 *  a VISIBLE could-not-load state instead, then stop. */
	var Core = window.SNProvVerifyCore;
	if ( ! Core ) {
		var failLine = root.querySelector( '[data-role="status-line"]' );
		if ( failLine ) {
			failLine.textContent = 'Could not load the verifier — reload the page.';
		}
		var pendingChecks = root.querySelectorAll( '.sn-verify-check' );
		Array.prototype.forEach.call( pendingChecks, function ( li ) {
			li.setAttribute( 'data-state', 'UNREACHABLE' );
			var st = li.querySelector( '[data-role="state"]' );
			if ( st ) {
				st.textContent = '⚠ UNREACHABLE';
			}
			var dt = li.querySelector( '[data-role="detail"]' );
			if ( dt ) {
				dt.textContent = 'The verifier script did not load, so this check could not run.';
			}
		} );
		return;
	}

	/** Per-fetch budget: a slow/blocked origin degrades to UNREACHABLE, never a silent hang. */
	var FETCH_TIMEOUT_MS = 8000;

	/** The four check states (see the core's STATE contract). */
	var STATE = Core.STATE;

	var ICON = {
		pending:     '…',
		PASS:        '✓',
		FAIL:        '✕',
		UNREACHABLE: '⚠',
		NOTE:        'ℹ'
	};

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

	/** Paint a { state, detail } verdict the core derived onto a check row. */
	function setVerdict( key, verdict ) {
		setCheck( key, verdict.state, verdict.detail );
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
		if ( Core.UUID_SHAPE.test( raw ) ) {
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
			return Promise.resolve( { handled: true } );
		}
		var twinUrl = Core.pastedTwinUrl( url.href );
		return fetchJSON( twinUrl ).then( function ( res ) {
			if ( ! res.ok || ! res.json ) {
				setStatusLine( 'That page has no public credential twin — open the note and use its own Verify link.' );
				return { handled: true };
			}
			var ref = Core.resolveTwinRef( res.json, location.href );
			if ( ! ref ) {
				setStatusLine( 'That page\'s public twin carries no note id — open the note and use its own Verify link.' );
				return { handled: true };
			}
			return ref;
		} );
	}

	/** Signature check: cross-check the key across all three origins, then verify. */
	function checkSignature( cred, didDoc, siteKeys, ledgerKeys ) {
		return ed25519Supported().then( function ( supported ) {
			if ( ! supported ) {
				setCheck( 'signature', STATE.NOTE, 'This browser does not support Ed25519 verification — showing the credential\'s facts and links below instead of a pass/fail verdict.' );
				return null; // caller renders the fallback facts panel.
			}
			var agreement = Core.deriveKeyAgreement( didDoc, siteKeys, ledgerKeys );
			if ( agreement.verdict ) {
				setVerdict( 'signature', agreement.verdict );
				return false;
			}

			var decoded = Core.decodeProofBytes( cred );
			if ( decoded.malformed ) {
				setVerdict( 'signature', decoded.verdict );
				return false;
			}

			return window.crypto.subtle
				.importKey( 'jwk', agreement.jwk, { name: 'Ed25519' }, false, [ 'verify' ] )
				.then( function ( key ) {
					return window.crypto.subtle.verify( 'Ed25519', key, decoded.sigBytes, decoded.payloadBytes );
				} )
				.then( function ( valid ) {
					setVerdict( 'signature', Core.deriveSignatureVerdict( valid ) );
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
		var decoded = Core.decodeSignedPayloadBytes( cred );
		if ( decoded.malformed ) {
			setVerdict( 'content-hash', decoded.verdict );
			return Promise.resolve( false );
		}

		return window.crypto.subtle.digest( 'SHA-256', decoded.payloadBytes ).then( function ( digest ) {
			var actual = Core.bytesToHex( new Uint8Array( digest ) );
			var verdict = Core.deriveContentHashVerdict( actual, Core.claimedContentHash( cred ) );
			setVerdict( 'content-hash', verdict );
			return STATE.PASS === verdict.state;
		} );
	}

	/**
	 * Live-match check: never a FAIL (the core's deriveLiveMatchVerdict holds
	 * the semantic). This wrapper only fetches the twin and paints.
	 */
	function checkLiveMatch( cred ) {
		var twinUrl = Core.liveMatchTwinUrl( cred );
		if ( ! twinUrl ) {
			setCheck( 'live-match', STATE.UNREACHABLE, 'This credential does not carry a live URL to compare against.' );
			return Promise.resolve();
		}
		// Same origin pin resolvePasted() enforces: the credential's claimed
		// live URL is attacker-influenceable data, so a foreign origin is
		// never fetched — skipping the comparison, not probing another host.
		var twinOrigin = '';
		try {
			twinOrigin = new URL( twinUrl, location.href ).origin;
		} catch ( e ) {
			twinOrigin = '';
		}
		if ( twinOrigin !== location.origin ) {
			setCheck( 'live-match', STATE.NOTE, 'This credential\'s live URL is not on this site — skipping the live comparison rather than fetching a foreign origin.' );
			return Promise.resolve();
		}
		return fetchJSON( twinUrl ).then( function ( res ) {
			if ( ! res.ok ) {
				setCheck( 'live-match', STATE.UNREACHABLE, 'Could not reach the live version of this note to compare.' );
				return;
			}
			setVerdict( 'live-match', Core.deriveLiveMatchVerdict( cred, res.json ) );
		} );
	}


	/**
	 * Proof walk (v9.87.0): render Core.deriveProofWalk() steps into the
	 * docket. Values via textContent (ledger/chain data is untrusted);
	 * hrefs only from the fixed explorer base the core builds. Section
	 * stays hidden until steps exist.
	 */
	function renderProofWalk( cred, ledgerRes, txRes ) {
		var section = document.querySelector( '[data-role="walk"]' );
		var list    = document.querySelector( '[data-role="walk-steps"]' );
		if ( ! section || ! list ) {
			return;
		}
		var steps = Core.deriveProofWalk( cred, ledgerRes, txRes, config.mempoolBase );
		list.textContent = '';
		steps.forEach( function ( step, i ) {
			var li = document.createElement( 'li' );
			li.className = 'sn-verify-walk-step';
			var no = document.createElement( 'span' );
			no.className = 'sn-verify-walk-no';
			no.setAttribute( 'aria-hidden', 'true' );
			no.textContent = ( i < 9 ? '0' : '' ) + ( i + 1 );
			var label = document.createElement( 'span' );
			label.className = 'sn-verify-walk-label';
			label.textContent = step.label;
			var value = document.createElement( 'code' );
			value.className = 'sn-verify-walk-value';
			if ( step.href ) {
				var a = document.createElement( 'a' );
				a.href = step.href;
				a.rel = 'noopener';
				a.target = '_blank';
				a.textContent = String( step.value );
				value.appendChild( a );
			} else {
				value.textContent = String( step.value );
			}
			var source = document.createElement( 'span' );
			source.className = 'sn-verify-walk-source';
			source.textContent = step.source;
			li.appendChild( no );
			li.appendChild( label );
			li.appendChild( value );
			li.appendChild( source );
			list.appendChild( li );
		} );
		section.hidden = false;
	}

	/** Anchor check: the Bitcoin confirmation, cross-attested against the independent ledger record. */
	function checkAnchor( cred, uid, version ) {
		var plan = Core.deriveAnchorPlan( cred );
		if ( plan.verdict ) {
			setVerdict( 'anchor', plan.verdict );
			return Promise.resolve();
		}
		var anchor   = plan.anchor;
		var evidence = plan.evidence;

		if ( 'block-only' === plan.mode ) {
			var ledgerOnlyUrl = Core.ledgerRecordUrl( config.ledgerBase, uid, version, evidence );
			return fetchJSON( ledgerOnlyUrl ).then( function ( ledgerRes ) {
				var outcome = Core.deriveBlockOnlyAnchor( anchor, evidence, ledgerRes );
				if ( outcome.verdict ) {
					setVerdict( 'anchor', outcome.verdict );
					renderProofWalk( cred, ledgerRes.ok && ledgerRes.json, null );
					return;
				}
				var ledgerTxUrl = Core.mempoolTxStatusUrl( config.mempoolBase, outcome.followTxid );
				return fetchJSON( ledgerTxUrl ).then( function ( txRes2 ) {
					setVerdict( 'anchor', Core.deriveLedgerTxAnchor( anchor, outcome.blockNote, txRes2 ) );
					renderProofWalk( cred, ledgerRes.ok && ledgerRes.json, txRes2.ok && txRes2.json );
				} );
			} );
		}

		var txStatusUrl = Core.mempoolTxStatusUrl( config.mempoolBase, anchor.txid );
		var ledgerUrl   = Core.ledgerRecordUrl( config.ledgerBase, uid, version, evidence );

		return Promise.all( [ fetchJSON( txStatusUrl ), fetchJSON( ledgerUrl ) ] ).then( function ( results ) {
			setVerdict( 'anchor', Core.deriveTxAnchor( anchor, evidence, results[ 0 ], results[ 1 ] ) );
			renderProofWalk( cred, results[ 1 ].ok && results[ 1 ].json, results[ 0 ].ok && results[ 0 ].json );
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
		// The scheme guard lives in the core (isSafeExplorerUrl): http(s) only.
		if ( anchor.explorer && Core.isSafeExplorerUrl( anchor.explorer ) ) {
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

	/** A run that dies before (or while) the checks resolve must not leave any
	 *  check blinking "pending" forever — settle every still-pending check to
	 *  UNREACHABLE so the docket reads as finished, honestly. */
	function settlePendingChecks( detail ) {
		[ 'signature', 'content-hash', 'live-match', 'anchor' ].forEach( function ( key ) {
			var li = root.querySelector( '.sn-verify-check[data-check="' + key + '"]' );
			if ( li && STATE.PENDING === li.getAttribute( 'data-state' ) ) {
				setCheck( key, STATE.UNREACHABLE, detail );
			}
		} );
	}

	/** Orchestrate the whole run for a resolved { uid, version }. */
	function runVerification( uid, version ) {
		resetChecks();
		setStatusLine( 'Fetching the credential…' );

		var credUrl = config.credentialBase.replace( /\/?$/, '' ) + '/' + encodeURIComponent( uid ) + ( version ? '?v=' + encodeURIComponent( version ) : '' );

		fetchJSON( credUrl ).then( function ( credRes ) {
			if ( ! credRes.ok ) {
				setStatusLine( Core.credentialFailureStatus( credRes.status ) );
				settlePendingChecks( 'Could not run — no credential to check.' );
				return 'failed'; // keep the specific status line — no "Done." overwrite
			}
			var cred = credRes.json;
			setStatusLine( 'Checking…' );

			var ledgerKeysUrl = Core.ledgerKeysUrl( config.ledgerBase );
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
		} ).then( function ( outcome ) {
			if ( Core.shouldWriteDone( outcome ) ) {
				setStatusLine( 'Done.' );
			}
		} ).catch( function () {
			setStatusLine( 'Something went wrong while running these checks.' );
			settlePendingChecks( 'This check could not be completed.' );
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( evt ) {
			evt.preventDefault();
			var value = input ? input.value : '';
			resolvePasted( value ).then( function ( resolved ) {
				if ( resolved && resolved.uid ) {
					runVerification( resolved.uid, resolved.version );
				} else if ( ! resolved && value.trim() ) {
					// resolved === {handled:true} means a specific status line was
					// already set — don't clobber it with the generic one.
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
