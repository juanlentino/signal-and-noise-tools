/**
 * Signal & Noise Tools — admin JS.
 *
 * Loaded only on SN admin pages (same hook guard as assets/admin.css).
 * Pure vanilla JS, no jQuery, no build step. Keeps the zero-build-
 * pipeline architecture of the rest of the plugin.
 *
 * Two responsibilities — both scoped to the Identity tab via
 * `.sn-identity-form`:
 *
 *   1. dirty-tracking on the sticky save bar
 *      — snapshots all input values on load; on any change, compares
 *        current vs initial; updates the save bar hint to show
 *        "N unsaved change(s)" or "No unsaved changes"
 *
 *   2. "+ Add another profile URL" button
 *      — clones the existing input template into a new row above the
 *        button. Submission still works as social_same_as[] array.
 *
 * Added in v1.9.6 (2026-05-16).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '.sn-identity-form' );
		if ( ! form ) {
			return;
		}

		initDirtyTracking( form );
		initAddRowButton( form );
	} );

	/**
	 * Dirty-tracking: snapshot initial values, listen for input changes,
	 * update the save bar hint with the count of changed fields.
	 */
	function initDirtyTracking( form ) {
		var hint = form.querySelector( '.sn-savebar-hint' );
		if ( ! hint ) {
			return;
		}

		var initial = snapshotForm( form );
		hint.dataset.cleanCopy = hint.textContent;

		var update = function () {
			var current = snapshotForm( form );
			var changed = countChanges( initial, current );
			if ( changed === 0 ) {
				form.removeAttribute( 'data-dirty' );
				hint.textContent = hint.dataset.cleanCopy;
			} else {
				form.setAttribute( 'data-dirty', 'true' );
				hint.textContent = changed === 1
					? '1 unsaved change'
					: changed + ' unsaved changes';
			}
		};

		form.addEventListener( 'input', update );
		// Dynamic rows added via the "+ Add" button also fire 'input'
		// when typed in, so update naturally catches them. We also need
		// to refresh the initial snapshot if a new row is added empty —
		// otherwise adding a row appears "dirty" before any typing.
		form.addEventListener( 'sn:row-added', function () {
			initial = snapshotForm( form );
			update();
		} );
	}

	/**
	 * Snapshot all form input/textarea/select values into a plain
	 * key+index map. Repeated input names (e.g. social_same_as[]) get
	 * stable per-index keys so adding/removing rows shows accurately.
	 */
	function snapshotForm( form ) {
		var snap = {};
		var counters = {};
		var inputs = form.querySelectorAll( 'input, textarea, select' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var el = inputs[ i ];
			if ( el.type === 'hidden' || el.type === 'submit' || el.disabled ) {
				continue;
			}
			var name = el.name || '';
			if ( ! name ) {
				continue;
			}
			counters[ name ] = ( counters[ name ] || 0 ) + 1;
			var key = name + '#' + counters[ name ];
			snap[ key ] = el.type === 'checkbox' ? el.checked : el.value;
		}
		return snap;
	}

	/**
	 * Count keys that differ between two snapshots. Keys present in only
	 * one snapshot count as changed (covers row add/remove cases).
	 */
	function countChanges( a, b ) {
		var changed = 0;
		var keys = {};
		Object.keys( a ).forEach( function ( k ) { keys[ k ] = true; } );
		Object.keys( b ).forEach( function ( k ) { keys[ k ] = true; } );
		Object.keys( keys ).forEach( function ( k ) {
			if ( a[ k ] !== b[ k ] ) {
				changed++;
			}
		} );
		return changed;
	}

	/**
	 * "+ Add another profile URL" button handler. Clones the sameAs
	 * input template into a new row above the button. New input is
	 * empty and immediately focused.
	 */
	function initAddRowButton( form ) {
		var btn = form.querySelector( '.sn-add-row-btn' );
		if ( ! btn ) {
			return;
		}
		var container = form.querySelector( '.sn-sameas' );
		if ( ! container ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var input = document.createElement( 'input' );
			input.type = 'url';
			input.name = 'social_same_as[]';
			input.value = '';
			input.placeholder = 'https://...';
			input.className = 'sn-row-fresh';
			container.insertBefore( input, btn );
			input.focus();

			// Custom event so the dirty-tracker can refresh its snapshot
			// (otherwise an empty new row reads as "dirty" before typing).
			form.dispatchEvent( new CustomEvent( 'sn:row-added' ) );
		} );
	}
} )();
