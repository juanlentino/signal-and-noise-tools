/**
 * Signal & Noise Tools: the SN Queue card's calendar (2026-10-12, the
 * owner's redesign: "a calendar for the future notes, a list for the past").
 *
 * A rolling grid of WEEKS weeks from the Monday of this week, every scheduled
 * note a dot on its day, and one box under it that names the selected day's
 * notes (title, time, a link to the editor). The box starts on the next-up
 * day, so the card's old "Next up" headline lives there now.
 *
 * Dates: every day string (Y-m-d) comes from the server in the SITE timezone
 * (week_start, today, each row's day). This file only counts days forward
 * from week_start in UTC milliseconds, which is calendar arithmetic on
 * strings, never a question to the browser's clock or timezone.
 *
 * Marks that survive forced colors: today is a solid border and an underlined
 * number, a planned gap a dashed border, the selected day an outline, and the dots are drawn with
 * borders. A background (the next-up tint) is only ever a second cue; every
 * mark is also in the day's accessible name.
 *
 * Keyboard: one tab stop (a roving tabindex grid). Arrow keys move by day and
 * week, Home and End to the week's ends; focus or a click selects the day.
 * At the grid's edge the key is left alone, so the page can still scroll.
 *
 * Text is written with textContent only. Loaded before the card; a shell
 * that injects only the card's own script gets the old "Then" list instead.
 */
( function() {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// Measured on the owner's desk (2026-10-12): six weeks fit the card's
	// default height with every other section in place. See the PR.
	var WEEKS    = 6;
	var DAY_MS   = 86400000;
	var MONTHS   = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
	var WEEKDAYS = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];
	// The links' color (assets/snt-link.css): the accent pick lifted to AA on
	// the card, so the selection outline and the dots hold 3:1 under any pick.
	var ACCENT   = 'var(--snt-link, var(--os-ui-color-accent, #4a9eff))';
	var SUBTLE   = 'var(--os-ui-color-text-subtle, rgba(255,255,255,.7))';
	var AMBER    = '#d29922'; // the card's gap line: a schedule that needs you

	function el( tag, style, text ) {
		var n = document.createElement( tag );
		if ( style ) { n.setAttribute( 'style', style ); }
		if ( text != null ) { n.textContent = text; }
		return n;
	}

	function ms( day ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( typeof day === 'string' ? day : '' );
		return m ? Date.UTC( +m[1], +m[2] - 1, +m[3] ) : NaN;
	}

	function ymd( t ) {
		var d = new Date( t ), mo = d.getUTCMonth() + 1, da = d.getUTCDate();
		return d.getUTCFullYear() + '-' + ( mo < 10 ? '0' : '' ) + mo + '-' + ( da < 10 ? '0' : '' ) + da;
	}

	/** "Tue, Oct 13": the day's name, as the card's other dates read. */
	function dayLabel( day ) {
		var d = new Date( ms( day ) );
		return WEEKDAYS[ ( d.getUTCDay() + 6 ) % 7 ] + ', ' + MONTHS[ d.getUTCMonth() ] + ' ' + d.getUTCDate();
	}

	function titleOf( r ) {
		var t = r && typeof r.title === 'string' ? r.title.trim() : '';
		return t || '(untitled)';
	}

	/** A note's time, or "Overdue" when its schedule passed unpublished. */
	function timeOf( r ) {
		if ( r && 'Overdue' === r.when ) { return 'Overdue'; }
		return r && typeof r.time === 'string' ? r.time : '';
	}

	/** "Title at 1:01 PM" or "Title, overdue", for a day's accessible name. */
	function spoken( r ) {
		var t = timeOf( r );
		return titleOf( r ) + ( 'Overdue' === t ? ', overdue' : t ? ' at ' + t : '' );
	}

	/** A linked title (plain text without a link) in the given style. */
	function titleNode( r, style ) {
		var n = r && r.edit_url ? el( 'a', style + 'color:inherit;text-decoration:none;min-width:0;', titleOf( r ) ) : el( 'span', style + 'min-width:0;', titleOf( r ) );
		if ( r && r.edit_url ) { n.href = r.edit_url; }
		return n;
	}

	/** The scheduled rows grouped by their site-timezone day. */
	function byDay( rows ) {
		var out = {};
		( Array.isArray( rows ) ? rows : [] ).forEach( function( r ) {
			if ( r && typeof r.day === 'string' ) { ( out[ r.day ] = out[ r.day ] || [] ).push( r ); }
		} );
		return out;
	}

	function plannedOn( day, planned ) {
		return ( Array.isArray( planned ) ? planned : [] ).some( function( p ) {
			return p && typeof p.from === 'string' && typeof p.to === 'string' && day >= p.from && day <= p.to;
		} );
	}

	/** A dot drawn with a border, so forced colors keeps it. */
	function dot() {
		return el( 'span', 'display:block;width:0;height:0;border:2.5px solid ' + ACCENT + ';border-radius:50%;' );
	}

	/**
	 * Paint the box for one day: its name, then each note (title linked to
	 * the editor, time), or a plain "Nothing scheduled".
	 */
	function paintDetail( box, day, notes, flags ) {
		while ( box.firstChild ) { box.removeChild( box.firstChild ); }
		var bits = [ dayLabel( day ) ];
		if ( flags.today ) { bits.push( 'today' ); }
		if ( flags.planned ) { bits.push( 'planned gap' ); }
		box.appendChild( el( 'p', 'margin:0;font-size:11px;color:' + SUBTLE + ';', ( flags.next ? 'Next up · ' : '' ) + bits.join( ' · ' ) ) );
		if ( ! notes.length ) {
			box.appendChild( el( 'p', 'margin:2px 0 0;font-size:12px;color:' + SUBTLE + ';', 'Nothing scheduled' ) );
			return;
		}
		notes.forEach( function( r ) {
			var big  = flags.next ? 'font-size:14px;font-weight:600;line-height:1.3;' : 'font-size:12px;line-height:1.4;';
			var line = el( 'div', 'display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-top:2px;' );
			line.appendChild( titleNode( r, big ) );
			var t = timeOf( r );
			line.appendChild( el( 'span', 'flex:none;font-size:11px;font-variant-numeric:tabular-nums;color:' + ( 'Overdue' === t ? AMBER : SUBTLE ) + ';', t ) );
			box.appendChild( line );
		} );
	}

	/**
	 * Build the calendar. data: the content-queue payload. opts.selected: a
	 * day the user picked, kept across repaints while it is on the grid;
	 * opts.onSelect(day): told only when the user picks (a click, a key, a
	 * focus), never for the default, so an untouched card follows the next-up
	 * day as notes publish (review on #2031). Null when the payload carries no
	 * week_start (an older site).
	 */
	function render( data, opts ) {
		var start = ms( data && data.week_start );
		if ( isNaN( start ) ) { return null; }
		opts = opts || {};
		var notes   = byDay( data.scheduled );
		var today   = typeof data.today === 'string' ? data.today : '';
		var first   = Array.isArray( data.next ) && data.next[0] && typeof data.next[0].day === 'string' ? data.next[0].day : '';
		var lastDay = ymd( start + ( WEEKS * 7 - 1 ) * DAY_MS );
		var root    = el( 'div', 'container-type:inline-size;' );
		var cells   = [];
		var box     = el( 'div', 'margin-top:8px;min-height:36px;' );
		box.setAttribute( 'aria-live', 'polite' );

		// A note whose schedule passed before this week is off the grid: said
		// first, by title, so a missed note is never lost (review on #2031).
		var early = ( Array.isArray( data.scheduled ) ? data.scheduled : [] ).filter( function( r ) { return r && typeof r.day === 'string' && r.day < ymd( start ); } );
		if ( early.length ) {
			var miss = el( 'p', 'margin:0 0 6px;font-size:11px;line-height:1.4;color:' + AMBER + ';', early.length + ' overdue: ' );
			early.forEach( function( r, j ) {
				if ( j ) { miss.appendChild( el( 'span', '', '; ' ) ); }
				miss.appendChild( titleNode( r, 'font-size:11px;' ) );
			} );
			root.appendChild( miss );
		}

		var head = el( 'div', 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px;margin-bottom:2px;' );
		head.setAttribute( 'aria-hidden', 'true' );
		WEEKDAYS.forEach( function( w ) { head.appendChild( el( 'span', 'text-align:center;font-size:11px;color:' + SUBTLE + ';', w.charAt( 0 ) ) ); } );
		root.appendChild( head );

		var grid = el( 'div', 'display:flex;flex-direction:column;gap:2px;' );
		grid.setAttribute( 'role', 'grid' );
		grid.setAttribute( 'aria-label', 'Scheduled notes, ' + WEEKS + ' weeks from ' + dayLabel( ymd( start ) ) );

		function select( i, focus, user ) {
			cells.forEach( function( c, j ) {
				c.node.setAttribute( 'tabindex', i === j ? '0' : '-1' );
				c.node.setAttribute( 'aria-selected', i === j ? 'true' : 'false' );
				c.node.style.outline = i === j ? '2px solid ' + ACCENT : '';
			} );
			var c = cells[ i ];
			paintDetail( box, c.day, c.notes, c.flags );
			if ( focus && c.node.focus ) { c.node.focus(); }
			if ( user && typeof opts.onSelect === 'function' ) { opts.onSelect( c.day ); }
		}

		for ( var w = 0; w < WEEKS; w++ ) {
			var row = el( 'div', 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px;' );
			row.setAttribute( 'role', 'row' );
			for ( var d = 0; d < 7; d++ ) {
				row.appendChild( cell( ymd( start + ( w * 7 + d ) * DAY_MS ), w * 7 + d ) );
			}
			grid.appendChild( row );
		}

		function cell( day, i ) {
			var list  = notes[ day ] || [];
			var flags = { today: day === today, next: day === first, planned: plannedOn( day, data.planned ) };
			var dom   = +day.slice( 8 );
			var style = 'position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-width:0;min-height:clamp(26px, 10cqi, 34px);'
				+ 'padding:2px 0;margin:0;font:inherit;font-size:11px;line-height:1.1;font-variant-numeric:tabular-nums;cursor:pointer;border-radius:5px;outline-offset:-2px;'
				+ 'border:1px ' + ( flags.today ? 'solid currentColor' : flags.planned ? 'dashed ' + SUBTLE : 'solid transparent' ) + ';'
				+ 'background:' + ( flags.next ? 'color-mix(in srgb, ' + ACCENT + ' 24%, transparent)' : 'transparent' ) + ';'
				+ 'color:' + ( list.length || flags.today ? 'inherit' : SUBTLE ) + ';'
				+ ( list.length || flags.today ? 'font-weight:600;' : '' );
			var node = el( 'button', style );
			node.setAttribute( 'type', 'button' );
			node.setAttribute( 'role', 'gridcell' );
			// The 1st carries its month over the number (which stays). Only the
			// 1st: a tag on the grid's first day too cost its week 13px of
			// height for a month the box under the grid already names.
			if ( 1 === dom ) { node.appendChild( el( 'span', 'font-size:11px;font-weight:400;letter-spacing:-.02em;color:' + SUBTLE + ';', MONTHS[ +day.slice( 5, 7 ) - 1 ] ) ); }
			// Today's number is also underlined: text decoration keeps its shape
			// in forced colors even where every border is repainted alike.
			node.appendChild( el( 'span', flags.today ? 'text-decoration:underline;text-underline-offset:2px;' : '', String( dom ) ) );
			// Up to three dots; past three, two dots and "+N" for the rest, so
			// a busy day never reads as three notes.
			var dots = el( 'span', 'display:flex;align-items:center;gap:2px;min-height:5px;' );
			dots.setAttribute( 'aria-hidden', 'true' );
			list.slice( 0, list.length > 3 ? 2 : 3 ).forEach( function() { dots.appendChild( dot() ); } );
			if ( list.length > 3 ) { dots.appendChild( el( 'span', 'font-size:11px;line-height:1;font-weight:400;', '+' + ( list.length - 2 ) ) ); }
			node.appendChild( dots );
			// The next-up day's own shape: a small square in the corner, drawn
			// with a border so forced colors keeps it once another day is
			// selected (the tint is color only).
			if ( flags.next ) {
				var tick = el( 'span', 'position:absolute;top:2px;right:2px;width:0;height:0;border:2px solid ' + ACCENT + ';' );
				tick.setAttribute( 'aria-hidden', 'true' );
				node.appendChild( tick );
			}
			var name = [ dayLabel( day ) ];
			if ( flags.today ) { name.push( 'today' ); }
			if ( flags.next ) { name.push( 'next up' ); }
			if ( flags.planned ) { name.push( 'planned gap' ); }
			name.push( list.length ? list.length + ( 1 === list.length ? ' note: ' : ' notes: ' ) + list.map( spoken ).join( '; ' ) : 'nothing scheduled' );
			node.setAttribute( 'aria-label', name.join( ', ' ) );
			node.setAttribute( 'tabindex', '-1' );
			node.addEventListener( 'click', function() { select( i, false, true ); } );
			node.addEventListener( 'focus', function() { if ( 'true' !== node.getAttribute( 'aria-selected' ) ) { select( i, false, true ); } } );
			cells.push( { node: node, day: day, notes: list, flags: flags } );
			return node;
		}

		grid.addEventListener( 'keydown', function( e ) {
			if ( e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey ) { return; }
			var at = -1;
			cells.forEach( function( c, j ) { if ( '0' === c.node.getAttribute( 'tabindex' ) ) { at = j; } } );
			var to = { ArrowLeft: at - 1, ArrowRight: at + 1, ArrowUp: at - 7, ArrowDown: at + 7, Home: at - at % 7, End: at - at % 7 + 6 }[ e.key ];
			if ( to === undefined || to < 0 || to >= cells.length || to === at ) { return; }
			e.preventDefault();
			select( to, true, true );
		} );
		root.appendChild( grid );

		// The day to show: the user's pick while it is on the grid (empty or
		// not), else the next-up day, else today. A next-up off the grid (an
		// overdue note, a far one) still shows in the box; no cell is
		// selected then.
		var onGrid = function( day ) { return typeof day === 'string' && day >= ymd( start ) && day <= lastDay; };
		var want   = onGrid( opts.selected ) ? opts.selected : first || today;
		var idx  = -1;
		cells.forEach( function( c, j ) { if ( c.day === want ) { idx = j; } } );
		if ( idx >= 0 ) {
			select( idx, false, false );
		} else {
			cells[0].node.setAttribute( 'tabindex', '0' );
			if ( want ) { paintDetail( box, want, notes[ want ] || [], { next: want === first, today: want === today, planned: plannedOn( want, data.planned ) } ); }
		}

		root.appendChild( box );

		// Notes past the grid: counted, through the date the queue runs to.
		var beyond = ( Array.isArray( data.scheduled ) ? data.scheduled : [] ).filter( function( r ) { return r && typeof r.day === 'string' && r.day > lastDay; } );
		if ( beyond.length ) {
			var through = data.runs_to && typeof data.runs_to.label === 'string' ? ' through ' + data.runs_to.label : '';
			root.appendChild( el( 'p', 'margin:6px 0 0;font-size:11px;color:' + SUBTLE + ';font-variant-numeric:tabular-nums;', '+' + beyond.length + ' more' + through ) );
		}
		if ( ( Array.isArray( data.planned ) ? data.planned : [] ).some( function( p ) { return p && p.to >= ymd( start ) && p.from <= lastDay; } ) ) {
			root.appendChild( el( 'p', 'margin:4px 0 0;font-size:11px;color:' + SUBTLE + ';', 'Dashed days: a gap you marked planned' ) );
		}
		return { node: root, lastDay: lastDay };
	}

	window.sntQueueCalendar = { render: render, weeks: WEEKS };
}() );
