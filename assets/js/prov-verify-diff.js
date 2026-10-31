/**
 * Signal & Noise — /verify version compare (the diff docket), v9.81.0.
 *
 * The chain stores every version's payload and the credential route serves any
 * version (?v=N) — this asset answers "what changed between v1 and v2". It
 * fetches BOTH credentials from this site's own credential endpoint (the same
 * data-credential-base attribute the verifier reads — no hardcoded URL, no
 * foreign origin), decodes each signed payload's content, and renders the
 * word-level diff the pure core derives (SNProvVerifyCore.diffWords, fixtures
 * in tests/js/prov-verify-core.test.mjs).
 *
 * Reader-facing, escaped by construction: every node is built with
 * createElement + textContent — payload text is NEVER assigned as markup.
 * Each side is labeled by its own anchor state (confirmed / pending / absent),
 * so the reader knows which version is actually on-chain.
 *
 * 2026.10: a link can arrive with ?compare_a=N&compare_b=M (a note's "Updated"
 * line sends its last two versions). Valid, in-range numbers fill the form,
 * run the compare and land focus on the result; anything else is ignored.
 * Pinned by tests/prov-verify-compare-arrival.cjs.
 *
 * Classic script IIFE; depends only on the SNProvVerifyCore global loaded
 * before it by inc/provenance-verify.php.
 *
 * @since 9.81.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}
	var Core = window.SNProvVerifyCore;
	var root = document.querySelector( '.sn-verify' );
	var box  = root ? root.querySelector( '[data-role="compare"]' ) : null;
	if ( ! Core || ! box || 'function' !== typeof Core.diffWords ) {
		return;
	}

	var credentialBase = root.getAttribute( 'data-credential-base' ) || '';
	var form   = box.querySelector( '[data-role="compare-form"]' );
	var out    = box.querySelector( '[data-role="compare-out"]' );
	var uidIn  = document.getElementById( 'sn-compare-uid' );
	var aIn    = document.getElementById( 'sn-compare-a' );
	var bIn    = document.getElementById( 'sn-compare-b' );
	if ( ! form || ! out ) {
		return;
	}

	// Prefill the uid from the page's own ?note= context.
	if ( uidIn && ! uidIn.value ) {
		uidIn.value = root.getAttribute( 'data-note' ) || '';
	}

	function say( text ) {
		out.textContent = '';
		var p = document.createElement( 'p' );
		p.className = 'sn-verify-compare-note';
		p.textContent = text;
		out.appendChild( p );
	}

	function fetchCredential( uid, version ) {
		// Version 0 asks for the latest, the way prov-verify.js asks for it.
		var url = credentialBase.replace( /\/?$/, '' ) + '/' + encodeURIComponent( uid ) + ( version ? '?v=' + encodeURIComponent( version ) : '' );
		return fetch( url, { credentials: 'omit' } ).then( function ( res ) {
			if ( ! res.ok ) {
				return null;
			}
			return res.json().catch( function () { return null; } );
		} ).catch( function () {
			return null;
		} );
	}

	/** The payload content a credential signed, or '' when undecodable. */
	function signedContent( cred ) {
		try {
			// UTF-8 aware (v10.66.1): atob() alone yields a binary string, which
			// rendered every non-ASCII character in this diff as mojibake.
			var payload = JSON.parse( Core.base64ToUtf8( String( ( cred && cred.proof && cred.proof.signedPayloadB64 ) || '' ) ) );
			return String( payload.content || '' );
		} catch ( e ) {
			return '';
		}
	}

	/** A side's label: "v2 — anchor confirmed at block N" style, plain text. */
	function sideLabel( version, cred ) {
		var evidence = ( cred && cred.evidence && cred.evidence[ 0 ] ) || {};
		var anchor   = evidence.anchor || {};
		var state    = 'no Bitcoin anchor recorded';
		if ( 'confirmed' === anchor.status ) {
			state = anchor.block ? 'anchor confirmed at block ' + anchor.block : 'anchor confirmed';
		} else if ( 'pending' === anchor.status ) {
			state = 'anchor pending confirmation';
		}
		// The ' — ' here is a VALUE SEPARATOR inside a label chip ("v1 — anchor
		// confirmed at block 957611"), not prose joining two clauses, so it is
		// deliberately left by the em-dash sweep — the same exemption v10.48.2
		// applied across wp-admin. Do not "correct" it to a period: the two
		// halves are a version and its state, not two sentences.
		return 'v' + version + ' — ' + state + ( cred && cred.validFrom ? ', signed ' + cred.validFrom : '' );
	}

	function renderDiff( runs, labelA, labelB ) {
		out.textContent = '';

		var head = document.createElement( 'div' );
		head.className = 'sn-verify-compare-labels';
		[ labelA, labelB ].forEach( function ( text, i ) {
			var span = document.createElement( 'span' );
			span.className = 0 === i ? 'sn-verify-compare-label-del' : 'sn-verify-compare-label-add';
			span.textContent = text;
			head.appendChild( span );
		} );
		out.appendChild( head );

		var body = document.createElement( 'p' );
		body.className = 'sn-verify-compare-diff';
		runs.forEach( function ( run ) {
			var node;
			if ( 'del' === run.op ) {
				node = document.createElement( 'del' );
			} else if ( 'add' === run.op ) {
				node = document.createElement( 'ins' );
			} else {
				node = document.createElement( 'span' );
			}
			node.className = 'sn-verify-compare-' + run.op;
			node.textContent = run.text; // payload text: textContent ONLY, never assigned as markup.
			body.appendChild( node );
			body.appendChild( document.createTextNode( ' ' ) );
		} );
		out.appendChild( body );
	}

	/**
	 * Fetch both versions and draw the diff, or say why not. Resolves once the
	 * output holds its answer. The form and a note's "Updated" link both run it.
	 */
	function runCompare( uid, va, vb ) {
		if ( ! Core.UUID_SHAPE.test( uid ) ) {
			say( 'Enter a note id first (run a verification above, or paste one).' );
			return Promise.resolve();
		}
		if ( va < 1 || vb < 1 || va === vb ) {
			say( 'Pick two different version numbers (1 or higher).' );
			return Promise.resolve();
		}
		say( 'Fetching both versions…' );
		return Promise.all( [ fetchCredential( uid, va ), fetchCredential( uid, vb ) ] ).then( function ( creds ) {
			var credA = creds[ 0 ];
			var credB = creds[ 1 ];
			if ( ! credA || ! credB ) {
				say( 'Could not fetch both versions. One of them may not exist for this note.' );
				return;
			}
			var contentA = signedContent( credA );
			var contentB = signedContent( credB );
			if ( ! contentA || ! contentB ) {
				say( 'One of the signed payloads could not be decoded for comparison.' );
				return;
			}
			var runs = Core.diffWords( contentA, contentB );
			if ( null === runs ) {
				say( 'These versions are too large to diff in the browser.' );
				return;
			}
			renderDiff( runs, sideLabel( va, credA ), sideLabel( vb, credB ) );
		} );
	}

	form.addEventListener( 'submit', function ( evt ) {
		evt.preventDefault();
		runCompare(
			String( ( uidIn && uidIn.value ) || '' ).trim().toLowerCase(),
			parseInt( ( aIn && aIn.value ) || '0', 10 ) || 0,
			parseInt( ( bIn && bIn.value ) || '0', 10 ) || 0
		);
	} );

	// Arrival from a note's "Updated" line (theme, 2026.10):
	// ?note=<uid>&compare_a=N&compare_b=M#compare, the form's own field names.
	// Each version must be a plain number (no sign, exponent, decimal or
	// leading zero) inside the note's range, which its latest credential states
	// (the version prov-verify.js reports). Anything else is ignored without a
	// word, so a bad or stale link leaves the page as a bare ?note= link does.
	// The values reach the page only as input values, after the pattern passed.
	var VERSION_PARAM = /^[1-9][0-9]{0,5}$/;
	var query = new URLSearchParams( window.location.search );
	var wantA = query.get( 'compare_a' ) || '';
	var wantB = query.get( 'compare_b' ) || '';
	var noteId = String( root.getAttribute( 'data-note' ) || '' ).toLowerCase();
	if ( aIn && bIn && VERSION_PARAM.test( wantA ) && VERSION_PARAM.test( wantB ) && wantA !== wantB && Core.UUID_SHAPE.test( noteId ) ) {
		fetchCredential( noteId, 0 ).then( function ( latest ) {
			var top = parseInt( ( latest && latest.evidence && latest.evidence[ 0 ] && latest.evidence[ 0 ].version ) || 0, 10 ) || 0;
			if ( Number( wantA ) > top || Number( wantB ) > top ) {
				return;
			}
			aIn.value = wantA;
			bIn.value = wantB;
			// Land the reader on the answer, unless they have already moved on.
			// Focus moves before the result arrives, so the polite region reads
			// the diff once instead of focus and region reading it twice.
			if ( document.activeElement === document.body || ! document.activeElement ) {
				var tab = document.getElementById( 'sn-tab-compare' );
				if ( box.hidden && tab ) {
					tab.click(); // a link without #compare: show the panel the result lands in.
				}
				out.setAttribute( 'tabindex', '-1' );
				out.focus();
			}
			runCompare( noteId, Number( wantA ), Number( wantB ) );
		} );
	}
} )();
