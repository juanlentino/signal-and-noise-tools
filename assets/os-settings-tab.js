( function( window, document ) {
	'use strict';

	var config = window.sntOpenStationPreferences || {};
	var __ = window.wp && window.wp.i18n && window.wp.i18n.__
		? window.wp.i18n.__
		: function( text ) {
			return text;
		};
	var preferences = Object.assign(
		{ 'signal-noise': true, dashboard: true, analytics: true },
		config.preferences || {}
	);

	function save( patch ) {
		if ( ! config.endpoint ) {
			return Promise.reject( new Error( __( 'Missing preferences endpoint.', 'signal-and-noise-tools' ) ) );
		}
		return window.fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( patch )
		} ).then( function( response ) {
			if ( ! response.ok ) {
				throw new Error( __( 'Preference request failed.', 'signal-and-noise-tools' ) );
			}
			return response.json();
		} );
	}

	function isAdminPage( parsed, page ) {
		return !! parsed &&
			parsed.pathname.endsWith( '/admin.php' ) &&
			parsed.searchParams.get( 'page' ) === page;
	}

	function remapParams( parsed, fixedKeys ) {
		var params = {};
		if ( ! parsed || ! parsed.searchParams ) {
			return params;
		}
		parsed.searchParams.forEach( function( value, key ) {
			if ( fixedKeys.indexOf( key ) !== -1 || /^sn_[a-z0-9_]+$/.test( key ) ) {
				params[ key ] = value;
			}
		} );
		return params;
	}

	function wireUrlRemaps() {
		if ( ! window.wp || ! window.wp.os || typeof window.wp.os.registerNativeUrlRemap !== 'function' ) {
			return;
		}
		window.wp.os.registerNativeUrlRemap( {
			id: 'snt-dashboard-remap',
			nativeWindowId: 'sn-dashboard',
			matches: function( url, parsed ) {
				return isAdminPage( parsed, 'sn-theme-options' );
			},
			enabled: function() {
				return preferences.dashboard === true;
			},
			params: function( url, parsed ) {
				return remapParams( parsed, [ 'tab', 'sub', 'anchor' ] );
			}
		} );
		window.wp.os.registerNativeUrlRemap( {
			id: 'snt-analytics-remap',
			nativeWindowId: 'sn-analytics',
			matches: function( url, parsed ) {
				return isAdminPage( parsed, 'sn-analytics' );
			},
			enabled: function() {
				return preferences.analytics === true;
			},
			params: function( url, parsed ) {
				return remapParams( parsed, [] );
			}
		} );
	}

	function render( body ) {
		body.replaceChildren();

		var section = document.createElement( 'os-section' );
		section.setAttribute( 'heading', __( 'Native window replacements', 'signal-and-noise-tools' ) );
		section.setAttribute( 'description', __( 'Choose whether to use OpenStation native windows or classic WordPress admin windows for Signal & Noise. Toggle either off to return to the classic screen.', 'signal-and-noise-tools' ) );
		section.setAttribute( 'stack', '' );

		var status = document.createElement( 'p' );
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.style.margin = '4px 0 0';
		status.style.fontSize = '12px';
		status.style.color = 'var(--os-ui-fg-muted, #b3afb5)';

		function createToggle( key, label, desc ) {
			var sw = document.createElement( 'os-switch' );
			sw.setAttribute( 'block', '' );
			sw.setAttribute( 'value', key );
			sw.setAttribute( 'label', label );
			sw.setAttribute( 'description', desc );
			if ( preferences[ key ] ) {
				sw.setAttribute( 'checked', '' );
			}
			sw.addEventListener( 'os-switch-change', function( event ) {
				var checked = Boolean(
					event.detail && typeof event.detail.checked !== 'undefined'
						? event.detail.checked
						: ( event.target && event.target.checked )
				);
				var previous = !! preferences[ key ];
				preferences[ key ] = checked;
				sw.setAttribute( 'disabled', '' );
				status.textContent = __( 'Saving…', 'signal-and-noise-tools' );
				status.style.color = 'var(--os-ui-fg-muted, #b3afb5)';

				var patch = {};
				patch[ key ] = checked;

				save( patch ).then( function( saved ) {
					preferences = Object.assign( {}, preferences, saved );
					wireUrlRemaps();
					status.textContent = __( 'Saved.', 'signal-and-noise-tools' );
					status.style.color = 'var(--os-ui-success, #7bd88f)';
					if ( window.wp && window.wp.os && typeof window.wp.os.refreshMenu === 'function' ) {
						window.wp.os.refreshMenu();
					}
				} ).catch( function() {
					preferences[ key ] = previous;
					if ( previous ) {
						sw.setAttribute( 'checked', '' );
					} else {
						sw.removeAttribute( 'checked' );
					}
					status.textContent = __( 'Could not save preference.', 'signal-and-noise-tools' );
					status.style.color = 'var(--os-ui-danger, #ff5a5a)';
				} ).finally( function() {
					sw.removeAttribute( 'disabled' );
				} );
			} );
			return sw;
		}

		section.appendChild( createToggle(
			'signal-noise',
			__( 'Use the native Signal & Noise app window', 'signal-and-noise-tools' ),
			__( 'Opens the native Signal & Noise App Framework window for notes, pages, citations, schedules, and the attention queue. Toggle off to hide from the dock.', 'signal-and-noise-tools' )
		) );

		section.appendChild( createToggle(
			'dashboard',
			__( 'Use the native S&N Dashboard window', 'signal-and-noise-tools' ),
			__( 'Replaces the classic S&N Dashboard admin page with the native App Framework window.', 'signal-and-noise-tools' )
		) );

		section.appendChild( createToggle(
			'analytics',
			__( 'Use the native S&N Analytics window', 'signal-and-noise-tools' ),
			__( 'Replaces the classic S&N Analytics admin page with the native App Framework window.', 'signal-and-noise-tools' )
		) );

		section.appendChild( status );
		body.appendChild( section );
	}

	function init() {
		wireUrlRemaps();
		if ( window.wp && window.wp.os && typeof window.wp.os.registerSettingsTab === 'function' ) {
			window.wp.os.registerSettingsTab( {
				id: 'signal-noise',
				label: __( 'Signal & Noise', 'signal-and-noise-tools' ),
				capability: 'manage_options',
				order: 32,
				owner: 'snt-os-settings-tab',
				render: render
			} );
		}
	}

	var readyFn = window.wp && window.wp.os && ( window.wp.os.ready || window.wp.os.whenReady );
	if ( typeof readyFn === 'function' ) {
		readyFn( init );
	} else if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
