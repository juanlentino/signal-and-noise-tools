( function( window, document ) {
	'use strict';

	var config = window.sntOpenStationNativeWindows || {};
	var __ = window.wp && window.wp.i18n && window.wp.i18n.__
		? window.wp.i18n.__
		: function( text ) {
			return text;
		};
	var preferences = Object.assign(
		{ dashboard: true, analytics: true },
		config.preferences || {}
	);

	function setStatus( node, message, error ) {
		node.textContent = message;
		node.style.color = error ? 'var(--os-ui-danger, #ff5a5a)' : 'var(--os-ui-fg-muted, #b3afb5)';
	}

	function save( patch ) {
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

	function addSwitch( section, key, label, description, status ) {
		var control = document.createElement( 'os-switch' );
		control.setAttribute( 'block', '' );
		control.setAttribute( 'value', key );
		control.setAttribute( 'label', label );
		control.setAttribute( 'description', description );
		if ( preferences[ key ] ) {
			control.setAttribute( 'checked', '' );
		}
		control.addEventListener( 'os-switch-change', function( event ) {
			var checked = !! ( event.detail && event.detail.checked );
			var previous = !! preferences[ key ];
			preferences[ key ] = checked;
			control.setAttribute( 'disabled', '' );
			setStatus( status, __( 'Saving…', 'signal-and-noise-tools' ), false );
			save( Object.assign( {}, preferences ) ).then( function( saved ) {
				preferences = Object.assign( {}, preferences, saved );
				setStatus( status, __( 'Saved. Updating the OpenStation menu…', 'signal-and-noise-tools' ), false );
				if ( window.wp && window.wp.os && typeof window.wp.os.refreshMenu === 'function' ) {
					return window.wp.os.refreshMenu();
				}
				return null;
			} ).then( function() {
				control.removeAttribute( 'disabled' );
				setStatus( status, __( 'Saved.', 'signal-and-noise-tools' ), false );
			} ).catch( function() {
				preferences[ key ] = previous;
				if ( previous ) {
					control.setAttribute( 'checked', '' );
				} else {
					control.removeAttribute( 'checked' );
				}
				control.removeAttribute( 'disabled' );
				setStatus( status, __( 'Could not save this preference.', 'signal-and-noise-tools' ), true );
			} );
		} );
		section.appendChild( control );
	}

	function render( body ) {
		body.replaceChildren();
		var section = document.createElement( 'os-section' );
		section.setAttribute( 'heading', __( 'Signal & Noise native windows', 'signal-and-noise-tools' ) );
		section.setAttribute( 'description', __( 'Choose which S&N screens use native OpenStation windows for this account. Turn either one off to use its classic WordPress admin page instead.', 'signal-and-noise-tools' ) );
		section.setAttribute( 'stack', '' );
		var status = document.createElement( 'p' );
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.style.margin = '0';
		status.style.fontSize = '12px';
		addSwitch(
			section,
			'dashboard',
			__( 'Use the native S&N Dashboard window', 'signal-and-noise-tools' ),
			__( 'Turn off to open the classic S&N Dashboard page in a regular OpenStation browser window.', 'signal-and-noise-tools' ),
			status
		);
		addSwitch(
			section,
			'analytics',
			__( 'Use the native S&N Analytics window', 'signal-and-noise-tools' ),
			__( 'Turn off to open the classic S&N Analytics page in a regular OpenStation browser window.', 'signal-and-noise-tools' ),
			status
		);
		section.appendChild( status );
		body.appendChild( section );
	}

	function register() {
		if ( ! window.wp || ! window.wp.os || typeof window.wp.os.registerSettingsTab !== 'function' ) {
			return;
		}
		window.wp.os.registerSettingsTab( {
			id: 'signal-noise',
			label: __( 'Signal & Noise', 'signal-and-noise-tools' ),
			capability: 'manage_options',
			order: 35,
			owner: 'snt-openstation-native-windows',
			render: render
		} );
	}

	if ( window.wp && window.wp.os && typeof window.wp.os.ready === 'function' ) {
		window.wp.os.ready( register );
	}
} )( window, document );
