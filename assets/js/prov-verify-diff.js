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
		var url = credentialBase.replace( /\/?$/, '' ) + '/' + encodeURIComponent( uid ) + '?v=' + encodeURIComponent( version );
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
			var payload = JSON.parse( atob( String( ( cred && cred.proof && cred.proof.signedPayloadB64 ) || '' ) ) );
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

	form.addEventListener( 'submit', function ( evt ) {
		evt.preventDefault();
		var uid = String( ( uidIn && uidIn.value ) || '' ).trim().toLowerCase();
		var va  = parseInt( ( aIn && aIn.value ) || '0', 10 ) || 0;
		var vb  = parseInt( ( bIn && bIn.value ) || '0', 10 ) || 0;
		if ( ! Core.UUID_SHAPE.test( uid ) ) {
			say( 'Enter a note id first (run a verification above, or paste one).' );
			return;
		}
		if ( va < 1 || vb < 1 || va === vb ) {
			say( 'Pick two different version numbers (1 or higher).' );
			return;
		}
		say( 'Fetching both versions…' );
		Promise.all( [ fetchCredential( uid, va ), fetchCredential( uid, vb ) ] ).then( function ( creds ) {
			var credA = creds[ 0 ];
			var credB = creds[ 1 ];
			if ( ! credA || ! credB ) {
				say( 'Could not fetch both versions — one of them may not exist for this note.' );
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
	} );
} )();
