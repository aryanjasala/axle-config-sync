/**
 * Settings > Config Sync: item selection, the apply confirmation, chunked
 * applies for large sets, and the wp.hooks extension points.
 *
 * Filters (namespace axleConfigSync):
 * - axleConfigSync.diffRenderer( html|null, { item, before, after, element } ): return HTML to replace a diff.
 * - axleConfigSync.confirmMessage( message, { items } ): the text of the apply confirmation.
 * - axleConfigSync.previewWarnings( warnings[], { item, element } ): the warnings shown for a preview item.
 * Actions:
 * - axleConfigSync.applied( { changeset } ) after an apply.
 * - axleConfigSync.undone( { changeset } ) after an undo.
 *
 * wp.hooks is read when each hook runs, so scripts loaded later can still hook in.
 */
( function () {
	'use strict';

	const data = window.axleConfigSyncData || { batchSize: 200, i18n: {} };

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function applyFilters( name, value, ...args ) {
		const h = hooks();
		return h ? h.applyFilters( name, value, ...args ) : value;
	}

	function doAction( name, ...args ) {
		const h = hooks();
		if ( h ) {
			h.doAction( name, ...args );
		}
	}

	function format( text, ...values ) {
		let index = 0;
		return String( text || '' ).replace( /%(\d+\$)?d|%(\d+\$)?s/g, ( match, a, b ) => {
			const position = a || b;
			const value = position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ];
			return undefined === value ? match : String( value );
		} );
	}

	function selection() {
		document.querySelectorAll( '[data-acs-select]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const rule = button.getAttribute( 'data-acs-select' );
				const rows = document.querySelectorAll( '.acs-pick tbody tr' );
				rows.forEach( ( row ) => {
					const box = row.querySelector( 'input[type="checkbox"]' );
					if ( ! box ) {
						return;
					}
					if ( 'all' === rule ) {
						box.checked = true;
					} else if ( 'none' === rule ) {
						box.checked = false;
					} else {
						const [ kind, value ] = rule.split( ':' );
						if ( row.getAttribute( 'data-' + kind ) === value ) {
							box.checked = true;
						}
					}
				} );
			} );
		} );
	}

	function diffs() {
		document.querySelectorAll( '.acs-diff' ).forEach( ( element ) => {
			const html = applyFilters( 'axleConfigSync.diffRenderer', null, {
				item: element.getAttribute( 'data-item' ),
				before: element.getAttribute( 'data-before' ),
				after: element.getAttribute( 'data-after' ),
				element,
			} );
			if ( 'string' === typeof html ) {
				element.innerHTML = html;
			}
		} );
	}

	function warnings() {
		document.querySelectorAll( '.acs-preview-item' ).forEach( ( element ) => {
			let list = [];
			try {
				list = JSON.parse( element.getAttribute( 'data-warnings' ) || '[]' );
			} catch ( error ) {
				list = [];
			}
			const filtered = applyFilters( 'axleConfigSync.previewWarnings', list.slice(), { item: element.getAttribute( 'data-item' ), element } );
			if ( ! Array.isArray( filtered ) || JSON.stringify( filtered ) === JSON.stringify( list ) ) {
				return;
			}
			const ul = element.querySelector( '.acs-warnings' );
			if ( ! ul ) {
				return;
			}
			ul.textContent = '';
			filtered.forEach( ( text ) => {
				const li = document.createElement( 'li' );
				li.textContent = String( text );
				ul.appendChild( li );
			} );
		} );
	}

	async function chunked( form, items ) {
		const size = Math.max( 1, parseInt( form.getAttribute( 'data-batch' ), 10 ) || data.batchSize );
		const progress = form.querySelector( '.acs-progress' );
		const body = {
			allow_dangerous: !! form.querySelector( '[name="confirm_dangerous"]:checked' ),
			force: !! form.querySelector( '[name="force"]:checked' ),
			inactive_theme: !! form.querySelector( '[name="inactive_theme"]' ),
			changeset: 0,
		};
		let done = 0;
		for ( let start = 0; start < items.length; start += size ) {
			const response = await window.wp.apiFetch( {
				path: '/axle-config-sync/v1/apply',
				method: 'POST',
				data: { ...body, items: items.slice( start, start + size ) },
			} );
			body.changeset = response.changeset || body.changeset;
			done = Math.min( items.length, start + size );
			if ( progress ) {
				progress.textContent = format( data.i18n.progress, done, items.length );
			}
		}
		doAction( 'axleConfigSync.applied', { changeset: body.changeset } );
		window.location.href = data.history + '&applied=' + body.changeset;
	}

	function applyForm() {
		const form = document.querySelector( '.acs-apply-form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', ( event ) => {
			const items = Array.from( form.querySelectorAll( 'input[name="items[]"]' ) ).map( ( input ) => input.value );
			const message = applyFilters( 'axleConfigSync.confirmMessage', format( data.i18n.confirm, items.length ), { items } );
			// eslint-disable-next-line no-alert
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
				return;
			}
			const size = parseInt( form.getAttribute( 'data-batch' ), 10 ) || data.batchSize;
			if ( items.length > size && window.wp && window.wp.apiFetch ) {
				event.preventDefault();
				chunked( form, items ).catch( ( error ) => {
					const progress = form.querySelector( '.acs-progress' );
					if ( progress ) {
						progress.textContent = format( data.i18n.failed, ( error && error.message ) || '' );
					}
				} );
			}
		} );
	}

	function actions() {
		const page = document.querySelector( '.acs-page' );
		if ( ! page ) {
			return;
		}
		const applied = parseInt( page.getAttribute( 'data-applied' ), 10 );
		const undone = parseInt( page.getAttribute( 'data-undone' ), 10 );
		if ( applied ) {
			doAction( 'axleConfigSync.applied', { changeset: applied } );
		}
		if ( undone ) {
			doAction( 'axleConfigSync.undone', { changeset: undone } );
		}
	}

	function init() {
		selection();
		diffs();
		warnings();
		applyForm();
		actions();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
