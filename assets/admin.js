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
		// TOC scroll-spy is independent of the Identity form — run it first
		// so it works on any tab that renders a .sn-toc nav.
		initTocScrollSpy();

		var form = document.querySelector( '.sn-identity-form' );
		if ( ! form ) {
			return;
		}

		initDirtyTracking( form );
		initAddRowButton( form );
	} );

	/**
	 * Scroll-spy: mark the TOC link for the section currently in view with
	 * aria-current="true" (WCAG 4.1.2-A, PA-03). Vanilla, no build step.
	 *
	 * Selects `.sn-toc a[href^="#sn-sec-"]`, observes each `#sn-sec-<slug>`
	 * target with IntersectionObserver, and sets aria-current on the topmost
	 * intersecting section's link (removing it from the others). Graceful
	 * no-op when there's no .sn-toc or IntersectionObserver is unavailable.
	 */
	function initTocScrollSpy() {
		var toc = document.querySelector( '.sn-toc' );
		if ( ! toc || typeof window.IntersectionObserver === 'undefined' ) {
			return;
		}

		var links = Array.prototype.slice.call(
			toc.querySelectorAll( 'a[href^="#sn-sec-"]' )
		);
		if ( ! links.length ) {
			return;
		}

		// Map each section id → its TOC link, and collect observe targets.
		var linkById = {};
		var targets = [];
		links.forEach( function ( link ) {
			var id = link.getAttribute( 'href' ).slice( 1 ); // drop leading '#'
			var target = document.getElementById( id );
			if ( target ) {
				linkById[ id ] = link;
				targets.push( target );
			}
		} );
		if ( ! targets.length ) {
			return;
		}

		var setActive = function ( id ) {
			links.forEach( function ( link ) {
				if ( link === linkById[ id ] ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		};

		// First paint: mark the first link active until the observer fires.
		setActive( links[ 0 ].getAttribute( 'href' ).slice( 1 ) );

		// Click gives immediate feedback (don't wait for the scroll observer).
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function () {
				setActive( link.getAttribute( 'href' ).slice( 1 ) );
			} );
		} );

		var visible = {};
		var observer = new window.IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					visible[ entry.target.id ] = entry.isIntersecting;
				} );
				// Pick the topmost section currently intersecting, in DOM order.
				for ( var i = 0; i < targets.length; i++ ) {
					if ( visible[ targets[ i ].id ] ) {
						setActive( targets[ i ].id );
						break;
					}
				}
			},
			{ rootMargin: '-40% 0px -55% 0px' }
		);

		targets.forEach( function ( target ) {
			observer.observe( target );
		} );
	}

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
			// WCAG 4.1.2 — screen readers need an accessible name.
			// Placeholder is not a label. The visible .sn-field-label above
			// applies to the group; each row also needs its own name.
			input.setAttribute( 'aria-label', 'Profile URL' );
			container.insertBefore( input, btn );
			input.focus();

			// Custom event so the dirty-tracker can refresh its snapshot
			// (otherwise an empty new row reads as "dirty" before typing).
			form.dispatchEvent( new CustomEvent( 'sn:row-added' ) );
		} );
	}
} )();
