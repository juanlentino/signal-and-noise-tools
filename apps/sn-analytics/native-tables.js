/**
 * Native report tables. The server owns formatted values and escaped rich cells;
 * os-table owns layout. Slots keep links in the light DOM, where the framework's
 * action delegation and the report's drill-down handlers can still reach them.
 */
( function () {
	'use strict';
	if ( customElements.get( 'snt-analytics-table' ) ) {
		return;
	}
	class AnalyticsTable extends HTMLElement {
		connectedCallback() {
			this.observer = new MutationObserver( () => this.schedule() );
			this.observer.observe( this, { childList: true } );
			this.schedule();
		}
		disconnectedCallback() {
			this.observer.disconnect();
			if ( this.sourceObserver ) {
				this.sourceObserver.disconnect();
			}
			this.source = null;
		}
		schedule() {
			if ( this.pending ) {
				return;
			}
			this.pending = true;
			customElements.whenDefined( 'os-table' ).then( () => {
				this.pending = false;
				if ( this.isConnected ) {
					this.paint();
				}
			} );
		}
		paint() {
			const source = this.querySelector( ':scope > template' );
			if ( ! source ) {
				return;
			}
			if ( this.source !== source ) {
				if ( this.sourceObserver ) {
					this.sourceObserver.disconnect();
				}
				this.source = source;
				this.sourceObserver = new MutationObserver( () => this.schedule() );
				this.sourceObserver.observe( source.content, { subtree: true, childList: true, characterData: true, attributes: true } );
			}
			const clamp = this.closest( '.snt-native-clamp' );
			const expanded = ! clamp || clamp.hasAttribute( 'data-expanded' );
			const visible = clamp ? Number( clamp.getAttribute( 'data-visible' ) ) : Infinity;
			const signature = source.innerHTML + ':' + expanded + ':' + visible;
			if ( this.signature === signature && this.output && this.output.parentNode === this ) {
				return;
			}
			const table = source.content.querySelector( 'table' );
			if ( ! table ) {
				return;
			}
			const headers = table.tHead ? Array.from( table.tHead.rows ) : [];
			const rows = Array.from( table.tBodies ).flatMap( body => Array.from( body.rows ) );
			// Grouped headers, footers and spanning cells retain their semantics.
			// The framework table has no colspan/rowspan contract.
			const matrix = headers.length !== 1 || table.tFoot || table.querySelector( '[colspan], [rowspan], table' );
			let output;
			if ( matrix ) {
				output = table.cloneNode( true );
				output.classList.remove( 'widefat', 'striped' );
				output.classList.add( 'snt-native-matrix' );
				Array.from( output.tBodies ).flatMap( body => Array.from( body.rows ) ).forEach( ( row, index ) => {
					row.hidden = ! expanded && index >= visible;
				} );
			} else {
				output = document.createElement( 'os-table' );
				output.setAttribute( 'compact', '' );
				output.setAttribute( 'hover', '' );
				const columns = Array.from( headers[0].cells ).map( ( cell, index ) => ( {
					key: 'c' + index,
					label: cell.textContent.trim(),
					align: cell.classList.contains( 'num' ) ? 'end' : 'start',
					render: ( value, row ) => {
						const slot = document.createElement( 'slot' );
						slot.name = 'r' + row._index + 'c' + index;
						return slot;
					}
				} ) );
				const data = rows.map( ( row, index ) => {
					const item = { _index: index };
					Array.from( row.cells ).forEach( ( cell, col ) => {
						item[ 'c' + col ] = cell.textContent.trim();
						const content = document.createElement( 'span' );
						content.slot = 'r' + index + 'c' + col;
						content.className = 'snt-native-cell';
						Array.from( cell.childNodes ).forEach( node => content.appendChild( node.cloneNode( true ) ) );
						output.appendChild( content );
					} );
					return item;
				} );
				output.columns = columns;
				output.data = expanded ? data : data.slice( 0, visible );
				if ( table.caption ) {
					output.setAttribute( 'aria-label', table.caption.textContent.trim() );
				}
			}
			if ( this.output ) {
				this.output.remove();
			}
			this.output = output;
			this.signature = signature;
			this.appendChild( output );
		}
	}
	customElements.define( 'snt-analytics-table', AnalyticsTable );
	document.addEventListener( 'click', event => {
		const button = event.target.closest && event.target.closest( '.snt-native-viewall' );
		if ( ! button ) {
			return;
		}
		const clamp = button.closest( '.snt-native-clamp' );
		const expanded = clamp.toggleAttribute( 'data-expanded' );
		button.setAttribute( 'aria-expanded', String( expanded ) );
		button.textContent = button.getAttribute( expanded ? 'data-less' : 'data-more' );
		clamp.querySelectorAll( 'snt-analytics-table' ).forEach( table => table.schedule() );
	} );
} )();
