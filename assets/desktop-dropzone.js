/**
 * Signal & Noise Tools — drop a markdown/text file on the desktop,
 * get a drafted Note.
 *
 * Rides the shell's OS-file-drop pipeline (upstream contract,
 * docs/examples/os-file-drop.md): the `desktop-mode.drop.files-detected`
 * FILTER fires before the shell's MIME/size gate, so .md/.txt files are
 * claimed here (drafted via core REST wp/v2/posts) and every other file
 * passes through untouched to the shell's own Media Library dialog.
 *
 * Markdown handling is deliberately md-lite: `#`-headings become
 * core/heading blocks, blank-line-separated paragraphs become
 * core/paragraph blocks, everything else stays literal text. The point
 * is a DRAFT to finish in the editor, not a converter — and the output
 * is always valid serialized block markup, never recovery-prone HTML.
 *
 * v10.43.0: post-#475 OpenStation (WordPress/openstation PR #475, not yet in
 * any release) renames this filter to `os.drop.files-detected`
 * (src/os-file-drop/hooks.ts:19, FILE_DROP_HOOKS.FILES_DETECTED) — no shim,
 * so this file registers its handler under BOTH names. A WeakSet guards
 * against a hypothetical future double-fire delivering the SAME files array
 * to both names, which would otherwise draft every claimed file twice.
 *
 * @since plugin v9.77.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.hooks || ! window.wp.apiFetch ) {
		return;
	}

	var TEXT_EXT = /\.(md|markdown|txt)$/i;

	// Byte ceiling for files THIS filter claims. It fires BEFORE the shell's
	// own MIME/size gate, and the FileReader below reads the whole file into
	// memory — so anything above the ceiling is passed through untouched to
	// the shell's pipeline (which applies its own gate) instead of being read
	// unbounded here. 2 MB is far beyond any real markdown note.
	var MAX_BYTES = 2 * 1024 * 1024;

	function isTextDoc( file ) {
		if ( TEXT_EXT.test( file.name || '' ) ) {
			return true;
		}
		return 'text/plain' === file.type || 'text/markdown' === file.type;
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/**
	 * md-lite → serialized core blocks. Headings + paragraphs only, and
	 * heading depth is clamped to h2–h4 (a Note's h1 is its title).
	 */
	function toBlocks( text ) {
		var blocks = [];
		String( text ).replace( /\r\n/g, '\n' ).split( /\n{2,}/ ).forEach( function ( chunk ) {
			chunk = chunk.trim();
			if ( ! chunk ) {
				return;
			}
			var m = chunk.match( /^(#{1,6})\s+(.+)$/ );
			if ( m && -1 === m[ 2 ].indexOf( '\n' ) ) {
				var level = Math.min( 4, Math.max( 2, m[ 1 ].length ) );
				blocks.push(
					'<!-- wp:heading {"level":' + level + '} --><h' + level + ' class="wp-block-heading">' +
					escapeHtml( m[ 2 ].trim() ) + '</h' + level + '><!-- /wp:heading -->'
				);
				return;
			}
			blocks.push(
				'<!-- wp:paragraph --><p>' +
				escapeHtml( chunk ).replace( /\n/g, '<br>' ) +
				'</p><!-- /wp:paragraph -->'
			);
		} );
		return blocks.join( '' );
	}

	/** Title: first `# heading` if present, else the filename sans extension. */
	function titleFor( file, text ) {
		var m = String( text ).match( /^#\s+(.+)$/m );
		if ( m ) {
			return m[ 1 ].trim();
		}
		return String( file.name || 'Dropped note' ).replace( TEXT_EXT, '' ).replace( /[-_]+/g, ' ' ).trim();
	}

	function notify( title, body, onClick ) {
		if ( window.wp.desktop && typeof window.wp.desktop.notify === 'function' ) {
			window.wp.desktop.notify( { title: title, body: body || '', onClick: onClick } );
		}
	}

	function draftFrom( file ) {
		var reader = new FileReader();
		reader.onload = function () {
			var text = String( reader.result || '' );
			window.wp.apiFetch( {
				path:   '/wp/v2/posts',
				method: 'POST',
				data:   {
					title:   titleFor( file, text ),
					content: toBlocks( text ),
					status:  'draft',
				},
			} ).then( function ( post ) {
				notify(
					'Draft created',
					'"' + ( ( post && post.title && post.title.raw ) || file.name ) + '" is in Notes as a draft.',
					function ( n ) {
						if ( n && n.close ) { n.close(); }
						if ( post && post.id ) {
							window.open( 'post.php?post=' + post.id + '&action=edit', '_blank' );
						}
					}
				);
			} ).catch( function () {
				notify( 'Draft failed', 'Could not create a draft from "' + file.name + '".' );
			} );
		};
		reader.onerror = function () {
			notify( 'Draft failed', 'Could not read "' + file.name + '".' );
		};
		reader.readAsText( file );
	}

	// files-detected is a FILTER: claim the text documents, return the
	// rest so the shell's media pipeline handles them exactly as before.
	//
	// v10.43.0: registered under BOTH the pre-rename and post-#475 hook
	// names (see the file docblock). A WeakSet keyed on the `files` array
	// REFERENCE stops a hypothetical future double-fire — where both hook
	// names fire for the SAME drop event with the same array — from
	// drafting every claimed file twice. Today, with exactly one hook name
	// ever live, the guard never trips: each real drop passes a fresh array
	// through exactly one registered handler.
	var seenFileLists = typeof WeakSet !== 'undefined' ? new WeakSet() : null;

	function handleFilesDetected( files ) {
		if ( seenFileLists && files && seenFileLists.has( files ) ) {
			return files; // Already claimed via the other hook name — pass through untouched.
		}
		if ( seenFileLists && files ) {
			seenFileLists.add( files );
		}
		var pass = [];
		( files || [] ).forEach( function ( file ) {
			if ( isTextDoc( file ) && ( file.size || 0 ) <= MAX_BYTES ) {
				draftFrom( file );
			} else {
				pass.push( file );
			}
		} );
		return pass;
	}

	window.wp.hooks.addFilter( 'desktop-mode.drop.files-detected', 'signal-noise/drop-to-note', handleFilesDetected );
	window.wp.hooks.addFilter( 'os.drop.files-detected', 'signal-noise/drop-to-note', handleFilesDetected );
} )();
