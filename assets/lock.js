/**
 * "Managed in git": marks the settings Config Sync locks on production.
 *
 * On a core settings screen it adds a badge next to each managed field and one
 * notice that lists them, each with a "Change it here anyway" button (a form
 * post to admin-post.php, checked by a nonce and the capability that owns the
 * setting). In the Customizer it adds a warning to each managed control.
 * Nothing here enforces the lock; the server refuses the save.
 */
( function () {
	'use strict';

	const data = window.axleConfigSyncLock;
	if ( ! data || ! Array.isArray( data.managed ) ) {
		return;
	}

	function fieldsFor( name ) {
		const selector = '[name="' + name + '"], [name="' + name + '[]"]';
		return Array.from( document.querySelectorAll( 'form[action="options.php"] ' + selector ) ).filter( ( element ) => 'hidden' !== element.type );
	}

	function labelFor( field, name ) {
		const row = field.closest( 'tr' );
		const th = row ? row.querySelector( 'th' ) : null;
		const text = th ? th.textContent.trim() : '';
		return text || name;
	}

	function overrideForm( names, label ) {
		const form = document.createElement( 'form' );
		form.method = 'post';
		form.action = data.postUrl;
		form.className = 'axle-lock-form';
		[ [ 'action', data.action ], [ '_wpnonce', data.nonce ] ].concat( names.map( ( name ) => [ 'option[]', name ] ) ).forEach( ( pair ) => {
			const input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = pair[ 0 ];
			input.value = pair[ 1 ];
			form.appendChild( input );
		} );
		const button = document.createElement( 'button' );
		button.type = 'submit';
		button.className = 'button button-small';
		button.textContent = data.i18n.override;
		button.setAttribute( 'aria-label', data.i18n.override + ': ' + label );
		form.appendChild( button );
		return form;
	}

	function settingsScreen() {
		// One entry per settings row, so a row with seven checkboxes is one setting to the person.
		const rows = [];
		data.managed.forEach( ( name ) => {
			const fields = fieldsFor( name );
			if ( ! fields.length ) {
				return;
			}
			const field = fields[ 0 ];
			const row = field.closest( 'tr' ) || field.parentElement;
			let entry = rows.find( ( candidate ) => candidate.row === row );
			if ( ! entry ) {
				entry = { row, names: [], fields: [], label: labelFor( field, name ) };
				rows.push( entry );
			}
			entry.names.push( name );
			entry.fields = entry.fields.concat( fields );
		} );

		if ( ! rows.length ) {
			return;
		}

		rows.forEach( ( entry, index ) => {
			const holder = entry.row.querySelector( 'td' ) || entry.row;
			const badge = document.createElement( 'span' );
			badge.className = 'axle-managed-badge';
			badge.id = 'axle-managed-' + index;
			badge.textContent = data.i18n.badge;
			holder.appendChild( badge );
			entry.fields.forEach( ( element ) => {
				const described = ( element.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( Boolean );
				described.push( badge.id );
				element.setAttribute( 'aria-describedby', described.join( ' ' ) );
			} );
		} );

		const wrap = document.querySelector( '#wpbody-content .wrap' );
		if ( ! wrap ) {
			return;
		}

		const box = document.createElement( 'div' );
		box.className = 'notice notice-warning axle-managed-summary';
		const intro = document.createElement( 'p' );
		intro.textContent = data.i18n.summary;
		box.appendChild( intro );
		const list = document.createElement( 'ul' );
		rows.forEach( ( entry ) => {
			const item = document.createElement( 'li' );
			item.appendChild( document.createTextNode( entry.label + ' ' ) );
			const allowed = entry.names.filter( ( name ) => data.can && data.can[ name ] );
			if ( allowed.length ) {
				item.appendChild( overrideForm( allowed, entry.label ) );
			}
			list.appendChild( item );
		} );
		const holder = document.createElement( 'details' );
		const title = document.createElement( 'summary' );
		title.textContent = data.i18n.count.replace( '%d', String( rows.length ) );
		holder.appendChild( title );
		holder.appendChild( list );
		if ( rows.length <= 3 ) {
			holder.open = true;
		}
		box.appendChild( holder );
		const help = document.createElement( 'p' );
		help.className = 'description';
		help.textContent = data.i18n.help;
		box.appendChild( help );

		const heading = wrap.querySelector( 'h1' );
		if ( heading ) {
			heading.insertAdjacentElement( 'afterend', box );
		} else {
			wrap.insertBefore( box, wrap.firstChild );
		}
	}

	function customizer() {
		const api = window.wp && window.wp.customize;
		if ( ! api ) {
			return;
		}
		api.bind( 'ready', () => {
			data.managed.forEach( ( name ) => {
				api.control( name, ( control ) => {
					control.notifications.add( new api.Notification( 'axle_managed', {
						type: 'warning',
						message: data.i18n.badge + '. ' + data.i18n.help,
					} ) );
				} );
			} );
		} );
	}

	if ( 'customizer' === data.context ) {
		customizer();
	} else if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', settingsScreen );
	} else {
		settingsScreen();
	}
}() );
