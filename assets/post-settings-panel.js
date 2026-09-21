/**
 * Signal & Noise Tools: the per-post settings panel (#1608).
 *
 * A PluginDocumentSettingPanel from @wordpress/editor (Stable, exported
 * since 6.6) registered through wp.plugins.registerPlugin, reading and
 * writing the registered post meta through useEntityProp from
 * @wordpress/core-data. It replaces the classic meta box that
 * inc/post-settings.php used to paint through the legacy bridge, with the
 * same fields, labels and helpers.
 *
 * Save semantics: the editor sends the whole meta object on save, and for a
 * key at its registered default ('' or false) that was never stored, core's
 * WP_REST_Meta_Fields::update_meta_value() finds no row to compare against
 * and writes the default as an '' row. So before every entity write the
 * panel hands every S&N key at its default back as null, which the REST meta
 * controller turns into delete_post_meta(), a no-op when the row is absent.
 * A flag stores '1' or nothing, text the sanitized string or nothing.
 *
 * The AI suggest scripts (assets/ai-meta-description.js,
 * assets/ai-og-card-title.js, assets/ai-excerpt.js) push actions onto
 * window.sntPostSettingsActions; the panel paints each one as a Button
 * under its field, or as a standalone row when it names no field.
 *
 * No JSX (classic-script IIFE, matching assets/pre-publish-gate.js): every
 * node is built with wp.element.createElement.
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}
	var wp = window.wp;
	if ( ! wp.plugins || ! wp.editor || ! wp.element || ! wp.data || ! wp.coreData || ! wp.components ) {
		return;
	}
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var useEntityProp = wp.coreData.useEntityProp;
	var C = wp.components;
	var __ = ( wp.i18n && wp.i18n.__ ) || function( s ) { return s; };
	var cfg = window.sntPostSettingsConfig || {};

	// Field table. `page` marks the Pages-only controls; `kind` picks the
	// control; `help` is the helper line under it.
	var FIELDS = [
		{ key: '_sn_prov_sign', kind: 'flag', page: true, label: __( 'Sign this page (provenance)', 'signal-noise-tools' ),
			help: __( 'Publishes a signed record of this page to the public ledger on every update, and shows the verification badge above the title. Anchoring is permanent: unticking later hides the badge and stops new versions, but cannot withdraw a record already anchored.', 'signal-noise-tools' ) },
		{ key: '_sn_evergreen', kind: 'flag', label: __( 'Evergreen (timeless)', 'signal-noise-tools' ),
			help: __( 'Marks this Note as intentionally timeless: exempt from the stale content health check and never a refresh candidate on the Analytics Posts leaf, even if its traffic is cooling.', 'signal-noise-tools' ) },
		{ key: '_sn_noindex', kind: 'flag', label: __( 'Hide from search engines (noindex)', 'signal-noise-tools' ),
			help: __( 'Adds noindex to the robots meta tag. Links on this page still pass ranking signal; tick nofollow below as well if they should not.', 'signal-noise-tools' ) },
		{ key: '_sn_nofollow', kind: 'flag', label: __( 'Don’t vouch for outbound links (nofollow)', 'signal-noise-tools' ),
			help: __( 'Adds nofollow: links leaving this page pass no ranking signal. Independent of noindex.', 'signal-noise-tools' ) },
		{ key: '_sn_noarchive', kind: 'flag', label: __( 'No cached copy (noarchive)', 'signal-noise-tools' ),
			help: __( 'Tells Google etc. not to show a cached version of this page.', 'signal-noise-tools' ) },
		{ key: '_sn_noimageindex', kind: 'flag', label: __( 'Hide images from image search (noimageindex)', 'signal-noise-tools' ),
			help: __( 'Images on this page will not appear in Google Images.', 'signal-noise-tools' ) },
		{ key: '_sn_seo_title', kind: 'text', placeholder: 'title', label: __( 'SEO title', 'signal-noise-tools' ),
			help: __( 'Overrides the page title used for the browser tab, og:title, and twitter:title. The site name is still appended. Empty falls back to the real page title.', 'signal-noise-tools' ) },
		{ key: '_sn_meta_description', kind: 'textarea', rows: 3, label: __( 'Meta description', 'signal-noise-tools' ),
			help: __( 'Overrides the post excerpt for the description meta tag, OG description, and JSON-LD. Empty falls back to excerpt.', 'signal-noise-tools' ) },
		{ key: '_sn_focus_keyword', kind: 'text', maxLength: 80, placeholder: 'music provenance', label: __( 'Focus keyword', 'signal-noise-tools' ),
			help: __( 'The SEO keyword this post targets. The AI meta-description generator requires it verbatim in its output; empty falls back to the title’s topic noun. Not rendered anywhere public.', 'signal-noise-tools' ) },
		{ key: '_sn_canonical_url', kind: 'url', placeholder: 'permalink', label: __( 'Canonical URL', 'signal-noise-tools' ),
			help: __( 'Overrides the default canonical link. Use when this post is a republish or syndication of content that lives at another URL. Empty falls back to the permalink.', 'signal-noise-tools' ) },
		{ key: '_sn_og_image_url', kind: 'url', placeholder: 'https://...', label: __( 'OG image URL', 'signal-noise-tools' ),
			help: __( 'Overrides the featured image or auto-generated card for OG and Twitter shares. Empty falls back to default resolution.', 'signal-noise-tools' ) },
		{ key: '_sn_og_card_title', kind: 'textarea', rows: 2, label: __( 'OG card title', 'signal-noise-tools' ),
			help: __( 'Replaces the post title in the social-share card image only: the og:title HTML meta still uses the real title. Empty falls back to the post title. The card fits 3 lines of Bebas across 1040px and steps 88, 74, 62px to make room, then cuts with an ellipsis. One line holds about 24 characters at full size. Aim for one full-size line: ~24 characters.', 'signal-noise-tools' ) },
		{ key: '_sn_pillar', kind: 'flag', page: true, label: __( 'Feature as a pillar essay', 'signal-noise-tools' ),
			help: __( 'Surfaces this Page in the theme’s pillar essay rail.', 'signal-noise-tools' ) },
		{ key: '_sn_pillar_designation', kind: 'text', page: true, placeholder: '1.01', label: __( 'Pillar designation', 'signal-noise-tools' ),
			help: __( 'Editorial number, for example 1.01. The pillar rail sorts numerically by major.minor.', 'signal-noise-tools' ) }
	];

	// Every key the panel owns: the field table plus the prepop sentinels.
	var OWN_KEYS = FIELDS.map( function( f ) { return f.key; } ).concat( Object.keys( cfg.prepop || {} ) );

	// A key at its default goes back as null so the REST meta controller
	// deletes the row instead of storing '' (see the file header).
	function normalize( next ) {
		OWN_KEYS.forEach( function( k ) {
			if ( '' === next[ k ] || false === next[ k ] ) {
				next[ k ] = null;
			}
		} );
		return next;
	}

	function actions() {
		return Array.isArray( window.sntPostSettingsActions ) ? window.sntPostSettingsActions : [];
	}

	// One AI action: a Button plus a status line. `apply` receives the
	// resolved value when the action names a field.
	function ActionButton( props ) {
		var state = useState( { busy: false, note: '' } );
		var s = state[ 0 ];
		var setS = state[ 1 ];
		var action = props.action;
		function click() {
			if ( ! props.postId ) {
				setS( { busy: false, note: __( 'Could not detect post ID. Save the post first.', 'signal-noise-tools' ) } );
				return;
			}
			setS( { busy: true, note: __( 'Generating…', 'signal-noise-tools' ) } );
			Promise.resolve( action.run( props.postId ) ).then( function( res ) {
				if ( res && res.value !== undefined && props.apply ) {
					props.apply( res.value );
				}
				setS( { busy: false, note: ( res && res.note ) || __( 'Generated', 'signal-noise-tools' ) } );
			} ).catch( function( err ) {
				setS( { busy: false, note: __( 'Failed', 'signal-noise-tools' ) + ': ' + ( ( err && err.message ) || __( 'Unknown error.', 'signal-noise-tools' ) ) } );
			} );
		}
		return el( 'div', { className: 'snt-post-settings-action', style: { display: 'flex', alignItems: 'center', gap: '8px', margin: '4px 0 12px' } },
			el( 'span', { style: { flex: 1, fontSize: '12px', color: '#646970' } }, s.note ),
			el( C.Button, { variant: 'secondary', size: 'compact', isBusy: s.busy, disabled: s.busy, onClick: click }, action.label )
		);
	}

	function Field( props ) {
		var f = props.field;
		var value = props.meta[ f.key ];
		var common = { label: f.label, help: f.help, __nextHasNoMarginBottom: true };
		var control;
		if ( 'flag' === f.kind ) {
			control = el( C.CheckboxControl, Object.assign( common, {
				checked: !! value,
				onChange: function( v ) { props.set( f.key, v ? true : null ); }
			} ) );
		} else if ( 'textarea' === f.kind ) {
			control = el( C.TextareaControl, Object.assign( common, {
				rows: f.rows, value: value || '',
				onChange: function( v ) { props.set( f.key, '' === v ? null : v ); }
			} ) );
		} else {
			control = el( C.TextControl, Object.assign( common, {
				__next40pxDefaultSize: true,
				type: 'url' === f.kind ? 'url' : 'text',
				maxLength: f.maxLength,
				placeholder: props.placeholders[ f.placeholder ] || f.placeholder || '',
				value: value || '',
				onChange: function( v ) { props.set( f.key, '' === v ? null : v ); }
			} ) );
		}
		var buttons = actions().filter( function( a ) { return a.field === f.key; } ).map( function( a ) {
			return el( ActionButton, { key: a.id, action: a, postId: props.postId, apply: function( v ) { props.set( f.key, v ); } } );
		} );
		return el( 'div', { className: 'snt-post-settings-field', style: { marginBottom: '12px' } }, control, buttons );
	}

	// The "auto-generated when you published" notice, read off the prepop
	// sentinels the entity carries. Dismiss clears them in the entity now
	// (deleted on save) and on the server through the prepop-dismiss ability.
	function PrepopNotice( props ) {
		var labels = cfg.prepop || {};
		var set = Object.keys( labels ).filter( function( k ) { return !! props.meta[ k ]; } );
		if ( ! set.length ) {
			return null;
		}
		function dismiss() {
			var next = Object.assign( {}, props.meta );
			set.forEach( function( k ) { next[ k ] = null; } );
			props.setMeta( normalize( next ) );
			if ( props.postId && 'function' === typeof window.sntAbilityRun ) {
				window.sntAbilityRun( 'prepop-dismiss', { post_id: props.postId } ).catch( function() {} );
			}
		}
		return el( C.Notice, { status: 'info', onRemove: dismiss },
			__( 'Auto-generated when you published: ', 'signal-noise-tools' ) + set.map( function( k ) { return labels[ k ]; } ).join( ', ' ) + '.' );
	}

	function Panel() {
		var editor = useSelect( function( select ) {
			var e = select( 'core/editor' );
			return {
				postType: e.getCurrentPostType(),
				postId: e.getCurrentPostId(),
				title: e.getEditedPostAttribute( 'title' ),
				permalink: e.getPermalink()
			};
		}, [] );
		var entity = useEntityProp( 'postType', editor.postType, 'meta' );
		var meta = entity[ 0 ] || {};
		var setMeta = entity[ 1 ];
		function set( key, value ) {
			var next = Object.assign( {}, meta );
			next[ key ] = value;
			setMeta( normalize( next ) );
		}
		var isPage = 'page' === editor.postType;
		var placeholders = { title: String( editor.title || '' ), permalink: editor.permalink || 'https://...' };
		var fields = FIELDS.filter( function( f ) { return ! f.page || isPage; } ).map( function( f ) {
			return el( Field, { key: f.key, field: f, meta: meta, set: set, postId: editor.postId, placeholders: placeholders } );
		} );
		var loose = actions().filter( function( a ) { return ! a.field; } ).map( function( a ) {
			return el( 'div', { key: a.id, className: 'snt-post-settings-field' },
				el( 'div', { style: { fontWeight: 500, marginBottom: '4px' } }, a.title || a.label ),
				a.help ? el( 'p', { style: { fontSize: '12px', color: '#646970', margin: '0 0 4px' } }, a.help ) : null,
				el( ActionButton, { action: a, postId: editor.postId } )
			);
		} );
		return el( PluginDocumentSettingPanel, { name: 'snt-post-settings', title: 'Signal & Noise' },
			el( PrepopNotice, { meta: meta, setMeta: setMeta, postId: editor.postId } ),
			fields,
			loose
		);
	}

	wp.plugins.registerPlugin( 'snt-post-settings', { render: Panel } );
} )();
