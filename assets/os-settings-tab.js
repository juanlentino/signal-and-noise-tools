( function( window, document ) {
	'use strict';

	var config = window.sntOpenStationPreferences || {};
	var __ = window.wp && window.wp.i18n && window.wp.i18n.__
		? window.wp.i18n.__
		: function( text ) {
			return text;
		};
	var saving = false;
	var mountedControls = [];
	var mountedStatus;
	var mountedBody;
	var preferences = Object.assign(
		{ dashboard: true, analytics: true, mio_tips: true, mio_help: true, mio_look: false },
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

	/**
	 * The two remaps this plugin registers, as a function a click can call.
	 *
	 * 14.7.6. The shell's remap registry is consulted by the dock, the portal,
	 * the top-window link interceptor and files-on-the-desktop, and NOT by an
	 * app's `open_url` effect (WordPress/openstation#819 adds it). Until that
	 * ships, every door the plugin paints to its own pages ("Open Trust checks
	 * in S&N Dashboard", the Analytics gate) opened an iframe of the classic
	 * page while the dock tile beside it opened the native window. This is
	 * the same match and the same params as the registry entries below, so
	 * the two can never disagree; it opens the native window with
	 * wp.os.openWindow (the registry's own opener) and says whether it did.
	 *
	 * @param {string} url Any URL.
	 * @return {boolean} True when a native window took it.
	 */
	function tryNativeRemap( url ) {
		if ( ! url || ! window.wp || ! window.wp.os || typeof window.wp.os.openWindow !== 'function' ) {
			return false;
		}
		var parsed;
		try {
			parsed = new URL( String( url ), window.location.href );
		} catch ( e ) {
			return false;
		}
		if ( parsed.origin !== window.location.origin ) {
			return false;
		}
		if ( preferences.dashboard === true && isAdminPage( parsed, 'sn-theme-options' ) ) {
			return window.wp.os.openWindow( 'sn-dashboard', { params: remapParams( parsed, [ 'tab', 'sub', 'anchor' ] ) } ) === true;
		}
		if ( preferences.analytics === true && isAdminPage( parsed, 'sn-analytics' ) ) {
			return window.wp.os.openWindow( 'sn-analytics', { params: remapParams( parsed, [] ) } ) === true;
		}
		return false;
	}

	/**
	 * A door painted by the kit (`<os-button os-action="door" os-arg-url>`)
	 * that names one of this plugin's own pages opens the NATIVE window,
	 * not the framework's iframe. Capture phase on the document, ahead of
	 * the runtime's own click listener; when the native window took the
	 * URL the framework never sees the click. Any other door is untouched.
	 */
	function armDoorClicks() {
		if ( armDoorClicks.done ) {
			return;
		}
		armDoorClicks.done = true;
		document.addEventListener( 'click', function( e ) {
			if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}
			var t = e.target;
			var door = t && typeof t.closest === 'function' ? t.closest( '[os-action="door"][os-arg-url]' ) : null;
			if ( ! door || door.hasAttribute( 'disabled' ) ) {
				return;
			}
			if ( tryNativeRemap( door.getAttribute( 'os-arg-url' ) ) ) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
		}, true );
	}

	function wireUrlRemaps() {
		config.tryNativeRemap = tryNativeRemap;
		window.sntOpenStationPreferences = config;
		armDoorClicks();
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

	function syncDockTiles( prefs ) {
		if ( ! window.wp || ! window.wp.os ) {
			return;
		}
		var os = window.wp.os;
		if ( ! prefs.analytics ) {
			if ( os.dock && typeof os.dock.removeSystemItem === 'function' ) {
				os.dock.removeSystemItem( 'sn-analytics' );
			}
			if ( os.sideDock && typeof os.sideDock.removeSystemItem === 'function' ) {
				os.sideDock.removeSystemItem( 'sn-analytics' );
			}
			var nativeTile = document.querySelector( '[data-tile-id="sn-analytics"], [data-id="sn-analytics"]' );
			if ( nativeTile && nativeTile.parentNode ) {
				nativeTile.parentNode.removeChild( nativeTile );
			}
		}
		if ( ! prefs.dashboard ) {
			if ( os.dock && typeof os.dock.removeSystemItem === 'function' ) {
				os.dock.removeSystemItem( 'sn-dashboard' );
			}
			if ( os.sideDock && typeof os.sideDock.removeSystemItem === 'function' ) {
				os.sideDock.removeSystemItem( 'sn-dashboard' );
			}
			var nativeDbTile = document.querySelector( '[data-tile-id="sn-dashboard"], [data-id="sn-dashboard"]' );
			if ( nativeDbTile && nativeDbTile.parentNode ) {
				nativeDbTile.parentNode.removeChild( nativeDbTile );
			}
		}
	}

	function render( body ) {
		mountedBody = body;
		body.replaceChildren();
		mountedControls = [];

		var section = document.createElement( 'os-section' );
		section.setAttribute( 'heading', __( 'Native window replacements', 'signal-and-noise-tools' ) );
		section.setAttribute( 'description', __( 'Choose whether to use OpenStation native windows or classic WordPress admin windows for the S&N Home and Analytics screens. Signal & Noise is native-only.', 'signal-and-noise-tools' ) );
		section.setAttribute( 'stack', '' );

		var status = document.createElement( 'p' );
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.style.margin = '4px 0 0';
		status.style.fontSize = '12px';
		status.style.color = 'var(--os-ui-fg-muted, #b3afb5)';
		mountedStatus = status;
		if ( saving ) { status.textContent = __( 'Saving…', 'signal-and-noise-tools' ); }

		function createToggle( key, label, desc ) {
			var item = document.createElement( 'div' );
			item.className = 'os-features__item';
			var sw = document.createElement( 'os-checkbox-label' );
			var hint = document.createElement( 'p' );
			hint.className = 'os-features__hint';
			hint.textContent = desc;
			item.append( sw, hint );
			mountedControls.push( { key: key, control: sw } );
			if ( saving ) { sw.setAttribute( 'disabled', '' ); }
			sw.setAttribute( 'value', key );
			sw.setAttribute( 'label', label );
			if ( preferences[ key ] ) {
				sw.setAttribute( 'checked', '' );
			}
			sw.addEventListener( 'os-checkbox-change', function( event ) {
				if ( saving ) { return; }
				saving = true;
				var checked = Boolean(
					event.detail && typeof event.detail.checked !== 'undefined'
						? event.detail.checked
						: ( event.target && event.target.checked )
				);
				var previous = !! preferences[ key ];
				var failed = false;
				preferences[ key ] = checked;
				mountedControls.forEach( function( entry ) { entry.control.setAttribute( 'disabled', '' ); } );
				status = mountedStatus;
				status.textContent = __( 'Saving…', 'signal-and-noise-tools' );
				status.style.color = 'var(--os-ui-fg-muted, #b3afb5)';

				var patch = {};
				patch[ key ] = checked;

				save( patch ).then( function( saved ) {
					preferences = Object.assign( {}, preferences, saved );
					status = mountedStatus;
					status.textContent = __( 'Saved.', 'signal-and-noise-tools' );
					status.style.color = 'var(--os-ui-success, #7bd88f)';
					// The mascot's look is shipped in the shell's boot config
					// (openstation_mio_config), so a change lands on the next
					// reload; the other switches are read at the next paint.
					if ( key === 'mio_look' ) {
						status.textContent = __( 'Saved. MIO wears it on the next reload.', 'signal-and-noise-tools' );
					}
					// Shell refresh is secondary to persistence: its failure must not
					// roll back a preference the server has already accepted.
					Promise.resolve().then( function() {
						wireUrlRemaps();
						syncDockTiles( preferences );
						if ( window.wp && window.wp.os && typeof window.wp.os.refreshMenu === 'function' ) {
							return window.wp.os.refreshMenu();
						}
					} ).then( function() {
						syncDockTiles( preferences );
					} ).catch( function() {
						// No write rollback: reopening the shell retries menu discovery.
					} );
				} ).catch( function() {
					failed = true;
					preferences[ key ] = previous;
					if ( previous ) {
						sw.setAttribute( 'checked', '' );
					} else {
						sw.removeAttribute( 'checked' );
					}
					status = mountedStatus;
					status.textContent = __( 'Could not save preference.', 'signal-and-noise-tools' );
					status.style.color = 'var(--os-ui-danger, #ff5a5a)';
				} ).finally( function() {
					saving = false;
					mountedControls.forEach( function( entry ) {
						entry.control.toggleAttribute( 'checked', !! preferences[ entry.key ] );
						entry.control.removeAttribute( 'disabled' );
					} );
					if ( failed && mountedBody && mountedBody.isConnected ) {
						// 1.1.7 binds a checked ATTRIBUTE, which cannot reset a dirty
						// native input property. Recreate it from the restored state.
						var restoreFocus = mountedControls.some( function( entry ) { return document.activeElement === entry.control; } );
						render( mountedBody );
						mountedStatus.textContent = __( 'Could not save preference.', 'signal-and-noise-tools' );
						mountedStatus.style.color = 'var(--os-ui-danger, #ff5a5a)';
						if ( restoreFocus ) {
							window.requestAnimationFrame( function() {
								var entry = mountedControls.find( function( item ) { return item.key === key; } );
								var input = entry && entry.control.shadowRoot && entry.control.shadowRoot.querySelector( 'input' );
								if ( input ) { input.focus(); }
							} );
						}
					}
				} );
			} );
			return item;
		}

		section.appendChild( createToggle(
			'dashboard',
			__( 'Use S&N Home', 'signal-and-noise-tools' ),
			__( 'Opens S&N Home instead of the classic S&N Dashboard. Toggle off to use the classic dashboard.', 'signal-and-noise-tools' )
		) );

		section.appendChild( createToggle(
			'analytics',
			__( 'Use the native S&N Analytics window', 'signal-and-noise-tools' ),
			__( 'Replaces the classic S&N Analytics admin page with the native App Framework window.', 'signal-and-noise-tools' )
		) );

		// 14.8.0: what MIO, the shell's companion, may do in this plugin's
		// windows. The shell's own MIO switch (Features) sits above these.
		section.appendChild( createToggle(
			'mio_tips',
			__( 'MIO tips in Signal & Noise windows', 'signal-and-noise-tools' ),
			__( 'Plain-text callouts that say which state an item is in. Never a model call.', 'signal-and-noise-tools' )
		) );
		section.appendChild( createToggle(
			'mio_help',
			__( 'Ask MIO about Signal & Noise', 'signal-and-noise-tools' ),
			__( 'Registers the plugin\'s help and read-only tools for Ask MIO. Needs the shell\'s AI switch and a configured connector; makes no call of its own.', 'signal-and-noise-tools' )
		) );
		section.appendChild( createToggle(
			'mio_look',
			__( 'Dress MIO in the site\'s palette', 'signal-and-noise-tools' ),
			__( 'Bone body, blood ring. Your own saved look in Make it yours still wins.', 'signal-and-noise-tools' )
		) );

		section.appendChild( status );
		body.appendChild( section );
	}

	function init() {
		// 1.1.7 reserves a blank glyph for third-party tabs; style only ours.
		if ( ! document.getElementById( 'snt-preferences-icon' ) ) {
			var iconStyle = document.createElement( 'style' );
			iconStyle.id = 'snt-preferences-icon';
			iconStyle.textContent = '.os-settings os-tab[value="signal-noise"] > .os-settings__nav-glyph-blank { background: currentColor; mask: url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27%3E%3Cpath d=%27M3 12h4l3-8 4 16 3-8h4%27 fill=%27none%27 stroke=%27black%27 stroke-width=%272%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27/%3E%3C/svg%3E") center / contain no-repeat; }';
			document.head.appendChild( iconStyle );
		}
		wireUrlRemaps();
		syncDockTiles( preferences );
		document.addEventListener( 'os-registry-changed', function() {
			syncDockTiles( preferences );
		} );
		if ( window.wp && window.wp.os && typeof window.wp.os.registerSettingsTab === 'function' ) {
			window.wp.os.registerSettingsTab( {
				id: 'signal-noise',
				label: __( 'Signal & Noise', 'signal-and-noise-tools' ),
				capability: 'manage_options',
				order: 32,
				// OS icon-set name; read by OpenStation from 1.1.9
				// (WordPress/openstation#808), ignored before.
				icon: 'bell',
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
