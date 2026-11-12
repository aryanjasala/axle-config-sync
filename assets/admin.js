/**
 * Settings > Config Sync: item selection, the in-place confirm steps, chunked
 * applies for large sets, copy buttons, live announcements and the wp.hooks
 * extension points.
 *
 * Filters (namespace axleConfigSync):
 * - axleConfigSync.diffRenderer( html|null, { item, before, after, element } ): return HTML to replace a diff.
 * - axleConfigSync.confirmMessage( message, { items } ): the text of the apply confirm step.
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

	function speak( message, politeness ) {
		if ( message && window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
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

	function busy( form, on ) {
		const button = form.querySelector( '#acs-apply-now' );
		const spinner = form.querySelector( '.spinner' );
		if ( button ) {
			button.disabled = on;
			button.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		}
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', on );
		}
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
		busy( form, true );
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
		speak( data.i18n.applied );
		window.location.href = data.history + '&applied=' + body.changeset + '&undo=' + body.changeset;
	}

	function applyForm() {
		const form = document.querySelector( '.acs-apply-form' );
		if ( ! form ) {
			return;
		}

		const trigger = form.querySelector( '#acs-apply' );
		const text = form.querySelector( '.acs-confirm-text' );
		if ( trigger && text ) {
			trigger.addEventListener( 'click', () => {
				const items = Array.from( form.querySelectorAll( 'input[name="items[]"]' ) ).map( ( input ) => input.value );
				const message = applyFilters( 'axleConfigSync.confirmMessage', text.textContent || format( data.i18n.confirm, items.length ), { items } );
				if ( 'string' === typeof message && message ) {
					text.textContent = message;
				}
			}, true );
		}

		form.addEventListener( 'submit', ( event ) => {
			const items = Array.from( form.querySelectorAll( 'input[name="items[]"]' ) ).map( ( input ) => input.value );
			const size = parseInt( form.getAttribute( 'data-batch' ), 10 ) || data.batchSize;
			if ( items.length > size && window.wp && window.wp.apiFetch ) {
				event.preventDefault();
				chunked( form, items ).catch( ( error ) => {
					busy( form, false );
					const progress = form.querySelector( '.acs-progress' );
					const message = format( data.i18n.failed, ( error && error.message ) || '' );
					if ( progress ) {
						progress.textContent = message;
					}
					speak( message, 'assertive' );
				} );
			}
		} );
	}

	/**
	 * In-place confirm steps: a button with data-acs-reveal opens the
	 * .axle-confirm with that ID and moves focus into it. Cancel closes it and
	 * returns focus to the button named in data-acs-cancel. Escape cancels.
	 */
	function confirms() {
		function close( box ) {
			box.hidden = true;
			const owner = box.querySelector( '[data-acs-cancel]' );
			const start = owner ? document.getElementById( owner.getAttribute( 'data-acs-cancel' ) ) : null;
			if ( start ) {
				start.setAttribute( 'aria-expanded', 'false' );
				start.focus();
			}
		}

		document.addEventListener( 'click', ( event ) => {
			const open = event.target.closest( '[data-acs-reveal]' );
			if ( open ) {
				const box = document.getElementById( open.getAttribute( 'data-acs-reveal' ) );
				if ( box ) {
					event.preventDefault();
					box.hidden = false;
					open.setAttribute( 'aria-expanded', 'true' );
					box.setAttribute( 'tabindex', '-1' );
					box.focus();
				}
				return;
			}
			const cancel = event.target.closest( '[data-acs-cancel]' );
			if ( cancel ) {
				const box = cancel.closest( '.axle-confirm' );
				if ( box ) {
					event.preventDefault();
					close( box );
				}
			}
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' !== event.key ) {
				return;
			}
			const box = event.target.closest ? event.target.closest( '.axle-confirm' ) : null;
			if ( box && ! box.hidden ) {
				close( box );
			}
		} );
	}

	/** Copy buttons: data-acs-copy names a textarea. */
	function copying() {
		document.querySelectorAll( '[data-acs-copy]' ).forEach( ( button ) => {
			button.addEventListener( 'click', async () => {
				const field = document.getElementById( button.getAttribute( 'data-acs-copy' ) );
				if ( ! field ) {
					return;
				}
				try {
					if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
						await window.navigator.clipboard.writeText( field.value );
					} else {
						field.focus();
						field.select();
						if ( ! document.execCommand( 'copy' ) ) {
							throw new Error( 'copy failed' );
						}
					}
					speak( data.i18n.copied );
				} catch ( error ) {
					field.focus();
					field.select();
					speak( data.i18n.copyFail, 'assertive' );
				}
			} );
		} );
	}

	/** Announces the notice an action left, once. */
	function announce() {
		const notice = document.querySelector( '.acs-notice' );
		if ( notice ) {
			speak( notice.textContent.trim(), notice.classList.contains( 'notice-error' ) ? 'assertive' : 'polite' );
		}
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
		confirms();
		copying();
		announce();
		actions();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
