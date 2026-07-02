/**
 * Signal & Noise Tools — shared Abilities run-path client (v7.7.2).
 *
 * window.sntAbilityRun( slug, input ) → Promise (wp.apiFetch)
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
	 * Execute an ability via the run path with the annotation-correct verb.
	 *
	 * @param {string} slug  Ability slug — bare ('get-audit-log') or
	 *                       namespaced ('signal-noise/get-audit-log').
	 * @param {Object} [input] Ability input; omit/empty for input-less calls.
	 * @return {Promise} wp.apiFetch promise resolving to the ability output.
	 */
	window.sntAbilityRun = function ( slug, input ) {
		var name = -1 === slug.indexOf( '/' ) ? 'signal-noise/' + slug : slug;
		// Unknown slug (e.g. an ability removed server-side) falls back to
		// POST — the controller's own default expectation for un-annotated
		// abilities; a 404/405 there is loud, not silent.
		var verb = VERBS[ name ] || 'POST';
		var path = '/wp-abilities/v1/abilities/' + name + '/run';
		var opts = { path: path, method: verb };

		var hasInput = input && 'object' === typeof input && Object.keys( input ).length > 0;
		if ( hasInput ) {
			if ( 'POST' === verb ) {
				opts.data = { input: input };
			} else {
				var pairs = [];
				encodeInput( input, 'input', pairs );
				if ( pairs.length ) {
					opts.path += '?' + pairs.join( '&' );
				}
			}
		}
		return window.wp.apiFetch( opts );
	};
} )();
