/**
 * Signal & Noise Tools — shared Abilities run-path client (v7.7.2).
 *
 * window.sntAbilityRun( slug, input, options ) → Promise
 *
 * Inside the station the request rides the shell's fetch (wp.os.fetch,
 * Stable, docs/javascript-reference.md "Every HTTP call from a plugin"): the
 * title bar's status ring moves, the heartbeat-refreshed nonce is stamped at
 * call time, a 401/403 reaches the shell's auth recovery. On a classic page
 * (no shell) it is wp.apiFetch, as before. Either way the promise keeps
 * wp.apiFetch's contract: parsed JSON on success, the parsed WP_Error body as
 * the rejection. options.silent skips the ring (background polls). #1601
 *
 * ONE transport for every ability call. The run controller enforces the HTTP
 * verb by the ability's annotations (validate_request_method in
 * class-wp-rest-abilities-v1-run-controller.php: readonly => GET,
 * destructive+idempotent => DELETE, else POST) and 405s any mismatch. The
 * verb map here is LOCALIZED FROM THE SERVER'S OWN ANNOTATIONS
 * (inc/ability-run-client.php), so a client verb can never drift from the
 * registration again — the v6.39.2 annotation fixes silently 405'd every
 * hardcoded-POST caller, and v7.7.0 repeated the class (the force-check
 * banner). Call sites pass a SLUG only; hardcoding '/wp-abilities/' outside
 * this file fails tests/ability-run-client.php's transport guard.
 *
 * Input transport per verb:
 *   - POST: JSON body { input } — decoded by WP REST normally.
 *   - GET/DELETE: the controller reads the RAW `input` query param
 *     (get_input_from_request), so a JSON string fails
 *     rest_validate_value_from_schema against object schemas. PHP bracket
 *     syntax (input[key]=value) arrives as a decoded array and validates;
 *     numeric/boolean values ride as strings, which rest_validate accepts and
 *     the PHP callbacks cast. Limitation: bracket transport cannot preserve
 *     non-string scalar types inside nested arrays (e.g. integer cron args) —
 *     exact-match impls treat those as no-match, never as a wrong-target hit.
 */
( function () {
	'use strict';

	var cfg   = window.sntAbilityRunData || {};
	var VERBS = cfg.verbs || {};
	// rest_url() from the server, so the shell branch builds a full URL that
	// routes on plain permalinks too (the same value as openStationConfig.restUrl).
	var ROOT  = ( cfg.root || '/wp-json/' ).replace( /\/$/, '' );

	/**
	 * Bracket-encode an input object for GET/DELETE query transport.
	 * Recurses into nested objects/arrays; skips null/undefined leaves so
	 * schema defaults apply server-side.
	 */
	function encodeInput( value, prefix, pairs ) {
		Object.keys( value ).forEach( function ( k ) {
			var v   = value[ k ];
			var key = prefix + '[' + k + ']';
			if ( v === null || v === undefined ) {
				return;
			}
			if ( typeof v === 'object' ) {
				encodeInput( v, key, pairs );
				return;
			}
			pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( String( v ) ) );
		} );
	}

	/**
	 * Send one run-path request: the shell's fetch inside the station,
	 * wp.apiFetch elsewhere. The seam is a typeof guard because the same
	 * script loads on classic pages and in the block editor's iframe.
	 */
	function send( path, verb, query, data, options ) {
		var signal = options && options.signal;
		if ( ! ( window.wp.os && 'function' === typeof window.wp.os.fetch ) ) {
			var opts = { path: path + ( query ? '?' + query : '' ), method: verb };
			if ( signal ) { opts.signal = signal; }
			if ( data ) { opts.data = data; }
			return window.wp.apiFetch( opts );
		}
		var url  = ROOT + path + ( query ? ( -1 === ROOT.indexOf( '?' ) ? '?' : '&' ) + query : '' );
		var init = { method: verb, credentials: 'same-origin' };
		if ( signal ) { init.signal = signal; }
		if ( data ) {
			init.headers = { 'Content-Type': 'application/json' };
			init.body    = JSON.stringify( data );
		}
		return window.wp.os.fetch( url, init, { silent: !! ( options && options.silent ) } ).then( function ( res ) {
			// A native Response: parse it and hand back what wp.apiFetch would.
			// A body that is not JSON (an HTML 503 from Varnish, a challenge
			// page, the WAF's 403 page) rejects as apiFetch's parseJsonAndNormalizeError
			// does, code invalid_json, plus the HTTP status the SyntaxError lost.
			return res.json().catch( function () {
				throw { code: 'invalid_json', message: 'The response is not a valid JSON response.', data: { status: res.status } };
			} ).then( function ( body ) {
				if ( ! res.ok ) { throw body; }
				return body;
			} );
		} );
	}

	/**
	 * Execute an ability via the run path with the annotation-correct verb.
	 *
	 * @param {string} slug  Ability slug — bare ('get-audit-log') or
	 *                       namespaced ('signal-noise/get-audit-log').
	 * @param {Object} [input] Ability input; omit/empty for input-less calls.
	 * @param {Object} [options] { signal, silent } only; cannot override verb/path.
	 * @return {Promise} Resolves to the ability output; rejects with the WP_Error body.
	 */
	window.sntAbilityRun = function ( slug, input, options ) {
		var name = -1 === slug.indexOf( '/' ) ? 'signal-noise/' + slug : slug;
		// Unknown slug (e.g. an ability removed server-side) falls back to
		// POST — the controller's own default expectation for un-annotated
		// abilities; a 404/405 there is loud, not silent.
		var verb = VERBS[ name ] || 'POST';
		var path  = '/wp-abilities/v1/abilities/' + name + '/run';
		var query = '';
		var data  = null;

		var hasInput = input && 'object' === typeof input && Object.keys( input ).length > 0;
		// 15.7.1: a POST always carries an input object. With no body the
		// controller validates a missing input as null, and an ability whose
		// schema says `object` refuses it ("input is not of type object", the
		// anchor-sweep widget on 2026-09-17). GET keeps its query transport.
		if ( 'POST' === verb ) {
			data = { input: hasInput ? input : {} };
		} else if ( hasInput ) {
			var pairs = [];
			encodeInput( input, 'input', pairs );
			query = pairs.join( '&' );
		}
		return send( path, verb, query, data, options );
	};
} )();
